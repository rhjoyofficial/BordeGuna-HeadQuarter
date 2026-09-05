<?php

namespace App\Scheduling\Commands;

use App\Models\ScheduledJob;
use App\Scheduling\Enums\JobKind;
use App\Scheduling\Enums\JobState;
use App\Scheduling\Enums\MissedPolicy;
use App\Scheduling\Jobs\RunScheduledJob;
use App\Scheduling\NextRunCalculator;
use App\Scheduling\ScheduledJobService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The scheduler tick (doc 21). Runs every minute from the Laravel scheduler inside the
 * `scheduler` container. A Redis lock makes a duplicate logical tick impossible even if two
 * scheduler processes exist; the unique run slot makes a duplicate logical run impossible
 * even if the lock failed. Execution itself is at-least-once (ADR-011).
 */
final class SchedulerTick extends Command
{
    protected $signature = 'hq:scheduler:tick {--now= : ISO-8601 instant to treat as "now" (tests)}';

    protected $description = 'Find due scheduled jobs, claim one run per logical slot, dispatch to Horizon.';

    public const HEARTBEAT_KEY = 'hq:scheduler:last_tick';

    public function handle(ScheduledJobService $service, NextRunCalculator $calculator): int
    {
        $lock = Cache::lock('hq:scheduler:tick', 55);
        if (! $lock->get()) {
            $this->info('another tick holds the lock; skipping');

            return self::SUCCESS;
        }

        try {
            $now = $this->option('now') ? CarbonImmutable::parse($this->option('now'))->utc() : CarbonImmutable::now('UTC');
            $dispatched = 0;
            $skipped = 0;

            ScheduledJob::query()
                ->where('state', JobState::Active->value)
                ->where('approval_state', 'approved')
                ->whereIn('kind', [JobKind::Once->value, JobKind::Cron->value, JobKind::Interval->value])
                ->whereNotNull('next_run_at')
                ->where('next_run_at', '<=', $now)
                ->orderBy('next_run_at')
                ->limit(200)
                ->each(function (ScheduledJob $job) use ($service, $calculator, $now, &$dispatched, &$skipped) {
                    $slot = CarbonImmutable::parse($job->next_run_at)->utc();
                    $late = $slot->diffInSeconds($now) > 120;

                    // Missed-slot policy applies when the slot is more than two minutes old (downtime, pause, deploy).
                    $shouldRun = ! $late || match ($job->missed_policy) {
                        MissedPolicy::CatchUp => true,
                        MissedPolicy::RunLatestOnly => true, // one run for the latest missed slot; older slots are collapsed below
                        MissedPolicy::Skip => false,
                    };

                    if ($shouldRun) {
                        $trigger = $late ? 'catch_up' : 'tick';
                        $run = $service->claimSlot($job, $slot, $trigger);
                        if ($run) {
                            RunScheduledJob::dispatch($run->id);
                            $dispatched++;
                        } else {
                            $skipped++; // slot already claimed by another tick: exactly the case the unique index exists for
                        }
                    } else {
                        $skipped++;
                        Log::info('scheduled slot skipped by missed policy', ['job_id' => $job->id, 'slot' => $slot->toIso8601String()]);
                    }

                    // Advance the clock. For catch_up we advance one slot at a time so each missed slot gets its
                    // own run on subsequent ticks; for the others we jump past `now`.
                    $job->last_run_at = $shouldRun ? $slot : $job->last_run_at;
                    $next = $job->kind === JobKind::Once
                        ? null
                        : ($job->missed_policy === MissedPolicy::CatchUp && $late
                            ? $calculator->next($job, $slot)
                            : $calculator->next($job, $now));
                    $job->next_run_at = $next;
                    if ($job->kind === JobKind::Once) {
                        $job->state = JobState::Done;
                    }
                    $job->saveQuietly();
                });

            Cache::put(self::HEARTBEAT_KEY, $now->toIso8601String(), 600);
            $this->info("tick at {$now->toIso8601String()}: dispatched {$dispatched}, skipped {$skipped}");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
