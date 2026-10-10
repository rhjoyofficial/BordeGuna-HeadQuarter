<?php

namespace App\Models;

use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\OutcomeState;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A claim that one HQ action played a role in an outcome (docs/107 section 4.3). Append-only; a
 * re-judgement is a successor row. Its `business_id` repeats the outcome's and a composite foreign
 * key makes a cross-business row impossible (D16).
 */
class OutcomeAttribution extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'role' => AttributionRole::class,
            'method' => AttributionMethod::class,
            'state' => OutcomeState::class,
            'confidence' => 'float',
            'evidence' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('outcome_attributions is append-only: record a successor row rather than editing history.'));
        static::deleting(fn () => throw new LogicException('outcome_attributions is append-only: a retraction is a successor row, never a deletion.'));
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(Outcome::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_attribution_id');
    }

    /** The id of the credited action, from whichever typed column its kind uses. */
    public function actionId(): int
    {
        return (int) $this->getAttribute($this->action_type->column());
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }

    public function scopeCurrent(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($query->qualifyColumn('state'), OutcomeState::Asserted->value)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from("{$table} as successor")
                ->whereColumn('successor.supersedes_attribution_id', "{$table}.id"));
    }
}
