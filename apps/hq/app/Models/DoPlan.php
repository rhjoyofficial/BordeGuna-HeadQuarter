<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** docs/143: a Do-mode plan (docs/143 A). Written only by App\OwnerConsole\Do\DoPlanService. */
class DoPlan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DoPlanStep::class)->orderBy('seq');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
