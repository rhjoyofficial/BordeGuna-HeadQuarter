<?php

namespace App\Ai\Budget;

use App\Models\AiBudgetRow;
use App\Models\AiRun;
use Carbon\CarbonInterface;

/**
 * What HQ has spent on models, and what it is allowed to spend.
 *
 * Two scopes are enforced here: a **global** cap and a **per-business** cap. The per-agent cap is
 * NOT here: it already lives on the `agents` row (`daily_cost_cap_usd`, `daily_call_cap`) and is
 * enforced by the policy engine before a tool ever runs. Two places claiming to own the same limit
 * is how one of them silently stops being consulted, so this class defers to that one.
 *
 * ⚠ **The shipped enforcement is SOFT** (decision V.8). At 80 percent of a cap it notifies; at 100
 * percent it degrades the tier. It does not refuse, because a cap that silently stops the morning
 * briefing is a worse failure than the overspend it prevents. A `hard` row exists for when Joy asks
 * for one, and even then the refusal is announced rather than silent.
 *
 * ⚠ Spend is summed from `actual_cost_usd` where it exists and `estimated_cost_usd` otherwise, never
 * from both for the same run. Mixing an estimate and a reported figure in one total produces a
 * number that reconciles against nothing.
 */
final class AiBudget
{
    /** Used when no `ai_budgets` row exists, so a cap always exists even before anybody sets one. */
    public const DEFAULT_GLOBAL_MONTHLY_USD = 50.0;

    /**
     * @return array{spent: float, cap: float, share: float, share_percent: int, notify: bool, downgrade: bool, refuse: bool, scope: string}
     */
    public function pressure(?int $businessId = null): array
    {
        $business = $businessId !== null ? $this->evaluate('business', (string) $businessId, 'monthly') : null;

        // The tighter of the two wins. A business under its own cap still cannot spend past the
        // group's, and a group with room does not license a business to exceed its own limit.
        if ($business !== null && $business['refuse']) {
            return $business;
        }

        $global = $this->evaluate('global', null, 'monthly');

        if ($business === null) {
            return $global;
        }

        return $business['share'] >= $global['share'] ? $business : $global;
    }

    /**
     * @return array{spent: float, cap: float, share: float, share_percent: int, notify: bool, downgrade: bool, refuse: bool, scope: string}
     */
    public function evaluate(string $scopeType, ?string $scopeRef, string $period): array
    {
        $row = AiBudgetRow::query()
            ->where('scope_type', $scopeType)
            ->where('scope_ref', $scopeRef)
            ->where('period', $period)
            ->where('is_active', true)
            ->first();

        $cap = $row !== null
            ? (float) $row->limit_usd
            : ($scopeType === 'global'
                ? (float) config('hq.gateway.ai_monthly_soft_cap_usd', self::DEFAULT_GLOBAL_MONTHLY_USD)
                // A business with no row of its own is bounded only by the group cap. Inventing a
                // per-business number here would be a policy decision made by a default value.
                : INF);

        $spent = $this->spend($scopeType, $scopeRef, $period);
        $share = $cap > 0 && is_finite($cap) ? $spent / $cap : 0.0;
        $notifyAt = ($row?->notify_at_percent ?? 80) / 100;
        $hard = $row?->enforcement === 'hard';

        return [
            'spent' => round($spent, 6),
            'cap' => $cap,
            'share' => $share,
            'share_percent' => (int) round($share * 100),
            'notify' => $share >= $notifyAt,
            'downgrade' => $share >= 1.0 && ! $hard,
            'refuse' => $share >= 1.0 && $hard,
            'scope' => $scopeRef === null ? $scopeType : "{$scopeType}:{$scopeRef}",
        ];
    }

    public function spend(string $scopeType, ?string $scopeRef, string $period): float
    {
        $query = AiRun::query()->where('created_at', '>=', $this->periodStart($period));

        if ($scopeType === 'business' && $scopeRef !== null) {
            $query->where('business_id', (int) $scopeRef);
        } elseif ($scopeType === 'agent' && $scopeRef !== null) {
            $query->where('agent_key', $scopeRef);
        }

        // COALESCE, not two sums added together: a run contributes its reported cost when it has
        // one and its estimate otherwise, and never both.
        return (float) $query->sum(
            AiRun::query()->getConnection()->raw('COALESCE(actual_cost_usd, estimated_cost_usd, 0)')
        );
    }

    /**
     * ⚠ Typed `CarbonInterface`, not `Illuminate\Support\Carbon`. `AppServiceProvider` calls
     * `Date::use(CarbonImmutable::class)`, so `now()` returns a `CarbonImmutable`, which is NOT a
     * subclass of `Illuminate\Support\Carbon` - the narrower hint threw a `TypeError` on every
     * call that touched a budget, which is most of M4.
     */
    private function periodStart(string $period): CarbonInterface
    {
        return $period === 'daily' ? now()->startOfDay() : now()->startOfMonth();
    }

    /**
     * Spend broken down along the dimensions `ai_runs` already carries.
     *
     * @return array<string, mixed>
     */
    public function report(string $period = 'monthly'): array
    {
        $since = $this->periodStart($period);
        $cost = 'COALESCE(actual_cost_usd, estimated_cost_usd, 0)';
        $connection = AiRun::query()->getConnection();

        $by = fn (string $column) => AiRun::query()
            ->where('created_at', '>=', $since)
            ->selectRaw("{$column} as k, count(*) as runs, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cached_tokens) as cached_tokens, sum({$cost}) as usd")
            ->groupBy($connection->raw($column))
            ->orderByRaw('usd desc')
            ->get()
            ->map(fn ($r) => [
                'key' => $r->k ?? '(none)',
                'runs' => (int) $r->runs,
                'input_tokens' => (int) $r->input_tokens,
                'output_tokens' => (int) $r->output_tokens,
                'cached_tokens' => (int) $r->cached_tokens,
                'usd' => round((float) $r->usd, 6),
            ])
            ->all();

        return [
            'period' => $period,
            'since' => $since->toIso8601String(),
            'total_usd' => round((float) AiRun::query()->where('created_at', '>=', $since)->sum($connection->raw($cost)), 6),
            'runs' => AiRun::query()->where('created_at', '>=', $since)->count(),
            'by_model' => $by('model'),
            'by_tier' => $by('model_tier'),
            'by_task' => $by('task'),
            'by_agent' => $by('agent_key'),
            'by_business' => $by('business_id'),
            'by_status' => $by('status'),
            'degraded_runs' => AiRun::query()->where('created_at', '>=', $since)->whereNotNull('degraded_from_tier')->count(),
        ];
    }
}
