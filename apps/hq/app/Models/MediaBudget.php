<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One business's monthly media spending cap in BDT (ruling 31 Oct item 12). No row = BDT 0 = no paid media. Set only by the owner. */
class MediaBudget extends Model
{
    protected $fillable = ['business_id', 'monthly_limit_bdt', 'set_by_user_id', 'reason'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
