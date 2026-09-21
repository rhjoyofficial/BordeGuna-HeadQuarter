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
 * An R4 tool that declares a sensitive argument whose key is NOT on the redactor's leaf deny list.
 *
 * It exists so the redaction of an edited approval can be tested at all (docs/81 C.6 item 4): the
 * deny list masks `token` or `password` whatever the tool says, so only a declared path such as
 * `customer_phone` shows whether an edit is redacted with the tool's own paths or without them.
 */
final class RedactingGatedTool implements Tool
{
    public static int $executions = 0;

    public function key(): string
    {
        return 'test.redacting_gated';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note' => ['type' => 'string', 'maxLength' => 200],
                'customer_phone' => ['type' => 'string', 'maxLength' => 32],
            ],
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
            title: 'Redacting gated test tool',
            purpose: 'Declares a sensitive argument path the leaf deny list does not know.',
            riskTier: RiskTier::R4,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_redacting_gated',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Write,
            idempotent: true,
            idempotencyKeyFrom: ['note'],
            redact: ['customer_phone'],
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
