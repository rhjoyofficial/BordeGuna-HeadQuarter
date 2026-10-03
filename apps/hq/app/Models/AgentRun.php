<?php

namespace App\Models;

use App\Gateway\Descriptor\AutonomyLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent run, and the record a human reviews during the shadow period (docs/48 M5).
 *
 * ⚠ `shadow` is derived from the resolved autonomy level at the moment the run started, not set by
 * a caller. `AgentRunner` is the only writer.
 */
class AgentRun extends Model
{
    protected $fillable = [
        'uuid', 'agent_id', 'agent_key', 'correlation_id', 'trigger', 'triggered_by_user_id',
        'business_id', 'brand_id', 'conversation_uuid', 'question', 'task', 'canonical_task', 'task_grounding', 'authority_request', 'security_intent', 'authority_source', 'prohibited_capability', 'role_boundary', 'task_scope', 'execution_context', 'execution_context_digest', 'autonomy_level', 'autonomy_reason',
        'autonomy_constraints', 'autonomy_evidence', 'shadow', 'status', 'stop_reason', 'closed_by_budget', 'answer', 'error',
        'evidence', 'proposals', 'context_provenance', 'model', 'model_tier', 'prompt_version', 'support_workflow', 'language_requirement',
        'policy_version', 'turns', 'tool_calls_count', 'duration_ms',
        'reviewed_at', 'reviewed_by', 'review_verdict', 'review_note',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'security_intent' => 'array',
            'prohibited_capability' => 'array',
            'role_boundary' => 'array',
            'task_scope' => 'array',
            'execution_context' => 'array',
            'support_workflow' => 'array',
            'language_requirement' => 'array',
            'autonomy_constraints' => 'array',
            'autonomy_evidence' => 'array',
            'evidence' => 'array',
            'proposals' => 'array',
            'context_provenance' => 'array',
            'shadow' => 'boolean',
            'closed_by_budget' => 'boolean',
            'autonomy_level' => AutonomyLevel::class,
            'turns' => 'integer',
            'tool_calls_count' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /**
     * Whether the answer may be shown to the operator as HQ's answer.
     *
     * ⛔ A shadow run's answer is not withheld from the SCREEN - Joy has to read it to review it.
     * It is withheld from being treated as an answer: the agent surface returns it labelled, and no
     * caller may present it as operational. The distinction is the whole of A0.
     */
    public function isOperational(): bool
    {
        return ! $this->shadow && $this->status === 'answered';
    }

    public function awaitingReview(): bool
    {
        return $this->shadow && $this->reviewed_at === null && in_array($this->status, ['answered', 'refused', 'clarified', 'stopped'], true);
    }
}
