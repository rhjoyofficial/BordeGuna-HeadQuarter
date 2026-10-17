<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An auditable resolution requirement (docs/122, ruling docs/120 A2): an identity a lookup refused to move to
 * another customer (`link_refused`), or a row the migration quarantined rather than guess (`quarantined`).
 * Open until a person or the evidence-backed repair resolves it; repeats count up on the open row.
 */
class CustomerIdentityConflict extends Model
{
    public const KIND_LINK_REFUSED = 'link_refused';

    public const KIND_QUARANTINED = 'quarantined';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentity::class, 'identity_id');
    }

    /** The person's append-only resolution (docs/140), when one was recorded; a repair closes a conflict without one. */
    public function resolutionRecord(): HasOne
    {
        return $this->hasOne(CustomerIdentityConflictResolution::class, 'conflict_id');
    }
}
