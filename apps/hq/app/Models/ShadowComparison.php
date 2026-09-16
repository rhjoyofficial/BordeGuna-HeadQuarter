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
    protected $fillable = ['conversation_id', 'agent_run_id', 'hq_message_id', 'external_message_ref', 'external_message_id', 'hq_digest', 'external_digest', 'compared_at', 'verdict', 'verdict_by', 'verdict_at'];

    protected function casts(): array
    {
        return ['compared_at' => 'datetime', 'verdict_at' => 'datetime'];
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function externalMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'external_message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
