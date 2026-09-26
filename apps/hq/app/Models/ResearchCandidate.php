<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A search result: a URL the task may fetch, not yet a source (docs/85 C.2). */
class ResearchCandidate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'provider', 'query', 'rank', 'url', 'url_key', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
