<?php

namespace App\Models;

use App\Identity\Quarantine\QuarantineDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A person's audited decision on a quarantine flag (docs/117 §5). Append-only; the newest row is current. */
class CrossBusinessQuarantineReview extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['quarantine_id', 'decision', 'note', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['decision' => QuarantineDecision::class, 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('cross_business_quarantine_reviews is append-only: a changed mind is a new review.'));
        static::deleting(fn () => throw new LogicException('cross_business_quarantine_reviews is append-only: a review is never removed.'));
    }

    public function quarantine(): BelongsTo
    {
        return $this->belongsTo(CrossBusinessQuarantine::class, 'quarantine_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
