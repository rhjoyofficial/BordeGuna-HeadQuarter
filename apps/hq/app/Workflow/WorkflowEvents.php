<?php

namespace App\Workflow;

use App\Models\DomainEvent;
use App\Models\WorkflowInstance;
use App\Scheduling\EventTriggeredJobs;

/**
 * The events outbox's part (docs/102 §4): the drain hands each processed event here, and every open
 * run whose correlation key is the event's "{aggregate_type}:{aggregate_id}", and whose definition
 * listens to that type, receives it as the signal "event:{type}". The event's uuid is the dedupe key,
 * so a replayed event or a second drain delivers nothing new. With no open run, this is one query.
 */
final class WorkflowEvents
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly WorkflowDefinitions $definitions,
    ) {}

    /** @return int how many runs received the event */
    public function deliver(DomainEvent $event): int
    {
        if ($event->aggregate_type === null || $event->aggregate_id === null) {
            return 0;
        }

        $delivered = 0;
        WorkflowInstance::query()
            ->whereIn('status', WorkflowInstance::OPEN)
            ->where('correlation_key', "{$event->aggregate_type}:{$event->aggregate_id}")
            // Business-scoped exactly as event-triggered jobs are: a run of one business hears only its own.
            ->where(function ($q) use ($event) {
                $q->whereNull('business_id');
                if ($event->business_id !== null) {
                    $q->orWhere('business_id', $event->business_id);
                }
            })
            ->orderBy('id')
            ->each(function (WorkflowInstance $instance) use ($event, &$delivered) {
                if (! $this->definitions->has($instance->type) || ! in_array($event->type, $this->definitions->get($instance->type)->listensTo(), true)) {
                    return;
                }
                if ($this->engine->signal($instance->instance_key, 'event:'.$event->type, EventTriggeredJobs::snapshot($event), 'event:'.$event->event_id)) {
                    $delivered++;
                }
            });

        return $delivered;
    }
}
