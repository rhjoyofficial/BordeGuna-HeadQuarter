<?php

namespace App\FollowUps\Outcomes;

use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\User;

/**
 * The null binding: reads and records nothing. Kept for tests and for a deployment that must run S4
 * without the outcome ledger; the application binds `App\Outcomes\FollowUps\LedgerFollowUpOutcomeLink`.
 */
final class NoFollowUpOutcomeLink implements FollowUpOutcomeLink
{
    public function afterCompleted(FollowUp $followUp, User $by): void {}

    public function creditsFor(int $businessId, array $followUpIds, User $viewer): array
    {
        return [];
    }

    public function outcomesFor(Opportunity $opportunity, User $viewer): array
    {
        return [];
    }

    public function supportsConversion(Opportunity $opportunity, User $viewer): bool
    {
        return false;
    }
}
