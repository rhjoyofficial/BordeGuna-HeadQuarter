<?php

namespace App\Facts;

use App\Identity\CrossBusinessIdentity;
use App\Identity\IdentityResolver;
use App\Identity\PhoneCanonical;
use App\Models\Customer;
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

    /**
     * Writes an order fact from a FULL-STATE shop payload (`order.created`, `order.updated`, or a
     * reconciliation replay of the admin API).
     *
     * `$occurredAt` is when the shop says this snapshot was true. Delivery order is not event order
     * - a retried or delayed `order.updated` can arrive after a newer one - so a full-state payload
     * older than the newest already applied is refused rather than allowed to overwrite good data.
     * Passing null keeps the old behaviour of always applying, which is what reconciliation wants:
     * it reads the shop's current truth, so it is never stale by definition.
     */
    public function upsertFromShopPayload(Store $store, array $o, ?string $eventId = null, ?CarbonImmutable $occurredAt = null): FactsOrder
    {
        $ref = (string) ($o['order_number'] ?? $o['ref'] ?? '');
        if ($ref === '') {
            throw new \InvalidArgumentException('order payload has no order_number');
        }
        $phone = $o['customer_phone'] ?? ($o['shipping_address']['customer_phone'] ?? null);
        $address = $o['shipping_address'] ?? [];
        $placedAt = $o['placed_at'] ?? $o['created_at'] ?? null;

        return DB::transaction(function () use ($store, $o, $ref, $phone, $address, $placedAt, $eventId, $occurredAt) {
            $customer = $this->identity->resolve($store, [
                'name' => $o['customer_name'] ?? null,
                'phone' => $phone,
                'email' => $o['customer_email'] ?? null,
                'district' => $address['district'] ?? $address['city'] ?? null,
                'order_ref' => $ref,
                'shop_user_id' => $o['user']['id'] ?? $o['user_id'] ?? null,
                'placed_at' => $placedAt,
            ]);

            // ⛔ docs/122 (A4): the order's customer is a customer of the order's own business, checked here and
            // not only in the resolver.
            if ((int) $customer->business_id !== (int) $store->business_id) {
                throw CrossBusinessIdentity::customer((int) $customer->business_id, (int) $store->business_id);
            }

            $existing = FactsOrder::where('store_id', $store->id)->where('order_ref', $ref)->first();
            // A copy written before the fix may hang under another business's customer. Moving it is the
            // evidence-backed repair's job (docs/120 A3), never a side effect of the next event: it stays put.
            $customerId = $customer->id;
            if ($existing !== null && $existing->customer_id !== null && (int) $existing->customer_id !== (int) $customer->id
                && Customer::query()->whereKey($existing->customer_id)->where('business_id', '!=', $store->business_id)->exists()) {
                $customerId = (int) $existing->customer_id;
            }

            // An older snapshot must never beat a newer one. Only a full-state event carries a
            // timestamp; without one the caller is reconciliation, reading the shop's current truth.
            if ($existing && $occurredAt && $existing->last_event_at && $occurredAt->lt($existing->last_event_at)) {
                return $existing;
            }

            $incomingStatus = (string) ($o['order_status'] ?? $o['status'] ?? 'pending');
            $status = $existing && (self::STATUS_RANK[$incomingStatus] ?? 0) < (self::STATUS_RANK[$existing->status] ?? 0) ? $existing->status : $incomingStatus;

            $fact = FactsOrder::updateOrCreate(['store_id' => $store->id, 'order_ref' => $ref], [
                'business_id' => $store->business_id,
                'source' => self::pick($o, 'source', $existing?->source),
                'status' => $status,
                'payment_status' => self::pick($o, 'payment_status', $existing?->payment_status),
                'payment_method' => self::pick($o, 'payment_method', $existing?->payment_method),
                'subtotal' => $o['subtotal'] ?? $existing?->subtotal ?? 0,
                'discount_total' => $o['discount_total'] ?? $existing?->discount_total ?? 0,
                'shipping_cost' => $o['shipping_cost'] ?? $existing?->shipping_cost ?? 0,
                'gross' => $o['grand_total'] ?? $existing?->gross ?? 0,
                'customer_id' => $customerId,
                'customer_phone_canonical' => PhoneCanonical::normalize($phone) ?? $existing?->customer_phone_canonical,
                'district' => self::pick($address, 'district', self::pick($address, 'city', $existing?->district)),
                'thana' => self::pick($address, 'thana', self::pick($address, 'area', $existing?->thana)),
                'placed_at' => $placedAt ? CarbonImmutable::parse($placedAt) : $existing?->placed_at,
                'confirmed_at' => isset($o['confirmed_at']) ? CarbonImmutable::parse($o['confirmed_at']) : $existing?->confirmed_at,
                'delivered_at' => isset($o['delivered_at']) ? CarbonImmutable::parse($o['delivered_at']) : $existing?->delivered_at,
                'cancelled_at' => isset($o['cancelled_at']) ? CarbonImmutable::parse($o['cancelled_at']) : $existing?->cancelled_at,
                'campaign_ref' => self::pick($o, 'fbc', self::pick($o, 'campaign_ref', $existing?->campaign_ref)),
                'lines' => isset($o['items']) ? array_map(self::line(...), $o['items']) : $existing?->lines,
                'last_event_id' => $eventId ?? $existing?->last_event_id,
                'last_event_at' => $occurredAt ?? $existing?->last_event_at,
                'synced_at' => now(),
            ]);

            // Figures from this business's copies only.
            $customer->forceFill([
                'orders_count' => FactsOrder::where('customer_id', $customer->id)->where('business_id', $customer->business_id)->count(),
                'lifetime_value' => FactsOrder::where('customer_id', $customer->id)->where('business_id', $customer->business_id)->where('status', '!=', 'cancelled')->sum('gross'),
            ])->save();

            return $fact;
        });
    }

    /**
     * Reads one key with the three-way distinction a full-state payload needs.
     *
     * `??` cannot make it: it treats an ABSENT key and an EXPLICIT null identically, so a shop that
     * says "this order genuinely has no district now" is indistinguishable from a shop that simply
     * did not mention district. In a full-state event those mean opposite things, and getting them
     * backwards either strands stale data or erases good data.
     *
     *  - key absent        -> keep what HQ already holds (the shop is not talking about this field)
     *  - key present, null -> the shop is asserting emptiness; store null
     *  - key present, 0    -> zero is a value, not a missing one; store 0
     */
    private static function pick(array $payload, string $key, mixed $existing): mixed
    {
        return array_key_exists($key, $payload) ? $payload[$key] : $existing;
    }

    /**
     * One order line, from whichever shape the shop used.
     *
     * The two live paths do not agree on key names: the admin API's OrderResource sends
     * `unit_price`/`total`, while the `order.created` webhook listener sends `price` and no line
     * total at all. Reading only the first pair stored every webhook-delivered line at 0.00 and
     * nothing failed, because a zero is a perfectly valid price. Accept both, and derive the
     * total from qty x unit price when the shop did not send one.
     */
    private static function line(array $i): array
    {
        $qty = (int) ($i['qty'] ?? $i['quantity'] ?? 1);
        $unitPrice = (float) ($i['unit_price'] ?? $i['price'] ?? 0);
        $total = isset($i['total']) || isset($i['line_total'])
            ? (float) ($i['total'] ?? $i['line_total'])
            : round($qty * $unitPrice, 2);

        return [
            'sku' => $i['sku'] ?? null,
            'name' => $i['product_name'] ?? $i['name'] ?? null,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'total' => $total,
            'variant_id' => $i['variant_id'] ?? null,
            'combo_id' => $i['combo_id'] ?? null,
        ];
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
