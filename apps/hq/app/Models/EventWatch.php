<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** docs/143: an event watch proposed from the chat (docs/143 F). */
class EventWatch extends Model
{
    /**
     * Ruling 31 Oct item 6: what a watch looks for and what it may propose are fixed when it is proposed. Neither a
     * person nor a model rewrites them later; a different watch is a new proposal that goes through enabling again.
     */
    public const IMMUTABLE = ['business_id', 'event_type', 'event_version', 'conditions', 'action', 'proposed_by_user_id', 'proposer_kind', 'agent_key'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (EventWatch $watch) {
            if ($watch->isDirty(self::IMMUTABLE)) {
                throw new LogicException('The event, conditions and action of an event watch are fixed when it is proposed.');
            }
        });
    }

    protected function casts(): array
    {
        return ['conditions' => 'array', 'action' => 'array', 'event_version' => 'integer', 'enabled_at' => 'datetime', 'last_fired_at' => 'datetime'];
    }

    public function firings(): HasMany
    {
        return $this->hasMany(WatchFiring::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
