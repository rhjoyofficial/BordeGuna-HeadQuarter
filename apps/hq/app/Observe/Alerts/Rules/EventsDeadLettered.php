<?php

namespace App\Observe\Alerts\Rules;

use App\Models\DomainEvent;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * Events that exhausted their retries recently (docs/48 N.5).
 *
 * Recent, because an old dead letter that was investigated and deliberately left dead (production's
 * six from 7 September, replayed before the resolution fix) is history, not news. A dead event dies
 * within about two hours of arriving, so its arrival time is a fair measure of recency.
 */
final class EventsDeadLettered implements AlertRule
{
    public function key(): string
    {
        return 'events.dead_letter';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $days = max(1, (int) config('hq.alerts.thresholds.dead_letters_window_days', 7));
        $query = DomainEvent::query()->where('status', DomainEvent::STATUS_DEAD)->where('created_at', '>=', $now->subDays($days));
        $count = (clone $query)->count();

        if ($count === 0) {
            return [];
        }

        $types = (clone $query)->groupBy('type')->selectRaw('type, count(*) as n')->pluck('n', 'type')->map(fn ($n) => (int) $n)->all();

        return [new AlertCondition('recent', 'warning', "{$count} event(s) could not be processed in the last {$days} days",
            'By type: '.collect($types)->map(fn ($n, $type) => "{$type} {$n}")->implode(', ')
            .'. They stay dead until someone reads why (last_error) and replays them with php artisan hq:events:replay.',
            ['count' => $count, 'by_type' => $types, 'window_days' => $days])];
    }
}
