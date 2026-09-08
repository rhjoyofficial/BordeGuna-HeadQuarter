<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One spending cap, on `ai_budgets`.
 *
 * Named `AiBudgetRow` rather than `AiBudget` because `App\Ai\Budget\AiBudget` is the service that
 * decides what a cap MEANS. A row is data; the judgement about notify, degrade or refuse is not.
 */
class AiBudgetRow extends Model
{
    protected $table = 'ai_budgets';

    protected $fillable = [
        'scope_type', 'scope_ref', 'period', 'limit_usd',
        'enforcement', 'notify_at_percent', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'limit_usd' => 'decimal:4',
            'notify_at_percent' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isHard(): bool
    {
        return $this->enforcement === 'hard';
    }
}
