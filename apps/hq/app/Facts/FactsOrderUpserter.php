<?php

namespace App\Facts;

use App\Identity\IdentityResolver;
use App\Identity\PhoneCanonical;
use App\Models\FactsOrder;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the orders read model from a shop order payload (the shop's OrderResource shape or the
 * adapter's Order DTO converted to array). Idempotent on (store, order_ref): the same order
 * arriving twice, or an older event after a newer one, never regresses a later status.
 */
final class FactsOrderUpserter
{
    private const STATUS_RANK = ['pending' => 1, 'confirmed' => 2, 'processing' => 3, 'shipped' => 4, 'delivered' => 5, 'cancelled' => 5, 'returned' => 6];

    public function __construct(private readonly IdentityResolver $identity) {}

    public function upsertFromShopPayload(Store $store, array $o, ?string $eventId = null): FactsOrder
    {
        $ref = (string) ($o['order_number'] ?? $o['ref'] ?? '');
        if ($ref === '') {
            throw new \InvalidArgumentException('order payload has no order_number');
        }
        $phone = $o['customer_phone'] ?? ($o['shipping_address']['customer_phone'] ?? null);
        $address = $o['shipping_address'] ?? [];
        $placedAt = $o['placed_at'] ?? $o['created_at'] ?? null;

        return DB::transaction(function () use ($store, $o, $ref, $phone, $address, $placedAt, $eventId) {
            $customer = $this->identity->resolve($store, [
                'name' => $o['customer_name'] ?? null,
                'phone' => $phone,
                'email' => $o['customer_email'] ?? null,
                'district' => $address['district'] ?? $address['city'] ?? null,
                'order_ref' => $ref,
                'shop_user_id' => $o['user']['id'] ?? $o['user_id'] ?? null,
                'placed_at' => $placedAt,
            ]);

            $existing = FactsOrder::where('store_id', $store->id)->where('order_ref', $ref)->first();
            $incomingStatus = (string) ($o['order_status'] ?? $o['status'] ?? 'pending');
            $status = $existing && (self::STATUS_RANK[$incomingStatus] ?? 0) < (self::STATUS_RANK[$existing->status] ?? 0) ? $existing->status : $incomingStatus;

            $fact = FactsOrder::updateOrCreate(['store_id' => $store->id, 'order_ref' => $ref], [
                'business_id' => $store->business_id,
                'source' => $o['source'] ?? $existing?->source,
                'status' => $status,
                'payment_status' => $o['payment_status'] ?? $existing?->payment_status,
                'payment_method' => $o['payment_method'] ?? $existing?->payment_method,
                'subtotal' => $o['subtotal'] ?? $existing?->subtotal ?? 0,
                'discount_total' => $o['discount_total'] ?? $existing?->discount_total ?? 0,
                'shipping_cost' => $o['shipping_cost'] ?? $existing?->shipping_cost ?? 0,
                'gross' => $o['grand_total'] ?? $existing?->gross ?? 0,
                'customer_id' => $customer->id,
                'customer_phone_canonical' => PhoneCanonical::normalize($phone) ?? $existing?->customer_phone_canonical,
                'district' => $address['district'] ?? $address['city'] ?? $existing?->district,
                'thana' => $address['thana'] ?? $address['area'] ?? $existing?->thana,
                'placed_at' => $placedAt ? CarbonImmutable::parse($placedAt) : $existing?->placed_at,
                'confirmed_at' => isset($o['confirmed_at']) ? CarbonImmutable::parse($o['confirmed_at']) : $existing?->confirmed_at,
                'delivered_at' => isset($o['delivered_at']) ? CarbonImmutable::parse($o['delivered_at']) : $existing?->delivered_at,
                'cancelled_at' => isset($o['cancelled_at']) ? CarbonImmutable::parse($o['cancelled_at']) : $existing?->cancelled_at,
                'campaign_ref' => $o['fbc'] ?? $o['campaign_ref'] ?? $existing?->campaign_ref,
                'lines' => isset($o['items']) ? array_map(fn ($i) => [
                    'sku' => $i['sku'] ?? null, 'name' => $i['product_name'] ?? $i['name'] ?? null, 'qty' => (int) ($i['qty'] ?? $i['quantity'] ?? 1),
                    'unit_price' => (float) ($i['unit_price'] ?? 0), 'total' => (float) ($i['total'] ?? 0),
                    'variant_id' => $i['variant_id'] ?? null, 'combo_id' => $i['combo_id'] ?? null,
                ], $o['items']) : $existing?->lines,
                'last_event_id' => $eventId ?? $existing?->last_event_id,
                'synced_at' => now(),
            ]);

            $customer->forceFill([
                'orders_count' => FactsOrder::where('customer_id', $customer->id)->count(),
                'lifetime_value' => FactsOrder::where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->sum('gross'),
            ])->save();

            return $fact;
        });
    }

    /** Applies a status-only event (order.status_changed, payment.completed, shipment.delivered). Parks if the order is unknown. */
    public function applyStatusEvent(Store $store, string $type, array $data, ?string $eventId = null): FactsOrder
    {
        $ref = (string) ($data['order_number'] ?? '');
        $fact = FactsOrder::where('store_id', $store->id)->where('order_ref', $ref)->first();
        if (! $fact) {
            throw new UnknownOrderException("order {$ref} not yet known for store {$store->slug}; will retry");
        }
        // The shop's listeners send old_status/new_status/changed_at; the docs/35 envelope uses from/to/at. Accept both.
        $to = $data['to'] ?? $data['new_status'] ?? null;
        $data['to'] = $to;
        $at = isset($data['at']) ? CarbonImmutable::parse($data['at']) : (isset($data['changed_at']) ? CarbonImmutable::parse($data['changed_at']) : now());
        match ($type) {
            'order.status_changed' => $fact->forceFill(array_filter([
                'status' => (self::STATUS_RANK[$data['to'] ?? ''] ?? 0) >= (self::STATUS_RANK[$fact->status] ?? 0) ? ($data['to'] ?? $fact->status) : $fact->status,
                'confirmed_at' => ($data['to'] ?? null) === 'confirmed' ? $at : null,
                'delivered_at' => ($data['to'] ?? null) === 'delivered' ? $at : null,
                'cancelled_at' => ($data['to'] ?? null) === 'cancelled' ? $at : null,
            ], fn ($v) => $v !== null)),
            'order.payment_updated' => $fact->forceFill(array_filter(['payment_status' => $to, 'payment_method' => $data['payment_method'] ?? $fact->payment_method, 'status' => isset($data['order_status']) && (self::STATUS_RANK[$data['order_status']] ?? 0) >= (self::STATUS_RANK[$fact->status] ?? 0) ? $data['order_status'] : null], fn ($v) => $v !== null)),
            'payment.completed' => $fact->forceFill(['payment_status' => 'paid', 'payment_method' => $data['method'] ?? $fact->payment_method]),
            'shipment.status_updated' => $to === 'delivered' ? $fact->forceFill(['status' => 'delivered', 'delivered_at' => $at]) : $fact,
            'shipment.delivered' => $fact->forceFill(['status' => 'delivered', 'delivered_at' => isset($data['delivered_at']) ? CarbonImmutable::parse($data['delivered_at']) : $at]),
            default => $fact,
        };
        $fact->last_event_id = $eventId ?? $fact->last_event_id;
        $fact->synced_at = now();
        $fact->save();

        return $fact;
    }
}
