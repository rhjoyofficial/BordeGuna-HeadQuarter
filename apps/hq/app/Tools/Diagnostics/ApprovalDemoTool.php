<?php

namespace App\Tools\Diagnostics;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\AuditLog;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;

/**
 * An R4 tool that exists so the approval mechanism can be proven without a real consequence.
 *
 * ⚠ **Why this exists at all.** M3 has to demonstrate, on production, that a gated action parks,
 * waits for a human, executes exactly once and only when approved. Doing that with a real R4 tool
 * would mean creating or cancelling a customer's order to test a mechanism, which is not an
 * acceptable way to test anything. So this tool is classified R4 and behaves like one in every
 * respect the control plane cares about, while its only effect is to write a note into HQ's own
 * append-only audit log.
 *
 * It is REGISTERED so the tier is reachable, and it is **not agent-executable**: an agent may never
 * run it, exactly like every other tool in Phase 2. In production it is switched OFF at runtime
 * after the acceptance test, so the registry ships with nothing at R4 enabled.
 *
 * It is previewable, which lets the approval card show what would happen before anybody agrees to it.
 */
final class ApprovalDemoTool implements PreviewableTool, Tool
{
    public function key(): string
    {
        return 'demo.request_approval';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note' => ['type' => 'string', 'maxLength' => 200],
                'amount' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000000],
            ],
            'required' => ['note'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'recorded' => ['type' => 'boolean'],
            'note' => ['type' => 'string'],
            'audit_log_id' => ['type' => 'integer'],
        ]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Approval demonstration',
            purpose: 'Proves the approval mechanism end to end. Its only effect is one row in HQ audit log.',
            // R4 in earnest: policy must treat it exactly as it treats a real recoverable external
            // side effect, or the demonstration proves nothing about the real ones.
            riskTier: RiskTier::R4,
            permission: 'hq.tools.diagnostics',
            auditAction: 'tool.demo_request_approval',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            idempotent: true,
            idempotencyKeyFrom: ['note'],
            outputSchema: $this->outputSchema(),
        );
    }

    /** Describes the effect without causing it, which is the whole contract of a preview. */
    public function preview(array $payload, ToolContext $context): array
    {
        return [
            'would_record' => "an audit note reading: {$payload['note']}",
            'amount' => $payload['amount'] ?? null,
            'external_effect' => 'none: this tool writes only to HQ audit log',
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $log = AuditLog::create([
            'occurred_at' => now(),
            'actor_type' => 'system',
            'actor_label' => 'demo.request_approval',
            'action' => 'demo.approved_action_executed',
            'target_type' => 'approval_demo',
            'reason' => 'executed under a human approval',
            'after' => ['note' => $payload['note'], 'amount' => $payload['amount'] ?? null],
            'correlation_id' => $context->correlationId,
        ]);

        return ToolResult::succeeded([
            'recorded' => true,
            'note' => $payload['note'],
            'audit_log_id' => $log->id,
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // nothing outside HQ to look up
    }
}
