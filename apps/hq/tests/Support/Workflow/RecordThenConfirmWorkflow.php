<?php

namespace Tests\Support\Workflow;

use App\Workflow\Step;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowState;

/** Tests only: one R3 effect, then a plain named signal. Used for the crash, uncertain and replay cases. */
final class RecordThenConfirmWorkflow extends WorkflowDefinition
{
    public const TYPE = 'test.record_then_confirm';

    public function type(): string
    {
        return self::TYPE;
    }

    public function steps(): array
    {
        return [
            Step::tool('record', 'test.wf_record', fn (WorkflowState $s) => ['text' => (string) $s->input('note')])->retries(2, 10),
            Step::awaitSignal('confirm', 'confirmed', timeoutSeconds: 3600),
        ];
    }
}
