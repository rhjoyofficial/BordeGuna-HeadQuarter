<?php

namespace App\FollowUps\Outcomes;

use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\User;

/**
 * The ONLY seam between S4 and the S3 outcome model (docs/113 section 10). Named now so the call
 * sites are visible; filled only after the docs/107 contract is ruled on. Part 1 binds
 * `NoFollowUpOutcomeLink`, which records nothing and reports nothing, and imports no S3 class.
 *
 * Open (for the S3 review): which action type credits a follow-up; the `rule_window` for "an order
 * after a follow-up" and who owns it; whether a `converted` close must be supported by an outcome row.
 */
interface FollowUpOutcomeLink
{
    /** Would record the follow-up (its run, or the person's completion) as an action a later outcome can credit. */
    public function afterCompleted(FollowUp $followUp, User $by): void;

    /**
     * Would read the outcomes whose result references name this customer's later orders.
     *
     * @return list<array<string, mixed>>
     */
    public function outcomesFor(Opportunity $opportunity): array;
}
