<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A transcript a person agreed to keep. Visible only to the person who made it; expires. */
class VoiceTranscript extends Model
{
    protected $fillable = ['uuid', 'user_id', 'business_id', 'provider', 'language', 'text', 'confidence', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
