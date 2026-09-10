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
        /** @var list<array{tool: string, reason: string}> proposed and refused, with the reason */
        public array $refusedToolCalls = [],
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
    ) {}

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
            // Empty until a capability with an effect exists. Reported anyway, because a field that
            // appears only when it is non-empty is a field nobody notices is missing.
            'confirmed_effects' => $this->confirmedEffects,
            'refused' => $this->refused,
            'refusal_reason' => $this->refusalReason,
            'citations' => $this->citations,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
