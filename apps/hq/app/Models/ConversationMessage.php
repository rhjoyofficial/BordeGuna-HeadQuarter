<?php

namespace App\Models;

use App\Context\ContextBlock;
use App\Context\Provenance;
use App\Gateway\Descriptor\Channel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One message in a conversation, carrying the provenance of its own text (ADR-024).
 *
 * ⛔ An inbound customer message is UNTRUSTED and says so in its own row, so everything downstream
 * (context assembly, memory, evaluation) inherits the label instead of re-deciding it. A message
 * that loses its label on the way into memory is a delayed injection with the label removed.
 */
class ConversationMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'conversation_id', 'direction', 'author_type', 'customer_id', 'user_id',
        'agent_key', 'channel', 'external_message_ref', 'body', 'provenance', 'content_digest',
        'ai_run_id', 'tool_call_id', 'status', 'sent_at', 'received_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'provenance' => Provenance::class,
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        // The digest is what an audit points at when the body itself is too long, too personal, or
        // has since been redacted. Computed here so no caller can forget it.
        static::saving(function (self $message) {
            if ($message->body !== null && $message->content_digest === null) {
                $message->content_digest = hash('sha256', $message->body);
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'conversation_message_attachments', 'message_id', 'media_asset_id')
            ->withTimestamps();
    }

    /** The message as a LABELLED context block, so it cannot enter a prompt unlabelled. */
    public function toContextBlock(): ContextBlock
    {
        return new ContextBlock(
            provenance: $this->provenance,
            source: "conversation:{$this->conversation_id}/message:{$this->id}",
            content: (string) $this->body,
        );
    }
}
