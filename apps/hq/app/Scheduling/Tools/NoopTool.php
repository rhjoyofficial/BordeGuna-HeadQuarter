<?php

namespace App\Scheduling\Tools;

use Illuminate\Support\Facades\Log;

/**
 * Harmless tool for verifying the scheduler end to end without any external call.
 * Accepts an optional `message` and echoes it back with the context it ran under.
 */
final class NoopTool implements Tool
{
    public function key(): string
    {
        return 'noop';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'maxLength' => 500],
                'sleep_ms' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5000],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        if (($payload['sleep_ms'] ?? 0) > 0) {
            usleep((int) $payload['sleep_ms'] * 1000);
        }

        Log::info('noop tool executed', ['tool' => $this->key(), 'idempotency_key' => $context->idempotencyKey, 'attempt' => $context->attempt]);

        return ToolResult::succeeded([
            'message' => $payload['message'] ?? 'noop',
            'idempotency_key' => $context->idempotencyKey,
            'attempt' => $context->attempt,
            'executed_at' => now()->toIso8601String(),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // nothing outside HQ to look up
    }
}
