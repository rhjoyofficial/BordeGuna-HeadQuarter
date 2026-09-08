<?php

namespace App\Gateway\Approval;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Redaction\Redactor;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\DecisionRecord;
use App\Models\ToolCall;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Owns the approval lifecycle. Every state change in the system goes through here.
 *
 * ⚠ This class does NOT execute tools. It claims an approval and hands it to the Tool Gateway,
 * which is the only executor (brief section 8). There is no privileged approval path, because a
 * second executor is a second set of rules and an audit trail with a hole in it.
 */
final class ApprovalService
{
    /** How long a pending approval waits before it is nobody's decision any more. */
    private const DEFAULT_EXPIRY_HOURS = 48;

    private const DEFAULT_SLA_HOURS = 4;

    public function __construct(
        private readonly ApprovalGuard $guard,
        private readonly Auditor $auditor,
        private readonly Notifier $notifier,
        private readonly Redactor $redactor,
    ) {}

    /**
     * Parks a call that policy said needs a human.
     *
     * The payload stored here is the VALIDATED one, after schema validation and coercion, redacted
     * for storage. It is what will execute: the proposer is never asked to regenerate it, so there
     * is no window in which it could change (ADR-018).
     *
     * @param  array<string, mixed>  $args  the validated arguments
     */
    public function park(
        ToolRequest $request,
        ToolDescriptor $descriptor,
        array $args,
        PolicyDecision $decision,
        ToolCall $call,
        ?int $businessId = null,
        ?int $storeId = null,
        ?array $evidence = null,
        ?array $preview = null,
    ): Approval {
        $payload = $this->redactor->redact($args, $descriptor->redact);
        $now = CarbonImmutable::now();

        $approval = Approval::create([
            'tool_key' => $descriptor->key,
            'tool_version' => $descriptor->version,
            'payload' => $payload,
            // Hashed over what is STORED, so the check verifies the same bytes it will execute.
            'payload_hash' => CanonicalPayload::hash($payload),
            'risk_tier' => $descriptor->riskTier->value,
            'action_class' => $decision->actionClass()->value,
            'business_id' => $businessId,
            'brand_id' => $request->brandId,
            'store_id' => $storeId,
            'requested_by_user_id' => $request->identity->enablingUser()?->getKey(),
            'agent_key' => $request->identity->agent?->key,
            'ai_run_id' => $request->aiRunId,
            'correlation_id' => $request->correlationId,
            'requesting_tool_call_id' => $call->id,
            'reason' => $decision->reason,
            'evidence' => $evidence,
            'preview' => $preview,
            'estimated_cost' => $descriptor->costHint->toArray(),
            'policy_id' => $decision->policyId,
            'policy_version' => $decision->policyVersion,
            'status' => ApprovalStatus::Pending->value,
            'approver_role' => 'operator',
            'escalate_to_role' => 'owner',
            'sla_due_at' => $now->addHours(self::DEFAULT_SLA_HOURS),
            'expires_at' => $now->addHours(self::DEFAULT_EXPIRY_HOURS),
        ]);

        $call->approval_id = $approval->id;
        $call->save();

        $this->auditor->record('approval.requested', $approval, after: $approval->toCard(), reason: $decision->reason);
        $this->notifier->approvalRequested($approval);

        return $approval;
    }

    /**
     * Claims a pending approval for execution, atomically.
     *
     * ⚠ This is where single use is guaranteed, and it is guaranteed by the DATABASE rather than by
     * the interface trying not to double-submit. The conditional update matches only a row still in
     * `pending`, so of any number of simultaneous callers exactly one gets a row count of 1 and the
     * rest get 0. Double clicks, browser retries, concurrent requests and queue redelivery are all
     * the same problem and this is the same answer to all of them.
     *
     * @return Approval|null the claimed approval, or null when somebody else claimed it first
     */
    public function claim(Approval $approval, User $approver, ?string $note = null): ?Approval
    {
        $claimed = Approval::query()
            ->whereKey($approval->getKey())
            ->where('status', ApprovalStatus::Pending->value)
            ->update([
                'status' => ApprovalStatus::Approved->value,
                'decided_by_user_id' => $approver->getKey(),
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => $note,
                'updated_at' => CarbonImmutable::now(),
            ]);

        if ($claimed === 0) {
            return null;
        }

        $fresh = $approval->fresh();
        $this->auditor->record('approval.approved', $fresh, after: $fresh->toCard(), reason: $note, actorUserId: $approver->getKey());

        return $fresh;
    }

