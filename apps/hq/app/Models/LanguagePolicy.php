<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of the customer language policy (docs/92 A.8): which language and script a reply must be
 * written in for each language the conversation core records on a thread, and what the script check
 * exempts. The rules are data (`rules`, jsonb), the version is the contract a run names.
 *
 * ⛔ A version is immutable once activated, at the database level. Change means a new version.
 * ⛔ At most one version is active (partial unique index on pgsql).
 * ⛔ Activation is the act of a named person through `hq:language-policy:activate`; the model never
 *    chooses the policy and a prompt never overrides it.
 */
class LanguagePolicy extends Model
{
    use HasUuids;

    public const PROPOSED = 'proposed';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $fillable = [
        'uuid', 'version', 'name', 'summary', 'status', 'rules', 'supersedes_id', 'proposed_by', 'proposed_at',
        'activated_by', 'activated_at', 'activation_reason', 'retired_by', 'retired_at', 'retirement_reason', 'content_digest',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rules' => 'array',
            'proposed_at' => 'datetime',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(LanguagePolicyEvent::class, 'policy_id')->orderBy('id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
