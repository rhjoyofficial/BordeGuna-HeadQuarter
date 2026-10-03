<?php

namespace App\Scheduling;

use App\Models\DomainEvent;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Scheduling\Enums\ApprovalState;
use App\Scheduling\Enums\JobKind;
use App\Scheduling\Enums\JobState;
use App\Scheduling\Jobs\RunScheduledJob;
use App\Support\Audit\Auditor;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;

/**
 * Event-triggered scheduled jobs (docs/95 §5 "Platform", §10, §16 NOW item 4).
 *
 * `scheduled_jobs.kind = 'event'` has existed since doc 21, validated and NL-parsable, and nothing
 * dispatched it: the tick reads clock kinds only and `NextRunCalculator` has no slot for an event.
 * This is the missing half. The event drain calls `fire()` once it has delivered an event to its
 * handlers; every active, approved event job whose `event_type` matches, and whose business scope
 * admits the event, gets one run for that event:
 *
 *  - once per (job, event): the event's uuid is the run's logical slot, held by a unique index, so a
 *    replayed event, a redelivered webhook or a second drain creates nothing (`claimEventSlot`);
 *  - business-scoped: a job with no business takes the event whatever its business; a job with one
 *    takes only an event of that business, and an event of no business reaches only group-wide jobs;
 *  - never past approval: a job waiting for a person (`approval_state = pending`) does not fire,
 *    exactly as the tick and "Run now" refuse it (docs/48 L.5);
 *  - the event reaches the tool as `payload.event` WHEN the tool's input schema accepts it (declares
 *    `event` or allows additional properties); a tool that declares neither runs with the job's own
 *    payload, because the gateway would otherwise refuse the extra argument and the job would fail
 *    on every event.
 *
 * Nothing here switches anything on: with no event job in the table, every event is as it was.
 */
final class EventTriggeredJobs
{
    public function __construct(
        private readonly ScheduledJobService $service,
        private readonly ToolRegistry $tools,
        private readonly Auditor $auditor,
    ) {}

    /** @return int how many runs were created and dispatched for this event */
    public function fire(DomainEvent $event): int
    {
        $dispatched = 0;

        ScheduledJob::query()
            ->where('state', JobState::Active->value)
            ->where('approval_state', ApprovalState::Approved->value)
            ->where('kind', JobKind::Event->value)
            ->where('event_type', $event->type)
            ->where(function ($query) use ($event) {
                $query->whereNull('business_id');
                if ($event->business_id !== null) {
                    $query->orWhere('business_id', $event->business_id);
                }
            })
            ->orderBy('id')
            ->each(function (ScheduledJob $job) use ($event, &$dispatched) {
                $run = $this->service->claimEventSlot($job, $event);
                if ($run === null) {
                    return; // this event already has its run for this job
                }

                $job->last_run_at = CarbonImmutable::now('UTC');
                $job->saveQuietly();

                $this->auditor->recordSystem('scheduled_job.event_fired', $job, after: [
                    'job_run_id' => $run->id, 'event_id' => $event->event_id, 'event_type' => $event->type,
                ], correlationId: (string) $run->correlation_id);

                RunScheduledJob::dispatch($run->id);
                $dispatched++;
            });

        return $dispatched;
    }

    /**
     * The tool's arguments for one run: the job's payload, plus `event` for a run an event started
     * and a tool whose schema can take it.
     *
     * @return array<string, mixed>
     */
    public function arguments(ScheduledJob $job, JobRun $run): array
    {
        $args = $job->payload ?? [];
        if ($run->event_id === null) {
            return $args;
        }

        $event = $run->event()->first();
        if ($event === null || ! $this->accepts($job->tool_key)) {
            return $args;
        }

        return $args + ['event' => self::snapshot($event)];
    }

    /** What a tool sees of the event: its identity and payload, never the drain's bookkeeping. */
    public static function snapshot(DomainEvent $event): array
    {
        $payload = $event->payload ?? [];
        unset($payload['_correlation_id']);

        return [
            'id' => $event->event_id,
            'type' => $event->type,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'business_id' => $event->business_id,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'payload' => $payload,
        ];
    }

    private function accepts(string $toolKey): bool
    {
        if (! $this->tools->has($toolKey)) {
            return false;
        }
        $schema = $this->tools->get($toolKey)->inputSchema();

        return array_key_exists('event', $schema['properties'] ?? []) || (bool) ($schema['additionalProperties'] ?? false);
    }
}
