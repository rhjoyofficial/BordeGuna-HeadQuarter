<?php

namespace App\Models;

use App\Consent\ConsentRefusal;
use App\FollowUps\FollowUpKind;
use App\FollowUps\FollowUpOrigin;
use App\FollowUps\FollowUpState;
use App\FollowUps\IllegalTransition;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A planned future contact a person owns (docs/113 sections 2 and 9.2). A plan, never a message (F1).
 * Written only through `App\FollowUps\FollowUpProposalService`; moved only by `transition()`.
 *
 * The consent columns record what the S7 ledger answered and when; they are never read as permission
 * (F8). The workflow columns reference the run by key and uuid, no foreign key (section 7).
 */
class FollowUp extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'origin' => FollowUpOrigin::class,
            'kind' => FollowUpKind::class,
            'state' => FollowUpState::class,
            'consent_refusal' => ConsentRefusal::class,
            'consent_permitted' => 'boolean',
            'evidence' => 'array',
            'due_at' => 'immutable_datetime',
            'consent_checked_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('follow-ups are never deleted by application code: decline or cancel one, and the audit log keeps its history. Only retention removes refused or declined rows after 365 days (docs/123).'));
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

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function consentEvent(): BelongsTo
    {
        return $this->belongsTo(ConsentEvent::class);
    }

    /** Business-scoped reads through the one existing rule (docs/113 section 5, docs/107 D19). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('state'), [FollowUpState::Proposed->value, FollowUpState::Approved->value, FollowUpState::Scheduled->value]);
    }

    public function isDue(): bool
    {
        return $this->state === FollowUpState::Scheduled && $this->due_at->isPast();
    }

    /**
     * One conditional UPDATE: the move happens only if the row is still in a state that allows it (F3).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(FollowUpState $to, array $attributes = []): void
    {
        $from = $this->state;
        if (! in_array($to, $from->allowedNext(), true)) {
            throw new IllegalTransition("A follow-up cannot go from [{$from->value}] to [{$to->value}].");
        }
        $moved = static::query()->whereKey($this->getKey())->where('state', $from->value)
            ->update($attributes + ['state' => $to->value, 'updated_at' => now()]);
        if ($moved !== 1) {
            throw new IllegalTransition("Follow-up {$this->uuid} is no longer [{$from->value}]; somebody else moved it first.");
        }
        $this->refresh();
    }
}
