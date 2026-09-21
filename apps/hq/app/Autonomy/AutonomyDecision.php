<?php

namespace App\Autonomy;

use App\Gateway\Descriptor\AutonomyLevel;

/**
 * The resolved autonomy level, and every constraint that produced it (ADR-020).
 *
 * ⭐ The trace is not diagnostics. Autonomy combines by minimum, so the interesting question is
 * never "what level did we get" but "what BOUND it", and an answer that cannot name the binding
 * constraint cannot be argued with, audited, or widened deliberately.
 */
final readonly class AutonomyDecision
{
    /**
     * @param  list<array{source: string, level: string, detail: string}>  $constraints
     * @param  list<int>  $grantIds
     */
    public function __construct(
        public AutonomyLevel $level,
        public string $boundBy,
        public array $constraints = [],
        public array $grantIds = [],
        /**
         * The autonomy evidence policy this decision was judged under, and the evidence it was judged
         * on (ADR-048): policy id, version and digest, the evidence digest and run ids, the ceiling
         * and its reasons. Null when no policy was active, which keeps every older record unchanged.
         *
         * @var array<string, mixed>|null
         */
        public ?array $evidence = null,
    ) {}

    /** The same decision, carrying the policy and evidence it was judged under. */
    public function withEvidence(?array $evidence): self
    {
        return new self($this->level, $this->boundBy, $this->constraints, $this->grantIds, $evidence);
    }

    public static function floor(string $reason): self
    {
        return new self(AutonomyLevel::A0, $reason, [[
            'source' => 'default', 'level' => AutonomyLevel::A0->value, 'detail' => $reason,
        ]]);
    }

    public function allowsExecution(): bool
    {
        return $this->level->allowsExecution();
    }

    /** Safe for tool_calls, audit rows and evaluation runs: levels and reasons, never content. */
    public function toArray(): array
    {
        $out = [
            'level' => $this->level->value,
            'bound_by' => $this->boundBy,
            'constraints' => $this->constraints,
            'grant_ids' => $this->grantIds,
        ];

        // Additive: the key exists only when a policy was applied, so a record made with no active
        // policy is byte for byte what it was before ADR-048.
        if ($this->evidence !== null) {
            $out['evidence_policy'] = $this->evidence;
        }

        return $out;
    }
}
