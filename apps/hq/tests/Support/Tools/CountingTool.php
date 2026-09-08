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
 * A tool that counts how many times it actually executed.
 *
 * Proving idempotency needs this. "The second call returned the same output" is satisfied by a
 * deterministic tool that simply ran twice, which is precisely the thing replay is supposed to
 * prevent. Counting executions is the only way to tell the difference between a replay and a
 * coincidence.
 */
final class CountingTool implements Tool
{
    public static int $executions = 0;

    public static ?ToolResult $reconcileWith = null;

    public static function reset(): void
    {
        self::$executions = 0;
        self::$reconcileWith = null;
    }

    public function key(): string
    {
        return 'test.counting';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['label' => ['type' => 'string', 'maxLength' => 32]],
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
            title: 'Counting tool',
            purpose: 'Counts executions so a replay can be told apart from a repeat.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_counting',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$executions++;

        return ToolResult::succeeded(['executions' => self::$executions, 'label' => $payload['label'] ?? null]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return self::$reconcileWith;
    }
}
