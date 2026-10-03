<?php

namespace App\Tools\Erp;

use App\Erp\ErpPlatform;
use App\Tools\ToolResult;

/**
 * Stock and availability for a SKU, read live from the ERP as the two facts the ERP keeps apart
 * (docs/81 C.5.3): how much is on hand, and whether a named store may sell it right now. Without a
 * store there is no verdict, because the ERP decides availability per store.
 */
final class ReadAvailabilityTool extends ErpOrderReadTool
{
    public function key(): string
    {
        return 'erp.read_availability';
    }

    protected function title(): string
    {
        return 'Read ERP stock and availability';
    }

    protected function purpose(): string
    {
        return 'Reads a SKU\'s stock on hand in one business\'s ERP and, for a named store, whether that store may sell it now '
            .'(published, priced, and the store\'s policy). Stock and availability are separate facts. Read-only.';
    }

    protected function arguments(): array
    {
        return [
            'sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            'store_key' => ['type' => 'string', 'maxLength' => 64],
        ];
    }

    protected function requiredArguments(): array
    {
        return ['sku'];
    }

    protected function outputProperties(): array
    {
        return [
            'found' => ['type' => 'boolean'],
            'stock' => ['type' => ['object', 'null']],
            'availability' => ['type' => ['object', 'null']],
        ];
    }

    protected function read(ErpPlatform $erp, array $payload): array|ToolResult
    {
        $read = $erp->readAvailability((string) $payload['sku'], $payload['store_key'] ?? null);

        if (! $read->found) {
            if ($read->code === 'unknown_store') {
                return ToolResult::failed((string) $read->message);
            }

            return [$read, ['sku' => (string) $payload['sku'], 'found' => false, 'stock' => null, 'availability' => null, 'reason' => $read->message]];
        }

        return [$read, [
            'sku' => (string) ($read->data['sku'] ?? $payload['sku']),
            'found' => true,
            'stock' => $read->data['stock'] ?? null,
            'availability' => $read->data['availability'] ?? null,
        ]];
    }
}
