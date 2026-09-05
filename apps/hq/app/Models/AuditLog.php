<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit record. The database refuses UPDATE/DELETE on PostgreSQL (trigger);
 * this model refuses them too so the rule holds on every driver and in every code path.
 * Write through App\Support\Audit\Auditor, not directly.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'occurred_at';

    protected $fillable = [
        'occurred_at', 'actor_type', 'actor_id', 'actor_label', 'action', 'target_type', 'target_id',
        'reason', 'before', 'after', 'correlation_id', 'request_id', 'ip', 'user_agent', 'context',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs is append-only; updates are refused.'));
        static::deleting(fn () => throw new LogicException('audit_logs is append-only; deletes are refused.'));
    }
}
