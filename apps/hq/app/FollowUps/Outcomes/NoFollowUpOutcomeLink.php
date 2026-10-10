<?php

namespace App\FollowUps\Outcomes;

use App\Models\FollowUp;
use App\Models\Opportunity;
use App\Models\User;

/** Part 1's binding: no outcome is read or written by S4 until the S3 contract is agreed (docs/113 section 10). */
final class NoFollowUpOutcomeLink implements FollowUpOutcomeLink
{
    public function afterCompleted(FollowUp $followUp, User $by): void {}

    public function outcomesFor(Opportunity $opportunity): array
    {
        return [];
    }
}
