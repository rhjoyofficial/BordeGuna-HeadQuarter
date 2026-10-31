<?php

namespace Tests\Support\Tools;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/** docs/143 B and C: an R4, customer-facing stand-in with no consent requirement, so a batch can really execute. */
final class CustomerMessageTool implements Tool
{
    /** @var list<string> */
    public static array $sent = [];

    public static bool $requesterMayDecide = true;

    public static RiskTier $tier = RiskTier::R4;

    public function key(): string
    {
        return 'test.customer_message';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string', 'maxLength' => 200]], 'required' => ['text'], 'additionalProperties' => false];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(), version: '1.0.0', title: 'Customer message', purpose: 'Stands in for a customer-facing send.',
            riskTier: self::$tier, permission: 'hq.tools.diagnostics', auditAction: 'tool.test_customer_message', scope: ToolScope::Hq,
            readWrite: ReadWrite::Write, agentExecutable: false, customerFacing: true, requesterMayDecide: self::$requesterMayDecide,
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        self::$sent[] = (string) $payload['text'];

        return ToolResult::succeeded(['sent' => true]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
