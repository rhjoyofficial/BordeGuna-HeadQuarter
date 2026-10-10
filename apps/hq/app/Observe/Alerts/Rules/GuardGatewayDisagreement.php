<?php

namespace App\Observe\Alerts\Rules;

use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use App\Observe\Alerts\GuardGatewayDisagreements as Counter;
use Carbon\CarbonImmutable;

/**
 * A runtime guard keeps refusing effectful calls the Tool Gateway would have authorized (docs/116, ruling 110 §2g).
 *
 * ⛔ Not on one refusal: an isolated disagreement is audit only. It fires when one agent reaches the threshold in
 * the window (default 3 in 24 hours, `hq.alerts.thresholds.guard_gateway_disagreement`): a repeated pattern means
 * the guard and the gateway's policy have drifted apart, and a person should decide which one is right. Nothing
 * ran in any of these cases; the refusal always stands.
 */
final class GuardGatewayDisagreement implements AlertRule
{
    public function __construct(private readonly Counter $counter) {}

    public function key(): string
    {
        return 'agents.guard_gateway_disagreement';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $threshold = max(1, (int) config('hq.alerts.thresholds.guard_gateway_disagreement.count', 3));
        $hours = max(1, (int) config('hq.alerts.thresholds.guard_gateway_disagreement.window_hours', 24));

        $conditions = [];
        foreach ($this->counter->recent($hours, $now) as $agent => $count) {
            if ($count < $threshold) {
                continue;
            }

            $conditions[] = new AlertCondition("agent:{$agent}", 'warning', "Agent {$agent}: a runtime guard and the gateway disagree, repeatedly",
                "{$count} effectful call(s) in the last {$hours} hours were refused by a runtime guard that the Tool Gateway would have authorized. "
                .'Nothing ran; the refusals stand. Read the `agent_run.effectful_call_refused` audit rows (gateway_would_authorize = true) and decide '
                .'whether the guard is too narrow or the gateway policy too wide.',
                ['agent_key' => $agent, 'count' => $count, 'window_hours' => $hours, 'threshold' => $threshold]);
        }

        return $conditions;
    }
}
