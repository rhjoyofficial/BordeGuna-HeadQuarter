<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One inbound supplier email, as read from a business's mailbox. Untrusted content; HQ's own copy. */
class MailMessage extends Model
{
    protected $fillable = ['uuid', 'business_id', 'connector', 'external_id', 'from_address', 'subject', 'received_at', 'body_text'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }
}
