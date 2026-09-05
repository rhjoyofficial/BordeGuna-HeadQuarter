<?php

namespace App\Scheduling\Tools\Erp;

use App\Erp\ErpConnector;

final class PollErpLowStockTool extends PollErpTool
{
    public function key(): string
    {
        return 'erp.poll_low_stock';
    }

    protected function kind(): string
    {
        return 'low_stock';
    }

    protected function poll(ErpConnector $erp): array
    {
        $lines = $erp->lowStock();

        return ['payload' => array_map(fn ($l) => $l->toArray(), $lines), 'as_of' => now()->toIso8601String()];
    }
}
