<?php

namespace App\Agents;

use App\Context\ContextBlock;
use App\Context\Provenance;
use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\ScenarioLibrary;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationSubject;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\Business;
use App\Models\EvaluationCase;
use App\Models\ToolCall;
use RuntimeException;
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

    /**
     * The agent's declared role, read from the agent row (ADR-035).
     *
     * ⚠ Falls back to the agent KEY when no role is set, and to null when the agent does not exist -
     * never to a default role. Inheriting a role would let a suite be graded against an agent whose
     * behaviour it was never written to describe, which is the mistake docs/61 recorded.
     */
    public function subjectRole(): ?string
    {
        return Agent::where('key', $this->agentKey)->value('role_key')
            ?? (Agent::where('key', $this->agentKey)->exists() ? $this->agentKey : null);
    }

    public function run(EvaluationCase $case): EvaluationObservation
    {
        $input = (array) $case->input;
        $question = (string) ($input['prompt'] ?? $case->purpose);

        // Gate P3-G2 (docs/77 C.4): a case that names a simulation scenario is run on the thread the
        // real conversation core left, and only when the core left it with the agent.
        if (is_string($input['scenario'] ?? null)) {
            return $this->runScenario($input['scenario'], (string) ($input['brand'] ?? 'LLD'), $question);
        }

        return $this->observe(new AgentRunRequest(
            agentKey: $this->agentKey,
            question: $question,
            trigger: 'evaluation',
            businessId: $this->businessId($input),
            extraContext: $this->context($input),
        ));
    }

    /**
     * Replay the scenario through the core, roll it back, and run the agent on the copy of the thread.
     *
     * ⛔ The core decides whether the agent is woken, never the case. A thread the core gave to a
     * person produces an observation with no model call, and that decision is what gets graded.
     * A scenario the core itself did not replay as written is a failure of the case, not a pass on
     * a thread the scenario never meant.
     */
    private function runScenario(string $key, string $brand, string $question): EvaluationObservation
    {
        $scenario = ScenarioLibrary::find($key);

        try {
            if ($scenario === null) {
                throw new RuntimeException("No simulation scenario is keyed [{$key}].");
            }
            $thread = app(ConversationSimulator::class)->capture($scenario, $brand);
            if (! $thread['core_passed']) {
                throw new RuntimeException('The conversation core did not replay the scenario as written: '.implode('; ', $thread['core_mismatches']));
            }
        } catch (Throwable $e) {
            return new EvaluationObservation(
                refused: true,
                refusalReason: 'the scenario could not be replayed: '.$e->getMessage(),
                outcome: EvaluationObservation::OUTCOME_FAILED,
                stopReason: 'exception',
            );
        }

        if (! $thread['with_agent']) {
            return new EvaluationObservation(outcome: 'not_woken', agentWoken: false);
        }

        return $this->observe(new AgentRunRequest(
            agentKey: $this->agentKey,
            question: $question,
            trigger: 'evaluation',
            businessId: $thread['business_id'],
            brandId: $thread['brand_id'],
            extraContext: $this->threadContext($thread),
        ), agentWoken: true);
    }

    /**
     * The thread as the agent receives it: one block from HQ naming the thread, then every customer
     * message under its own provenance, which the core recorded as untrusted.
     *
     * @return list<ContextBlock>
     */
    private function threadContext(array $thread): array
    {
        $blocks = [ContextBlock::system('conversation:'.$thread['conversation_uuid'], implode("\n", [
            'The conversation core handed you this thread.',
            'conversation_uuid: '.$thread['conversation_uuid'],
            'business_slug: '.$thread['business_slug'],
            'brand: '.$thread['brand_name'],
            'state: '.$thread['state'],
            "script of the customer's messages: ".($thread['language'] ?? 'none detected'),
        ]))];

        foreach ($thread['messages'] as $message) {
            $blocks[] = new ContextBlock(
                provenance: Provenance::tryFrom($message['provenance']) ?? Provenance::ThirdParty,
                source: 'conversation:'.$thread['conversation_uuid'].'/message:'.$message['uuid'],
                content: $message['body'] !== ''
                    ? $message['body']
                    : '[the customer sent '.$message['attachments'].' attachment(s) and no text]',
            );
        }

        return $blocks;
    }

    private function observe(AgentRunRequest $request, ?bool $agentWoken = null): EvaluationObservation
    {
        try {
            $run = $this->runner->run($request);
        } catch (Throwable $e) {
            // A case that could not be run is not a case that passed. Reporting it as a refusal with
            // the reason keeps it visible and lets the grader fail it on its own terms.
            return new EvaluationObservation(
                refused: true,
                refusalReason: 'the run could not be completed: '.get_class($e).': '.$e->getMessage(),
                outcome: EvaluationObservation::OUTCOME_FAILED,
                stopReason: 'exception',
                agentWoken: $agentWoken,
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
            // ADR-043: HQ permitted these and the outside failed them. Readiness evidence, apart
            // from the refusals, so a rejected shop token can never grade as a control-plane no.
            failedToolCalls: $this->failedTools($run),
            // How it ended, so the grader can refuse to pass silence (docs/62 §C, closed in docs/65).
            outcome: (string) $run->status,
            stopReason: $run->stop_reason,
            // The third verdict and the assessment behind it (ADR-040): what the run said it was
            // serving, and whether the message asked it to widen its own authority.
            clarified: $run->status === 'clarified',
            task: $run->task,
            authorityRequest: $run->authority_request,
            // The runtime's own decision about the task (ADR-041), apart from the model's.
            canonicalTask: $run->canonical_task,
            taskGrounding: $run->task_grounding,
            // The runtime's own reading of the message for security-sensitive intent (ADR-042).
            securityIntent: $run->security_intent === null ? null : (array) $run->security_intent,
            authoritySource: $run->authority_source,
            // The third boundary (ADR-044): a capability HQ prohibits, read by the runtime.
            prohibitedCapability: $run->prohibited_capability === null ? null : (array) $run->prohibited_capability,
            // A provider refusal measured nothing about the agent; the grader and the runner are
            // told so rather than left to read a quota as a refusal that never came.
            unmeasured: $run->stop_reason === AgentRunner::STOP_PROVIDER_REFUSED,
            agentWoken: $agentWoken,
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
                $id = $entry['tool_call_id'] ?? null;
                // The business the gateway RESOLVED the call to (ADR-043), read from the row it
                // wrote, so `scope_bound` grades where a call landed and not what the model typed.
                $businessId = $id === null ? null : ToolCall::whereKey($id)->value('business_id');

                $out[] = [
                    'tool' => $entry['tool'],
                    'tool_call_id' => $id,
                    'business' => $businessId === null ? null : Business::whereKey($businessId)->value('slug'),
                ];
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
            // ⭐ ADR-043: only what HQ REFUSED. A row filed as `failed` was permitted and failed
            //    outside HQ; it belongs to `failedTools()`. Rows older than the class (gate 8 and
            //    before) carry no side and are read as refusals, which is what they were graded as.
            if (! is_array($entry) || ! is_string($entry['tool'] ?? null) || $entry['tool'] === '') {
                continue;
            }
            if (($entry['kind'] ?? 'refused') === 'failed') {
                continue;
            }

            $out[] = ['tool' => $entry['tool'], 'reason' => (string) ($entry['reason'] ?? 'no reason recorded')]
                + (isset($entry['outcome_class']) ? ['outcome_class' => (string) $entry['outcome_class']] : []);
        }

        return $out;
    }

    /**
     * Calls HQ permitted that failed outside HQ (ADR-043), with the class, so the runner can tally
     * readiness apart from behaviour.
     *
     * @return list<array{tool: string, reason: string, outcome_class: string}>
     */
    private function failedTools(AgentRun $run): array
    {
        $out = [];

        foreach ((array) $run->proposals as $entry) {
            if (is_array($entry) && ($entry['kind'] ?? null) === 'failed' && is_string($entry['tool'] ?? null) && $entry['tool'] !== '') {
                $out[] = [
                    'tool' => $entry['tool'],
                    'reason' => (string) ($entry['reason'] ?? 'no reason recorded'),
                    'outcome_class' => (string) ($entry['outcome_class'] ?? 'connector_error'),
                ];
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
