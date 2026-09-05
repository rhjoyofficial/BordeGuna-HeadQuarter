<?php

namespace App\Scheduling\Tools;

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
