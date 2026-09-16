<?php

namespace App\Observe\Alerts\Rules;

use App\Models\Approval;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * Approvals still waiting past their decision deadline (docs/48 N.5). The approvals sweep already
 * escalates and sends each one, so this is the standing count, recorded and shown.
 */
final class ApprovalsPastDeadline implements AlertRule
{
    public function key(): string
    {
        return 'approvals.past_deadline';
    }

    public function pagedBy(): ?string
    {
        return 'approvals sweep';
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $query = Approval::query()->pending()->whereNotNull('sla_due_at')->where('sla_due_at', '<=', $now);
        $count = (clone $query)->count();

        if ($count === 0) {
            return [];
        }

        $oldest = CarbonImmutable::parse((clone $query)->min('sla_due_at'));

        return [new AlertCondition('pending', 'warning', "{$count} approval(s) past their decision deadline",
            'The oldest was due '.$oldest->timezone(config('hq.timezone'))->format('D j M H:i T').'. They still wait; none approves itself. Open HQ > Approvals.',
            ['count' => $count, 'oldest_due_at' => $oldest->toIso8601String()])];
    }
}
