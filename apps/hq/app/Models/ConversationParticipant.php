<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who is in a conversation: a customer, an operator, an agent, or HQ itself.
 *
 * `identity_id` points at the `customer_identities` row the person actually arrived through, which
 * is what lets one conversation be linked to a person who has three identities and no phone number.
 */
class ConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id', 'participant_type', 'customer_id', 'user_id', 'agent_key',
        'identity_id', 'role', 'joined_at', 'left_at',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
