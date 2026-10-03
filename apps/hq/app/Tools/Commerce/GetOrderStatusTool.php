<?php

namespace App\Tools\Commerce;

use App\Erp\Dto\ErpOrderRead;
use App\Erp\ErpPlatform;
use App\Erp\ErpRegistry;
use App\Facts\ErpOrderReadBackFact;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Tools\Erp\ErpOrderReadTool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * `commerce.get_order_status`: one order HQ placed, read live from the ERP (docs/88 A point 3,
 * docs/79 D and C.14). Order status is never memory: the ERP's status sits beside its approval state.
 *
 * By the reference a customer quotes (the shop reference) or the ERP order number, or by the key HQ
 * sent when it asked for the order (the reconcile path). ⛔ Only an order HQ placed is reachable: an
 * order a colleague typed in answers "none", exactly as the ERP does. "None" is an answer.
 *
 * The output names no person: no recipient, no address, no customer id. What it carries is the
 * status, the ERP approval status, whether goods may leave, the totals, the promise, the dates,
 * whether HQ asked for a cancellation, and the HQ keys the order was made under.
 */
final class GetOrderStatusTool extends ErpOrderReadTool
{
    public const KEY = 'commerce.get_order_status';

    public function __construct(ErpRegistry $registry, private readonly ErpOrderReadBackFact $readBack)
    {
        parent::__construct($registry);
    }

    public function key(): string
    {
        return self::KEY;
    }

    protected function title(): string
    {
        return 'Read an order\'s status from the ERP';
    }

    protected function purpose(): string
    {
        return 'Reads one order HQ placed, live from the ERP, by the reference the customer quotes, the ERP order number, or the '
            .'HQ key it was made under: its status, whether a person in the ERP has approved it, whether goods may leave, its '
            .'totals and promise, and whether HQ asked to cancel it. Says "none" for an order HQ did not place. Read-only.';
    }

    protected function contract(): string
    {
        return ErpPlatform::SALES_ORDERS_CONTRACT;
    }

    protected function arguments(): array
    {
        return [
            'reference' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'hq_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
        ];
    }

    protected function outputProperties(): array
    {
        return [
            'found' => ['type' => 'boolean'],
            'reason' => ['type' => ['string', 'null']],
            'order_number' => ['type' => ['string', 'null']],
            'shop_reference' => ['type' => ['string', 'null']],
            'status' => ['type' => ['string', 'null']],
            'approval_status' => ['type' => ['string', 'null']],
            'deliverable' => ['type' => ['boolean', 'null']],
            'payment_method' => ['type' => ['string', 'null']],
            'goods_total' => ['type' => ['string', 'null']],
            'delivery_charge' => ['type' => ['string', 'null']],
            'agreed_total' => ['type' => ['string', 'null']],
            'delivery_promise' => ['type' => ['string', 'null']],
            'expected_date' => ['type' => ['string', 'null']],
            'ordered_at' => ['type' => ['string', 'null']],
            'lines' => ['type' => 'array'],
            'cancellation_requested' => ['type' => ['boolean', 'null']],
            'hq' => ['type' => ['object', 'null']],
            'effects' => ['type' => ['object', 'null']],
            'next_step' => ['type' => ['string', 'null']],
        ];
    }

    /**
     * docs/90 A.5: a status read is a read-back too. The order's fact row already exists from
     * `take_order` (keyed by the store and the reference the customer quotes); what the ERP says now
     * replaces what it said then, with its own `as_of`, or writes nothing when nothing changed.
     *
     * ⚠ An order with no fact row is left as it is: the ERP answer names its store by the ERP's key,
     * and HQ keeps no map from an ERP store key to an HQ store, so the row cannot be placed. That is
     * an order HQ did not place through `take_order` on this build, and it is recorded in docs/90 C.
     */
    protected function afterRead(Business $business, ErpOrderRead $read, ToolContext $context): void
    {
        if (! $read->found || ! is_string($read->data['shop_reference'] ?? null)) {
            return;
        }
        $fact = FactsOrder::query()->where('business_id', $business->id)->where('order_ref', $read->data['shop_reference'])
            ->where('source_system', ErpOrderReadBackFact::SOURCE_SYSTEM)->with('store')->first();
        if ($fact === null || $fact->store === null) {
            return;
        }

        $this->readBack->record($fact->store, $read, $context->correlationId);
    }

    protected function read(ErpPlatform $erp, array $payload): array|ToolResult
    {
        $reference = is_string($payload['reference'] ?? null) ? trim($payload['reference']) : '';
        $key = is_string($payload['hq_key'] ?? null) ? trim($payload['hq_key']) : '';
        if (($reference === '') === ($key === '')) {
            return ToolResult::failed('Name exactly one of [reference] (the customer\'s reference or the ERP order number) and [hq_key] (the key HQ made the order under).');
        }

        $read = $reference !== '' ? $erp->readSalesOrder($reference) : $erp->readSalesOrderByKey($key);
        if (! $read->found) {
            return [$read, ['found' => false, 'reason' => $read->message, 'lines' => [], 'cancellation_requested' => null]];
        }
        $data = $read->data;

        return [$read, [
            'found' => true,
            'reason' => null,
            'order_number' => $data['order_number'] ?? null,
            'shop_reference' => $data['shop_reference'] ?? null,
            'status' => $data['status'] ?? null,
            'approval_status' => $data['approval_status'] ?? null,
            'deliverable' => $data['deliverable'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'goods_total' => $data['goods_total'] ?? null,
            'delivery_charge' => $data['delivery_charge'] ?? null,
            'agreed_total' => $data['agreed_total'] ?? null,
            'delivery_promise' => $data['delivery_promise'] ?? null,
            'expected_date' => $data['expected_date'] ?? null,
            'ordered_at' => $data['ordered_at'] ?? null,
            'lines' => collect((array) ($data['lines'] ?? []))->map(fn ($l) => [
                'sku' => $l['sku'] ?? null, 'quantity' => $l['quantity'] ?? null, 'quantity_delivered' => $l['quantity_delivered'] ?? null,
                'unit_price' => $l['unit_price'] ?? null,
            ])->values()->all(),
            'cancellation_requested' => (bool) ($data['cancellation']['requested'] ?? false),
            'hq' => is_array($data['hq'] ?? null) ? $data['hq'] : null,
            'effects' => is_array($data['effects'] ?? null) ? $data['effects'] : null,
            'next_step' => $data['next_step'] ?? null,
        ]];
    }
}
