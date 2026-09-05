<?php

namespace App\Scheduling\Tools\Erp;

use App\Erp\ErpConnector;

final class PollErpOverviewTool extends PollErpTool
{
    public function key(): string
    {
        return 'erp.poll_overview';
    }

    protected function kind(): string
    {
        return 'overview';
    }

    protected function poll(ErpConnector $erp): array
    {
        $overview = $erp->overview();

        return ['payload' => $overview->toArray(), 'as_of' => $overview->asOf];
    }
}
