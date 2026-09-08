<?php

namespace Tests\Support\Tools;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * Fails on every attempt, whatever the arguments.
 *
 * The registered `test.fail` tool recovers after a configurable number of attempts, which is what
 * makes it useful for exercising retry. This one never recovers, which is what makes it useful for
 * exercising dead-lettering through the gateway without waiting for a retry schedule.
 */
final class AlwaysFailingTool implements Tool
{
    public function key(): string
    {
        return 'test.always_fail';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
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
            title: 'Always fails',
            purpose: 'Exercises dead-lettering through the gateway.',
            riskTier: RiskTier::R0,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_always_fail',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        return ToolResult::failed('this tool always fails, on purpose');
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
