<?php

namespace App\Observe\Dashboards;

use App\Ai\Budget\AiBudget;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cost (docs/48 N.3, docs/26): model spend by day, provider and model, agent and business; what a
 * run, an agent run and a conversation cost; the monthly budget and how fast it is burning; and the
 * calls that ran on a cheaper tier than asked. A run's cost is its reported cost when the provider
 * reported one and its estimate otherwise, never both, exactly as the budget counts it.
 *
 * `ai_runs` stores its times without a zone, in UTC; the day buckets convert them to Dhaka days.
 */
final class CostDashboard
{
    private const COST = 'COALESCE(ai_runs.actual_cost_usd, ai_runs.estimated_cost_usd, 0)';

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly AiBudget $budget,
    ) {}

    public function build(DashboardWindow $window, ?User $user): array
    {
        $base = function () use ($window, $user): Builder {
            $query = AiRun::query()->where('ai_runs.created_at', '>=', $window->since);

            return $user === null ? $query : $this->scope->restrict($query, $user, 'ai_runs.business_id');
        };
        $cost = self::COST;
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');

        $totals = $base()->selectRaw("count(*) as runs, sum({$cost}) as usd, sum(input_tokens) as tokens_in, sum(output_tokens) as tokens_out")->first();
        $runs = (int) ($totals->runs ?? 0);
        $usd = (float) ($totals->usd ?? 0);

        $byDay = $base()
            ->selectRaw("to_char((ai_runs.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day, count(*) as runs, sum({$cost}) as usd", [$timezone])
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => $r->day, 'runs' => (int) $r->runs, 'usd' => round((float) $r->usd, 6)])->all();

        $group = fn (string $column, string $alias) => $base()
            ->selectRaw("{$column} as {$alias}, count(*) as runs, sum({$cost}) as usd")
            ->groupBy($column)->orderByDesc('usd')->get()
            ->map(fn ($r) => [$alias => $r->{$alias}, 'runs' => (int) $r->runs, 'usd' => round((float) $r->usd, 6)])->all();

        $byModel = $base()
            ->selectRaw("provider, model, count(*) as runs, sum({$cost}) as usd, sum(input_tokens) as tokens_in, sum(output_tokens) as tokens_out")
            ->groupBy('provider', 'model')->orderByDesc('usd')->get()
            ->map(fn ($r) => ['provider' => $r->provider, 'model' => $r->model, 'runs' => (int) $r->runs, 'usd' => round((float) $r->usd, 6),
                'tokens_in' => (int) $r->tokens_in, 'tokens_out' => (int) $r->tokens_out])->all();

        $byBusiness = $base()
            ->leftJoin('businesses', 'businesses.id', '=', 'ai_runs.business_id')
            ->selectRaw("businesses.name as business, count(*) as runs, sum({$cost}) as usd")
            ->groupBy('businesses.name')->orderByDesc('usd')->get()
            ->map(fn ($r) => ['business' => $r->business, 'runs' => (int) $r->runs, 'usd' => round((float) $r->usd, 6)])->all();

        $agentRuns = ($user === null ? AgentRun::query() : $this->scope->restrict(AgentRun::query(), $user))
            ->where('created_at', '>=', $window->since)->count();
        $agentSpend = (float) $base()->whereNotNull('agent_key')->sum($base()->getConnection()->raw($cost));

        $conversations = $base()
            ->join('conversation_messages', 'conversation_messages.ai_run_id', '=', 'ai_runs.id')
            ->selectRaw('count(distinct conversation_messages.conversation_id) as conversations')
            ->value('conversations');
        $conversationSpend = (float) $base()
            ->whereIn('ai_runs.id', fn ($q) => $q->select('ai_run_id')->from('conversation_messages')->whereNotNull('ai_run_id'))
            ->sum($base()->getConnection()->raw($cost));

        $degraded = $base()
            ->whereNotNull('degraded_from_tier')
            ->selectRaw('degraded_from_tier, model_tier, count(*) as n')
            ->groupBy('degraded_from_tier', 'model_tier')->get()
            ->map(fn ($r) => ['from' => $r->degraded_from_tier, 'to' => $r->model_tier, 'count' => (int) $r->n])->all();

        $pressure = $this->budget->pressure();
        $month = now()->toImmutable();
        $elapsedDays = max(1, (int) $month->startOfMonth()->diffInDays($month) + 1);

        return [
            'window' => $window->toArray(),
            'totals' => [
                'runs' => $runs,
                'usd' => round($usd, 6),
                'tokens_in' => (int) ($totals->tokens_in ?? 0),
                'tokens_out' => (int) ($totals->tokens_out ?? 0),
                'per_run_usd' => $runs > 0 ? round($usd / $runs, 6) : null,
            ],
            'by_day' => $byDay,
            'by_model' => $byModel,
            'by_agent' => $group('agent_key', 'agent_key'),
            'by_business' => $byBusiness,
            'by_cost_source' => $group('cost_source', 'cost_source'),
            'unit_costs' => [
                'agent_runs' => $agentRuns,
                'per_agent_run_usd' => $agentRuns > 0 ? round($agentSpend / $agentRuns, 6) : null,
                'conversations' => (int) ($conversations ?? 0),
                'per_conversation_usd' => (int) ($conversations ?? 0) > 0 ? round($conversationSpend / (int) $conversations, 6) : null,
                // The daily briefing needs a model to compose it and arrives after the gates; until then
                // there is nothing to divide by, which the page says rather than showing a zero.
                'briefings' => 0,
                'per_briefing_usd' => null,
            ],
            'budget' => [
                'scope' => $pressure['scope'],
                'spent_usd' => round($pressure['spent'], 6),
                'cap_usd' => is_finite($pressure['cap']) ? $pressure['cap'] : null,
                'share_percent' => $pressure['share_percent'],
                'notify' => $pressure['notify'],
                'downgrade' => $pressure['downgrade'],
                'refuse' => $pressure['refuse'],
                'projected_month_usd' => round($pressure['spent'] / $elapsedDays * $month->daysInMonth, 2),
            ],
            'degradations' => $degraded,
        ];
    }
}
