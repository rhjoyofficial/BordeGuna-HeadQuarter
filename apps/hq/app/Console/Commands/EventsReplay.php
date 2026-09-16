<?php

namespace App\Console\Commands;

use App\Models\DomainEvent;
use App\Observe\Retention\RetentionPolicy;
use App\Support\Audit\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Re-delivers events HQ has already accepted to their current handlers.
 *
 * This exists because a mapping bug is not a delivery failure. When HQ read the wrong key out of a
 * webhook payload, every affected fact was written from a payload HQ still holds verbatim in
 * `events`; the repair is therefore to run the corrected handler over the payload that already
 * arrived, not to ask the shop again and not to type values into `facts_orders` by hand. The
 * shop is never contacted, so nothing on the source system can change.
 *
 * Safety, in the order it matters:
 *  - it refuses to run without a filter, so "replay everything" is never a slip of the finger;
 *  - it reports what it would do and changes nothing until `--apply` is given;
 *  - handlers are idempotent (facts upsert on (store, order_ref), identities on (channel, external
 *    id, store)), so a replay cannot duplicate a fact, a customer or an identity;
 *  - a status event never regresses a later status, so replaying an old `order.created` over an
 *    order that has since been cancelled leaves it cancelled;
 *  - every applied replay writes one audit record naming the event and the reason.
 *
 * `attempts` is deliberately left alone: it counts how many times delivery has been *tried*, and a
 * replay is not evidence that the original delivery was flaky.
 *
 * One status IS rewritten. An event that exhausted its retries sits at `dead`, meaning "this effect
 * never landed". When a replay of such an event succeeds, that sentence stops being true: the effect
 * has landed, and leaving the row at `dead` tells a future reader the opposite of what happened. So a
 * successful replay promotes `dead` and `failed` rows to `processed` and stamps `processed_at`, while
 * `last_error` is kept verbatim - the row still records that delivery failed and why, it simply no
 * longer claims the failure is outstanding. Nothing else is touched: an already-`processed` event is
 * left exactly as it was, and a replay that throws changes no status at all.
 */
final class EventsReplay extends Command
{
    protected $signature = 'hq:events:replay
        {--event=* : event_id (uuid) to replay; repeatable}
        {--type= : replay events of this type, e.g. order.created}
        {--store= : restrict to one store slug (matches the payload _store)}
        {--hours= : restrict to events that occurred within this many hours}
        {--limit=200 : most events to replay in one run}
        {--reason= : why this replay is being run; recorded in the audit log}
        {--apply : actually re-deliver. Without it the command only reports what it would do.}';

    protected $description = 'Re-deliver already-processed events to their handlers (repairs facts written by a mapping bug).';

    public function handle(Auditor $auditor): int
    {
        $ids = array_filter((array) $this->option('event'));
        if (! $ids && ! $this->option('type') && ! $this->option('store') && ! $this->option('hours')) {
            $this->error('refusing to replay every event: give at least one of --event, --type, --store or --hours.');

            return self::FAILURE;
        }

        $events = $this->select($ids);

        if ($events->isEmpty()) {
            $this->warn('no events match that selection; nothing to replay');

            return self::SUCCESS;
        }

        $this->line("selected {$events->count()} event(s):");
        foreach ($events as $event) {
            $this->line(sprintf('  %s  %-22s %s  %s', $event->event_id, $event->type, $event->occurred_at?->toDateTimeString() ?? '-', $event->aggregate_id ?? ''));
        }

        if (! $this->option('apply')) {
            $this->info('dry run: nothing was re-delivered. Add --apply to run the handlers.');

            return self::SUCCESS;
        }

        $handlers = config('hq.events.handlers', []);
        $replayed = 0;
        $failed = 0;
        $resolvedCount = 0;

        foreach ($events as $event) {
            // docs/78 B.2: past the payload window a processed event keeps only a digest of what it
            // carried. Replaying the digest would hand a handler an empty order, so it is refused.
            if (RetentionPolicy::isCompacted($event->payload)) {
                $this->error("  {$event->event_id}: its payload was reduced to a digest by retention; it cannot be replayed.");
                $failed++;

                continue;
            }

            Context::add(['correlation_id' => $event->payload['_correlation_id'] ?? $event->event_id, 'event_id' => $event->event_id]);
            try {
                foreach ((array) ($handlers[$event->type] ?? []) as $class) {
                    app($class)->handle($event);
                }
                $resolved = $this->resolveIfUndelivered($event);
                $auditor->recordSystem(
                    action: 'event.replayed',
                    target: $event,
                    reason: $this->option('reason') ?: 'manual replay',
                    context: array_filter([
                        'event_id' => $event->event_id,
                        'type' => $event->type,
                        'aggregate_id' => $event->aggregate_id,
                        'resolved_from' => $resolved,
                    ]),
                );
                if ($resolved !== null) {
                    $this->line("  {$event->event_id}: {$resolved} -> processed");
                    $resolvedCount++;
                }
                $replayed++;
            } catch (Throwable $e) {
                // A replay failure leaves the event exactly as it was: this command never marks an
                // already-processed event failed, because its delivery genuinely did succeed once.
                $this->error("  {$event->event_id}: ".get_class($e).': '.$e->getMessage());
                $failed++;
            }
        }

        $this->info("replayed {$replayed}, failed {$failed}, resolved {$resolvedCount}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Promote an event whose delivery never landed to `processed`, now that a replay has landed it.
     *
     * Returns the status it was promoted from, or null when there was nothing to promote - a
     * `processed` event replayed again is a repair, not a resolution, and must not be relabelled.
     */
    private function resolveIfUndelivered(DomainEvent $event): ?string
    {
        if (! in_array($event->status, [DomainEvent::STATUS_DEAD, DomainEvent::STATUS_FAILED], true)) {
            return null;
        }

        $from = $event->status;
        $event->forceFill([
            'status' => DomainEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'next_attempt_at' => null,
        ])->save();

        return $from;
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<int, DomainEvent>
     */
    private function select(array $ids)
    {
        $type = $this->option('type');
        $store = $this->option('store');
        $hours = $this->option('hours');

        return DomainEvent::query()
            ->when($ids, fn ($q) => $q->whereIn('event_id', $ids))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($store, fn ($q) => $q->where('payload->_store', $store))
            ->when($hours, fn ($q) => $q->where('occurred_at', '>=', now()->subHours((int) $hours)))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();
    }
}
