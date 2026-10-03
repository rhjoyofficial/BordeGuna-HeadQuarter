<?php

namespace App\Tools\Erp;

use App\Erp\ErpPlatform;
use App\Tools\ToolResult;

/**
 * The one product a SKU names in one business, read live from the ERP (docs/81 C.5.3). The ERP has
 * no variants, so a SKU is the variant; the answer says so rather than leaving it to be assumed.
 */
final class LookupSkuTool extends ErpOrderReadTool
{
    public function key(): string
    {
        return 'erp.lookup_sku';
    }

    protected function title(): string
    {
        return 'Look up an ERP SKU';
    }

    protected function purpose(): string
    {
        return 'Names the one product a SKU identifies in one business\'s ERP, or says there is none. The ERP has no variants: '
            .'a SKU names exactly one product. Read-only.';
    }

    protected function arguments(): array
    {
        return ['sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]];
    }

    protected function requiredArguments(): array
    {
        return ['sku'];
    }

    protected function outputProperties(): array
    {
        return [
            'found' => ['type' => 'boolean'],
            'product' => ['type' => ['object', 'null']],
            'variant_model' => ['type' => 'string'],
            'reason' => ['type' => ['string', 'null']],
        ];
    }

    protected function read(ErpPlatform $erp, array $payload): array|ToolResult
    {
        $read = $erp->lookupSku((string) $payload['sku']);
        $product = $read->found ? array_diff_key($read->data, ['variant_model' => true, 'variant_note' => true]) : null;

        return [$read, [
            'found' => $read->found,
            'product' => $product,
            'variant_model' => (string) ($read->data['variant_model'] ?? 'none'),
            'reason' => $read->found ? null : $read->message,
        ]];
    }
}
