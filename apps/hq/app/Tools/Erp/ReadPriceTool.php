<?php

namespace App\Tools\Erp;

use App\Erp\ErpPlatform;
use App\Tools\ToolResult;

/**
 * The price the ERP's one pricing engine answers for a SKU, with the rule that produced it (docs/81
 * C.5.3). For a store, the store's own price group (what its checkout would quote); for nobody, the
 * standard selling price only.
 *
 * ⛔ The price source travels with the number, so no answer can present a price without its rule.
 * "No price in force" is an answer, never a zero and never a guess.
 *
 * ⚠ No customer argument, on purpose: a price agreed with a named customer is their agreement with
 * the business, and naming customers is not a thing this tool does. The ERP endpoint can answer it
 * for a caller holding the customer ability; this tool does not ask.
 */
final class ReadPriceTool extends ErpOrderReadTool
{
    public function key(): string
    {
        return 'erp.read_price';
    }

    protected function title(): string
    {
        return 'Read an ERP price';
    }

    protected function purpose(): string
    {
        return 'Reads the selling price the ERP\'s pricing engine answers for a SKU in one business, at a quantity, for a store '
            .'(its price group) or for nobody (the standard price), and names the rule that produced it. Says so when no price is in force. Read-only.';
    }

    protected function arguments(): array
    {
        return [
            'sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'quantity' => ['type' => 'number', 'minimum' => 0.0001, 'maximum' => 99999999],
            'store_key' => ['type' => 'string', 'maxLength' => 64],
            'on' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 10],
        ];
    }

    protected function requiredArguments(): array
    {
        return ['sku'];
    }

    protected function outputProperties(): array
    {
        return [
            'sku' => ['type' => 'string'],
            'price_in_force' => ['type' => 'boolean'],
            'unit_price' => ['type' => ['string', 'null']],
            'currency' => ['type' => ['string', 'null']],
            'priced_as' => ['type' => ['string', 'null']],
            'price_source' => ['type' => ['object', 'null']],
            'reason_code' => ['type' => ['string', 'null']],
        ];
    }

    protected function read(ErpPlatform $erp, array $payload): array|ToolResult
    {
        $read = $erp->readPrice(
            (string) $payload['sku'],
            self::quantity($payload['quantity'] ?? 1),
            storeKey: $payload['store_key'] ?? null,
            on: $payload['on'] ?? null,
        );

        if (! $read->found) {
            if ($read->code === 'unknown_store') {
                return ToolResult::failed((string) $read->message);
            }

            return [$read, [
                'sku' => (string) $payload['sku'],
                'price_in_force' => false,
                'unit_price' => null,
                'currency' => null,
                'priced_as' => null,
                'price_source' => null,
                'reason_code' => $read->code,
                'reason' => $read->message,
            ]];
        }

        $d = $read->data;

        return [$read, [
            'sku' => (string) ($d['sku'] ?? $payload['sku']),
            'price_in_force' => true,
            'unit_price' => $d['unit_price'] ?? null,
            'currency' => $d['currency'] ?? null,
            'basis' => $d['basis'] ?? null,
            'quantity' => $d['quantity'] ?? null,
            'on' => $d['on'] ?? null,
            'priced_as' => $d['priced_as'] ?? null,
            'store' => $d['store'] ?? null,
            'mrp' => $d['mrp'] ?? null,
            'mrp_note' => $d['mrp_note'] ?? null,
            'price_source' => $d['price_source'] ?? null,
            'promotions_applied_to_sales_orders' => $d['promotions_applied_to_sales_orders'] ?? [],
            'promotions_note' => $d['promotions_note'] ?? null,
            'tier_note' => $d['tier_note'] ?? null,
            'reason_code' => null,
        ]];
    }

    /** A quantity as the ERP reads it: a plain decimal string, never a float's exponent form. */
    private static function quantity(mixed $value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $text === '' || $text === '0' ? '1' : $text;
    }
}
