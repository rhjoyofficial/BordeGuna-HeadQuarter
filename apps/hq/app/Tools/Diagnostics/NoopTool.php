<?php

namespace App\Tools\Diagnostics;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
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

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'No-op',
            purpose: 'Proves the scheduler and the gateway run end to end without touching anything outside HQ.',
            // R0: it logs and it can sleep. No I/O, nothing read, nothing changed.
            riskTier: RiskTier::R0,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.noop',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            // A diagnostic is not a business capability. No agent has a reason to run one, so none may.
            agentExecutable: false,
            outputSchema: ['type' => 'object', 'properties' => [
                'message' => ['type' => 'string'],
                'idempotency_key' => ['type' => 'string'],
                'attempt' => ['type' => 'integer'],
                'executed_at' => ['type' => 'string'],
                'event_id' => ['type' => 'string'],
            ]],
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'maxLength' => 500],
                'sleep_ms' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5000],
                // An event-triggered job hands the tool the event that started it (`EventTriggeredJobs`).
                // Declared here so the scheduler's event path can be proven end to end on a no-op.
                'event' => ['type' => 'object'],
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
        if (($payload['sleep_ms'] ?? 0) > 0) {
            usleep((int) $payload['sleep_ms'] * 1000);
        }

        Log::info('noop tool executed', ['tool' => $this->key(), 'idempotency_key' => $context->idempotencyKey, 'attempt' => $context->attempt]);

        return ToolResult::succeeded([
            'message' => $payload['message'] ?? 'noop',
            'idempotency_key' => $context->idempotencyKey,
            'attempt' => $context->attempt,
            'executed_at' => now()->toIso8601String(),
            'event_id' => is_string($payload['event']['id'] ?? null) ? $payload['event']['id'] : null,
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // nothing outside HQ to look up
    }
}
