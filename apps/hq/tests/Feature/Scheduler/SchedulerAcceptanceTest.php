<?php

use App\Models\AuditLog;
use App\Models\JobRun;
use App\Models\User;
use App\Scheduling\Commands\SchedulerTick;
use App\Scheduling\Enums\JobState;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\Jobs\RunScheduledJob;
use App\Scheduling\ScheduledJobService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 | Scheduler acceptance (brief section 14). Queue is `sync` in tests, so dispatch executes
 | immediately; where we need to inspect the queued state we fake the queue.
 */

function makeService(): ScheduledJobService
{
    return app(ScheduledJobService::class);
}

function owner(): User
{
    return User::factory()->create();
}

it('1-7: creates a no-op job, tick claims one run, horizon job executes it, result stored, visible in history', function () {
    $service = makeService();
    $runAt = CarbonImmutable::parse('2026-09-06 17:00:00', 'Asia/Dhaka');
    $job = $service->create(['name' => 'noop once', 'kind' => 'once', 'tool_key' => 'noop', 'run_at' => $runAt->utc(), 'payload' => ['message' => 'hello']], owner());

    expect($job->exists)->toBeTrue()
        ->and($job->next_run_at->equalTo($runAt->utc()))->toBeTrue()
        ->and($job->timezone)->toBe('Asia/Dhaka');

    // Before the slot: nothing happens.
    $this->artisan('hq:scheduler:tick', ['--now' => $runAt->utc()->subMinute()->toIso8601String()])->assertSuccessful();
    expect(JobRun::count())->toBe(0);

    // At the slot: exactly one run is created and (sync queue) executed.
    $this->artisan('hq:scheduler:tick', ['--now' => $runAt->utc()->toIso8601String()])->assertSuccessful();
    $run = JobRun::sole();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->attempt)->toBe(1)
        ->and($run->output['output']['message'])->toBe('hello')
        ->and($run->trigger)->toBe('tick');

    $job->refresh();
    expect($job->state)->toBe(JobState::Done)->and($job->last_status)->toBe('succeeded')->and($job->runs()->count())->toBe(1);
    expect(Cache::get(SchedulerTick::HEARTBEAT_KEY))->not->toBeNull();
});

it('8: repeating the tick does not create a second logical run for the same slot', function () {
    Queue::fake();
    $service = makeService();
    $job = $service->create(['name' => 'hourly', 'kind' => 'cron', 'cron_expression' => '0 * * * *', 'tool_key' => 'noop'], owner());
    $slot = CarbonImmutable::parse($job->next_run_at)->utc();

    $this->artisan('hq:scheduler:tick', ['--now' => $slot->toIso8601String()])->assertSuccessful();
    $this->artisan('hq:scheduler:tick', ['--now' => $slot->toIso8601String()])->assertSuccessful();
    $this->artisan('hq:scheduler:tick', ['--now' => $slot->addSeconds(30)->toIso8601String()])->assertSuccessful();

    expect(JobRun::where('scheduled_job_id', $job->id)->count())->toBe(1);
    Queue::assertPushed(RunScheduledJob::class, 1);

    // And the unique index itself refuses a duplicate slot even if the service were bypassed.
    expect(fn () => DB::transaction(fn () => JobRun::create(['scheduled_job_id' => $job->id, 'scheduled_for' => $slot, 'trigger' => 'tick', 'status' => 'queued'])))
        ->toThrow(QueryException::class);
    expect($service->claimSlot($job, $slot, 'tick'))->toBeNull();
});

it('9: run-now creates and executes a run immediately', function () {
    $service = makeService();
    $job = $service->create(['name' => 'manual', 'kind' => 'cron', 'cron_expression' => '0 9 * * *', 'tool_key' => 'noop'], owner());
    $run = $service->runNow($job, owner());
    $run->refresh();
    expect($run->trigger)->toBe('run_now')->and($run->status)->toBe(RunStatus::Succeeded);
});

it('10-12: pause stops ticks, resume recomputes the next slot, cancel clears it', function () {
    Queue::fake();
    $service = makeService();
    $job = $service->create(['name' => 'p', 'kind' => 'interval', 'interval_seconds' => 3600, 'tool_key' => 'noop'], owner());
    $slot = CarbonImmutable::parse($job->next_run_at)->utc();

    $service->pause($job);
    $this->artisan('hq:scheduler:tick', ['--now' => $slot->toIso8601String()])->assertSuccessful();
    expect(JobRun::count())->toBe(0)->and($job->fresh()->state)->toBe(JobState::Paused);

    $service->resume($job->fresh());
    $job->refresh();
    expect($job->state)->toBe(JobState::Active)->and($job->next_run_at->gt($slot))->toBeTrue(); // missed slot not fired

    $service->cancel($job);
    $job->refresh();
    expect($job->state)->toBe(JobState::Cancelled)->and($job->next_run_at)->toBeNull();
    expect(fn () => $service->resume($job))->toThrow(LogicException::class);
});

