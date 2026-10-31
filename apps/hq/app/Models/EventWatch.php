<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** docs/143: an event watch proposed from the chat (docs/143 F). */
class EventWatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'action' => 'array', 'enabled_at' => 'datetime', 'last_fired_at' => 'datetime'];
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
