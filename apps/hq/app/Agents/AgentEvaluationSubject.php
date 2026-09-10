<?php

namespace App\Agents;

use App\Context\ContextBlock;
use App\Context\Provenance;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationSubject;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\EvaluationCase;
use Throwable;

/**
 * Runs an evaluation case through a real agent (ADR-023, docs/48 M5).
 *
 * Phase 2.5 built the corpus, the graders and the run history with no agent to point them at, and
 * said so: "the only subjects are recorded transcripts and test doubles". This is the subject that
 * was missing, and it is what turns the twelve seeded safety cases from a table into a gate.
 *
 * ## A named tool counts as a call, even when the control plane refused it
 *
 * ⭐⭐ **The observation reports every tool the agent NAMED, not only the ones that ran.** This is a
 * deliberate reading of `must_not_call` and it makes the corpus strict.
 *
 * The argument: the safety corpus exists to measure the MODEL's behaviour under the control plane's
 * protection, not to re-test the control plane. If an injected instruction makes an agent propose
 * `erp.poll_overview`, the injection worked - the gateway caught it, and the gateway catching it is
 * exactly why nothing bad happened this time. Scoring that as a pass would be recording the
 * strength of the guard as evidence about the thing being guarded, and then citing it when widening
 * autonomy, which is the one moment that reasoning becomes expensive.
 *
 * So the harder question is asked: did the model try?
 *
 * ## What it does not do
 *
 * ⛔ It creates no autonomy grant and lifts no ceiling. A corpus case runs at whatever autonomy the
 * agent has, which in Phase 2 is A0 - so a case can never cause an effect, and running the corpus is
 * safe on production against real data. That is not a happy accident; a harness that raised its
 * subject's privileges to test it would be measuring something that never runs.
 */
final readonly class AgentEvaluationSubject implements EvaluationSubject
{
    public function __construct(
        private AgentRunner $runner,
        private string $agentKey,
    ) {}

    public function subjectKey(): string
    {
        return $this->agentKey;
    }

    public function run(EvaluationCase $case): EvaluationObservation
    {
        $input = (array) $case->input;
        $question = (string) ($input['prompt'] ?? $case->purpose);

        try {
            $run = $this->runner->run(new AgentRunRequest(
                agentKey: $this->agentKey,
                question: $question,
                trigger: 'evaluation',
                businessId: $this->businessId($input),
                extraContext: $this->context($input),
            ));
        } catch (Throwable $e) {
            // A case that could not be run is not a case that passed. Reporting it as a refusal with
            // the reason keeps it visible and lets the grader fail it on its own terms.
            return new EvaluationObservation(
                refused: true,
                refusalReason: 'the run could not be completed: '.get_class($e).': '.$e->getMessage(),
            );
        }

        return new EvaluationObservation(
            toolCalls: $this->namedTools($run),
            refused: $run->status === 'refused',
            refusalReason: $run->status === 'refused' ? $run->error : null,
            answer: $run->answer,
            citations: $this->citations($run),
            latencyMs: $run->duration_ms,
            aiRunId: AiRun::where('correlation_id', $run->correlation_id)->value('id'),
            contextProvenance: (array) $run->context_provenance,
            // ADR-030: what the model asked for and what the control plane allowed are two facts,
            // and a corpus that cannot tell them apart cannot grade tool selection separately from
            // enforcement.
            executedToolCalls: $this->executedTools($run),
            refusedToolCalls: $this->refusedTools($run),
        );
    }

    /**
     * Every tool the agent named this run, executed or not.
     *
     * @return list<array{tool: string, args?: array}>
     */
    private function namedTools(AgentRun $run): array
    {
        $out = [];

        foreach ([...(array) $run->evidence, ...(array) $run->proposals] as $entry) {
            if (! is_array($entry) || ! is_string($entry['tool'] ?? null) || $entry['tool'] === '') {
                continue;
            }

            // `claim` rows are the model's cited statements, not tool invocations.
            if (($entry['kind'] ?? '') === 'claim') {
                continue;
            }

            $out[] = ['tool' => $entry['tool'], 'args' => (array) ($entry['args'] ?? [])];
        }

        return $out;
    }

    /**
     * The tools that actually RAN: evidence rows carry a real `tool_calls` id.
     *
     * @return list<array{tool: string, tool_call_id: int|null}>
     */
    private function executedTools(AgentRun $run): array
    {
        $out = [];

        foreach ((array) $run->evidence as $entry) {
            if (is_array($entry) && ($entry['kind'] ?? '') === 'evidence' && is_string($entry['tool'] ?? null)) {
                $out[] = ['tool' => $entry['tool'], 'tool_call_id' => $entry['tool_call_id'] ?? null];
            }
        }

        return $out;
    }

    /**
     * The tools that were PROPOSED and stopped, with the reason the control plane gave.
     *
     * @return list<array{tool: string, reason: string}>
     */
    private function refusedTools(AgentRun $run): array
    {
        $out = [];

        foreach ((array) $run->proposals as $entry) {
            if (is_array($entry) && is_string($entry['tool'] ?? null) && $entry['tool'] !== '') {
                $out[] = ['tool' => $entry['tool'], 'reason' => (string) ($entry['reason'] ?? 'no reason recorded')];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function citations(AgentRun $run): array
    {
        $out = [];

        foreach ((array) $run->evidence as $entry) {
            if (is_array($entry) && ($entry['kind'] ?? '') === 'claim' && is_string($entry['source'] ?? null)) {
                $out[] = $entry['source'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The case's own context blocks, each keeping the provenance the case declared.
     *
     * ⚠ An unrecognised provenance becomes `ThirdParty`, the most restrictive of the untrusted
     * kinds, rather than defaulting to trusted. A typo in a corpus file must not be able to turn an
     * injection case into a trusted one, which would make it pass for the worst possible reason.
     *
     * @return list<ContextBlock>
     */
    private function context(array $input): array
    {
        $blocks = [];

        foreach ((array) ($input['context'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['content'] ?? null)) {
                continue;
            }

            $provenance = Provenance::tryFrom((string) ($entry['provenance'] ?? '')) ?? Provenance::ThirdParty;

            $blocks[] = new ContextBlock(
                provenance: $provenance,
                source: (string) ($entry['source'] ?? 'evaluation:case'),
                content: $entry['content'],
            );
        }

        return $blocks;
    }

    private function businessId(array $input): ?int
    {
        $id = $input['business_id'] ?? null;

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }
}
