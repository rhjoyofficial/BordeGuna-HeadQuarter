<?php

namespace App\Observe\Alerts\Rules;

use App\FollowUps\OverdueFollowUps;
use App\Models\User;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * Overdue follow-ups (docs/120 item 16, docs/123 section 3). One condition per responsible person (the
 * person who approved the follow-up), addressed to that person only: the engine tells them in HQ and on
 * no shared channel. Follow-ups whose person can no longer be told (inactive, without the view permission,
 * or the follow-up now outside their business scope) are one unaddressed condition, shown on the alerts
 * page for whoever reviews alerts.
 *
 * ⛔ The alert informs a person. It never contacts a customer, never sends a message, never moves a
 *    follow-up and never starts or signals a workflow run. It reads; the engine records.
 * ⛔ Counts and a date only: no customer name, phone, intent or business name in the subject or body.
 */
final class FollowUpsOverdue implements AlertRule
{
    public function __construct(private readonly OverdueFollowUps $overdue) {}

    public function key(): string
    {
        return 'followups.overdue';
    }

    public function pagedBy(): ?string
    {
        // Not the shared channel: the engine tells the condition's own recipient, in HQ.
        return 'the responsible person, in HQ';
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $byPerson = $this->overdue->query($now)
            ->selectRaw('decided_by_user_id, count(*) as n')
            ->groupBy('decided_by_user_id')
            ->pluck('n', 'decided_by_user_id')
            ->all();

        $conditions = [];
        $untold = 0;
        $tz = (string) config('hq.timezone');

        foreach ($byPerson as $userId => $count) {
            $user = $userId === '' || $userId === null ? null : User::query()->find((int) $userId);
            $mine = $user !== null && $this->overdue->canBeTold($user) ? $this->overdue->forPerson($user, $now) : null;
            $visible = $mine === null ? 0 : (clone $mine)->count();
            $untold += (int) $count - $visible;
            if ($visible === 0) {
                continue;
            }

            $oldest = CarbonImmutable::parse((clone $mine)->min('due_at'));
            $conditions[] = new AlertCondition(
                subjectKey: 'person:'.$user->getKey(),
                severity: 'warning',
                subject: "{$visible} follow-up(s) you approved are overdue",
                body: 'The oldest was due '.$oldest->timezone($tz)->format('D j M H:i T').'. Nothing has been sent to any customer. '
                    .'Complete or cancel each one in HQ > Follow-ups.',
                context: ['count' => $visible, 'oldest_due_at' => $oldest->toIso8601String(), 'grace_hours' => $this->overdue->graceHours()],
                recipientUserId: (int) $user->getKey(),
            );
        }

        if ($untold > 0) {
            $conditions[] = new AlertCondition(
                subjectKey: 'unassigned',
                severity: 'warning',
                subject: "{$untold} overdue follow-up(s) whose approver can no longer act on them",
                body: 'The person who approved them is inactive, lacks the follow-ups permission, or no longer has their business in scope. '
                    .'Nothing has been sent to any customer. A person with the deciding permission should complete or cancel them in HQ > Follow-ups.',
                context: ['count' => $untold, 'grace_hours' => $this->overdue->graceHours()],
            );
        }

        return $conditions;
    }
}
