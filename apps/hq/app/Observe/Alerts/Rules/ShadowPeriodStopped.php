<?php

namespace App\Observe\Alerts\Rules;

use App\Models\ShadowPeriod;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * An A0 shadow period was stopped by a safety condition (ADR-045, docs/77 C.2).
 *
 * Nothing else tells a person that a shadow week ended early, so HQ sends it. It stays open for the
 * window, or until a newer period is opened for the same agent, whichever comes first.
 */
final class ShadowPeriodStopped implements AlertRule
{
    public function key(): string
    {
        return 'shadow.period_stopped';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $days = max(1, (int) config('hq.alerts.thresholds.shadow_stopped_window_days', 7));
        $conditions = [];

        $stopped = ShadowPeriod::query()
            ->where('status', ShadowPeriod::STATUS_STOPPED)
            ->where('closed_at', '>=', $now->subDays($days))
            ->orderBy('closed_at')
            ->get();

        foreach ($stopped as $period) {
            $superseded = ShadowPeriod::query()
                ->where('agent_key', $period->agent_key)
                ->where('opened_at', '>', $period->opened_at)
                ->exists();

            if ($superseded) {
                continue;
            }

            $violations = count($period->violations ?? []);
            $conditions[] = new AlertCondition("period:{$period->uuid}", 'critical',
                "The {$period->agent_key} shadow period was stopped",
                'Stopped '.$period->closed_at->timezone(config('hq.timezone'))->format('D j M H:i T')
                .' ('.($period->end_reason ?? 'no reason recorded')."), {$violations} violation(s) recorded. The agent is paused and nothing continues on its own. "
                ."Read it with php artisan hq:shadow:evidence --period={$period->id} and docs/runbooks/shadow-week.md.",
                ['shadow_period' => $period->uuid, 'agent_key' => $period->agent_key, 'violations' => $violations, 'end_reason' => $period->end_reason]);
        }

        return $conditions;
    }
}
