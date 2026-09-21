<?php

namespace App\Models;

use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\Approval\CanonicalPayload;
use App\Gateway\Descriptor\RiskTier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One human decision, bound to one exact action (ADR-018).
 *
 * ⚠ `payload`, `payload_hash` and `tool_version` are written once and never updated. Approve-with-
 * edit creates a SUCCESSOR row rather than changing this one, so the record of what a person
 * actually read and agreed to survives whatever happens afterwards.
 */
class Approval extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'tool_key', 'tool_version', 'payload', 'payload_hash', 'risk_tier', 'action_class',
        'business_id', 'brand_id', 'store_id', 'requested_by_user_id', 'enabled_by_user_id', 'requester_kind',
        'execution_context_digest', 'agent_key', 'ai_run_id',
        'correlation_id', 'requesting_tool_call_id', 'reason', 'evidence', 'preview', 'estimated_cost',
        'policy_id', 'policy_version', 'status', 'approver_role', 'decided_by_user_id', 'decided_at',
        'decision_note', 'delegated_to_user_id', 'delegated_by_user_id', 'delegated_at',
        'sla_due_at', 'escalate_to_role', 'escalated_at', 'expires_at', 'consumed_at',
        'resulting_tool_call_id', 'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'evidence' => 'array',
            'preview' => 'array',
            'estimated_cost' => 'array',
            'status' => ApprovalStatus::class,
            'risk_tier' => RiskTier::class,
            'decided_at' => 'datetime',
            'delegated_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** HasUuids fills `uuid` while the primary key stays an auto-incrementing id. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** The enabling user of the agent that parked it. Null for a non-agent caller, and for a row from before docs/81 P0-4. */
    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function delegatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to_user_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function resultingToolCall(): BelongsTo
    {
        return $this->belongsTo(ToolCall::class, 'resulting_tool_call_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value);
    }

    /** Past its expiry and still waiting. Expiry is only meaningful for a pending approval. */
    public function scopeLapsed(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        return $query->pending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at ?? CarbonImmutable::now());
    }

    public function scopePastSla(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        return $query->pending()
            ->whereNull('escalated_at')
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', $at ?? CarbonImmutable::now());
    }

    public function hasExpired(?CarbonImmutable $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at <= ($at ?? CarbonImmutable::now());
    }

    /** The tamper check. Constant time, and over the one canonical encoder. */
    public function payloadIntact(): bool
    {
        return CanonicalPayload::matches($this->payload ?? [], (string) $this->payload_hash);
    }

    /**
     * Whether requester and enabler were recorded apart. False for a non-agent caller (there is no
     * enabler) and for an agent row from before docs/81 P0-4, whose requester column holds the
     * ENABLING user; a screen must not present that person as the one who asked.
     */
    public function requesterRecordedApart(): bool
    {
        return $this->enabled_by_user_id !== null;
    }

    /**
     * The one separation-of-duties rule (docs/81 C.2.2), or null when it has nothing to say.
     *
     * A person who asked through an agent that borrows SOMEBODY ELSE's authority may not also be
     * the one who says yes. It applies only where two people were recorded: when the requester is
     * the enabling user (the single-owner setup), for a non-agent caller, and for a row from before
     * the split, nothing changes, because docs/48 G.4 (Q22) keeps the owner able to decide
     * everything. The enabling user MAY decide what somebody else asked: requester and approver
     * are then already two people.
     */
    public function separationOfDutiesReason(User $user): ?string
    {
        if ($this->enabled_by_user_id === null || $this->requested_by_user_id === null) {
            return null;
        }
        if ((int) $this->requested_by_user_id === (int) $this->enabled_by_user_id) {
            return null;
        }
        if ((int) $this->requested_by_user_id !== (int) $user->getKey()) {
            return null;
        }

        return 'You asked for this through an agent that acts under another person\'s authority, so the decision belongs to somebody else. You may still withdraw it.';
    }

    /**
     * Who may decide this one, as the reason a person may NOT, or null when they may: the delegate
     * when there is one, otherwise anybody holding the decide permission, and never the person the
     * separation rule bars. Delegation NARROWS, it never widens.
     *
     * The one rule for every door to a decision (docs/81 C.6): the screen, the executor, an edit and
     * a rejection all read it, so a caller that skips the controller meets the same answer.
     */
    public function decisionRefusalReason(User $user): ?string
    {
        if (($barred = $this->separationOfDutiesReason($user)) !== null) {
            return $barred;
        }
        if (! $user->can('hq.approvals.decide')) {
            return 'You do not hold the permission to decide approvals.';
        }
        if ($this->delegated_to_user_id !== null && (int) $this->delegated_to_user_id !== (int) $user->getKey()) {
            return 'This approval is delegated to somebody else.';
        }

        return null;
    }

    public function isDecidableBy(User $user): bool
    {
        return $this->decisionRefusalReason($user) === null;
    }

    /** What a screen may show. Contains no secret: the payload was redacted before it was stored. */
    /**
     * What actually happened, which for a consumed approval is not always what its status says.
     *
     * `consumed` means the approval was spent. It is spent whatever the tool did, including when the
     * gateway refused the call at execution time because authority had changed since the human said
     * yes. Only a `succeeded` resulting call means the action ran.
     */
    public function outcomeLabel(): string
    {
        if ($this->status !== ApprovalStatus::Consumed) {
            return $this->status->label();
        }

        $callStatus = $this->resultingToolCall?->status;

        return $callStatus === 'succeeded'
            ? 'approved and executed'
            : 'approved, but the action was refused at execution time and did not run';
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'tool_key' => $this->tool_key,
            'tool_version' => $this->tool_version,
            'risk_tier' => $this->risk_tier?->value,
            'risk_label' => $this->risk_tier?->label(),
            'action_class' => $this->action_class,
            'status' => $this->status->value,
            // ⚠ NOT simply `$this->status->label()`. A consumed approval has been SPENT, which is not
            // the same as having executed: a policy denial at execution time (a freeze, a revoked
            // permission) spends the approval and produces a `denied` tool call. Saying "approved and
            // executed" there tells a person something happened that did not, on the one screen whose
            // whole job is to report what happened. Found by the M3 production UI check.
            'status_label' => $this->outcomeLabel(),
            'execution_status' => $this->resultingToolCall?->status,
            'execution_error' => $this->resultingToolCall?->error,
            'payload' => $this->payload,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
            'preview' => $this->preview,
            'estimated_cost' => $this->estimated_cost,
            'business_id' => $this->business_id,
            'store_id' => $this->store_id,
            'agent_key' => $this->agent_key,
            'correlation_id' => $this->correlation_id,
            'policy_version' => $this->policy_version,
            'sla_due_at' => $this->sla_due_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'consumed_at' => $this->consumed_at?->toIso8601String(),
            'supersedes_id' => $this->supersedes_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
