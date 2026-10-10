<?php

namespace App\Outcomes;

/**
 * How an attribution claim was made (docs/107 D8). A closed vocabulary: a method nobody reviewed is
 * how incompatible attribution systems start.
 *
 * ⚠ Only the first three may ever count as evidence for an autonomy or money decision (D13). The
 *    last two are in the vocabulary so the contract is complete; v1 ships no rule and no model that
 *    uses them.
 */
enum AttributionMethod: string
{
    /** Proven by key arithmetic: the result carries a key only this action could have produced. */
    case DeterministicKey = 'deterministic_key';

    /** Joined by a shared HQ correlation id or a stored foreign key on the action. */
    case Correlation = 'correlation';

    /** A person said so, with a reason. */
    case OperatorAsserted = 'operator_asserted';

    /** A declared rule, for example a time window before the outcome. */
    case RuleWindow = 'rule_window';

    /** A model's judgement. Never certain, never the only basis for a consequential decision. */
    case ModelInferred = 'model_inferred';

    /** Null when the confidence is allowed for this method, else the reason it is not. */
    public function confidenceViolation(float $confidence): ?string
    {
        if ($confidence < 0.0 || $confidence > 1.0) {
            return 'confidence must be between 0 and 1';
        }

        return match ($this) {
            self::DeterministicKey, self::Correlation => $confidence === 1.0 ? null : "{$this->value} is a proof: its confidence must be exactly 1",
            self::RuleWindow, self::ModelInferred => $confidence < 1.0 ? null : "{$this->value} is never certain: its confidence must be below 1",
            self::OperatorAsserted => null,
        };
    }

    /** Whether a claim made this way may ever count as evidence for an autonomy or money decision. */
    public function isEvidenceGrade(): bool
    {
        return in_array($this, [self::DeterministicKey, self::Correlation, self::OperatorAsserted], true);
    }

    /**
     * Whether a claim made this way is independent deterministic proof: proven by key arithmetic or a
     * stored join, with no person's judgement in it.
     *
     * ⚠ Ruling of 10 Oct 2026 (docs/110 §3, constraint C7): an operator assertion is evidence-grade
     *    (a named human, a reason, an evidence reference) but is NEVER independent deterministic
     *    proof. The two questions are kept apart here so a future consumer of these rows cannot
     *    collapse them: a decision that needs proof may not rest on an `operator_asserted` row alone.
     */
    public function isDeterministicProof(): bool
    {
        return $this === self::DeterministicKey || $this === self::Correlation;
    }
}
