<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What HQ proposed beside what the n8n bot actually sent, for the shadow week (docs/22, gate P3-G3).
 *
 * Digests, not texts: the texts are already in `conversation_messages` and in n8n's own store, and a
 * comparison table that copied them would be a third place personal data lives.
 */
class ShadowComparison extends Model
{
    protected $fillable = ['conversation_id', 'hq_message_id', 'external_message_ref', 'hq_digest', 'external_digest', 'compared_at', 'verdict'];

    protected function casts(): array
    {
        return ['compared_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
