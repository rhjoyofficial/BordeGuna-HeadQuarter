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
        public array $toolCalls = [],
        public bool $refused = false,
        public ?string $refusalReason = null,
        public ?string $answer = null,
        public array $citations = [],
        public ?int $latencyMs = null,
        public ?int $aiRunId = null,
        public array $contextProvenance = [],
    ) {}

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
            'refused' => $this->refused,
            'refusal_reason' => $this->refusalReason,
            'citations' => $this->citations,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
