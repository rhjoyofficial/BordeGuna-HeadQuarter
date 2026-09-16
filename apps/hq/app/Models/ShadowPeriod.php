<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One agent's A0 shadow period, from the audited activation to its close or its stop (docs/77 C.2).
 *
 * ⛔ Nothing reads this row to decide what the agent may do. The autonomy plane, the gateway and the
 * agent row decide that, exactly as before; the period is the record of a deliberate window and the
 * place its evidence and its stop conditions hang. Deleting a row would not widen anything, and the
 * foreign keys refuse it anyway.
 */
class ShadowPeriod extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_STOPPED = 'stopped';

    protected $fillable = [
        'uuid', 'agent_id', 'agent_key', 'autonomy_level', 'status', 'opened_at', 'planned_end_at',
        'closed_at', 'opened_by_user_id', 'opened_by_label', 'reason', 'evidence', 'allowed_tools',
        'questions', 'end_reason', 'violations',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'planned_end_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'evidence' => 'array',
            'allowed_tools' => 'array',
            'questions' => 'array',
            'violations' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(ShadowEvidenceDay::class)->orderBy('day');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public static function openFor(string $agentKey): ?self
    {
        return self::query()->where('agent_key', $agentKey)->where('status', self::STATUS_OPEN)->first();
    }

    public static function anyOpen(): bool
    {
        return self::query()->where('status', self::STATUS_OPEN)->exists();
    }
}
