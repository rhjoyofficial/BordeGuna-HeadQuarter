<?php

namespace App\Scheduling;

use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\User;
use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\Enums\JobKind;
use App\Scheduling\Enums\JobState;
use App\Scheduling\Enums\MissedPolicy;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\Jobs\RunScheduledJob;
use App\Support\Audit\Auditor;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * All state changes to scheduled jobs go through here so every one is validated and audited.
 * Slot creation is guarded by the unique (scheduled_job_id, scheduled_for) index: two ticks,
 * two processes or a tick plus a run-now cannot create two logical runs for the same slot.
 */
final class ScheduledJobService
{
    public function __construct(
        private readonly NextRunCalculator $calculator,
        private readonly ToolRegistry $tools,
        private readonly Auditor $auditor,
    ) {}

    public function create(array $attributes, ?User $owner, string $createdFrom = 'ui'): ScheduledJob
    {
        $kind = JobKind::from($attributes['kind']);
        $toolKey = $attributes['tool_key'];
        if (! $this->tools->has($toolKey)) {
            throw new InvalidArgumentException("Tool [{$toolKey}] is not registered.");
        }
        if ($kind === JobKind::Cron) {
            $this->calculator->validateCron((string) ($attributes['cron_expression'] ?? ''));
        }
        if ($kind === JobKind::Once && empty($attributes['run_at'])) {
            throw new InvalidArgumentException('A one-time job needs run_at.');
        }
        if ($kind === JobKind::Interval && (int) ($attributes['interval_seconds'] ?? 0) < 60) {
            throw new InvalidArgumentException('Interval jobs need interval_seconds >= 60.');
        }
        if ($kind === JobKind::Event && empty($attributes['event_type'])) {
            throw new InvalidArgumentException('An event job needs event_type.');
        }

        $job = new ScheduledJob(array_merge([
            'timezone' => 'Asia/Dhaka',
            'approval_state' => ApprovalState::Approved,
            'state' => JobState::Active,
            'missed_policy' => MissedPolicy::Skip,
            'retry_max' => 3,
            'retry_backoff_seconds' => 60,
            'payload' => [],
        ], $attributes, [
            'owner_user_id' => $owner?->id,
            'created_from' => $createdFrom,
        ]));
        $job->next_run_at = $this->calculator->next($job);
        $job->save();

        $this->auditor->record('scheduled_job.created', $job, after: $job->toArray(), reason: $attributes['reason'] ?? null);

        return $job;
    }

    public function pause(ScheduledJob $job, ?string $reason = null): ScheduledJob
    {
        return $this->transition($job, JobState::Paused, 'scheduled_job.paused', $reason, from: [JobState::Active]);
    }

    public function resume(ScheduledJob $job, ?string $reason = null): ScheduledJob
    {
        $before = $job->toArray();
        if ($job->state !== JobState::Paused) {
            throw new LogicException('Only a paused job can be resumed.');
        }
        $job->state = JobState::Active;
        $job->next_run_at = $this->calculator->next($job); // never fire the slots missed while paused
        $job->save();
        $this->auditor->record('scheduled_job.resumed', $job, before: $before, after: $job->toArray(), reason: $reason);

        return $job;
    }

    public function cancel(ScheduledJob $job, ?string $reason = null): ScheduledJob
    {
        return $this->transition($job, JobState::Cancelled, 'scheduled_job.cancelled', $reason, from: [JobState::Active, JobState::Paused], clearNext: true);
    }

    public function update(ScheduledJob $job, array $attributes, ?string $reason = null): ScheduledJob
    {
        $before = $job->toArray();
        if (isset($attributes['tool_key']) && ! $this->tools->has($attributes['tool_key'])) {
            throw new InvalidArgumentException("Tool [{$attributes['tool_key']}] is not registered.");
        }
        if (isset($attributes['cron_expression'])) {
            $this->calculator->validateCron($attributes['cron_expression']);
        }
        $job->fill($attributes);
        if ($job->state === JobState::Active) {
            $job->next_run_at = $this->calculator->next($job);
        }
        $job->save();
        $this->auditor->record('scheduled_job.updated', $job, before: $before, after: $job->toArray(), reason: $reason);

        return $job;
    }

    /** Creates a run for `now` and dispatches it immediately. Allowed for paused jobs too (an operator's explicit act). */
    public function runNow(ScheduledJob $job, ?User $actor = null): JobRun
    {
        if ($job->state === JobState::Cancelled) {
            throw new LogicException('A cancelled job cannot be run.');
        }
        $run = $this->claimSlot($job, CarbonImmutable::now('UTC')->startOfSecond(), 'run_now');
        if ($run === null) {
            throw new LogicException('A run for this second already exists; try again in a moment.');
        }
        $this->auditor->record('scheduled_job.run_now', $job, after: ['job_run_id' => $run->id], actorUserId: $actor?->id);
        RunScheduledJob::dispatch($run->id);

        return $run;
    }

    /** Re-attempts a failed or dead run on the SAME row. */
    public function retry(JobRun $run, ?User $actor = null): JobRun
    {
        if (! in_array($run->status, [RunStatus::Failed, RunStatus::Dead], true)) {
            throw new LogicException('Only a failed or dead run can be retried.');
        }
        $run->status = RunStatus::Queued;
        $run->trigger = 'retry';
        $run->save();
        $this->auditor->record('job_run.retried', $run->job, after: ['job_run_id' => $run->id, 'attempt' => $run->attempt], actorUserId: $actor?->id);
        RunScheduledJob::dispatch($run->id);

        return $run;
    }

    /**
     * Insert the run row for one logical slot. Returns null if the slot already exists.
     * Relies on the database unique index, not on a read-then-write check.
     */
    public function claimSlot(ScheduledJob $job, CarbonImmutable $scheduledFor, string $trigger): ?JobRun
    {
        try {
            return DB::transaction(fn () => JobRun::create([
                'scheduled_job_id' => $job->id,
                'scheduled_for' => $scheduledFor,
                'trigger' => $trigger,
                'attempt' => 0,
                'status' => RunStatus::Queued,
                'correlation_id' => (string) Str::uuid(),
            ]));
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    private function transition(ScheduledJob $job, JobState $to, string $action, ?string $reason, array $from, bool $clearNext = false): ScheduledJob
    {
        if (! in_array($job->state, $from, true)) {
            throw new LogicException("Cannot move a {$job->state->value} job to {$to->value}.");
        }
        $before = $job->toArray();
        $job->state = $to;
        if ($clearNext) {
            $job->next_run_at = null;
        }
        $job->save();
        $this->auditor->record($action, $job, before: $before, after: $job->toArray(), reason: $reason);

        return $job;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        return $code === '23505' || $code === '23000' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
