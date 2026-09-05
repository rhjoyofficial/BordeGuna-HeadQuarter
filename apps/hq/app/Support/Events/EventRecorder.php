<?php

namespace App\Support\Events;

use App\Models\DomainEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Writes inbox (received from outside) and outbox (produced here) rows. Deduplicated by
 * dedupe_key: recording the same event twice returns the existing row and is not an error,
 * which is what makes webhook redelivery and internal retries safe.
 */
final class EventRecorder
{
    public function record(
        string $type,
        array $payload,
        string $source,
        string $direction = 'outbound',
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?int $businessId = null,
        ?string $dedupeKey = null,
        ?\DateTimeInterface $occurredAt = null,
        int $schemaVersion = 1,
    ): DomainEvent {
        $occurredAt ??= now();
        $dedupeKey ??= hash('sha256', implode('|', [$type, $source, $businessId ?? '', $aggregateType ?? '', $aggregateId ?? '', $occurredAt->format('c'), json_encode($payload)]));

        $existing = DomainEvent::query()->where('dedupe_key', $dedupeKey)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DomainEvent::create([
                'event_id' => (string) Str::uuid(),
                'type' => $type,
                'direction' => $direction,
                'source' => $source,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'business_id' => $businessId,
                'payload' => array_merge($payload, ['_correlation_id' => Context::get('correlation_id')]),
                'dedupe_key' => $dedupeKey,
                'schema_version' => $schemaVersion,
                'occurred_at' => $occurredAt,
                'status' => DomainEvent::STATUS_PENDING,
                'max_attempts' => (int) config('hq.events.default_max_attempts', 5),
            ]);
        } catch (QueryException $e) {
            // Lost a race with a concurrent insert of the same dedupe_key: return the winner.
            $existing = DomainEvent::query()->where('dedupe_key', $dedupeKey)->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }
}
