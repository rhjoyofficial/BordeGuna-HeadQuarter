<?php

namespace Tests\Support\Workflow;

use App\Gateway\Descriptor\RiskTier;

/** R3: see WorkflowTestTool. */
final class RecordNoteTool extends WorkflowTestTool
{
    public function key(): string
    {
        return 'test.wf_record';
    }

    protected function tier(): RiskTier
    {
        return RiskTier::R3;
    }
}
