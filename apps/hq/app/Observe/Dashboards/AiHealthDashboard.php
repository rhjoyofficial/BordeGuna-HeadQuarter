<?php

namespace App\Observe\Dashboards;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\ToolCall;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use Illuminate\Database\Eloquent\Builder;

/**
 * AI health (docs/48 N.3): how the agents' runs ended, why model and tool calls failed, how long model
 * calls took per tier, what policy refused, what waits for a person, and the runs the runtime stopped.
 * Rows are read inside the viewer's business scope, as every other screen reads them.
 */
final class AiHealthDashboard
{
    /** A run that ended the way a run is meant to end, whatever it said. */
    public const ENDED_NORMALLY = ['answered', 'refused', 'clarified'];

    public function __construct(
        private readonly BusinessScope $scope,
        private readonly Features $features,
    ) {}

    public function build(DashboardWindow $window, ?User $user): array
    {
        $scoped = fn (Builder $query, string $column = 'business_id') => $user === null ? $query : $this->scope->restrict($query, $user, $column);

        $runs = $scoped(AgentRun::query())
            ->where('created_at', '>=', $window->since)
            ->groupBy('agent_key', 'status')
            ->selectRaw('agent_key, status, count(*) as n')
            ->get()
            ->groupBy('agent_key')
            ->map(function ($rows, string $agent) {
                $byStatus = $rows->mapWithKeys(fn ($r) => [$r->status => (int) $r->n])->all();
                $total = array_sum($byStatus);
                $normal = array_sum(array_intersect_key($byStatus, array_flip(self::ENDED_NORMALLY)));

                return [
                    'agent_key' => $agent,
                    'total' => $total,
                    'by_status' => $byStatus,
                    'ended_normally_percent' => $total > 0 ? (int) round(100 * $normal / $total) : null,
                ];
            })
            ->sortKeys()->values()->all();

        $modelFailures = $scoped(AiRun::query())
            ->where('created_at', '>=', $window->since)
            ->where('status', '!=', 'succeeded')
            ->groupBy('status', 'stop_reason')
            ->selectRaw('status, stop_reason, count(*) as n')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'stop_reason' => $r->stop_reason, 'count' => (int) $r->n])
            ->all();

        $toolFailures = $scoped(ToolCall::query())
            ->where('created_at', '>=', $window->since)
            ->whereIn('status', ['failed', 'denied', 'invalid', 'unavailable', 'uncertain'])
            ->groupBy('status', 'outcome_class')
            ->selectRaw('status, outcome_class, count(*) as n')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'outcome_class' => $r->outcome_class, 'count' => (int) $r->n])
            ->all();

        $latency = $scoped(AiRun::query())
            ->where('created_at', '>=', $window->since)
            ->whereNotNull('latency_ms')
            ->groupBy('model_tier')
            ->selectRaw('model_tier, count(*) as n, percentile_cont(0.5) within group (order by latency_ms) as p50, percentile_cont(0.95) within group (order by latency_ms) as p95')
            ->orderBy('model_tier')
            ->get()
            ->map(fn ($r) => ['tier' => $r->model_tier, 'calls' => (int) $r->n, 'p50_ms' => (int) round((float) $r->p50), 'p95_ms' => (int) round((float) $r->p95)])
            ->all();

        $denials = $scoped(ToolCall::query(), 'tool_calls.business_id')
            ->leftJoin('policies', 'policies.id', '=', 'tool_calls.policy_id')
            ->where('tool_calls.created_at', '>=', $window->since)
            ->where('tool_calls.status', 'denied')
            ->groupBy('policies.key', 'tool_calls.tool_key')
            ->selectRaw('policies.key as policy, tool_calls.tool_key, count(*) as n')
            ->orderByDesc('n')
            ->limit(20)
            ->get()
            ->map(fn ($r) => ['policy' => $r->policy, 'tool_key' => $r->tool_key, 'count' => (int) $r->n])
            ->all();

        $stops = $scoped(AgentRun::query())
            ->where('created_at', '>=', $window->since)
            ->whereNotNull('stop_reason')
            ->groupBy('stop_reason')
            ->selectRaw('stop_reason, count(*) as n')
            ->pluck('n', 'stop_reason')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'window' => $window->toArray(),
            'ai_runtime' => $this->features->enabled((string) config('hq.ai.enabled_flag', 'ai.runtime')),
            'agents' => Agent::query()->orderBy('key')->get(['key', 'name', 'status', 'max_autonomy_level', 'enabling_user_id'])
                ->map(fn (Agent $a) => [
                    'key' => $a->key,
                    'name' => $a->name,
                    'status' => $a->status,
                    'autonomy' => $a->max_autonomy_level?->value,
                    'has_enabling_user' => $a->enabling_user_id !== null,
                ])->all(),
            'runs_by_agent' => $runs,
            'model_failures' => $modelFailures,
            'tool_failures' => $toolFailures,
            'latency_by_tier' => $latency,
            'denials_by_policy' => $denials,
            'stops' => $stops,
            'loop_bound_hits' => $stops['loop_bound'] ?? 0,
            'approvals' => [
                'pending' => $scoped(Approval::query()->pending())->count(),
                'past_deadline' => $scoped(Approval::query()->pending())->whereNotNull('sla_due_at')->where('sla_due_at', '<=', $window->until)->count(),
            ],
            'shadow_runs_to_review' => $scoped(AgentRun::query())->where('shadow', true)->whereNull('reviewed_at')
                ->whereIn('status', ['answered', 'refused', 'clarified', 'stopped'])->count(),
            'repeated_denial_alerts' => Alert::open()->where('rule', 'agents.repeated_denials')->orderBy('first_seen_at')
                ->get(['subject', 'first_seen_at', 'occurrences'])
                ->map(fn (Alert $a) => ['subject' => $a->subject, 'first_seen_at' => $a->first_seen_at->toIso8601String(), 'occurrences' => $a->occurrences])
                ->all(),
        ];
    }
}
