<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of the autonomy evidence policy (ADR-048): what evidence each autonomy level needs,
 * per evaluation dimension, stated once and independently of any grant.
 *
 * ⛔ A version is immutable once activated, at the database level. Change means a new version.
 * ⛔ At most one version is active. A NULL numeric field means "not decided", and the database
 *    refuses to activate a version holding one.
 * ⛔ The policy is a CEILING the resolver combines by minimum. It never creates a grant and never
 *    raises a level on its own: a grant is still required to go above A0.
 */
class AutonomyEvidencePolicy extends Model
{
    use HasUuids;

    public const PROPOSED = 'proposed';

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $fillable = [
        'uuid', 'version', 'name', 'summary', 'status', 'supersedes_id', 'proposed_by', 'proposed_at',
        'activated_by', 'activated_at', 'activation_reason', 'retired_by', 'retired_at', 'retirement_reason', 'content_digest',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'proposed_at' => 'datetime',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(AutonomyEvidenceRequirement::class, 'policy_id')->orderBy('autonomy_level')->orderBy('dimension');
    }

    public function hardChecks(): HasMany
    {
        return $this->hasMany(AutonomyEvidenceHardCheck::class, 'policy_id')->orderBy('autonomy_level')->orderBy('check_key');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AutonomyEvidencePolicyEvent::class, 'policy_id')->orderBy('id');
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
