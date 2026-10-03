<?php

namespace Tests\Support\Workflow;

use App\Workflow\Step;
use App\Workflow\WorkflowDefinition;
use App\Workflow\WorkflowState;

/**
 * The docs/102 example, used ONLY in tests: wait for the order's delivery event (14 days at most),
 * wait N days, look the order up, then propose a follow-up message that a person must approve.
 * If the delivery never comes, everything after the wait is skipped and the run completes.
 */
final class OrderFollowUpWorkflow extends WorkflowDefinition
{
    public const TYPE = 'test.order_follow_up';

    public function type(): string
    {
        return self::TYPE;
    }

    public function listensTo(): array
    {
        return ['test.order_delivered'];
    }

    public function correlationKey(array $input): ?string
    {
        return 'order:'.$input['order_id'];
    }

    public function steps(): array
    {
        $delivered = fn (WorkflowState $s) => $s->status('await_delivery') === 'succeeded';

        return [
            Step::awaitEvent('await_delivery', 'test.order_delivered', timeoutSeconds: 14 * 86400),
            Step::sleep('cool_off', fn (WorkflowState $s) => (int) $s->input('wait_days', 3) * 86400)->when($delivered),
            Step::tool('check_order', 'test.wf_lookup', fn (WorkflowState $s) => ['order_id' => (string) $s->input('order_id')])
                ->retries(3, 30)->when($delivered),
            Step::tool('propose_message', 'test.wf_send_message', fn (WorkflowState $s) => [
                'order_id' => (string) $s->input('order_id'),
                'text' => 'Your order was '.$s->output('check_order', 'status').'. How was it?',
            ])->when($delivered),
        ];
    }
}
