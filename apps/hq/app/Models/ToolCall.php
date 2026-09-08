<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gateway invocation.
 *
 * Phase 0 created the table for an agent-only world and nothing ever wrote to it. From M1 the
 * gateway writes a row for EVERY call, including the ones that never reached a tool: an unknown
 * key, invalid arguments, a permission refusal. Those are the rows worth having. A control plane
 * that only records what succeeded cannot tell you that something spent a week being refused.
 *
 * `updated_at` was added in M1 because a row is now written on entry and completed on exit; the
 * table previously had only `created_at`, on the assumption of a single write at the end.
 */
class ToolCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_run_id', 'tool_key', 'tool_version', 'risk_tier', 'caller_kind', 'actor_type', 'actor_id',
        'actor_label', 'agent_key', 'enabling_user_id', 'business_id', 'store_id', 'job_run_id',
        'correlation_id', 'input', 'output', 'policy_result', 'policy_reason', 'approval_id',
        'idempotency_key', 'duration_ms', 'status', 'error', 'failure_class', 'replayed', 'attempt',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'replayed' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function jobRun(): BelongsTo
    {
        return $this->belongsTo(JobRun::class);
    }

    public function enablingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabling_user_id');
    }
}
