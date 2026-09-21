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
 * An R4 tool an agent MAY execute, which no registered tool is today.
 *
 * It exists so the approval an AGENT parks can be tested at all (docs/81 C.2): production has no
 * tool that is both agent-executable and approval-gated, so the requester an agent's approval
 * records was never exercised by anything real. It counts executions, because the point of most
 * tests that use it is that something did NOT run.
 */
final class AgentGatedTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.agent_gated';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['note' => ['type' => 'string', 'maxLength' => 200]],
            'required' => ['note'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['executions' => ['type' => 'integer']]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Agent-gated test tool',
            purpose: 'Stands in for the first approval-gated tool an agent is cleared to propose.',
            riskTier: RiskTier::R4,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_agent_gated',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Write,
            agentExecutable: true,
            idempotent: true,
            idempotencyKeyFrom: ['note'],
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$executions++;

        return ToolResult::succeeded(['executions' => self::$executions]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
