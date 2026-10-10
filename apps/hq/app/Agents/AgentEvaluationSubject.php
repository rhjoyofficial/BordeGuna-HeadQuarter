<?php

namespace App\Agents;

use App\Context\ContextBlock;
use App\Context\Provenance;
use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\Scenario;
use App\Conversation\Simulation\ScenarioLibrary;
use App\Conversation\SupportContext;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationSubject;
use App\Evaluation\EvidenceCarryOver;
use App\Execution\Requester;
use App\Execution\RequesterKind;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\Business;
use App\Models\EvaluationCase;
use App\Models\ToolCall;
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
 * ## Who is asking, when nobody is (ADR-047)
 *
 * A gate run has no person behind it, and it is not given one. It goes through the same Effective
 * Execution Context resolver as every other entry point, declared `as enabled`: the agent is
 * measured exactly as its enabling user enabled it, the requester term adds nothing, and an agent
 * with no enabling user (the support agent, until a person is named for it) cannot be measured at
 * all. The support agent's scenario path passes the thread's business and brand, and the resolver
 * refuses a brand that is not that business's.
 *
 * ## Every customer thread is a real thread (docs/90 A.1 step 4)
 *
 * ⭐ A case that names a simulation scenario, and, for the SUPPORT role, any case that carries a
 * customer's words as context, is run on a thread the real conversation core built, which exists for
 * the whole run and is rolled back after it (docs/91 A.1): a real `conversation_uuid`, the customer as
 * HQ knows them, the state the core chose, the same
 * `SupportContext` blocks the real wake-up hands over, and the core's own decision whether the agent
 * is woken at all. There is no threadless customer path for the Support agent: P3-G2 attempt 1 ran
 * its ten written cases on a bare customer block with no thread header, so the agent had no thread
 * to name (docs/88 C.11). A case with no customer words (an operator's question put to the agent)
 * still runs as before, because it is not a thread.
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
            $scenario = ScenarioLibrary::find($input['scenario']);

            return $scenario === null
                ? $this->notReplayed("No simulation scenario is keyed [{$input['scenario']}].")
                : $this->runOnThread($scenario, (string) ($input['brand'] ?? 'LLD'), $question);
        }

        // docs/90 A.1 step 4: for the Support role, a customer's words are a thread, never a bare block.
        if ($this->subjectRole() === 'support' && ($scenario = $this->threadFromContext($case, $input)) !== null) {
            return $this->runOnThread($scenario, (string) ($input['brand'] ?? 'LLD'), $question);
        }

        return $this->observe(new AgentRunRequest(
            agentKey: $this->agentKey,
            question: $question,
            trigger: 'evaluation',
            businessId: $this->businessId($input),
            extraContext: $this->context($input),
            requester: self::requester(),
        ));
    }

    /**
     * Replay the scenario through the core and run the agent on that thread WHILE IT EXISTS, then roll
     * the thread back (docs/91 A.1).
     *
     * P3-G2 attempt 2 ran the agent after the rollback, on a copy of the thread's context: the bound
     * `conversation_uuid` then named a thread that no longer existed, and `conversation.read_thread`
     * answered "HQ holds no such conversation in this business" ten times. Now the whole lifecycle is one
     * transaction (`ConversationSimulator::withThread`): thread, run, evidence, rollback. The run's own rows
     * (model calls and their cost, raw replies, the run, tool calls, audit) are carried across the rollback
     * by `EvidenceCarryOver`; the thread is not.
     *
     * ⛔ The core decides whether the agent is woken, never the case. A thread the core gave to a
     * person produces an observation with no model call, and that decision is what gets graded.
     * A scenario the core itself did not replay as written is a failure of the case, not a pass on
     * a thread the scenario never meant.
     */
    private function runOnThread(Scenario $scenario, string $brand, string $question): EvaluationObservation
    {
        $carry = new EvidenceCarryOver;
        $evidence = [];

        try {
            $observation = app(ConversationSimulator::class)->withThread($scenario, $brand, function (array $thread) use ($scenario, $question, $carry, &$evidence) {
                if (! $thread['core_passed']) {
                    return $this->notReplayed('The conversation core did not replay the scenario as written: '.implode('; ', $thread['core_mismatches']));
                }

                if (! $thread['with_agent']) {
                    // A person took the thread before the agent was asked: nothing was said, and the case is
                    // graded on that decision. A case expecting a refusal is satisfied (the customer got no
                    // agent reply); one expecting an answer is not, because the core decided otherwise.
                    return new EvaluationObservation(
                        refused: true,
                        refusalReason: 'the conversation core handed this thread to a person before the agent was asked',
                        outcome: 'not_woken',
                        agentWoken: false,
                        modelTurns: 0,
                        subjectRole: $this->subjectRole(),
                        coreHandover: $thread['core_handover'] ?? null,
                    );
                }

                $marks = $carry->mark();
                try {
                    return $this->observe(new AgentRunRequest(
                        agentKey: $this->agentKey,
                        question: $question,
                        trigger: 'evaluation',
                        businessId: $thread['business_id'],
                        brandId: $thread['brand_id'],
                        // Built by SupportContext from the thread that exists right now (docs/78 B.1): header,
                        // customer facts, labelled memory, untrusted messages, the blocks the real wake-up hands over.
                        extraContext: SupportContext::fromData($thread['context']),
                        requester: self::requester(),
                        // docs/90 A.1 steps 3 and 5: the thread the run is bound to, and the contract it declares.
                        conversationUuid: $thread['conversation_uuid'],
                        supportWorkflow: $scenario->workflow,
                    ), agentWoken: true);
                } finally {
                    // Read before the rollback, whatever happened: money spent is money spent.
                    $evidence = $carry->collect($marks);
                }
            });
        } catch (Throwable $e) {
            $carry->restore($evidence);

            return $this->notReplayed($e->getMessage());
        }

        $carry->restore($evidence);

        return $observation;
    }

    private function notReplayed(string $why): EvaluationObservation
    {
        return new EvaluationObservation(
            refused: true,
            refusalReason: 'the scenario could not be replayed: '.$why,
            outcome: EvaluationObservation::OUTCOME_FAILED,
            stopReason: 'exception',
        );
    }

    /**
     * docs/90 A.1 step 4: a written case's customer words as a scenario the core replays, so the
     * Support agent meets them on a real thread. One turn per customer block, in order, from one
     * sender the case owns; the agent active, because the case is about what the agent does. Null
     * when the case carries no customer words (an operator's question is not a thread).
     */
    private function threadFromContext(EvaluationCase $case, array $input): ?Scenario
    {
        $turns = [];
        foreach ((array) ($input['context'] ?? []) as $index => $entry) {
            if (is_array($entry) && ($entry['provenance'] ?? null) === Provenance::Customer->value && is_string($entry['content'] ?? null) && trim($entry['content']) !== '') {
                $turns[] = ['from' => 'sim-case-'.substr(hash('sha256', $case->key), 0, 12), 'text' => $entry['content'], 'mid' => "case-{$case->key}-{$index}"];
            }
        }
        if ($turns === []) {
            return null;
        }

        return new Scenario(
            key: 'case.'.$case->key,
            purpose: (string) $case->purpose,
            turns: $turns,
            expect: [],
            agentActive: true,
            tags: ['case'],
            agent: ['outcome' => 'any'],
        );
    }

    private static function requester(): Requester
    {
        return Requester::asEnabled(RequesterKind::GateRunner, 'evaluation: nobody asked; the agent is measured as its enabling user enabled it');
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
            // docs/106: the run's first model call, deterministically; the cost is attributed from every
            // call under `correlationId`, never from this one row.
            aiRunId: AiRun::where('correlation_id', $run->correlation_id)->orderBy('id')->value('id'),
            correlationId: (string) $run->correlation_id,
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
            // docs/86: the fourth runtime reading, and whether the provider was asked at all.
            roleBoundary: $run->role_boundary === null ? null : (array) $run->role_boundary,
            modelTurns: (int) $run->turns,
            // docs/90 A.2: what the model wrote, what the runner read, what the gateway validated.
            argumentTrace: $this->argumentTrace($run),
            // docs/90 A.1 step 5: the workflow the runtime held a thread run to.
            supportWorkflow: $run->support_workflow === null ? null : (array) $run->support_workflow,
            // docs/92 A.12: the reply requirement the runtime resolved, every script validation and the repairs.
            languageRequirement: $run->language_requirement === null ? null : (array) $run->language_requirement,
            // docs/91 A.3: the role this run was measured as, for the role-aware refusal reading.
            subjectRole: $this->subjectRole(),
        );
    }

    /**
     * The argument path of every call the agent named (docs/90 A.2): the key the model wrote its
     * payload under and the payload as written, the arguments the runner read, and, where the call
     * reached the gateway, the validated input the gateway recorded and how the call ended. A row
     * with `arguments_key` null is a call the model wrote without arguments; a row whose
     * `gateway_input` lacks what `args` holds is an argument lost between the runner and the tool.
     *
     * @return list<array<string, mixed>>
     */
    private function argumentTrace(AgentRun $run): array
    {
        $out = [];

        foreach ([...(array) $run->evidence, ...(array) $run->proposals] as $entry) {
            if (! is_array($entry) || ! is_string($entry['tool'] ?? null) || $entry['tool'] === '' || ($entry['kind'] ?? '') === 'claim') {
                continue;
            }

            $id = $entry['tool_call_id'] ?? null;
            $call = $id === null ? null : ToolCall::whereKey($id)->first(['input', 'status', 'outcome_class', 'error']);

            $out[] = [
                'tool' => $entry['tool'],
                'kind' => (string) ($entry['kind'] ?? ''),
                'arguments_key' => array_key_exists('arguments_key', $entry) ? $entry['arguments_key'] : 'unrecorded',
                'raw_arguments' => $entry['raw_arguments'] ?? null,
                'args' => (array) ($entry['args'] ?? []),
                'expanded_from' => $entry['expanded_from'] ?? null,
                'tool_call_id' => $id,
                'gateway_input' => $call === null ? null : (is_array($call->input) ? $call->input : (array) $call->input),
                'gateway_status' => $call?->status,
                'outcome_class' => $entry['outcome_class'] ?? $call?->outcome_class,
                'gateway_error' => $call?->error,
            ];
        }

        return $out;
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

            $named = ['tool' => $entry['tool'], 'args' => (array) ($entry['args'] ?? [])];
            // docs/92: a reply the runtime's script policy refused is named, and marked, so the grader can
            // count what a customer could receive without losing what the model proposed.
            if (isset($entry['script_policy'])) {
                $named['script_rejected'] = true;
            }
            $out[] = $named;
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
