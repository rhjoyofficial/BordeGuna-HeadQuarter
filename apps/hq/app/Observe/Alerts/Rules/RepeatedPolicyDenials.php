<?php

namespace App\Observe\Alerts\Rules;

use App\Models\AgentRun;
use App\Models\ToolCall;
use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;

/**
 * An agent keeps being refused by policy (docs/48 N.5): several denied tool calls within the window,
 * or a run the runtime stopped for repeated denials in the last day. Either means the agent is trying
 * something it may not do, and a person should read those runs.
 */
final class RepeatedPolicyDenials implements AlertRule
{
    public function key(): string
    {
        return 'agents.repeated_denials';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $threshold = max(1, (int) config('hq.alerts.thresholds.repeated_denials.count', 3));
        $window = max(1, (int) config('hq.alerts.thresholds.repeated_denials.window_minutes', 60));

        $denials = ToolCall::query()
            ->whereNotNull('agent_key')
            ->where('status', 'denied')
            ->where('created_at', '>=', $now->subMinutes($window))
            ->groupBy('agent_key')
            ->selectRaw('agent_key, count(*) as n')
            ->pluck('n', 'agent_key');

        $stopped = AgentRun::query()
            ->where('stop_reason', 'repeated_denial')
            ->where('created_at', '>=', $now->subDay())
            ->groupBy('agent_key')
            ->selectRaw('agent_key, count(*) as n')
            ->pluck('n', 'agent_key');

        $conditions = [];
        foreach ($denials->keys()->merge($stopped->keys())->unique()->sort() as $agent) {
            $denied = (int) ($denials[$agent] ?? 0);
            $runs = (int) ($stopped[$agent] ?? 0);

            if ($denied < $threshold && $runs === 0) {
                continue;
            }

            $conditions[] = new AlertCondition("agent:{$agent}", 'warning', "Agent {$agent} is repeatedly refused by policy",
                "{$denied} denied tool call(s) in the last {$window} minutes; {$runs} run(s) stopped for repeated denials in the last day. Read the runs on HQ > Agents.",
                ['agent_key' => $agent, 'denied' => $denied, 'window_minutes' => $window, 'stopped_runs' => $runs]);
        }

        return $conditions;
    }
}
