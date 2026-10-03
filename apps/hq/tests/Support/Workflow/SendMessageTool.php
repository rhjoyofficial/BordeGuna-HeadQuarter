<?php

namespace Tests\Support\Workflow;

use App\Gateway\Descriptor\RiskTier;

/** R4: see WorkflowTestTool. */
final class SendMessageTool extends WorkflowTestTool
{
    public function key(): string
    {
        return 'test.wf_send_message';
    }

    protected function tier(): RiskTier
    {
        return RiskTier::R4;
    }
}
