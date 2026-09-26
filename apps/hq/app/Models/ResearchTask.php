<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bounded research question (docs/85 C.3, ADR-051 point 1): a question, a scope, an owner-approved
 * source list and a budget. Nothing it reads can widen any of them; there is no method that does.
 */
class ResearchTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'contract_version', 'business_id', 'brand_id', 'question', 'scope', 'approved_sources',
        'sources_approved_by', 'freshness_days', 'max_searches', 'max_fetches', 'cost_ceiling_usd',
        'searches_used', 'fetches_used', 'cost_spent_usd', 'search_provider', 'requester_user_id',
        'status', 'verdict_reason', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'approved_sources' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ResearchCandidate::class, 'task_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ResearchSource::class, 'task_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ResearchArtifact::class, 'task_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
