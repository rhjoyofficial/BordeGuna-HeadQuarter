<?php

namespace App\Courier;

use App\Models\CourierParcel;
use App\Models\CourierParcelEvent;

/**
 * The parcel timeline as a screen needs it (Customer 360, the order view, the Parcels screen). Read-only, and always
 * bound to the business: a parcel is shown only beside an order of the same business, whatever its link says.
 */
final class ParcelTimeline
{
    /**
     * @param  list<int>  $orderIds  facts_orders ids
     * @return array<int, list<array<string, mixed>>> order id => its parcels
     */
    public static function forOrders(array $orderIds, int $businessId): array
    {
        if ($orderIds === []) {
            return [];
        }
        $out = [];
        CourierParcel::query()->where('business_id', $businessId)->whereIn('facts_order_id', $orderIds)->with('events')->orderBy('id')->get()
            ->each(function (CourierParcel $p) use (&$out) {
                $out[(int) $p->facts_order_id][] = self::row($p);
            });

        return $out;
    }

    /** @return array<string, mixed> */
    public static function row(CourierParcel $p): array
    {
        return [
            'uuid' => $p->uuid, 'provider' => $p->provider, 'consignment_ref' => $p->consignment_ref, 'status' => $p->status, 'raw_status' => $p->raw_status,
            'terminal' => (bool) $p->terminal, 'last_event_at' => $p->last_event_at?->toIso8601String(), 'last_polled_at' => $p->last_polled_at?->toIso8601String(),
            'poll_failures' => $p->poll_failures, 'linked' => $p->facts_order_id !== null, 'merchant_order_ref' => $p->merchant_order_ref,
            'events' => $p->events->map(fn (CourierParcelEvent $e) => [
                'at' => $e->occurred_at->toIso8601String(), 'status' => $e->status, 'raw_status' => $e->raw_status, 'message' => $e->message, 'source' => $e->source,
            ])->values()->all(),
        ];
    }
}
