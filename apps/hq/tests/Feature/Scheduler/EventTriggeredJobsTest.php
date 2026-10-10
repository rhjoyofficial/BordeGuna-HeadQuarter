<?php

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\DomainEvent;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\ToolCall;
use App\Models\User;
use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\EventTriggeredJobs;
use App\Scheduling\ScheduledJobService;
use App\Support\Events\EventRecorder;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Tools\CountingTool;

/*
 | docs/95 §5 "Platform", §10, §16 NOW item 4: event-triggered scheduled jobs fire. The drain delivers an
 | event to its handlers and then to every active, approved event job that names its type and admits its
 | business; one run per (job, event), the event's uuid as the slot, so a replay creates nothing. The tool
 | is the no-op, which declares `event` in its schema and echoes the event's id back.
 */

function evJob(array $overrides = [], ?User $owner = null): ScheduledJob
{
    return app(ScheduledJobService::class)->create($overrides + [
        'name' => 'on low stock', 'kind' => 'event', 'event_type' => 'inventory.low', 'tool_key' => 'noop', 'payload' => ['message' => 'fired'],
    ], $owner);
}

function evEvent(string $type = 'inventory.low', ?int $businessId = null, string $ref = 'LLD-1001'): DomainEvent
{
    return app(EventRecorder::class)->record($type, ['order_ref' => $ref], 'test', 'inbound', 'order', $ref, $businessId);
}

function evDrain(): void
{
    test()->artisan('hq:events:process')->assertSuccessful();
}

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

it('fires once per event: one run that names the event, runs the tool with the event as payload.event, and is audited', function () {
    $job = evJob();
    $event = evEvent();

    evDrain();

    $run = JobRun::sole();
    expect($run->scheduled_job_id)->toBe($job->id)
        ->and($run->trigger)->toBe('event')
        ->and($run->event_id)->toBe($event->event_id)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->output['output']['message'])->toBe('fired')
        ->and($run->output['output']['event_id'])->toBe($event->event_id)
        ->and($job->fresh()->last_run_at)->not->toBeNull()
        ->and($job->fresh()->next_run_at)->toBeNull();

    $audit = AuditLog::where('action', 'scheduled_job.event_fired')->sole();
    expect($audit->after['event_id'])->toBe($event->event_id)->and($audit->after['job_run_id'])->toBe($run->id);

    // The event itself is processed as before: the job is an addition, not a handler.
    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_PROCESSED);
});

it('does not run the job again when the same event is replayed, however it comes back', function () {
    $job = evJob();
    $event = evEvent();
    evDrain();

    // A redelivery that the drain picks up again (status reset, as a replay command would).
    $event->forceFill(['status' => DomainEvent::STATUS_PENDING, 'processed_at' => null])->save();
    evDrain();
    // And a direct second fire, as a concurrent drain or a retry after a handler failure would do.
    expect(app(EventTriggeredJobs::class)->fire($event->fresh()))->toBe(0);

    expect(JobRun::where('scheduled_job_id', $job->id)->count())->toBe(1)
        ->and(app(ScheduledJobService::class)->claimEventSlot($job, $event->fresh()))->toBeNull();
});

it('gives two different events of the same job two runs, even in the same second', function () {
    $job = evJob();
    evEvent(ref: 'LLD-1');
    evEvent(ref: 'LLD-2');

    evDrain();

    expect(JobRun::where('scheduled_job_id', $job->id)->count())->toBe(2)
        ->and(JobRun::where('scheduled_job_id', $job->id)->pluck('event_id')->unique()->count())->toBe(2);
});

it('is business-scoped: a job of one business takes only that business\'s events; a group job takes any; an event of no business reaches only group jobs', function () {
    $group = evJob(['name' => 'group']);
    $laladia = evJob(['name' => 'laladia only', 'business_id' => $this->laladia->id]);
    $bionic = evJob(['name' => 'bionic only', 'business_id' => $this->bionic->id]);

    evEvent(businessId: $this->laladia->id, ref: 'LLD-1');
    evEvent(businessId: null, ref: 'X-1');
    evDrain();

    expect(JobRun::where('scheduled_job_id', $group->id)->count())->toBe(2)
        ->and(JobRun::where('scheduled_job_id', $laladia->id)->count())->toBe(1)
        ->and(JobRun::where('scheduled_job_id', $bionic->id)->count())->toBe(0);
});

it('never fires a job that waits for approval, is paused or cancelled, and ignores events of another type', function () {
    $pending = evJob(['name' => 'pending', 'approval_state' => ApprovalState::Pending]);
    $paused = app(ScheduledJobService::class)->pause(evJob(['name' => 'paused']));
    $cancelled = app(ScheduledJobService::class)->cancel(evJob(['name' => 'cancelled']));
    $other = evJob(['name' => 'other type', 'event_type' => 'coupon.expired']);

    evEvent();
    evEvent('customer.deleted', ref: 'C-1');
    evDrain();

    expect(JobRun::count())->toBe(0);

    // Approval releases it for the NEXT event, never for the ones that passed while it waited.
    app(ScheduledJobService::class)->approve($pending, User::factory()->create());
    evEvent(ref: 'LLD-2');
    evDrain();

    expect(JobRun::where('scheduled_job_id', $pending->id)->count())->toBe(1)
        ->and(JobRun::whereIn('scheduled_job_id', [$paused->id, $cancelled->id, $other->id])->count())->toBe(0);
});

it('hands the event only to a tool whose schema can take it; another tool runs with the job\'s own payload', function () {
    app(ToolRegistry::class)->register(CountingTool::class);
    $noop = evJob();
    $counting = evJob(['name' => 'counting', 'tool_key' => 'test.counting', 'payload' => ['label' => 'x']]);
    $event = evEvent();

    $noopRun = app(ScheduledJobService::class)->claimEventSlot($noop, $event);
    $countingRun = app(ScheduledJobService::class)->claimEventSlot($counting, $event);

    $args = app(EventTriggeredJobs::class);
    expect($args->arguments($noop, $noopRun))->toBe(['message' => 'fired', 'event' => EventTriggeredJobs::snapshot($event)])
        ->and(EventTriggeredJobs::snapshot($event))->toMatchArray(['id' => $event->event_id, 'type' => 'inventory.low', 'aggregate_id' => 'LLD-1001', 'payload' => ['order_ref' => 'LLD-1001']])
        ->and(array_key_exists('_correlation_id', EventTriggeredJobs::snapshot($event)['payload']))->toBeFalse()
        ->and($args->arguments($counting, $countingRun))->toBe(['label' => 'x']);
});

it('passes the event argument through the gateway\'s schema validation (the scheduler\'s only path, docs/116)', function () {
    (new RolesAndPermissionsSeeder)->run();
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    evJob([], $owner->fresh());
    $event = evEvent();
    evDrain();

    $run = JobRun::sole();
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->output['output']['event_id'])->toBe($event->event_id)
        ->and(ToolCall::count())->toBe(1);
});
