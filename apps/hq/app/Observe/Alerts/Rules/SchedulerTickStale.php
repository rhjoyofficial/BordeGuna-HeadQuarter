<?php

namespace App\Observe\Alerts\Rules;

use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use App\Scheduling\Commands\SchedulerTick;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The scheduler's own tick has not run within its staleness threshold (docs/48 N.5).
 *
 * ⚠ This check itself runs from the scheduler, so it catches a tick that fails while the scheduler
 * process lives. A dead scheduler process is caught by the host monitor through `/api/health`, which
 * is why the host monitor pages this and HQ only records it.
 */
final class SchedulerTickStale implements AlertRule
{
    public function key(): string
    {
        return 'scheduler.tick_stale';
    }

    public function pagedBy(): ?string
    {
        return 'hq-monitor.sh';
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $threshold = (int) config('hq.scheduler.heartbeat_stale_after_seconds', 180);
        $last = Cache::get(SchedulerTick::HEARTBEAT_KEY);

        if ($last === null) {
            return [new AlertCondition('tick', 'critical', 'The scheduler has not ticked',
                'No scheduler tick is recorded. Scheduled jobs and the event drain are not running. Look: docker compose logs --tail 100 scheduler.',
                ['threshold_seconds' => $threshold])];
        }

        $age = (int) CarbonImmutable::parse($last)->diffInSeconds($now, false);

        if ($age <= $threshold) {
            return [];
        }

        return [new AlertCondition('tick', 'critical', "The scheduler last ticked {$age} seconds ago",
            "The threshold is {$threshold} seconds. Scheduled jobs and the event drain are not running on time. Look: docker compose logs --tail 100 scheduler.",
            ['age_seconds' => $age, 'threshold_seconds' => $threshold, 'last_tick' => $last])];
    }
}
