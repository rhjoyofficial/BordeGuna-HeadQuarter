<?php

namespace App\Tools\Diagnostics;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use RuntimeException;

/**
 * Test-only tool that fails a configurable number of times before succeeding, so retry,
 * failure state and dead-lettering can be exercised without any external system.
 *
 * payload.fail_times (default: always fail) - number of attempts that must fail first.
 * payload.mode = throw|result (default result) - fail by exception or by ToolResult::failed.
 */
final class FailingTool implements Tool
{
    public function key(): string
    {
        return 'test.fail';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Deliberate failure',
            purpose: 'Exercises retry, failure state and dead-lettering without any external system.',
            riskTier: RiskTier::R0,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.test_fail',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            agentExecutable: false,
            // It exists to fail, so a retry is the point rather than an accident.
            idempotent: true,
            outputSchema: ['type' => 'object', 'properties' => ['recovered_on_attempt' => ['type' => 'integer']]],
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fail_times' => ['type' => 'integer', 'minimum' => 0],
                'mode' => ['type' => 'string', 'enum' => ['throw', 'result']],
            ],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return $this->descriptor()->outputSchema;
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $failTimes = array_key_exists('fail_times', $payload) ? (int) $payload['fail_times'] : PHP_INT_MAX;

        if ($context->attempt <= $failTimes) {
            if (($payload['mode'] ?? 'result') === 'throw') {
                throw new RuntimeException("test.fail threw on attempt {$context->attempt}");
            }

            return ToolResult::failed("test.fail failed on attempt {$context->attempt}");
        }

        return ToolResult::succeeded(['recovered_on_attempt' => $context->attempt]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
