<?php

namespace App\Courier;

use App\Models\Business;
use App\Models\CourierParcel;
use App\Models\CourierParcelEvent;
use App\Models\FactsOrder;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes what a courier said into HQ's own parcel read model, idempotently (docs/144-courier-connectors).
 *
 * ⛔ HQ's rows only. Nothing here reaches the ERP, a shop or a courier.
 * ⛔ Business-scoped: a parcel belongs to one business, its events carry the same business, and an order is
 * linked only when it is that business's own.
 * ⭐ Idempotent: an event polled twice, pushed twice, or polled then pushed is one row (its dedupe key); a late
 * older event never moves a parcel backwards (the newest event by time decides, ties by journey rank).
 */
final class ParcelLedger
{
    public function __construct(private readonly Auditor $auditor) {}

    public function record(Business $business, ParcelSnapshot $snapshot, string $source): CourierParcel
    {
        return DB::transaction(function () use ($business, $snapshot, $source): CourierParcel {
            $parcel = CourierParcel::query()->firstOrCreate(
                ['business_id' => $business->id, 'provider' => $snapshot->provider, 'consignment_ref' => $snapshot->consignmentRef],
                ['uuid' => (string) Str::uuid(), 'status' => ParcelStatus::Unknown->value],
            );
            $before = $parcel->status;

            foreach ($snapshot->events as $event) {
                CourierParcelEvent::query()->firstOrCreate(
                    ['courier_parcel_id' => $parcel->id, 'dedupe_key' => $event->dedupeKey()],
                    ['business_id' => $business->id, 'occurred_at' => $event->at, 'status' => $event->status->value,
                        'raw_status' => mb_substr($event->rawStatus, 0, 120), 'message' => $event->message, 'source' => $source],
                );
            }

            $latest = $parcel->events()->get()
                ->sort(fn (CourierParcelEvent $a, CourierParcelEvent $b) => [$a->occurred_at, ParcelStatus::from($a->status)->rank(), $a->id] <=> [$b->occurred_at, ParcelStatus::from($b->status)->rank(), $b->id])
                ->last();
            $status = $latest === null ? ParcelStatus::from($parcel->status) : ParcelStatus::from($latest->status);

            $attributes = [
                'status' => $status->value,
                'raw_status' => $latest?->raw_status ?? $parcel->raw_status,
                'terminal' => $status->terminal(),
                'last_event_at' => $latest?->occurred_at ?? $parcel->last_event_at,
            ];
            if ($source === 'poll') {
                $attributes += ['last_polled_at' => now(), 'poll_failures' => 0];
            }
            if ($snapshot->merchantOrderRef !== null && $parcel->merchant_order_ref === null) {
                $attributes['merchant_order_ref'] = mb_substr($snapshot->merchantOrderRef, 0, 96);
            }
            $parcel->forceFill($attributes);
            $this->link($parcel, $business);
            $parcel->save();

            if ($before !== $parcel->status) {
                $this->auditor->record('courier.parcel_status_changed', $parcel, ['status' => $before], ['status' => $parcel->status, 'source' => $source], context: [
                    'business_id' => $business->id, 'provider' => $parcel->provider, 'consignment_ref' => $parcel->consignment_ref,
                ]);
            }

            return $parcel;
        });
    }

    /** A failed poll is counted, so a parcel a courier keeps refusing is visible rather than silently stale. */
    public function pollFailed(CourierParcel $parcel): void
    {
        $parcel->forceFill(['last_polled_at' => now(), 'poll_failures' => $parcel->poll_failures + 1])->save();
    }

    /** The order is this business's own, matched by the reference the courier echoes; otherwise the parcel stays unlinked. */
    private function link(CourierParcel $parcel, Business $business): void
    {
        if ($parcel->facts_order_id !== null || $parcel->merchant_order_ref === null) {
            return;
        }
        $orders = FactsOrder::query()->where('business_id', $business->id)->where('order_ref', $parcel->merchant_order_ref)->limit(2)->pluck('id');
        // Two orders with one reference is a doubt, not a match: leave the parcel unlinked.
        if ($orders->count() === 1) {
            $parcel->facts_order_id = $orders->first();
        }
    }
}
