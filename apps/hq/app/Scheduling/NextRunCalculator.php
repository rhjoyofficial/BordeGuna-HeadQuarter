<?php

namespace App\Scheduling;

use App\Models\ScheduledJob;
use App\Scheduling\Enums\JobKind;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use InvalidArgumentException;

/**
 * Computes the next slot for a job in the job's own timezone (default Asia/Dhaka) and
 * returns it in UTC for storage. Pure: no database access.
 */
final class NextRunCalculator
{
    public function next(ScheduledJob $job, ?CarbonImmutable $after = null): ?CarbonImmutable
    {
        $after ??= CarbonImmutable::now('UTC');
        $tz = $job->timezone ?: 'Asia/Dhaka';

        return match ($job->kind) {
            JobKind::Once => $job->run_at && $job->run_at->gt($after) && ! $job->last_run_at
                ? CarbonImmutable::parse($job->run_at)->utc()
                : null,
            JobKind::Cron => $this->nextCron((string) $job->cron_expression, $tz, $after),
            JobKind::Interval => $this->nextInterval((int) $job->interval_seconds, $job, $after),
            JobKind::Event => null, // event-triggered jobs have no clock slot
        };
    }

    public function validateCron(string $expression): void
    {
        if (! CronExpression::isValidExpression($expression)) {
            throw new InvalidArgumentException("Invalid cron expression [{$expression}].");
        }
    }

    private function nextCron(string $expression, string $tz, CarbonImmutable $after): CarbonImmutable
    {
        $this->validateCron($expression);
        $cron = new CronExpression($expression);
        $local = $after->setTimezone($tz);

        return CarbonImmutable::instance($cron->getNextRunDate($local->toDateTime(), 0, false, $tz))->utc();
    }

    private function nextInterval(int $seconds, ScheduledJob $job, CarbonImmutable $after): CarbonImmutable
    {
        if ($seconds < 60) {
            throw new InvalidArgumentException('Interval jobs run at most once a minute.');
        }
        $base = $job->last_run_at ? CarbonImmutable::parse($job->last_run_at)->utc() : ($job->next_run_at ? CarbonImmutable::parse($job->next_run_at)->utc() : $after);
        $next = $base->addSeconds($seconds);
        while ($next->lte($after)) {
            $next = $next->addSeconds($seconds);
        }

        return $next;
    }
}
