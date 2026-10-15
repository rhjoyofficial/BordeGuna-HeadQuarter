<?php

namespace App\FollowUps;

use App\Models\FollowUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What "overdue" means (docs/123 section 3, docs/120 item 16): a follow-up still `scheduled` more than
 * `hq.followups.overdue_after_hours` after its due time. Its responsible person is the one who approved
 * it (`decided_by_user_id`), who is also its run's requester (docs/113 section 7).
 *
 * The 180-day wait of `followup.await_person` is the run's timer, not this rule: a follow-up whose wait
 * has ended is still overdue here, and stays so until a person completes or cancels it.
 *
 * Reads only. One definition, used by the alert rule and the sidebar badge, so they never disagree.
 */
final class OverdueFollowUps
{
    public function graceHours(): int
    {
        return max(1, (int) config('hq.followups.overdue_after_hours', 24));
    }

    public function cutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subHours($this->graceHours());
    }

    /** Every overdue follow-up, all businesses: for the alert rule, which then binds each to its person. */
    public function query(CarbonImmutable $now): Builder
    {
        return FollowUp::query()
            ->where('state', FollowUpState::Scheduled->value)
            ->where('due_at', '<=', $this->cutoff($now));
    }

    /** The overdue follow-ups this person is responsible for AND may still see (their business scope). */
    public function forPerson(User $user, CarbonImmutable $now): Builder
    {
        return $this->query($now)->visibleTo($user)->where('decided_by_user_id', $user->getKey());
    }

    /** Whether this person can still be told and act: active, holds the view permission. */
    public function canBeTold(User $user): bool
    {
        return (bool) $user->is_active && $user->can(FollowUpProposalService::PERMISSION_VIEW);
    }
}