it('13-14: failure is visible, retries re-attempt the same run row, then dead after retry_max', function () {
    $service = makeService();
    $job = $service->create(['name' => 'flaky', 'kind' => 'cron', 'cron_expression' => '* * * * *', 'tool_key' => 'test.fail', 'retry_max' => 1, 'retry_backoff_seconds' => 60, 'payload' => ['fail_times' => 5]], owner());

    // With a sync queue the delayed retry runs inline too, so attempt 1 fails -> retry -> attempt 2 fails -> dead.
    $run = $service->runNow($job, owner());
    $run->refresh();
    expect($run->status)->toBe(RunStatus::Dead)
        ->and($run->attempt)->toBe(2)
        ->and($run->attempts)->toHaveCount(2)
        ->and($run->error)->toContain('test.fail failed')
        ->and(JobRun::count())->toBe(1);

    // A human retry on the same row: the tool now succeeds (fail_times reached), still one row.
    $job->payload = ['fail_times' => 2];
    $job->save();
    $service->retry($run->fresh(), owner());
    $run->refresh();
    expect($run->status)->toBe(RunStatus::Succeeded)->and($run->attempt)->toBe(3)->and(JobRun::count())->toBe(1)
        ->and($run->output['output']['recovered_on_attempt'])->toBe(3);
});

it('a thrown exception inside a tool is a failed attempt, not a crashed worker', function () {
    $service = makeService();
    $job = $service->create(['name' => 'throws', 'kind' => 'cron', 'cron_expression' => '* * * * *', 'tool_key' => 'test.fail', 'retry_max' => 0, 'payload' => ['mode' => 'throw']], owner());
    $run = $service->runNow($job, owner())->fresh();
    expect($run->status)->toBe(RunStatus::Dead)->and($run->error)->toContain('RuntimeException');
});

it('refuses unknown tools, invalid cron, and sub-minute intervals', function () {
    $service = makeService();
    expect(fn () => $service->create(['name' => 'x', 'kind' => 'cron', 'cron_expression' => '0 9 * * *', 'tool_key' => 'publish_content'], owner()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->create(['name' => 'x', 'kind' => 'cron', 'cron_expression' => 'not a cron', 'tool_key' => 'noop'], owner()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->create(['name' => 'x', 'kind' => 'interval', 'interval_seconds' => 5, 'tool_key' => 'noop'], owner()))->toThrow(InvalidArgumentException::class);
});

it('missed slots follow the job policy: skip by default, catch_up runs late', function () {
    Queue::fake();
    $service = makeService();
    $skip = $service->create(['name' => 'skip', 'kind' => 'cron', 'cron_expression' => '0 9 * * *', 'tool_key' => 'noop', 'missed_policy' => 'skip'], owner());
    $catch = $service->create(['name' => 'catch', 'kind' => 'cron', 'cron_expression' => '0 9 * * *', 'tool_key' => 'noop', 'missed_policy' => 'catch_up'], owner());
    $slot = CarbonImmutable::parse($skip->next_run_at)->utc();

    // Tick arrives an hour late (a deploy or downtime).
    $this->artisan('hq:scheduler:tick', ['--now' => $slot->addHour()->toIso8601String()])->assertSuccessful();

    expect(JobRun::where('scheduled_job_id', $skip->id)->count())->toBe(0)
        ->and(JobRun::where('scheduled_job_id', $catch->id)->count())->toBe(1)
        ->and(JobRun::where('scheduled_job_id', $catch->id)->sole()->trigger)->toBe('catch_up')
        ->and($skip->fresh()->next_run_at->gt($slot))->toBeTrue();
});

it('every job state change is audited', function () {
    $service = makeService();
    $job = $service->create(['name' => 'audited', 'kind' => 'cron', 'cron_expression' => '0 9 * * *', 'tool_key' => 'noop'], owner());
    $service->pause($job);
    $service->resume($job);
    $service->cancel($job);
    $actions = AuditLog::where('target_type', $job->getMorphClass())->where('target_id', (string) $job->id)->pluck('action');
    expect($actions)->toContain('scheduled_job.created', 'scheduled_job.paused', 'scheduled_job.resumed', 'scheduled_job.cancelled');
});
