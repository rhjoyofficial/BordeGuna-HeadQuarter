<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to deliver one outbound message, and whether the provider later confirmed it (ADR-031).
 *
 * Empty until gate P3-G4: nothing in HQ can send yet.
 */
class MessageDelivery extends Model
{
    protected $fillable = ['message_id', 'attempt', 'provider_ref', 'status', 'verified_at', 'error'];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'verified_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'message_id');
    }
}
