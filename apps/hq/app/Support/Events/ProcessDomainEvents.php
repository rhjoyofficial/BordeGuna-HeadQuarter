<?php

namespace App\Support\Events;

use App\Models\DomainEvent;
use App\Scheduling\EventTriggeredJobs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drains due events to their handlers with bounded retries and backoff. Runs every minute
 * from the scheduler; a Redis lock keeps two drains from processing the same batch.
 * An event with no handler is marked processed (nothing to do is not a failure).
 */
final class ProcessDomainEvents extends Command
{
    protected $signature = 'hq:events:process {--limit=200}';

    protected $description = 'Deliver pending domain events to their registered handlers.';

    public function handle(EventTriggeredJobs $eventJobs): int
    {
        $lock = Cache::lock('hq:events:process', 55);
        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            $handlers = config('hq.events.handlers', []);
            $backoff = config('hq.events.backoff_seconds', [30, 120, 600, 1800, 3600]);
            $processed = 0;
            $failed = 0;
            $fired = 0;

            $events = DomainEvent::query()
                ->whereIn('status', [DomainEvent::STATUS_PENDING, DomainEvent::STATUS_FAILED])
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')
                ->limit((int) $this->option('limit'))
                ->get();

            foreach ($events as $event) {
                // Claim atomically so a concurrent drain skips it.
                $claimed = DB::table('events')->where('id', $event->id)->whereIn('status', ['pending', 'failed'])
                    ->update(['status' => DomainEvent::STATUS_PROCESSING, 'attempts' => DB::raw('attempts + 1')]);
                if ($claimed === 0) {
                    continue;
                }
                $event->refresh();
                Context::add(['correlation_id' => $event->payload['_correlation_id'] ?? $event->event_id, 'event_id' => $event->event_id]);

                try {
                    foreach ((array) ($handlers[$event->type] ?? []) as $class) {
                        app($class)->handle($event);
                    }
                    // Event-triggered scheduled jobs (docs/95 §16 NOW item 4): one run per (job, event),
                    // after the handlers, so a job sees the state they wrote. A retry of this event
                    // after a handler failure cannot run a job twice: the event's uuid is the slot.
                    $fired += $eventJobs->fire($event);
                    $event->forceFill(['status' => DomainEvent::STATUS_PROCESSED, 'processed_at' => now(), 'last_error' => null])->save();
                    $processed++;
                } catch (Throwable $e) {
                    $failed++;
                    $dead = $event->attempts >= $event->max_attempts;
                    $delay = $backoff[min($event->attempts - 1, count($backoff) - 1)] ?? 3600;
                    $event->forceFill([
                        'status' => $dead ? DomainEvent::STATUS_DEAD : DomainEvent::STATUS_FAILED,
                        'next_attempt_at' => $dead ? null : now()->addSeconds($delay),
                        'last_error' => substr(get_class($e).': '.$e->getMessage(), 0, 2000),
                    ])->save();
                    Log::error('event handler failed', ['event_id' => $event->event_id, 'type' => $event->type, 'attempt' => $event->attempts, 'dead' => $dead, 'exception' => $e]);
                }
            }

            $this->info("events: processed {$processed}, failed {$failed}, jobs fired {$fired}");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
