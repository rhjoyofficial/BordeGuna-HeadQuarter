<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A flag on one relationship that crosses a business boundary (docs/117, ruling docs/110 §5 (m)).
 *
 * Append-only: the database refuses UPDATE and DELETE. The flagged row itself (a merge, an identity, an
 * order...) is never changed by flagging it; suppression on the read side does not depend on this row.
 */
class CrossBusinessQuarantine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'kind', 'subject_table', 'subject_id', 'customer_id', 'customer_business_id', 'row_business_id',
        'merge_id', 'reason', 'detail', 'detected_at', 'flagged_by',
    ];

    protected function casts(): array
    {
        return ['detail' => 'array', 'detected_at' => 'datetime', 'subject_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('cross_business_quarantines is append-only: a decision is a new review row, never an edit of the flag.'));
        static::deleting(fn () => throw new LogicException('cross_business_quarantines is append-only: a flag is never removed.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CrossBusinessQuarantineReview::class, 'quarantine_id');
    }

    /** The current decision: the newest review row. */
    public function latestReview(): HasOne
    {
        return $this->hasOne(CrossBusinessQuarantineReview::class, 'quarantine_id')->ofMany('id', 'max');
    }
}
