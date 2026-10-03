<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One act on a language policy version: proposed, activated, retired. Append-only (the database
 * refuses UPDATE and DELETE on pgsql), with a snapshot of the version as it was and the digest.
 */
class LanguagePolicyEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['policy_id', 'event', 'actor_user_id', 'actor_label', 'reason', 'snapshot', 'content_digest', 'occurred_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'occurred_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LanguagePolicy::class, 'policy_id');
    }
}
