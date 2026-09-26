<?php

namespace App\Models;

use App\Content\ClaimsPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * A versioned brief per brand (docs/85 C.15): persona, the owner's claims policy, banned words.
 * Append-only in the database; a change is a new version.
 */
class ContentBrief extends Model
{
    protected $fillable = [
        'brand_id', 'version', 'persona', 'claims_policy', 'banned_words', 'imagery_rule', 'typography',
        'model_tier', 'provenance', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'claims_policy' => 'array',
            'banned_words' => 'array',
            'typography' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function policy(): ClaimsPolicy
    {
        return ClaimsPolicy::fromArray($this->claims_policy ?? [], $this->banned_words ?? []);
    }
}
