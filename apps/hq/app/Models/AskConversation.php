<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One operator's Ask HQ conversation (docs/48 K.2). Trusted input from an authenticated, 2FA-verified
 * person, kept in its own table on purpose: customer conversations are untrusted, and one table with
 * a `kind` column would make every query's safety depend on a remembered `where`.
 */
class AskConversation extends Model
{
    protected $fillable = ['uuid', 'user_id', 'agent_key', 'business_id', 'title', 'status', 'last_activity_at'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AskMessage::class)->orderBy('id');
    }
}
