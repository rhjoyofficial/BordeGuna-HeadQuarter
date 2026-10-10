<?php

namespace App\Models;

use App\Outcomes\ResultType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A reference from an outcome to the business record it is about (docs/107 section 4.2): the system
 * of record's natural key always, HQ's read-model row when there is one. Never a copy of the record.
 */
class OutcomeResultRef extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result_type' => ResultType::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('outcome_result_refs is append-only: a changed reference is a successor outcome.'));
        static::deleting(fn () => throw new LogicException('outcome_result_refs is append-only.'));
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Outcome::class);
    }

    public function factsOrder(): BelongsTo
    {
        return $this->belongsTo(FactsOrder::class);
    }

    /** No business column of its own: visible exactly when its outcome is. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('outcome', fn (Builder $q) => $q->visibleTo($user));
    }
}
