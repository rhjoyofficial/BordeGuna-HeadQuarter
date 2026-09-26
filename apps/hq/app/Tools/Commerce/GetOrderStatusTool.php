<?php

namespace App\Tools\Commerce;

use App\Erp\ErpConnector;
use App\Tools\Erp\ErpOrderReadTool;
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
        return ErpConnector::SALES_ORDERS_CONTRACT;
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

    protected function read(ErpConnector $erp, array $payload): array|ToolResult
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
