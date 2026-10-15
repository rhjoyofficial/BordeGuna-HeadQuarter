<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One repair batch (docs/126, ruling docs/120 A3): the plan a person reviewed, keyed by its content, the
 * status of its application, and the report (counts, verification, the human lists). `verified` is the only
 * complete state (A3 point 10); `partial` means a split was refused or failed and the batch can be resumed.
 */
class CustomerIdentityRepair extends Model
{
    public const STATUS_APPLYING = 'applying';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_VERIFICATION_FAILED = 'verification_failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['plan' => 'array', 'report' => 'array', 'started_at' => 'datetime', 'applied_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(CustomerMergeReversal::class, 'repair_id');
    }
}
