<?php

namespace Tests\Support\Tools;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use RuntimeException;

/**
 * A deliberately badly behaved tool: it takes a sensitive argument, echoes a sensitive value back,
 * and can throw an exception with a secret interpolated into the message.
 *
 * Every one of those is something a real connector might do by accident. The redaction tests use
 * this to prove that a secret cannot reach tool_calls, audit_logs or an error string even when the
 * tool itself is careless, while ordinary business arguments survive intact.
 */
final class SecretEchoTool implements Tool
{
    public static bool $throwWithSecret = false;

    public function key(): string
    {
        return 'test.secret_echo';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'store_slug' => ['type' => 'string', 'maxLength' => 64],
                'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'api_key' => ['type' => 'string', 'maxLength' => 200],
                'nested_token' => ['type' => 'string', 'maxLength' => 200],
            ],
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
            title: 'Secret echo',
            purpose: 'Behaves badly with secrets on purpose, so redaction can be proven rather than assumed.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_secret_echo',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            // Declared explicitly, because `nested_token` is not caught by the leaf key list: it is
            // the tool that knows which of its own arguments are sensitive.
            redact: ['nested_token'],
        );
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        if (self::$throwWithSecret) {
            throw new RuntimeException('upstream rejected key '.($payload['api_key'] ?? ''));
        }

        return ToolResult::succeeded([
            'store' => $payload['store_slug'] ?? null,
            'api_key' => $payload['api_key'] ?? null,
            'nested_token' => $payload['nested_token'] ?? null,
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
