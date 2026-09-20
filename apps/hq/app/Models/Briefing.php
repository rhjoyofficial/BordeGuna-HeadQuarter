<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One stored briefing (docs/48 M.4, docs/80 C.1): the evidence bundle as collected, the validator's
 * verdict, and the narrative when one passed the unsourced-number check.
 *
 * Append-only. A briefing is evidence a decision record may later point at, so it is written once by
 * `BriefingComposer` and refused any change or removal here; retention keeps it forever.
 */
class Briefing extends Model
{
    public const STATE_COMPLETE = 'complete';

    public const STATE_DEGRADED = 'degraded';

    protected $fillable = [
        'uuid', 'capability', 'capability_version', 'bundle_version', 'briefing_date', 'timezone', 'business_id',
        'scope_business_ids', 'state', 'degraded_reasons', 'bundle', 'bundle_digest', 'validation', 'narration_state',
        'narrative', 'narrative_check', 'narrator', 'ai_run_id', 'composed_by_user_id', 'correlation_id',
        'idempotency_key', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'briefing_date' => 'immutable_date',
            'scope_business_ids' => 'array',
            'degraded_reasons' => 'array',
            'bundle' => 'array',
            'validation' => 'array',
            'narrative_check' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('briefings is append-only; a stored briefing is never changed.'));
        static::deleting(fn () => throw new LogicException('briefings is append-only; a stored briefing is never removed.'));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isDegraded(): bool
    {
        return $this->state === self::STATE_DEGRADED;
    }
}
