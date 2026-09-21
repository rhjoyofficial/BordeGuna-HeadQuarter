<?php

namespace App\Tools\Erp;

use App\Erp\ErpConnector;
use App\Tools\ToolResult;

/**
 * Products by identity, read live from the ERP (docs/81 C.5.3): text in the name or SKU, or an
 * exact SKU, barcode or brand code, active by default. Identity only: no cost under any ability,
 * and `orderable` means only that a sales order accepts the product's type.
 */
final class LookupProductTool extends ErpOrderReadTool
{
    private const MAX_ROWS = 100;

    public function key(): string
    {
        return 'erp.lookup_product';
    }

    protected function title(): string
    {
        return 'Look up ERP products';
    }

    protected function purpose(): string
    {
        return 'Finds products in one business\'s ERP catalogue by name or SKU text, or by exact SKU, barcode or brand code. '
            .'Returns identity only (SKU, name, type, unit, brand, category, whether active and whether a sales order accepts it). Read-only.';
    }

    protected function arguments(): array
    {
        return [
            'query' => ['type' => 'string', 'maxLength' => 100],
            'sku' => ['type' => 'string', 'maxLength' => 64],
            'barcode' => ['type' => 'string', 'maxLength' => 64],
            'brand_code' => ['type' => 'string', 'maxLength' => 64],
            'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'all']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS],
            'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
        ];
    }

    protected function outputProperties(): array
    {
        return [
            'products' => ['type' => 'array'],
            'returned' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'page' => ['type' => 'integer'],
            'more' => ['type' => 'boolean'],
        ];
    }

    protected function read(ErpConnector $erp, array $payload): array|ToolResult
    {
        $read = $erp->lookupProducts([
            'q' => $payload['query'] ?? null,
            'sku' => $payload['sku'] ?? null,
            'barcode' => $payload['barcode'] ?? null,
            'brand' => $payload['brand_code'] ?? null,
            'status' => $payload['status'] ?? null,
        ], (int) ($payload['limit'] ?? 20), (int) ($payload['page'] ?? 1));

        $rows = array_values($read->data);

        return [$read, [
            'products' => $rows,
            'returned' => count($rows),
            'total' => (int) ($read->meta['total'] ?? count($rows)),
            'page' => (int) ($read->meta['page'] ?? 1),
            // A page is not the catalogue: say when there is more, so a count is never read off one page.
            'more' => ($read->links['next'] ?? null) !== null,
            'orderable_means' => $read->meta['orderable_means'] ?? null,
        ]];
    }
}
