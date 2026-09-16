<?php

namespace App\Observe\Alerts\Rules;

use App\Ai\Budget\AiBudget;
use App\Models\AiBudgetRow;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * AI spend at or past a budget's notify line (decision V.8: notify at 80 percent, downgrade at 100).
 *
 * The model runtime already sends the warning once a month per scope, at the call that crosses the
 * line, so this rule records and shows it without a second message.
 */
final class AiBudgetPressure implements AlertRule
{
    public function __construct(private readonly AiBudget $budget) {}

    public function key(): string
    {
        return 'ai.budget';
    }

    public function pagedBy(): ?string
    {
        return 'model runtime';
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $scopes = ['global:-:monthly' => ['global', null, 'monthly']];
        foreach (AiBudgetRow::query()->where('is_active', true)->get(['scope_type', 'scope_ref', 'period']) as $row) {
            $scopes["{$row->scope_type}:".($row->scope_ref ?? '-').":{$row->period}"] = [$row->scope_type, $row->scope_ref, $row->period];
        }

        $conditions = [];
        foreach ($scopes as $subject => [$type, $ref, $period]) {
            $pressure = $this->budget->evaluate($type, $ref, $period);
            if (! $pressure['notify']) {
                continue;
            }

            $reached = $pressure['share'] >= 1.0;
            $conditions[] = new AlertCondition($subject, $reached ? 'critical' : 'warning',
                "AI spend at {$pressure['share_percent']}% of the {$period} cap ({$pressure['scope']})",
                'Spent USD '.number_format($pressure['spent'], 2).' of '.number_format($pressure['cap'], 2).'. '
                .($pressure['refuse'] ? 'This cap is hard: model calls are refused.' : ($reached ? 'HQ is choosing a cheaper model tier rather than stopping.' : 'At 100 percent HQ moves to a cheaper model tier.')),
                ['scope' => $pressure['scope'], 'period' => $period, 'share_percent' => $pressure['share_percent']]);
        }

        return $conditions;
    }
}
