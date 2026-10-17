<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A person's resolution of one `customer_identity_conflicts` row (docs/140, ruling docs/131 item 3): which of the three
 * evidence-backed choices they made, the owner before and after, whether a quarantined identity was released, the
 * evidence they stated with what HQ checked, and the reason. Append-only: the database refuses UPDATE and DELETE, the
 * model refuses them too. Written only by `ConflictResolver`, reached only from the human-only gateway tool.
 */
class CustomerIdentityConflictResolution extends Model
{
    public const UPDATED_AT = null;

    public const KEEP_OWNER = 'kept_owner';

    public const MOVE_TO_CLAIMANT = 'moved_to_claimant';

    public const NOT_RESOLVABLE = 'not_resolvable';

    public const CHOICES = [self::KEEP_OWNER, self::MOVE_TO_CLAIMANT, self::NOT_RESOLVABLE];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'carried_restrictions' => 'array', 'released_from_quarantine' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('customer_identity_conflict_resolutions is append-only: a correction is a new conflict and a new resolution.'));
        static::deleting(fn () => throw new LogicException('customer_identity_conflict_resolutions is append-only: a resolution is never removed.'));
    }

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(CustomerIdentityConflict::class, 'conflict_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
