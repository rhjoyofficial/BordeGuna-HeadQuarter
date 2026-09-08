<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Narrows one human to one business. Read through App\Support\Authorization\BusinessScope, never
 * queried directly by a controller: the "no rows means group-wide" rule has to live in exactly one
 * place or it will eventually be implemented twice and differently.
 */
class UserBusinessScope extends Model
{
    protected $fillable = ['user_id', 'business_id', 'access'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
