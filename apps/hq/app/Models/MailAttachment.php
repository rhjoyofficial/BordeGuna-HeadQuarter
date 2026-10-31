<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAttachment extends Model
{
    protected $fillable = ['mail_message_id', 'business_id', 'media_asset_id', 'filename', 'mime', 'bytes', 'sha256', 'scan_status', 'rejected_reason'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
