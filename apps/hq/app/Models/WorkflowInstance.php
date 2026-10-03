<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One durable workflow run (docs/102 §3). The status moves only along `TRANSITIONS`, and only through
 * a conditional update (`HqWorkflowEngine::transition()`), so two workers cannot both move it.
 */
class WorkflowInstance extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const WAITING = 'waiting';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** from => allowed next. Completed and cancelled are final; a failed run can be retried or closed. */
    public const TRANSITIONS = [
        self::PENDING => [self::RUNNING, self::CANCELLED],
        self::RUNNING => [self::RUNNING, self::WAITING, self::COMPLETED, self::FAILED, self::CANCELLED],
        self::WAITING => [self::RUNNING, self::CANCELLED],
        self::FAILED => [self::RUNNING, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    public const OPEN = [self::PENDING, self::RUNNING, self::WAITING];

    protected $fillable = [
        'uuid', 'instance_key', 'type', 'definition_version', 'status', 'current_step', 'input', 'output',
        'wait_kind', 'wait_for', 'wake_at', 'correlation_key', 'correlation_id', 'business_id',
        'requested_by_user_id', 'lease_token', 'lease_expires_at', 'last_error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'wake_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
