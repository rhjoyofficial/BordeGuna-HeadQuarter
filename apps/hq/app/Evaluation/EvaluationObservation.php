<?php

namespace App\Evaluation;

/**
 * What actually happened when a case was run (ADR-023).
 *
 * Deliberately in the control plane's own vocabulary - tool calls, a refusal, citations - rather
 * than raw model output. A grader that had to parse prose to decide whether a tool was called would
 * be measuring its own parser as much as the agent.
 */
final readonly class EvaluationObservation
{
    /**
     * @param  list<array{tool: string, args?: array}>  $toolCalls  what was actually invoked, in order
     * @param  list<string>  $citations  sources the answer claimed to rest on
     * @param  list<array<string, mixed>>  $contextProvenance  labels only, never the text
     */
    public function __construct(
        /**
         * Every tool the agent NAMED, executed or not (ADR-028). This is what `must_not_call`
         * grades against, because the corpus measures the model rather than the guard that caught it.
         */
        public array $toolCalls = [],
        public bool $refused = false,
        public ?string $refusalReason = null,
        public ?string $answer = null,
        public array $citations = [],
        public ?int $latencyMs = null,
        public ?int $aiRunId = null,
        public array $contextProvenance = [],
        /**
         * ── What the control plane did with what was proposed (ADR-030) ──────────────────────────
         *
         * ⭐ **A proposal and a permitted call must stay distinguishable.** Collapsed into one list,
         * "the model asked for something dangerous" and "something dangerous happened" read the
         * same - and they are the two facts a safety corpus most needs to tell apart. Keeping them
         * separate is what lets `tool_selection` be graded against what the model chose while
         * `enforcement` is graded against what the gateway then allowed.
         *
         * @var list<array{tool: string, tool_call_id?: int|null}>
         */
        public array $executedToolCalls = [],
        /** @var list<array{tool: string, reason: string, outcome_class?: string}> proposed and REFUSED BY HQ, with the reason */
        public array $refusedToolCalls = [],
        /**
         * Permitted by HQ and failed OUTSIDE it (ADR-043): a rejected token, an unreachable shop, a
         * provider that answered 500. Readiness evidence, never a refusal, and never held against
         * the model's choice of tool.
         *
         * @var list<array{tool: string, reason: string, outcome_class: string}>
         */
        public array $failedToolCalls = [],
        /**
         * What the EXTERNAL system ultimately confirmed (ADR-031).
         *
         * ⛔ **Always empty today, and that is the honest value.** It is the fourth of the four
         * facts an autonomous system must keep apart - proposed, permitted, executed, confirmed -
         * and the only one HQ cannot yet fill, because no agent-executable tool has an effect to
         * confirm. The field exists so the first capability that does meets a shape rather than an
         * absence; an empty list here means "nothing was verified", never "nothing needed to be".
         *
         * @var list<array{tool: string, postcondition: string, confirmed: bool}>
         */
        public array $confirmedEffects = [],
        /**
         * How the run ENDED: `answered`, `refused`, `stopped` or `failed`; null when the subject is
         * not a real run (a recorded transcript, a test double) and cannot say.
         *
         * ⭐ Closes the hole docs/62 §C recorded and deliberately left open: a run that stopped at
         * its loop bound has `refused = false`, so a must-answer case used to PASS on a run that
         * produced nothing. "Did not refuse" and "answered" are two different facts, and a gate
         * that cannot tell them apart passes silence.
         */
        public ?string $outcome = null,
        public ?string $stopReason = null,
        /**
         * The run ended by asking what the task is (ADR-040): a legitimate caller, no concrete
         * task, no tool run. A third verdict beside answer and refusal, because "Proceed." with
         * nothing to proceed on is neither served nor refused; it is asked about.
         */
        public bool $clarified = false,
        /** What the agent said it was serving, in the operator's words, before it asked for anything. */
        public ?string $task = null,
        /** `none`, `refused` or `in_untrusted_content`: where a request to widen the agent's authority came from. */
        public ?string $authorityRequest = null,
        /**
         * The task the RUNTIME established from the operator's own words (ADR-041), or '' when it
         * established none; null when the subject does not ground tasks at all.
         */
        public ?string $canonicalTask = null,
        /** The one-word reason behind `canonicalTask`. */
        public ?string $taskGrounding = null,
        /**
         * The model provider refused the run (a quota or a limit, not the agent). Such an
         * observation measured nothing and is excluded from every count (ADR-041).
         */
        public bool $unmeasured = false,
        public ?array $securityIntent = null,
        public ?string $authoritySource = null,
        /** The runtime's reading of the trusted text for a prohibited capability (ADR-044); null when not recorded. */
        public ?array $prohibitedCapability = null,
        /**
         * Gate P3-G2 (docs/77 C.4): whether the conversation core handed the simulated thread to the
         * agent. False means no model ran, by the core's decision; null when the case is not a scenario.
         */
        public ?bool $agentWoken = null,
        /**
         * The runtime's reading of the trusted text for an outbound action the agent's role forbids
         * (docs/86): the run's `role_boundary` column; null when the subject records none (a stub).
         */
        public ?array $roleBoundary = null,
        /**
         * How many model turns the run took (docs/86). Zero means the runtime reached its verdict from
         * the operator's words alone and the provider was never asked; null when not recorded.
         */
        public ?int $modelTurns = null,
        /**
         * docs/90 A.2: every call the agent named, with what the model wrote (`arguments_key`,
         * `raw_arguments`), what the runner read (`args`), and what the gateway validated and ran
         * (`gateway_input`, `gateway_status`); null when the subject does not record it (a stub).
         *
         * @var list<array<string, mixed>>|null
         */
        public ?array $argumentTrace = null,
    ) {}

    /** The reply texts the agent proposed through the reply tool, in order (docs/77 C.4). @return list<string> */
    public function proposedReplies(string $tool = 'messaging.send_reply'): array
    {
        $out = [];
        foreach ($this->toolCalls as $call) {
            if (($call['tool'] ?? null) === $tool && is_string($call['args']['text'] ?? null)) {
                $out[] = $call['args']['text'];
            }
        }

        return $out;
    }

    /** The runtime found an outbound action the agent's role forbids, or its detector failed closed (docs/86). */
    public function roleFlagged(): bool
    {
        return $this->roleBoundary !== null
            && ((array) ($this->roleBoundary['forbidden'] ?? []) !== [] || ($this->roleBoundary['error'] ?? null) !== null);
    }

    /** The runtime found a prohibited capability in the operator's words, or its detector failed closed (ADR-044). */
    public function capabilityFlagged(): bool
    {
        return $this->prohibitedCapability !== null
            && ((array) ($this->prohibitedCapability['kinds'] ?? []) !== [] || ($this->prohibitedCapability['error'] ?? null) !== null);
    }

    public const OUTCOME_ANSWERED = 'answered';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOME_CLARIFIED = 'clarified';

    public const OUTCOME_STOPPED = 'stopped';

    public const OUTCOME_FAILED = 'failed';

    /** The run ended without a verdict: neither an answer, nor a refusal, nor a clarification. */
    public function endedWithoutVerdict(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_STOPPED, self::OUTCOME_FAILED], true);
    }

    /**
     * The distinct business references the agent NAMED in calls to one tool (ADR-040): the measure
     * of whether a group-wide question became one scoped call per business rather than none.
     *
     * @return list<string>
     */
    public function businessesNamed(string $tool): array
    {
        $out = [];
        foreach ($this->toolCalls as $call) {
            if (($call['tool'] ?? null) !== $tool) {
                continue;
            }
            $args = (array) ($call['args'] ?? []);
            foreach (['business_slug', 'store_slug'] as $key) {
                if (is_string($args[$key] ?? null) && $args[$key] !== '') {
                    $out[] = $args[$key];
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function executedKeys(): array
    {
        return array_values(array_map(static fn (array $c) => (string) ($c['tool'] ?? ''), $this->executedToolCalls));
    }

    public function executed(string $tool): bool
    {
        return in_array($tool, $this->executedKeys(), true);
    }

    /** Proposed, and stopped by the control plane. The distinction ADR-030 exists to preserve. */
    public function wasRefused(string $tool): bool
    {
        foreach ($this->refusedToolCalls as $entry) {
            if (($entry['tool'] ?? null) === $tool) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function toolKeys(): array
    {
        return array_values(array_map(static fn (array $c) => (string) ($c['tool'] ?? ''), $this->toolCalls));
    }

    public function called(string $tool): bool
    {
        return in_array($tool, $this->toolKeys(), true);
    }

    public function toArray(): array
    {
        return [
            'tool_calls' => $this->toolKeys(),
            // Recorded separately so a report can say "it asked, and it was stopped" rather than
            // leaving a reader to guess which of the two happened.
            'executed' => $this->executedKeys(),
            'refused_calls' => $this->refusedToolCalls,
            // Permitted and failed outside HQ (ADR-043): reported beside the refusals, never inside them.
            'failed_calls' => $this->failedToolCalls,
            // Empty until a capability with an effect exists. Reported anyway, because a field that
            // appears only when it is non-empty is a field nobody notices is missing.
            'confirmed_effects' => $this->confirmedEffects,
            'refused' => $this->refused,
            'refusal_reason' => $this->refusalReason,
            'clarified' => $this->clarified,
            'task' => $this->task,
            'authority_request' => $this->authorityRequest,
            'canonical_task' => $this->canonicalTask,
            'task_grounding' => $this->taskGrounding,
            'security_intent' => $this->securityIntent,
            'authority_source' => $this->authoritySource,
            'prohibited_capability' => $this->prohibitedCapability,
            'role_boundary' => $this->roleBoundary,
            'model_turns' => $this->modelTurns,
            'argument_trace' => $this->argumentTrace,
            'unmeasured' => $this->unmeasured,
            'agent_woken' => $this->agentWoken,
            'outcome' => $this->outcome,
            'stop_reason' => $this->stopReason,
            'citations' => $this->citations,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
