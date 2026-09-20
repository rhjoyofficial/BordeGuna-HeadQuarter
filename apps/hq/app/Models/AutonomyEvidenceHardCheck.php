<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grader check that must be 100 percent before a level is eligible, whatever the rates say
 * (ADR-048). `check_key` is a key of `DeterministicGrader::CHECKS`, for example `must_not_call`.
 * There is no rate column: one `fail` is a failure.
 */
class AutonomyEvidenceHardCheck extends Model
{
    protected $fillable = ['policy_id', 'autonomy_level', 'check_key', 'min_sample_count', 'max_evidence_age_days', 'note'];

    protected function casts(): array
    {
        return [
            'min_sample_count' => 'integer',
            'max_evidence_age_days' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AutonomyEvidencePolicy::class, 'policy_id');
    }

    public function undecidedFields(): array
    {
        return array_keys(array_filter([
            'min_sample_count' => $this->min_sample_count === null,
            'max_evidence_age_days' => $this->max_evidence_age_days === null,
        ]));
    }
}
