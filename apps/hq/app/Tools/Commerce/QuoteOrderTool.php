<?php

namespace App\Tools\Commerce;

use App\Commerce\Orders\TakeOrderShape;
use App\Erp\ErpPlatform;
use App\Gateway\Descriptor\DataClass;
use App\Tools\Erp\ErpOrderReadTool;
use App\Tools\ToolResult;

/**
 * `commerce.quote_order`: what the ERP would charge for an order now, from its one pricing engine
 * (docs/88 A point 3, docs/79 D and C.4). Read-only: the ERP's `effects.written` says false.
 *
 * The quote is the figures a `commerce.take_order` proposal must carry, and the ERP re-checks them
 * at the draft and again at the order; a stale quote is refused there, never repaired. A request the
 * ERP would not take (an unknown SKU, a product not sellable at the store, a district it cannot
 * deliver to) comes back as the ERP's own sentences per field, a failure to report and not retry.
 *
 * ⚠ The destination carries a name and a phone, which the ERP needs (its quote validates them as it
 * would for an order). They are personal data: masked in the recorded arguments, and never in the
 * output, which carries prices, totals and the delivery promise only. R2 and agent-executable as
 * docs/79 D declares (reading a price is not acting); no agent's tool list names it.
 */
final class QuoteOrderTool extends ErpOrderReadTool
{
    public const KEY = 'commerce.quote_order';

    public function key(): string
    {
        return self::KEY;
    }

    protected function title(): string
    {
        return 'Quote an order in the ERP';
    }

    protected function purpose(): string
    {
        return 'Asks the ERP what it would charge for an order at a named store, now: each line\'s price from the one pricing engine, '
            .'whether it is sellable and made to order, the delivery charge for the district, the totals and the delivery promise. '
            .'Writes nothing. The figures are what a take_order proposal must carry; the ERP refuses a proposal whose figures moved.';
    }

    protected function contract(): string
    {
        return ErpPlatform::SALES_ORDERS_CONTRACT;
    }

    protected function dataClass(): DataClass
    {
        return DataClass::Personal;
    }

    protected function redact(): array
    {
        return ['destination.name', 'destination.phone', 'destination.address_line', 'destination.landmark'];
    }

    protected function arguments(): array
    {
        return [
            'erp_store_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'destination' => ['type' => 'object'],
            'lines' => ['type' => 'array'],
        ];
    }

    protected function requiredArguments(): array
    {
        return ['erp_store_key', 'destination', 'lines'];
    }

    protected function outputProperties(): array
    {
        return [
            'store_key' => ['type' => 'string'],
            'lines' => ['type' => 'array'],
            'payment_methods' => ['type' => 'array'],
            'goods_total' => ['type' => 'string'],
            'delivery_charge' => ['type' => 'string'],
            'order_discount' => ['type' => 'string'],
            'agreed_total' => ['type' => 'string'],
            'delivery_promise' => ['type' => ['string', 'null']],
            'quoted_at' => ['type' => ['string', 'null']],
            'written' => ['type' => 'boolean'],
        ];
    }

    protected function read(ErpPlatform $erp, array $payload): array|ToolResult
    {
        if (($problems = TakeOrderShape::quoteProblems($payload)) !== []) {
            return ToolResult::failed('The order cannot be quoted as it stands: '.implode(' ', $problems));
        }

        $read = $erp->quoteSalesOrder([
            'store_key' => (string) $payload['erp_store_key'],
            'destination' => $payload['destination'],
            'lines' => array_values(array_map(fn (array $l) => ['sku' => $l['sku'], 'quantity' => $l['quantity']], $payload['lines'])),
        ]);
        $data = $read->data;

        return [$read, [
            'store_key' => (string) ($data['store']['key'] ?? ''),
            'lines' => collect((array) ($data['lines'] ?? []))->map(fn ($l) => [
                'sku' => $l['sku'] ?? null, 'quantity' => $l['quantity'] ?? null, 'unit_price' => $l['unit_price'] ?? null,
                'line_total' => $l['line_total'] ?? null, 'price_note' => $l['price_note'] ?? null,
                'sellable' => $l['sellable'] ?? null, 'made_to_order' => $l['made_to_order'] ?? null,
            ])->values()->all(),
            'payment_methods' => array_values((array) ($data['payment_methods'] ?? [])),
            'goods_total' => (string) ($data['goods_total'] ?? ''),
            'delivery_charge' => (string) ($data['delivery_charge'] ?? ''),
            'order_discount' => (string) ($data['order_discount'] ?? ''),
            'agreed_total' => (string) ($data['agreed_total'] ?? ''),
            'delivery_promise' => $data['delivery_promise'] ?? null,
            'quoted_at' => $data['quoted_at'] ?? null,
            'written' => (bool) ($data['effects']['written'] ?? false),
        ]];
    }
}
