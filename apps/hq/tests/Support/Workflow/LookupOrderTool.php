<?php

namespace Tests\Support\Workflow;

use App\Gateway\Descriptor\RiskTier;

/** R1: see WorkflowTestTool. */
final class LookupOrderTool extends WorkflowTestTool
{
    public function key(): string
    {
        return 'test.wf_lookup';
    }

    protected function tier(): RiskTier
    {
        return RiskTier::R1;
    }
}
