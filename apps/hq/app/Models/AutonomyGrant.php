<?php

namespace App\Models;

use App\Gateway\Descriptor\AutonomyLevel;
use App\Gateway\Descriptor\MonetaryImpact;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ceiling on what an agent may do without a human (ADR-020).
 *
 * ⛔ A grant is a CEILING, never a floor and never a permission. Because the resolver combines by
 * minimum, adding a grant can only ever lower the effective level or leave it unchanged for the
 * combinations it does not match. There is deliberately no way to express "at least".
 */
class AutonomyGrant extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'name', 'reason', 'agent_key', 'business_id', 'brand_id', 'channel', 'tool_key',
        'action_class', 'customer_segment', 'environment', 'data_class', 'max_monetary_impact',
        'time_window', 'min_agreement_rate', 'evaluation_suite_key', 'max_level', 'status',
        'version', 'supersedes_id', 'granted_by', 'granted_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'max_level' => AutonomyLevel::class,
            'max_monetary_impact' => MonetaryImpact::class,
            'time_window' => 'array',
            'min_agreement_rate' => 'float',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** HasUuids would otherwise treat `id` as the uuid column. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->greaterThan($at));
    }
}
