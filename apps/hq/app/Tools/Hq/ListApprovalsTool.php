<?php

namespace App\Tools\Hq;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Approval;
use App\Models\Business;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * What is waiting for a human decision (docs/48 D.6).
 *
 * Half of "what needs attention today" is things that are already stopped and have somebody's name
 * on them. This is that half.
 *
 * ⛔ **It returns the approval's shape, never its payload.** An approval's `payload` is the exact
 * arguments of a parked call, and a parked call is parked precisely because it was consequential -
 * it can name a customer, an amount, an address. The agent needs to know that a decision is waiting,
 * on what tool, for how long. It does not need the arguments, and putting them into a model prompt
 * would move personal data into a place V.6 says it does not go.
 */
final class ListApprovalsTool implements Tool
{
    private const MAX = 50;

    public function key(): string
    {
        return 'hq.list_approvals';
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'List approvals',
            purpose: 'Lists approval requests and how long each has been waiting. Returns the shape of each request, never its payload.',
            riskTier: RiskTier::R1,
            permission: 'hq.approvals.view',
            auditAction: 'tool.hq_list_approvals',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            idempotent: true,
            timeoutSeconds: 20,
            outputSchema: $this->outputSchema(),
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'expired', 'cancelled', 'any']],
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX],
            ],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'approvals' => ['type' => 'array'],
            'pending_count' => ['type' => 'integer'],
            'overdue_count' => ['type' => 'integer'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');
        $now = CarbonImmutable::now($timezone);
        $status = (string) ($payload['status'] ?? 'pending');

        $query = Approval::query();
        $context->restrictBusinesses($query, 'business_id');

        if ($status !== 'any') {
            $query->where('status', $status);
        }

        if (($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
            if ($businessId === null) {
                return ToolResult::failed("HQ has no business with the slug [{$slug}].");
            }
            $query->where('business_id', $businessId);
        }

        $rows = $query->latest('id')->limit(min((int) ($payload['limit'] ?? 25), self::MAX))->get();

        $approvals = [];
        $overdue = 0;

        foreach ($rows as $approval) {
            $due = $approval->sla_due_at === null ? null : CarbonImmutable::parse($approval->sla_due_at)->setTimezone($timezone);
            $isOverdue = $approval->status === 'pending' && $due !== null && $due->lessThan($now);
            $overdue += $isOverdue ? 1 : 0;

            $approvals[] = [
                'uuid' => $approval->uuid,
                'tool' => $approval->tool_key,
                'risk_tier' => $approval->risk_tier,
                'status' => $approval->status,
                'reason' => $approval->reason,
                'requested_by' => $approval->agent_key ?? ($approval->requested_by_user_id === null ? 'system' : 'a person'),
                'waiting_since' => $approval->created_at?->setTimezone($timezone)->toIso8601String(),
                'waiting_hours' => $approval->created_at === null ? null : (int) $approval->created_at->diffInHours($now),
                'due_at' => $due?->toIso8601String(),
                'overdue' => $isOverdue,
            ];
        }

        return ToolResult::succeeded([
            'approvals' => $approvals,
            // docs/136: a count is a disclosure, so it is narrowed to the caller's businesses like the list.
            'pending_count' => $context->restrictBusinesses(Approval::query(), 'business_id')->where('status', 'pending')->count(),
            'overdue_count' => $overdue,
            'as_of' => $now->toIso8601String(),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }
}
