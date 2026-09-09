<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A named set of evaluation cases (ADR-023).
 *
 * ⛔ A suite marked `is_permanent` cannot be deleted. The safety corpus is the one that would be
 * quietly dropped on the day it becomes inconvenient, which is precisely the day it is doing its
 * job, so the rule lives in the model rather than in a convention.
 */
class EvaluationSuite extends Model
{
    protected $fillable = ['key', 'name', 'purpose', 'kind', 'is_permanent', 'is_active', 'pass_threshold'];

    protected function casts(): array
    {
        return ['is_permanent' => 'boolean', 'is_active' => 'boolean', 'pass_threshold' => 'float'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $suite) {
            if ($suite->is_permanent) {
                throw new LogicException("Evaluation suite [{$suite->key}] is permanent and cannot be deleted. Deactivate a case if one is wrong; never remove the corpus.");
            }
        });
    }

    public function cases(): HasMany
    {
        return $this->hasMany(EvaluationCase::class, 'suite_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'suite_id');
    }

    public function isSafety(): bool
    {
        return $this->kind === 'safety';
    }
}
