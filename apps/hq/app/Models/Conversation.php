<?php

namespace App\Models;

use App\Gateway\Descriptor\Channel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A long-running thread with a person, on whatever channel they used (Phase 2.5 F).
 *
 * ⛔ Not keyed by phone number. The same person reaches the group on Messenger, then WhatsApp, then
 * by calling; the thread is keyed by its own id and linked to a customer through the identity model
 * that already exists. `external_thread_ref` is the platform's id for its own thread, which is an
 * attribute of this conversation, never its identity.
 *
 * `owner` ships as `none` and stays there in Phase 2.5: no agent exists, so nothing is AI-owned.
 */
class Conversation extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'business_id', 'brand_id', 'customer_id', 'channel', 'external_thread_ref',
        'subject', 'status', 'owner', 'handoff_state', 'assigned_user_id', 'assigned_agent_key',
        'opened_at', 'last_message_at', 'closed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'metadata' => 'array',
            'opened_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** HasUuids would otherwise treat `id` as the uuid column. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isAiOwned(): bool
    {
        return $this->owner === 'ai';
    }
}
