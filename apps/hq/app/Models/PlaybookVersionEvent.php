<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One act on a playbook version: drafted, edited, submitted, approved, sent back, activated, retired,
 * withdrawn. Append-only (the database refuses UPDATE and DELETE on pgsql), with a snapshot of the
 * version as it was and the content digest at that moment (docs/112 §2).
 */
class PlaybookVersionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['playbook_version_id', 'event', 'actor_user_id', 'actor_label', 'reason', 'snapshot', 'content_digest', 'occurred_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'occurred_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PlaybookVersion::class, 'playbook_version_id');
    }
}