    /**
     * Marks a claimed approval as spent, whatever the tool then did.
     *
     * The approval was used either way: a tool that ran and failed still consumed the human's
     * decision, and letting it be re-used would turn one approval into an unlimited retry budget.
     */
    public function consume(Approval $approval, ToolCall $call): Approval
    {
        $this->assertTransition($approval, ApprovalStatus::Consumed);

        $approval->forceFill([
            'status' => ApprovalStatus::Consumed->value,
            'consumed_at' => CarbonImmutable::now(),
            'resulting_tool_call_id' => $call->id,
        ])->save();

        $this->auditor->record('approval.consumed', $approval, after: [
            'tool_call_id' => $call->id, 'tool_call_status' => $call->status,
        ]);

        return $approval;
    }

    /**
     * Approve with an edit.
     *
     * The original is left EXACTLY as it was and a successor is created carrying the edited payload
     * and its own hash. That is what keeps the record of what the person originally read, and the
     * delta between proposal and final is the highest-value signal the behaviour-alignment layer
     * receives: it is the owner saying "close, but this is how I would have done it".
     */
    public function approveWithEdit(Approval $original, array $editedArgs, User $approver, ?string $note = null): Approval
    {
        if (! $original->status->isOpen()) {
            throw new RuntimeException("Only a pending approval can be edited; this one is {$original->status->label()}.");
        }

        return DB::transaction(function () use ($original, $editedArgs, $approver, $note) {
            $payload = $this->redactor->redact($editedArgs);

            $successor = Approval::create(array_merge(
                collect($original->toArray())->only([
                    'tool_key', 'tool_version', 'risk_tier', 'action_class', 'business_id', 'brand_id',
                    'store_id', 'requested_by_user_id', 'agent_key', 'ai_run_id', 'correlation_id',
                    'requesting_tool_call_id', 'evidence', 'preview', 'estimated_cost', 'policy_id',
                    'policy_version', 'approver_role', 'escalate_to_role',
                ])->all(),
                [
                    'payload' => $payload,
                    'payload_hash' => CanonicalPayload::hash($payload),
                    'reason' => $original->reason,
                    // PENDING, not approved. The edit creates a new proposal, which the same human
                    // then claims through the ordinary path. Marking it approved here would give
                    // the successor a second route to execution that skips the atomic claim, and
                    // the claim is what makes single use a database guarantee.
                    'status' => ApprovalStatus::Pending->value,
                    'decision_note' => $note,
                    'supersedes_id' => $original->id,
                    'sla_due_at' => $original->sla_due_at,
                    'expires_at' => $original->expires_at,
                ],
            ));

            // The original becomes cancelled rather than approved: nobody approved IT.
            $original->forceFill([
                'status' => ApprovalStatus::Cancelled->value,
                'decided_by_user_id' => $approver->getKey(),
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => 'superseded by an edited approval',
            ])->save();

            $this->recordDecision($original, 'edited', $approver, $note, $payload);
            $this->auditor->record('approval.edited', $successor, before: $original->toCard(), after: $successor->toCard(), reason: $note, actorUserId: $approver->getKey());

            return $successor;
        });
    }

    public function reject(Approval $approval, User $approver, string $reason): Approval
    {
        $this->assertTransition($approval, ApprovalStatus::Rejected);

        $approval->forceFill([
            'status' => ApprovalStatus::Rejected->value,
            'decided_by_user_id' => $approver->getKey(),
            'decided_at' => CarbonImmutable::now(),
            'decision_note' => $reason,
        ])->save();

        $this->failRequestingCall($approval, "rejected by {$approver->email}: {$reason}");
        $this->recordDecision($approval, 'rejected', $approver, $reason);
        $this->auditor->record('approval.rejected', $approval, after: $approval->toCard(), reason: $reason, actorUserId: $approver->getKey());

        return $approval;
    }

    public function cancel(Approval $approval, ?User $by = null, ?string $reason = null): Approval
    {
        $this->assertTransition($approval, ApprovalStatus::Cancelled);

        $approval->forceFill([
            'status' => ApprovalStatus::Cancelled->value,
            'decided_by_user_id' => $by?->getKey(),
            'decided_at' => CarbonImmutable::now(),
            'decision_note' => $reason,
        ])->save();

        $this->failRequestingCall($approval, 'cancelled before a decision was made');
        $this->auditor->record('approval.cancelled', $approval, after: $approval->toCard(), reason: $reason, actorUserId: $by?->getKey());

        return $approval;
    }

