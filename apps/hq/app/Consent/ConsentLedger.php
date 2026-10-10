<?php

namespace App\Consent;

use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Support\Audit\Auditor;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
 * A request names a brand (or none), an identity (or none), a channel and a purpose. Events may be
 * recorded at up to sixteen levels of scope: each of brand, identity, channel and purpose either
 * specific or "all" (null on the event). For each scope, the latest event at or before the instant is
 * taken (docs/108 §3). A brand-scoped or identity-scoped event when the request names no brand or no
 * identity: a denial still applies (fail closed), a grant does not (docs/114 §2).
 *
 * ⭐ Identity (docs/114 §2, ruling (i)): an identity is one `customer_identities` row, a phone or a
 * PSID. An event scoped to identity A is not an applicable event for a send to identity B at all, so
 * a restriction on one identity can never be defeated by a grant on another, and a customer-wide
 * restriction (identity null) is applicable to every identity. The identity is resolved from the
 * thread row by the caller (`ThreadIdentity`), never from an id anybody typed.
 *
 * **Then: any denial wins.** A person-level "do not contact" cannot be overridden by a
 * channel-level grant recorded afterwards, because the broad refusal is a statement about the
 * person and the narrow grant is a statement about a channel. Getting this backwards is how a
 * business keeps messaging somebody who asked it to stop.
 *
 * If nothing denies and at least one live QUALIFYING grant applies, it is permitted. Otherwise it is
 * not: silence is never consent, and `ConsentPurpose::permittedWithoutRecord()` returns false for
 * every purpose including service, deliberately. For an opt-in purpose (marketing and the others,
 * `ConsentPurpose::requiresOptIn()`) only a grant of exactly that purpose on a basis that can
 * evidence an opt-in qualifies; a wildcard grant or a customer's message never does (docs/108 §3.4).
 *
 * `lifted` is neutral at its scope: as the latest event there it ends a restriction recorded at that
 * same scope and grants nothing.
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

        // docs/108 §3.4, defence in depth: an opt-in purpose is never GRANTED on a basis that cannot
        // evidence an opt-in. The resolution rule would not count such a row anyway; refusing to
        // write it keeps the ledger from ever appearing to hold permission it does not.
        $purposeEnum = $purposeValue === null ? null : ConsentPurpose::from($purposeValue);
        if ($state === ConsentState::Granted && $purposeEnum?->requiresOptIn() && ! $basis->evidencesOptIn()) {
            throw new InvalidArgumentException(
                "A [{$purposeEnum->value}] grant needs an opt-in; basis [{$basis->value}] cannot evidence one."
            );
        }

        return DB::transaction(function () use ($customerId, $businessId, $purposeValue, $state, $basis, $channel, $occurredAt, $attributes) {
            $event = ConsentEvent::create([
                'customer_id' => $customerId,
                'business_id' => $businessId,
                'brand_id' => $attributes['brand_id'] ?? null,
                'identity_id' => $attributes['identity_id'] ?? null,
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
                'basis' => $basis->value, 'brand_id' => $attributes['brand_id'] ?? null,
                'identity_id' => $attributes['identity_id'] ?? null,
            ], reason: $attributes['reason'] ?? null);

            return $event;
        });
    }

    /** Is contacting this person permitted RIGHT NOW. */
    public function permits(int $customerId, int $businessId, ?string $channel, ConsentPurpose $purpose, ?int $brandId = null, ?int $identityId = null): ConsentDecision
    {
        return $this->permittedAt($customerId, $businessId, $channel, $purpose, now(), $brandId, $identityId);
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
        ?int $brandId = null,
        ?int $identityId = null,
    ): ConsentDecision {
        $instant = Carbon::instance(Carbon::parse($at));

        $events = ConsentEvent::query()
            ->where('customer_id', $customerId)
            ->where('business_id', $businessId)
            ->where('occurred_at', '<=', $instant)
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', $channel))
            ->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', $purpose->value))
            // A brand-scoped event is read when the request names that brand, and ALSO when it names
            // none: such a denial must still apply (fail closed). Such a grant is skipped below.
            ->when($brandId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('brand_id')->orWhere('brand_id', $brandId)))
            // The same for identity (docs/114 §2): another identity's events are never read for this one;
            // with no identity resolved every identity's denial is read, and every identity's grant skipped.
            ->when($identityId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('identity_id')->orWhere('identity_id', $identityId)))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $optIn = $purpose->requiresOptIn();

        if ($events->isEmpty()) {
            return ConsentDecision::unrecorded(
                "no consent event has ever been recorded for customer {$customerId}, business {$businessId}, "
                .'brand ['.($brandId ?? 'any').'], identity ['.($identityId ?? 'unresolved').'], channel ['.($channel ?? 'any')."], purpose [{$purpose->value}]",
                $optIn ? ConsentRefusal::OptInMissing : ConsentRefusal::NotRecorded,
            );
        }

        // The latest event at each scope. A narrower scope does not outrank a broader refusal; see the
        // class docblock. Brand is part of the scope (docs/108 §3.2).
        $latest = [];
        foreach ($events as $event) {
            $scope = ($event->brand_id ?? '*').'|'.($event->identity_id ?? '*').'|'.($event->channel ?? '*').'|'.($event->purpose?->value ?? '*');
            $latest[$scope] = $event;
        }

        // Denials first, strongest first, whatever order the scopes were met in: do-not-contact beats
        // everything, then a withdrawal, then a recorded expiry.
        foreach ([ConsentState::DoNotContact, ConsentState::Withdrawn, ConsentState::Expired] as $denial) {
            foreach ($latest as $event) {
                if ($event->state !== $denial) {
                    continue;
                }

                return match ($denial) {
                    ConsentState::DoNotContact => ConsentDecision::refuse(
                        "customer {$customerId} is marked do-not-contact by consent event #{$event->id}", $event, $denial),
                    ConsentState::Withdrawn => ConsentDecision::refuse("consent was withdrawn by event #{$event->id}", $event, $denial),
                    default => ConsentDecision::refuse("consent expired, recorded by event #{$event->id}", $event, $denial),
                };
            }
        }

        $grant = null;
        $expired = null;
        $unqualified = null;
        foreach ($latest as $event) {
            if ($event->state !== ConsentState::Granted) {
                continue; // `lifted`: neutral at its scope
            }
            if ($event->brand_id !== null && $brandId === null) {
                continue; // a brand's grant never covers a send whose brand is unknown
            }
            if ($event->identity_id !== null && $identityId === null) {
                continue; // an identity's grant never covers a send whose identity is unresolved (fail closed)
            }
            if ($optIn && ($event->purpose !== $purpose || ! $event->basis->evidencesOptIn())) {
                $unqualified ??= $event;

                continue;
            }
            if ($event->expires_at !== null && $event->expires_at->lessThanOrEqualTo($instant)) {
                $expired ??= $event;

                continue;
            }
            $grant ??= $event;
        }

        if ($grant !== null) {
            return ConsentDecision::permit($grant);
        }

        if ($expired !== null) {
            return ConsentDecision::refuse(
                "the grant in event #{$expired->id} expired at {$expired->expires_at->toIso8601String()}",
                $expired, ConsentState::Expired,
            );
        }

        if ($optIn) {
            return ConsentDecision::unrecorded(
                "[{$purpose->value}] needs its own opt-in and none applies"
                .($unqualified !== null ? " (event #{$unqualified->id} is not an opt-in for this purpose)" : ''),
                ConsentRefusal::OptInMissing,
            );
        }

        return ConsentDecision::unrecorded("no live grant applies for purpose [{$purpose->value}]");
    }

    /**
     * The latest event recorded at EXACTLY this scope (null = "all" for that dimension), at or before
     * now. What a lift checks (docs/108 §5): only a restriction standing at the same scope may be lifted.
     */
    public function latestAtScope(int $customerId, int $businessId, ?int $brandId, ?string $channel, ?string $purpose, ?int $identityId = null): ?ConsentEvent
    {
        return ConsentEvent::query()
            ->where('customer_id', $customerId)
            ->where('business_id', $businessId)
            ->where('occurred_at', '<=', now())
            ->when($brandId === null, fn ($q) => $q->whereNull('brand_id'), fn ($q) => $q->where('brand_id', $brandId))
            ->when($identityId === null, fn ($q) => $q->whereNull('identity_id'), fn ($q) => $q->where('identity_id', $identityId))
            ->when($channel === null, fn ($q) => $q->whereNull('channel'), fn ($q) => $q->where('channel', $channel))
            ->when($purpose === null, fn ($q) => $q->whereNull('purpose'), fn ($q) => $q->where('purpose', $purpose))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Rewrite the projection row this event affects. */
    public function project(ConsentEvent $event): ConsentProjection
    {
        return ConsentProjection::updateOrCreate(
            [
                'customer_id' => $event->customer_id,
                'business_id' => $event->business_id,
                'brand_id' => $event->brand_id,
                'identity_id' => $event->identity_id,
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
