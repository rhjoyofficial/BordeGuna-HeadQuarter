<?php

namespace App\Facts;

use App\Context\Provenance;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\ErpConnector;
use App\Gateway\Approval\CanonicalPayload;
use App\Models\FactsOrder;
use App\Models\Store;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the ERP said about an order HQ placed, kept as a read-side fact the moment it was read back
 * (docs/90 A.5, docs/79 C.17): after `take_order`'s read-back, after a status read, after a
 * cancellation's read-back. The sequence is the one ChatGPT set: the ERP's answer, validated by the
 * connector against the orders contract; then this row; then the fact is readable like any shop
 * order; and reconciliation judges later whether the row and the ERP still agree.
 *
 * ⛔ `facts_orders` IS NOT THE SYSTEM OF RECORD. The ERP is. This class copies what the ERP said, with
 *    the ERP's own `as_of`, and never writes anything back to the ERP; a row that disagrees with a
 *    later read-back is replaced by the later reading (a copy with an age, `docs/79` C.14), and a
 *    row that disagrees with a PROPOSAL is exactly what it should be: the ERP's figures, which the
 *    tool reports as uncertain. Nothing here repairs a disagreement; it only records the ERP's side.
 *
 * ⭐ Idempotent on what was read: the digest of the ERP's `data` is stored, and a read-back with the
 *    same digest writes nothing (not even `synced_at`). A read-back whose `as_of` is older than the
 *    one already applied is refused, so a slow reply never overwrites a newer one. The order's fact
 *    is keyed like a shop's: the store and the reference the customer quotes (`shop_reference`).
 *
 * The row keeps the same shape as a shop fact so every reader of `facts_orders` (customer 360, the
 * integrity checker, the ads contribution report) sees an HQ order like any other, `source: hq_ai`,
 * and the ERP evidence sits in its own columns beside it.
 */
final class ErpOrderReadBackFact
{
    public const SOURCE = 'hq_ai';

    public const SOURCE_SYSTEM = 'erp';

    /** ERP order statuses as the shop-shaped fact reads them; the ERP's own word is kept in `erp_status`. */
    private const STATUS = [
        'open' => 'pending',
        'partially_delivered' => 'processing',
        'delivered' => 'delivered',
        'closed' => 'delivered',
        'cancelled' => 'cancelled',
    ];

    public function __construct(private readonly FactsOrderUpserter $facts) {}

    /**
     * Records the ERP's answer about one order as a fact of `$store`. Returns the row, written or
     * left exactly as it was.
     *
     * @throws InvalidArgumentException when the answer does not name an order (fail closed: no row)
     */
    public function record(Store $store, ErpOrderRead $read, ?string $correlationId = null): FactsOrder
    {
        $data = $this->validated($read);
        $reference = (string) $data['shop_reference'];
        $digest = hash('sha256', CanonicalPayload::encode($data));
        // ⚠ In UTC before it is stored: Eloquent writes a date's wall clock without its offset, so a
        //    +06:00 instant kept in Dhaka time would land six hours late in a timestamptz column and
        //    every later read-back would read as "older" than it. The instant is what is compared.
        $asOf = ($read->asOf() === null ? CarbonImmutable::now() : CarbonImmutable::parse($read->asOf()))->utc();

        $existing = FactsOrder::query()->where('store_id', $store->id)->where('order_ref', $reference)->first();
        if ($existing !== null) {
            // The same answer again: nothing new was learned, nothing is written.
            if ($existing->read_back_digest === $digest) {
                return $existing;
            }
            // An older answer than the one already applied: a slow reply never beats a newer one.
            if ($existing->read_back_at !== null && $asOf->lt(CarbonImmutable::instance($existing->read_back_at))) {
                return $existing;
            }
        }

        $fact = $this->facts->upsertFromShopPayload($store, $this->asShopPayload($data, $asOf), 'erp-read-back:'.substr($digest, 0, 16), $asOf);

        $fact->forceFill([
            'source_system' => self::SOURCE_SYSTEM,
            'provenance' => Provenance::Business->value,
            'erp_order_id' => isset($data['id']) ? (int) $data['id'] : null,
            'erp_order_number' => (string) $data['order_number'],
            'erp_status' => (string) $data['status'],
            'erp_approval_status' => isset($data['approval_status']) ? (string) $data['approval_status'] : null,
            'hq_key' => isset($data['hq']['idempotency_key']) ? (string) $data['hq']['idempotency_key'] : null,
            'correlation_id' => $correlationId,
            'read_back_digest' => $digest,
            'read_back_at' => $asOf,
        ])->save();

        return $fact;
    }

    /**
     * The ERP's answer must name an order (the connector already requires `status` and
     * `order_number` and the orders contract); a body that does not is never written as a fact.
     *
     * @return array<string, mixed>
     */
    private function validated(ErpOrderRead $read): array
    {
        $data = $read->data;
        if (! $read->found
            || ! is_string($data['status'] ?? null) || $data['status'] === ''
            || ! is_string($data['order_number'] ?? null) || $data['order_number'] === ''
            || ! is_string($data['shop_reference'] ?? null) || $data['shop_reference'] === '') {
            throw new InvalidArgumentException('The ERP answer names no order (status, order_number and shop_reference are required); no fact is written from it.');
        }
        if (($read->meta['contract'] ?? null) !== ErpConnector::SALES_ORDERS_CONTRACT) {
            throw new InvalidArgumentException('The ERP answer is not on the orders contract; no fact is written from it.');
        }

        return $data;
    }

    /**
     * The ERP order in the shape the shop upserter reads, so one writer keeps the one discipline
     * (status never regresses, the customer resolved the same way, the totals in the same columns).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function asShopPayload(array $data, CarbonImmutable $asOf): array
    {
        $status = self::STATUS[(string) $data['status']] ?? 'pending';
        if ($status === 'pending' && ($data['deliverable'] ?? false) === true) {
            $status = 'confirmed';
        }

        $payload = [
            'order_number' => (string) $data['shop_reference'],
            'source' => self::SOURCE,
            'order_status' => $status,
            'payment_method' => $data['payment_method'] ?? null,
            'subtotal' => $data['goods_total'] ?? 0,
            'discount_total' => $data['order_discount'] ?? 0,
            'shipping_cost' => $data['delivery_charge'] ?? 0,
            'grand_total' => $data['agreed_total'] ?? 0,
            'customer_name' => $data['recipient']['name'] ?? null,
            'customer_phone' => $data['recipient']['phone'] ?? null,
            'shipping_address' => [
                'district' => $data['destination']['district'] ?? null,
                'thana' => $data['destination']['thana'] ?? null,
            ],
            'placed_at' => $data['ordered_at'] ?? null,
            'items' => array_map(fn (array $l) => [
                'sku' => $l['sku'] ?? null,
                'quantity' => $l['quantity'] ?? 1,
                'unit_price' => $l['unit_price'] ?? 0,
            ], array_values((array) ($data['lines'] ?? []))),
        ];
        if ($status === 'cancelled') {
            $payload['cancelled_at'] = $asOf->toIso8601String();
        }
        if ($status === 'confirmed') {
            $payload['confirmed_at'] = $asOf->toIso8601String();
        }

        return $payload;
    }
}
