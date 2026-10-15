<?php

namespace App\FollowUps\Outcomes;

use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\User;

/**
 * The ONLY seam between S4 and the S3 outcome model (docs/113 section 10, docs/123). S4 imports no S3
 * class; the binding (`App\Outcomes\FollowUps\LedgerFollowUpOutcomeLink`) lives on the S3 side.
 *
 * Settled by the S3 ruling (docs/110 §3, docs/115, docs/120 items 1-7):
 *  - a follow-up is credited as its own action type, `follow_up` (docs/107 D7, one typed column);
 *  - there is no `rule_window` in v1 (Q3), so "an order after a follow-up" is never inferred: a credit is
 *    a person's `operator_asserted` claim made through the human-only `outcomes.attribute` tool;
 *  - an opportunity closed `converted` stays the person's word (F4); the ledger only says whether a
 *    current order outcome credits one of its follow-ups.
 *
 * Every read is business-scoped twice: the viewer's `BusinessScope` and the row's own business.
 */
interface FollowUpOutcomeLink
{
    /**
     * Called when a person completes a follow-up. In v1 it records nothing: no admitted method can link
     * a person's contact to a later result without a person saying so (docs/123 section 1).
     */
    public function afterCompleted(FollowUp $followUp, User $by): void;

    /**
     * The current credited outcomes of these follow-ups of ONE business, keyed by follow-up id.
     *
     * @param  list<int>  $followUpIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function creditsFor(int $businessId, array $followUpIds, User $viewer): array;

    /**
     * The current outcomes credited to any follow-up serving this opportunity.
     *
     * @return list<array<string, mixed>>
     */
    public function outcomesFor(Opportunity $opportunity, User $viewer): array;

    /** Whether a current order outcome (placed, confirmed or delivered) credits one of the opportunity's follow-ups. */
    public function supportsConversion(Opportunity $opportunity, User $viewer): bool;
}
