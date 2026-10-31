<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** docs/143: one owner-only Telegram brief delivery (docs/143 D). */
class OwnerBriefDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function briefing(): BelongsTo
    {
        return $this->belongsTo(Briefing::class);
    }
}
