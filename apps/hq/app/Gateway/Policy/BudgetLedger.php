<?php

namespace App\Gateway\Policy;

use App\Models\Agent;
use App\Models\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Rate limits and spend budgets, checked BEFORE execution rather than discovered on an invoice.
 *
 * Three separate ideas, deliberately not merged:
 *
 *  - **Call rate**, from a policy's `max_calls_per_hour` / `max_calls_per_day`. A counter per tool,
 *    per business, per window.
 *  - **Agent daily cost and call caps**, from the agent's own row. No agent exists in M2, so this
 *    reads zero; the code path exists so M5 does not have to invent it under time pressure.
 *  - **The AI spend budget**, which is a SOFT ADVISORY cap (decision V.8): notify at 80 percent,
 *    downgrade the model tier at 100 percent, and **never refuse work because of it**. A briefing
 *    that did not run because of an advisory cap would be the cap lying about its own name.
 *
 * Counters live in the cache because they are hot and rebuildable. A lost counter fails OPEN for
 * rate limiting, which is the correct direction: the policy engine is the thing that refuses, and a
 * cache outage must not become a business outage.
 */
final class BudgetLedger
{
    public function __construct() {}

    /** @return array{allowed: bool, reason: ?string} */
    public function checkCallRate(string $toolKey, ?int $businessId, PolicyRules $rules): array
    {
        $scope = $businessId === null ? 'all' : (string) $businessId;

        if (($perHour = $rules->maxCallsPerHour()) !== null) {
            $used = $this->count("hour:{$toolKey}:{$scope}:".CarbonImmutable::now()->format('YmdH'));
            if ($used >= $perHour) {
                return ['allowed' => false, 'reason' => "[{$toolKey}] has used its {$perHour} calls for this hour."];
            }
        }

        if (($perDay = $rules->maxCallsPerDay()) !== null) {
            $used = $this->count("day:{$toolKey}:{$scope}:".CarbonImmutable::now()->format('Ymd'));
            if ($used >= $perDay) {
                return ['allowed' => false, 'reason' => "[{$toolKey}] has used its {$perDay} calls for today."];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /** Called after a call is permitted, so a refused call never consumes somebody's quota. */
    public function recordCall(string $toolKey, ?int $businessId): void
    {
        $scope = $businessId === null ? 'all' : (string) $businessId;
        $now = CarbonImmutable::now();

        $this->increment("hour:{$toolKey}:{$scope}:".$now->format('YmdH'), 3600);
        $this->increment("day:{$toolKey}:{$scope}:".$now->format('Ymd'), 86400);
    }

    /**
     * The soft advisory AI spend cap (decision V.8).
     *
     * @return array{spent: float, cap: float, share: float, notify: bool, downgrade: bool}
     */
    public function aiSpendThisMonth(): array
    {
        $cap = (float) config('hq.gateway.ai_monthly_soft_cap_usd', 50.0);

        // `ai_runs` is empty in M2 because no model has been called. Reading it now rather than
        // stubbing means M4 inherits a working budget instead of a placeholder.
        $spent = (float) AiRun::query()
            ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
            ->sum('estimated_cost_usd');

        $share = $cap > 0 ? $spent / $cap : 0.0;

        return [
            'spent' => $spent,
            'cap' => $cap,
            'share' => $share,
            'notify' => $share >= 0.8,
            'downgrade' => $share >= 1.0,
        ];
    }

    /**
     * An agent's own daily caps. Returns null when there is nothing to enforce.
     *
     * @return array{allowed: bool, reason: ?string}|null
     */
    public function checkAgentBudget(?Agent $agent): ?array
    {
        if ($agent === null) {
            return null;
        }

        $today = CarbonImmutable::now()->startOfDay();

        if ($agent->daily_call_cap !== null) {
            $calls = AiRun::query()->where('agent_key', $agent->key)->where('created_at', '>=', $today)->count();
            if ($calls >= $agent->daily_call_cap) {
                return ['allowed' => false, 'reason' => "Agent [{$agent->key}] has used its {$agent->daily_call_cap} runs for today."];
            }
        }

        if ($agent->daily_cost_cap_usd !== null) {
            $cost = (float) AiRun::query()->where('agent_key', $agent->key)->where('created_at', '>=', $today)->sum('estimated_cost_usd');
            if ($cost >= (float) $agent->daily_cost_cap_usd) {
                return ['allowed' => false, 'reason' => "Agent [{$agent->key}] has reached its daily cost cap."];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    private function count(string $suffix): int
    {
        return (int) Cache::get('hq:budget:'.$suffix, 0);
    }

    private function increment(string $suffix, int $ttl): void
    {
        $key = 'hq:budget:'.$suffix;
        // add() then increment() so the first write establishes the expiry; a counter without a TTL
        // would quietly become a permanent limit.
        Cache::add($key, 0, $ttl);
        Cache::increment($key);
    }
}
