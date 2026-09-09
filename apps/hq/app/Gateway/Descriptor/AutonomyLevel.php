<?php

namespace App\Gateway\Descriptor;

/**
 * How much an agent may do without a human in the loop (docs/48 Q.1).
 *
 * Joy's V.2 and V.3 decisions (8 Sep 2026): both Phase 2 agents launch at A0 shadow for seven days,
 * then move to A1 propose. A3 is not used in Phase 2 and A4 is not in this roadmap at all.
 *
 * Widening is earned against a measured decision-replay agreement rate, never granted on a
 * schedule, which is why decision_records had to exist from Phase 0.
 */
enum AutonomyLevel: string
{
    /** Runs on real inputs, writes what it WOULD do, executes nothing. */
    case A0 = 'a0';

    /** Answers and proposes; a human executes. */
    case A1 = 'a1';

    /** Executes, with each action individually approved. */
    case A2 = 'a2';

    /** Executes inside configured guardrails; a breach downgrades to A2. Not used in Phase 2. */
    case A3 = 'a3';

    /** Execution with after-the-fact review only. Not in this roadmap. */
    case A4 = 'a4';

    /**
     * Strategic orchestration: an agent directing other agents against a goal.
     *
     * ⛔ **Defined and permanently unreachable** (ADR-020, Phase 2.5 C). Defining the whole ladder
     * now stops the enum being edited later, when editing an enum means editing every grant, every
     * evaluation run and every stored decision that named a level. Leaving it unreachable stops it
     * being used before anybody has decided what it would even mean. `AutonomyResolver` caps at A4
     * and says so in the trace; there is no configuration that lifts the cap.
     */
    case A5 = 'a5';

    public function rank(): int
    {
        return (int) substr($this->value, 1);
    }

    public function allowsExecution(): bool
    {
        return $this->rank() >= 2;
    }

    /** The lower of two levels. Autonomy combines by MINIMUM; this is that combinator. */
    public function min(self $other): self
    {
        return $this->rank() <= $other->rank() ? $this : $other;
    }

    /** The highest level any resolution may return. A5 exists; it is never reachable. */
    public static function ceiling(): self
    {
        return self::A4;
    }

    public function isReachable(): bool
    {
        return $this->rank() <= self::ceiling()->rank();
    }
}
