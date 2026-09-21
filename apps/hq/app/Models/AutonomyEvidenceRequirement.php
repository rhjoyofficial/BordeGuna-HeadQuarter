<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one autonomy level requires of one evaluation dimension (ADR-048).
 *
 * `min_agreement_rate`, `min_sample_count` and `max_evidence_age_days` are NULL until a human has
 * decided them, and a NULL is never met. `required_measured_state` has one value, `fully_measured`:
 * an unmeasured case blocks. There is no `overall` dimension here on purpose (ADR-030).
 */
class AutonomyEvidenceRequirement extends Model
{
    public const FULLY_MEASURED = 'fully_measured';

    protected $fillable = [
        'policy_id', 'autonomy_level', 'dimension', 'min_agreement_rate', 'min_sample_count',
        'max_evidence_age_days', 'required_measured_state', 'note',
    ];

    protected function casts(): array
    {
        return [
            'min_agreement_rate' => 'float',
            'min_sample_count' => 'integer',
            'max_evidence_age_days' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AutonomyEvidencePolicy::class, 'policy_id');
    }

    /** The fields a human must decide before the version may become active. */
    public function undecidedFields(): array
    {
        return array_keys(array_filter([
            'min_agreement_rate' => $this->min_agreement_rate === null,
            'min_sample_count' => $this->min_sample_count === null,
            'max_evidence_age_days' => $this->max_evidence_age_days === null,
        ]));
    }
}
