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

    public function rank(): int
    {
        return (int) substr($this->value, 1);
    }

    public function allowsExecution(): bool
    {
        return $this->rank() >= 2;
    }
}
