<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checked draft (docs/85 C.8, C.9). Its words, facts, assets, checks and digest are frozen by a
 * database trigger once written; only the review status moves. An edit is a new draft.
 */
class ContentDraft extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'business_id', 'brand_id', 'brief_id', 'research_artifact_id', 'parent_draft_id', 'platform',
        'variant', 'text', 'text_provenance', 'assets', 'product_facts', 'claim_refs', 'checks', 'status',
        'content_digest', 'created_by', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'assets' => 'array',
            'product_facts' => 'array',
            'claim_refs' => 'array',
            'checks' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class, 'brief_id');
    }
}
