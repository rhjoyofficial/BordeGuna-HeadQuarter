<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The step ledger: one row per (instance, step), whatever the number of attempts (docs/102 §3).
 *
 * `DONE` steps are never run again: on resume the engine reads their persisted outcome instead of
 * asking the gateway, which is what keeps a step completed more than 24 hours ago (the gateway's
 * replay ceiling) from executing twice.
 */
class WorkflowStep extends Model
{
    public const PENDING = 'pending';

    /** Written BEFORE the gateway is called. Found on a run whose lease expired, it means the worker died mid-call. */
    public const RUNNING = 'running';

    public const WAITING = 'waiting';

    /** The effect may have landed. Retried only through the gateway, which reconciles before executing. */
    public const UNCERTAIN = 'uncertain';

    public const SUCCEEDED = 'succeeded';

    public const SKIPPED = 'skipped';

    public const TIMED_OUT = 'timed_out';

    /** The approval was rejected, cancelled or expired: a decision, not a fault. The run continues. */
    public const DECLINED = 'declined';

    public const FAILED = 'failed';

    public const DONE = [self::SUCCEEDED, self::SKIPPED, self::TIMED_OUT, self::DECLINED];

    protected $fillable = [
        'workflow_instance_id', 'step', 'seq', 'kind', 'status', 'attempt', 'max_attempts', 'idempotency_key',
        'tool_key', 'tool_call_id', 'approval_id', 'outcome', 'error', 'wake_at', 'uncertain_accepted_at',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => 'array',
            'wake_at' => 'immutable_datetime',
            'uncertain_accepted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isDone(): bool
    {
        return in_array($this->status, self::DONE, true);
    }
}
