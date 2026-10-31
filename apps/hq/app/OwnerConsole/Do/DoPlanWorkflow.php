<?php

namespace App\OwnerConsole\Do;

use App\Workflow\Step;
use App\Workflow\WorkflowDefinition;

/**
 * docs/143 A: the durable record of a Do plan's run on the HQ-native engine (docs/102). It has NO tool step:
 * a listed definition may not (docs/120 item 17). The plan's effectful steps are gateway calls made one at a
 * time by `DoPlanService` as the person who started the plan, each parked on an ordinary approval; this run
 * only holds the plan open until the service says it is finished, so the plan is visible to `inspect()`,
 * survives a restart and is cancelled with the plan.
 */
final class DoPlanWorkflow extends WorkflowDefinition
{
    public const TYPE = 'do.plan';

    public const SIGNAL_FINISHED = 'do.plan_finished';

    /** A plan nobody finishes does not hold a run forever. */
    public const WAIT_SECONDS = 30 * 86400;

    public function type(): string
    {
        return self::TYPE;
    }

    public function correlationKey(array $input): ?string
    {
        return isset($input['plan_uuid']) ? 'do_plan:'.$input['plan_uuid'] : null;
    }

    public function steps(): array
    {
        return [Step::awaitSignal('plan_open', self::SIGNAL_FINISHED, self::WAIT_SECONDS)];
    }
}
