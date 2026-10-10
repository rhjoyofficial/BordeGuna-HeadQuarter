<?php

namespace App\Models;

use App\FollowUps\IllegalTransition;
use App\FollowUps\OpportunityCloseReason;
use App\FollowUps\OpportunityKind;
use App\FollowUps\OpportunityState;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A recorded commercial interest of one customer of one business (docs/113 sections 2 and 9.1).
 * Written only through `App\FollowUps\FollowUpProposalService`; moved only by `transition()`.
 *
 * ⛔ No amount, price or quantity (F2). Never deleted (F11).
 */
class Opportunity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => OpportunityKind::class,
            'state' => OpportunityState::class,
            'close_reason' => OpportunityCloseReason::class,
            'evidence' => 'array',
            'expected_at' => 'date',
            'decided_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('opportunities are never deleted: close or decline one, and the audit log keeps its history.'));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** Business-scoped reads through the one existing rule (docs/113 section 5, docs/107 D19). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }

    /**
     * One conditional UPDATE: the move happens only if the row is still in a state that allows it (F3).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(OpportunityState $to, array $attributes = []): void
    {
        $from = $this->state;
        if (! in_array($to, $from->allowedNext(), true)) {
            throw new IllegalTransition("An opportunity cannot go from [{$from->value}] to [{$to->value}].");
        }
        $moved = static::query()->whereKey($this->getKey())->where('state', $from->value)
            ->update($attributes + ['state' => $to->value, 'updated_at' => now()]);
        if ($moved !== 1) {
            throw new IllegalTransition("Opportunity {$this->uuid} is no longer [{$from->value}]; somebody else moved it first.");
        }
        $this->refresh();
    }
}
