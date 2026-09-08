<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One versioned policy row.
 *
 * ⚠ Rows are effectively append-only in practice: a rule change closes the current version
 * (`active_to`) and inserts a new one. Nothing rewrites `rules` on a row that has already decided
 * something, because `tool_calls` points at this row and expects it to still say what it said.
 */
class Policy extends Model
{
    protected $table = 'policies';

    protected $fillable = ['key', 'version', 'business_id', 'tool_key', 'rules', 'note', 'active_from', 'active_to', 'created_by'];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'active_from' => 'datetime',
            'active_to' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** In force at a given instant: opened, and not yet closed. */
    public function scopeActiveAt(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query->where('active_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('active_to')->orWhere('active_to', '>', $at));
    }

    /**
     * Most specific first: tool AND business, then tool, then business, then global.
     *
     * Expressed as an ordering rather than as four queries so that "which policy applies" has one
     * answer and one code path.
     */
    public function scopeMostSpecificFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('(CASE WHEN tool_key IS NOT NULL AND business_id IS NOT NULL THEN 0
                                WHEN tool_key IS NOT NULL THEN 1
                                WHEN business_id IS NOT NULL THEN 2
                                ELSE 3 END)')
            ->orderByDesc('active_from')
            ->orderByDesc('version');
    }

    public function isActiveAt(\DateTimeInterface $at): bool
    {
        return $this->active_from <= $at && ($this->active_to === null || $this->active_to > $at);
    }
}
