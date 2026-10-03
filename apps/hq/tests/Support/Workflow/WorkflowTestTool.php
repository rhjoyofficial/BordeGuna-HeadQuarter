<?php

namespace Tests\Support\Workflow;

use App\Commerce\Exceptions\Uncertain;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * The three workflow test tools share one body: each lands its effect on the FarSide under the
 * gateway's idempotency key, follows the FarSide's plan for planned faults, and reconciles by looking
 * its own key up there, as a real connector would ask the provider.
 */
abstract class WorkflowTestTool implements Tool
{
    abstract protected function tier(): RiskTier;

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['order_id' => ['type' => 'string', 'maxLength' => 32], 'text' => ['type' => 'string', 'maxLength' => 200]],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Workflow test tool '.$this->key(),
            purpose: 'Stands in for an effect a workflow step has, in tests only.',
            riskTier: $this->tier(),
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_workflow',
            scope: ToolScope::Hq,
            readWrite: $this->tier() === RiskTier::R1 ? ReadWrite::Read : ReadWrite::Write,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $fault = FarSide::next($this->key());
        if ($fault === 'fail') {
            return ToolResult::failed('the far side said 503 (planned)');
        }

        FarSide::$effects[] = ['tool' => $this->key(), 'key' => $context->idempotencyKey, 'args' => $payload];

        return match ($fault) {
            'uncertain' => throw new Uncertain('timed out after sending (planned)'),
            // Output the recorder cannot store: the gateway's completion write throws AFTER the effect
            // landed, leaving the `started` row and `running` step a killed worker leaves.
            'unrecordable' => ToolResult::succeeded(['ratio' => INF]),
            default => ToolResult::succeeded(['order_id' => $payload['order_id'] ?? null, 'status' => 'delivered', 'ref' => 'far-'.count(FarSide::$effects)]),
        };
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return FarSide::has($context->idempotencyKey)
            ? ToolResult::succeeded(['reconciled' => true, 'status' => 'delivered'])
            : null;
    }
}
