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
 * A tool that IS a publication (docs/88 point 12), for proving that the refused part of a mixed
 * request cannot run: whoever proposes it, the runner and the gateway both refuse it. It counts
 * executions, so "refused" can never mean "ran and then said no".
 */
final class PublishTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.publish';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string', 'maxLength' => 500]],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['published' => ['type' => 'boolean']]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Publish (test)',
            purpose: 'Stands for any publication tool a later phase registers.',
            riskTier: RiskTier::R2,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_publish',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            roleAction: 'publication',
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$executions++;

        return ToolResult::succeeded(['published' => true]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
