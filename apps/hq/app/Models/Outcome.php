<?php

namespace App\Models;

use App\Outcomes\OutcomeState;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One business result event (docs/107 section 4.1). Append-only: the database refuses UPDATE and
 * DELETE; a correction or retraction is a successor row. Written only through
 * `App\Outcomes\OutcomeRecorder`.
 *
 * ⛔ Holds no amount, status or line: the system of record owns those, reached through `results()`.
 */
class Outcome extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state' => OutcomeState::class,
            'evidence' => 'array',
            'occurred_at' => 'datetime',
            'source_as_of' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('outcomes is append-only: record a successor row (supersede or retract) rather than editing history.'));
        static::deleting(fn () => throw new LogicException('outcomes is append-only: a retraction is a successor row, never a deletion.'));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(OutcomeResultRef::class);
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(OutcomeAttribution::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_outcome_id');
    }

    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_outcome_id');
    }

    /** Business-scoped reads through the one existing rule (docs/107 section 10). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }

    /** The current belief: asserted rows nothing has superseded (D17). */
    public function scopeCurrent(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($query->qualifyColumn('state'), OutcomeState::Asserted->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from("{$table} as successor")
                ->whereColumn('successor.supersedes_outcome_id', "{$table}.id"));
    }
}
