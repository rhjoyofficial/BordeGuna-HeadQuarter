<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The append-only history of a policy version: proposed, activated, retired, each with the actor,
 * the reason and a snapshot of the whole version at that moment (ADR-048). Postgres refuses UPDATE
 * and DELETE on this table.
 */
class AutonomyEvidencePolicyEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'occurred_at';

    protected $fillable = ['policy_id', 'event', 'actor_user_id', 'actor_label', 'reason', 'snapshot', 'content_digest', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AutonomyEvidencePolicy::class, 'policy_id');
    }
}
