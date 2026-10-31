<?php

namespace App\Models;

use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reel or video DRAFT from the media pipeline. A person approves or rejects it; publishing is a separate gate. */
class MediaDraft extends Model
{
    protected $fillable = ['uuid', 'business_id', 'brand_id', 'kind', 'brief', 'source_asset_ids', 'provider', 'provider_job_ref', 'status',
        'result_media_asset_id', 'error', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['source_asset_ids' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'result_media_asset_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(BusinessScope::class)->restrict($query, $user, $query->qualifyColumn('business_id'));
    }
}
