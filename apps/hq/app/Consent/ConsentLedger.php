<?php

namespace App\Consent;

use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Support\Audit\Auditor;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The append-only consent ledger and its projection (ADR-021).
 *
 * ⭐ **The question this exists to answer is historical.** "May we contact them now" is easy and a
 * mutable column could do it. Compliance asks, months later, about one specific message somebody is
 * complaining about: **was consent valid at the moment we sent that**. `permittedAt()` replays the
 * ledger to that instant and answers with the event id that decided it.
 *
 * ## The resolution rule, stated once
 *
 * A request names a channel and a purpose. Events may be recorded at four levels of scope: exactly
 * that channel and purpose, that purpose on every channel, every purpose on that channel, or
 * everything for this business. For each of the four, the latest event at or before the instant is
 * taken.
 *
 * **Then: any denial wins.** A person-level "do not contact" cannot be overridden by a
 * channel-level grant recorded afterwards, because the broad refusal is a statement about the
 * person and the narrow grant is a statement about a channel. Getting this backwards is how a
 * business keeps messaging somebody who asked it to stop.
 *
 * If nothing denies and at least one live grant applies, it is permitted. Otherwise it is not:
 * silence is never consent, and `ConsentPurpose::permittedWithoutRecord()` returns false for every
 * purpose including service, deliberately.
 */
final readonly class ConsentLedger
{
    public function __construct(private Auditor $auditor) {}

    /**
     * Append one consent fact and update the projection.
     *
     * @param  array<string, mixed>  $attributes  evidence, actor, expiry, retention, correlation
     */
    public function record(
        int $customerId,
        int $businessId,
        ConsentPurpose|string|null $purpose,
        ConsentState $state,
        ConsentBasis $basis,
        ?string $channel = null,
        ?DateTimeInterface $occurredAt = null,
        array $attributes = [],
    ): ConsentEvent {
        $purposeValue = $purpose instanceof ConsentPurpose ? $purpose->value : $purpose;

        return DB::transaction(function () use ($customerId, $businessId, $purposeValue, $state, $basis, $channel, $occurredAt, $attributes) {
            $event = ConsentEvent::create([
                'customer_id' => $customerId,
                'business_id' => $businessId,
                'brand_id' => $attributes['brand_id'] ?? null,
                'channel' => $channel,
                'purpose' => $purposeValue,
                'state' => $state->value,
                'basis' => $basis->value,
                'occurred_at' => $occurredAt ?? now(),
                'recorded_at' => now(),
                'expires_at' => $attributes['expires_at'] ?? null,
                'evidence_ref' => $attributes['evidence_ref'] ?? null,
                'evidence' => $attributes['evidence'] ?? null,
                'actor_type' => $attributes['actor_type'] ?? 'system',
                'actor_id' => $attributes['actor_id'] ?? null,
                'actor_label' => $attributes['actor_label'] ?? null,
                'reason' => $attributes['reason'] ?? null,
                'data_class' => $attributes['data_class'] ?? 'personal',
                'retention_days' => $attributes['retention_days'] ?? null,
                'correlation_id' => $attributes['correlation_id'] ?? null,
                'tool_call_id' => $attributes['tool_call_id'] ?? null,
            ]);

            $this->project($event);

            // The ledger is the record; the audit log is the record that the record was made. Both,
            // because somebody adding consent nobody agreed to should be as visible as any other
            // privileged act.
            $this->auditor->record('consent.recorded', $event, after: [
                'customer_id' => $customerId, 'business_id' => $businessId,
                'channel' => $channel, 'purpose' => $purposeValue, 'state' => $state->value,
                'basis' => $basis->value,
            ], reason: $attributes['reason'] ?? null);

            return $event;
        });
    }

    /** Is contacting this person permitted RIGHT NOW. */
    public function permits(int $customerId, int $businessId, ?string $channel, ConsentPurpose $purpose): ConsentDecision
    {
        return $this->permittedAt($customerId, $businessId, $channel, $purpose, now());
    }

    /**
     * Was it permitted at $at. The ledger is replayed; the projection is not consulted at all.
     *
     * ⚠ The projection deliberately plays no part here. It holds today's answer, and today's answer
     * is exactly what a historical question must not be given.
     */
    public function permittedAt(
        int $customerId,
        int $businessId,
        ?string $channel,
        ConsentPurpose $purpose,
        DateTimeInterface $at,
    ): ConsentDecision {
        $instant = Carbon::instance(Carbon::parse($at));

        $events = ConsentEvent::query()
            ->where('customer_id', $customerId)
            ->where('business_id', $businessId)
            ->where('occurred_at', '<=', $instant)
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $channel))
            ->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', $purpose->value))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return ConsentDecision::unrecorded(
                "no consent event has ever been recorded for customer {$customerId}, business {$businessId}, "
                .'channel ['.($channel ?? 'any')."], purpose [{$purpose->value}]"
            );
        }

        // The latest event at each of the four scopes. A narrower scope does not outrank a broader
        // refusal; see the class docblock.
        $latest = [];
        foreach ($events as $event) {
            $scope = ($event->channel ?? '*').'|'.($event->purpose?->value ?? '*');
            $latest[$scope] = $event;
        }

        $grant = null;
        foreach ($latest as $event) {
            $state = $event->state;

            if ($state === ConsentState::DoNotContact) {
                return ConsentDecision::refuse(
                    "customer {$customerId} is marked do-not-contact by consent event #{$event->id}",
                    $event, $state,
                );
            }

            if ($state === ConsentState::Withdrawn) {
                return ConsentDecision::refuse("consent was withdrawn by event #{$event->id}", $event, $state);
            }

            if ($state === ConsentState::Expired) {
                return ConsentDecision::refuse("consent expired, recorded by event #{$event->id}", $event, $state);
            }

            if ($state === ConsentState::Granted) {
                if ($event->expires_at !== null && $event->expires_at->lessThanOrEqualTo($instant)) {
                    return ConsentDecision::refuse(
                        "the grant in event #{$event->id} expired at {$event->expires_at->toIso8601String()}",
                        $event, ConsentState::Expired,
                    );
                }
                $grant ??= $event;
            }
        }

        return $grant !== null
            ? ConsentDecision::permit($grant)
            : ConsentDecision::unrecorded("no live grant applies for purpose [{$purpose->value}]");
    }

    /** Rewrite the projection row this event affects. */
    public function project(ConsentEvent $event): ConsentProjection
    {
        return ConsentProjection::updateOrCreate(
            [
                'customer_id' => $event->customer_id,
                'business_id' => $event->business_id,
                'channel' => $event->channel,
                'purpose' => $event->purpose?->value,
            ],
            [
                'state' => $event->state->value,
                'effective_from' => $event->occurred_at,
                'expires_at' => $event->expires_at,
                'last_event_id' => $event->id,
            ],
        );
    }

    /**
     * Throw the projection away and rebuild it from the ledger.
     *
     * This is the proof that the projection is derived rather than authoritative. If it ever
     * disagrees with the ledger, running this makes the ledger win, which is the correct direction.
     *
     * @return int rows written
     */
    public function rebuild(?int $customerId = null): int
    {
        return DB::transaction(function () use ($customerId) {
            ConsentProjection::query()
                ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
                ->delete();

            $written = 0;
            ConsentEvent::query()
                ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->chunk(500, function ($events) use (&$written) {
                    foreach ($events as $event) {
                        $this->project($event);
                        $written++;
                    }
                });

            return $written;
        });
    }
}
