<?php

namespace App\Support\Navigation;

use App\Conversation\ConversationState;
use App\FollowUps\OverdueFollowUps;
use App\Models\AgentRun;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Conversation;
use App\Models\JobRun;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Carbon\CarbonImmutable;

/**
 * The small counts the navigation shows next to Approvals, Agents and Scheduler.
 *
 * Each count is computed inside the same visibility the screen itself uses, and only for a user who
 * holds the permission to open that screen. A count is a disclosure: a restricted operator must not
 * learn from the sidebar that three approvals exist on a business they may not see.
 */
final class NavBadges
{
    public function __construct(private readonly BusinessScope $businessScope) {}

    /** @return array<string, int> */
    public function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $badges = [];

        if ($user->can('hq.approvals.view')) {
            $badges['approvals'] = $this->businessScope->restrict(Approval::pending(), $user)->count();
        }

        if ($user->can('hq.agents.view')) {
            $badges['agents'] = $this->businessScope
                ->restrict(AgentRun::query(), $user)
                ->where('shadow', true)
                ->whereNull('reviewed_at')
                ->whereIn('status', ['answered', 'refused', 'clarified', 'stopped'])
                ->count();
        }

        if ($user->can('hq.inbox.view')) {
            $badges['inbox'] = $this->businessScope
                ->restrict(Conversation::query(), $user)
                ->where('state', ConversationState::HumanRequired->value)
                ->count();
        }

        if ($user->can('hq.audit.view')) {
            // Only what HQ itself pages; a condition the host monitor pages is on the page, not on the badge.
            $badges['alerts'] = Alert::open()->whereNull('paged_by')->count();
        }

        // docs/123 (docs/120 item 16): the overdue follow-ups THIS person approved and may still see. Each
        // person sees only their own count; it is how the overdue alert reaches its responsible person.
        if ($user->can('hq.followups.view')) {
            $badges['followups'] = app(OverdueFollowUps::class)->forPerson($user, CarbonImmutable::now())->count();
        }

        if ($user->can('hq.jobs.view')) {
            $badges['scheduler'] = JobRun::query()
                ->whereIn('status', ['failed', 'dead'])
                ->where('created_at', '>=', now()->subDay())
                ->count();
        }

        return $badges;
    }
}
