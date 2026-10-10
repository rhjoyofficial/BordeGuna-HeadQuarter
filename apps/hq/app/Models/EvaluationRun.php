<?php

namespace App\Models;

use App\Gateway\Descriptor\AutonomyLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of a suite, stamped with everything that was in force (ADR-023).
 *
 * ⭐ The version stamps are the point. Without them a regression can be NOTICED but never
 * ATTRIBUTED, and "something got worse last week" is not a finding anybody can act on. With them,
 * a drop in agreement rate points at the prompt version, the model, the policy version or the
 * autonomy level that changed.
 */
class EvaluationRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid', 'suite_id', 'model', 'model_tier', 'prompt_version', 'skill_version',
        'policy_version', 'autonomy_level', 'agent_key', 'business_id', 'channel', 'status',
        'cases_total', 'cases_passed', 'cases_failed', 'cases_skipped', 'cases_unmeasured', 'agreement_rate', 'dimension_rates', 'check_rates', 'family_rates', 'external_outcomes', 'cost_usd',
        'cost_estimated_usd', 'cost_provider_usd', 'cost_attribution',
        'compared_to_run_id', 'regressions', 'triggered_by', 'triggered_by_user_id',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'autonomy_level' => AutonomyLevel::class,
            'agreement_rate' => 'float',
            'dimension_rates' => 'array',
            'check_rates' => 'array',
            'family_rates' => 'array',
            'external_outcomes' => 'array',
            'cost_usd' => 'decimal:6',
            // docs/106: the estimate and the provider-reported figure, kept apart; how they were reached.
            'cost_estimated_usd' => 'decimal:6',
            'cost_provider_usd' => 'decimal:6',
            'cost_attribution' => 'array',
            'regressions' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(EvaluationSuite::class, 'suite_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(EvaluationResult::class, 'run_id');
    }

    public function comparedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compared_to_run_id');
    }

    public function passed(): bool
    {
        return $this->status === 'passed';
    }
}
