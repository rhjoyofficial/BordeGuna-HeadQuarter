<?php

namespace Tests\Support\Tools;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Scheduling\Tools\Tool;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolResult;

/**
 * An R4 tool: an external side effect that could be corrected, but not one HQ may take on its own.
 *
 * Nothing like this is enabled in production in Phase 2. It exists here so the R4 and R5 half of
 * the risk taxonomy is actually exercised: without it, the rule "every R4 and R5 action requires
 * human approval" would be a sentence in a document with no test behind it.
 *
 * It is also agent-executable, so the tests can prove that an agent is refused for the tier rather
 * than incidentally refused for not being agent-executable.
 */
final class WriteTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.external_write';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'maxLength' => 64]], 'additionalProperties' => false];
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
            title: 'External write',
            purpose: 'Stands in for a tool that changes something outside HQ.',
            riskTier: RiskTier::R4,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_external_write',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Write,
            agentExecutable: true,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$executions++;

        return ToolResult::succeeded(['wrote' => true]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
