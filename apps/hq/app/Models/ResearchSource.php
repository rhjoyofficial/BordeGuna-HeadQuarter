<?php

namespace App\Models;

use App\Context\Provenance;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A fetched page (docs/85 C.4): third-party, untrusted, and never a source of authority. The body is
 * kept only while the task is open, so a claim's span can be checked against it; the hash stays.
 */
class ResearchSource extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'task_id', 'url', 'url_key', 'canonical_host', 'title', 'publisher', 'retrieved_at',
        'published_at', 'content_hash', 'bytes', 'found_via', 'candidate_id', 'provenance', 'body',
        'body_purged_at',
    ];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return [
            'provenance' => Provenance::class,
            'retrieved_at' => 'datetime',
            'published_at' => 'datetime',
            'body_purged_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
