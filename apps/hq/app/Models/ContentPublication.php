<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One publication of one draft to one account (docs/85 C.12, C.20). `published` is written only after
 * the post was read back and matched; until then it is `uncertain` (ADR-031).
 */
class ContentPublication extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'draft_id', 'platform_account_id', 'variant', 'logical_key', 'content_digest', 'approval_id',
        'status', 'platform_post_id', 'read_back', 'read_back_at', 'published_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'read_back' => 'array',
            'read_back_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
