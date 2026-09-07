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
        $low = $erp->lowStock();

        // The ERP's own timestamp, not HQ's clock: `as_of` has to mean the same thing here as it
        // does on the overview snapshot, or the column is two different facts under one name.
        return ['payload' => $low->toArray(), 'as_of' => $low->asOf];
    }
}
