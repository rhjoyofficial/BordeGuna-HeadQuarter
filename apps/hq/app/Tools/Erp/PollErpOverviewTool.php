<?php

namespace App\Tools\Erp;

use App\Erp\ErpPlatform;
use App\Gateway\Descriptor\ToolDescriptor;

final class PollErpOverviewTool extends PollErpTool
{
    public function key(): string
    {
        return 'erp.poll_overview';
    }

    public function descriptor(): ToolDescriptor
    {
        return $this->baseDescriptor(
            'ERP overview snapshot',
            'Reads the ERP cross-module overview for one business and replaces the HQ snapshot of it.',
        );
    }

    protected function kind(): string
    {
        return 'overview';
    }

    protected function poll(ErpPlatform $erp): array
    {
        $overview = $erp->overview();

        return ['payload' => $overview->toArray(), 'as_of' => $overview->asOf];
    }
}