    /**
     * Delegation NARROWS who may decide; it never widens it.
     *
     * The delegate must already hold the decide permission, so this cannot be used to hand authority
     * to somebody who does not have it.
     */
    public function delegate(Approval $approval, User $to, User $by): Approval
    {
        if (! $approval->status->isOpen()) {
            throw new RuntimeException("Only a pending approval can be delegated; this one is {$approval->status->label()}.");
        }
        if (! $to->can('hq.approvals.decide')) {
            throw new InvalidArgumentException('The delegate does not hold hq.approvals.decide. Delegation narrows who may decide; it cannot grant authority.');
        }
        if ($to->getKey() === $by->getKey()) {
            throw new InvalidArgumentException('Delegating to yourself changes nothing.');
        }

        $approval->forceFill([
            'delegated_to_user_id' => $to->getKey(),
            'delegated_by_user_id' => $by->getKey(),
            'delegated_at' => CarbonImmutable::now(),
        ])->save();

        $this->auditor->record('approval.delegated', $approval, after: [
            'delegated_to' => $to->getKey(), 'delegated_by' => $by->getKey(),
        ], actorUserId: $by->getKey());
        $this->notifier->approvalDelegated($approval, $to);

        return $approval;
    }

    /** Sweeps pending approvals past their expiry. Never approves; expiry is a refusal. */
    public function expireLapsed(?CarbonImmutable $at = null): int
    {
        $expired = 0;
        foreach (Approval::lapsed($at)->get() as $approval) {
            $approval->forceFill([
                'status' => ApprovalStatus::Expired->value,
                'decided_at' => CarbonImmutable::now(),
                'decision_note' => 'expired without a decision',
            ])->save();

            $this->failRequestingCall($approval, 'the approval expired without a decision');
            $this->auditor->recordSystem('approval.expired', $approval, after: $approval->toCard(),
                reason: 'an ignored approval is a refusal, never an approval');
            $expired++;
        }

        return $expired;
    }

    /** Escalates pending approvals past their service deadline. State is unchanged; a person is told. */
    public function escalateOverdue(?CarbonImmutable $at = null): int
    {
        $escalated = 0;
        foreach (Approval::pastSla($at)->get() as $approval) {
            $approval->forceFill(['escalated_at' => CarbonImmutable::now()])->save();
            $this->auditor->recordSystem('approval.escalated', $approval, after: [
                'escalate_to_role' => $approval->escalate_to_role, 'sla_due_at' => $approval->sla_due_at?->toIso8601String(),
            ]);
            $this->notifier->approvalOverdue($approval);
            $escalated++;
        }

        return $escalated;
    }

    /** Records an integrity failure loudly. The approval is left as it is; nothing is repaired. */
    public function recordIntegrityFailure(Approval $approval, string $reason): void
    {
        $this->auditor->recordSystem('approval.integrity_failed', $approval, after: [
            'tool_key' => $approval->tool_key,
            'stored_hash' => $approval->payload_hash,
            'reason' => $reason,
        ], reason: 'execution refused; the approval was not modified');

        $this->notifier->approvalIntegrityFailed($approval, $reason);
    }

    public function recordDecision(Approval $approval, string $decision, ?User $by, ?string $reason = null, ?array $final = null): DecisionRecord
    {
        $proposal = $approval->payload ?? [];

        return DecisionRecord::create([
            'agent_key' => $approval->agent_key,
            'user_id' => $by?->getKey(),
            'context_type' => 'approval',
            'context_id' => (string) $approval->id,
            'proposal' => $proposal,
            'final' => $final ?? ($decision === 'approved' ? $proposal : null),
            'delta' => $final === null ? null : $this->delta($proposal, $final),
            'decision' => $decision,
            'reason' => $reason,
            'outcome' => ['tool_key' => $approval->tool_key, 'risk_tier' => $approval->risk_tier?->value],
            'correlation_id' => $approval->correlation_id,
        ]);
    }

    /** Marks the parked tool call as failed when the approval will never execute. */
    private function failRequestingCall(Approval $approval, string $reason): void
    {
        $call = $approval->requesting_tool_call_id ? ToolCall::find($approval->requesting_tool_call_id) : null;
        if ($call === null || $call->status !== OutcomeStatus::AwaitingApproval->value) {
            return;
        }

        $call->forceFill([
            'status' => OutcomeStatus::Denied->value,
            'error' => $reason,
            'finished_at' => CarbonImmutable::now(),
        ])->save();
    }

    private function assertTransition(Approval $approval, ApprovalStatus $next): void
    {
        if (! $approval->status->canTransitionTo($next)) {
            throw new RuntimeException(
                "An approval cannot move from {$approval->status->value} to {$next->value}."
            );
        }
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function delta(array $proposal, array $final): array
    {
        $delta = [];
        foreach (array_unique([...array_keys($proposal), ...array_keys($final)]) as $key) {
            $before = $proposal[$key] ?? null;
            $after = $final[$key] ?? null;
            if ($before !== $after) {
                $delta[$key] = ['from' => $before, 'to' => $after];
            }
        }

        return $delta;
    }
}
