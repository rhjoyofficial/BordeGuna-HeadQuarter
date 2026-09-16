<?php

namespace App\Observe\Dashboards;

use App\Models\Agent;
use App\Models\Approval;
use App\Models\AutonomyGrant;
use App\Models\FeatureFlag;
use App\Models\ShadowPeriod;
use App\Models\ToolCall;
use App\Models\ToolRegistration;
use App\Models\User;
use App\Support\Authorization\BusinessScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Control plane (docs/48 N.3): what the gateway was asked to do by risk tier and by caller, what policy
 * decided, how long people take to decide approvals, and the switches: tools turned off, feature
 * flags, agent status and autonomy, grants and shadow periods. Read-only: every switch is changed on
 * its own screen or not from the browser at all.
 */
final class ControlPlaneDashboard
{
    public function __construct(private readonly BusinessScope $scope) {}

    public function build(DashboardWindow $window, ?User $user): array
    {
        $scoped = fn (Builder $query) => $user === null ? $query : $this->scope->restrict($query, $user);
        $calls = fn () => $scoped(ToolCall::query())->where('created_at', '>=', $window->since);

        $byTier = $calls()
            ->selectRaw("risk_tier, count(*) as n, count(*) filter (where status = 'succeeded') as ok, count(*) filter (where status = 'denied') as denied, count(*) filter (where status in ('failed', 'invalid', 'unavailable', 'uncertain')) as failed")
            ->groupBy('risk_tier')->orderBy('risk_tier')->get()
            ->map(fn ($r) => ['risk_tier' => $r->risk_tier, 'calls' => (int) $r->n, 'succeeded' => (int) $r->ok, 'denied' => (int) $r->denied, 'failed' => (int) $r->failed])
            ->all();

        $count = fn (string $column) => $calls()
            ->selectRaw("{$column} as value, count(*) as n")
            ->groupBy($column)->orderByDesc('n')->get()
            ->map(fn ($r) => ['value' => $r->value, 'count' => (int) $r->n])->all();

        $decisions = $scoped(Approval::query())
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', $window->since)
            ->selectRaw('status, count(*) as n, percentile_cont(0.5) within group (order by extract(epoch from (decided_at - created_at))) as p50, percentile_cont(0.95) within group (order by extract(epoch from (decided_at - created_at))) as p95')
            ->groupBy('status')->orderBy('status')->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->n, 'p50_minutes' => round((float) $r->p50 / 60, 1), 'p95_minutes' => round((float) $r->p95 / 60, 1)])
            ->all();

        return [
            'window' => $window->toArray(),
            'calls_total' => $calls()->count(),
            'calls_by_tier' => $byTier,
            'policy_outcomes' => $count('policy_result'),
            'calls_by_caller' => $count('caller_kind'),
            'approval_decisions' => $decisions,
            'approvals_pending' => $scoped(Approval::query()->pending())->count(),
            'tools_disabled' => ToolRegistration::query()->where('enabled', false)->orderBy('key')
                ->get(['key', 'disabled_reason', 'disabled_at'])
                ->map(fn (ToolRegistration $t) => ['key' => $t->key, 'reason' => $t->disabled_reason, 'disabled_at' => $t->disabled_at?->toIso8601String()])
                ->all(),
            'flags' => FeatureFlag::query()->orderBy('key')->orderBy('environment')
                ->get(['key', 'environment', 'enabled', 'description'])
                ->map(fn (FeatureFlag $f) => ['key' => $f->key, 'environment' => $f->environment, 'enabled' => $f->enabled, 'description' => $f->description])
                ->all(),
            'agents' => Agent::query()->orderBy('key')->get(['key', 'name', 'status', 'max_autonomy_level'])
                ->map(fn (Agent $a) => ['key' => $a->key, 'name' => $a->name, 'status' => $a->status, 'autonomy' => $a->max_autonomy_level?->value])
                ->all(),
            'active_grants' => AutonomyGrant::query()->where('status', 'active')->count(),
            'open_shadow_periods' => ShadowPeriod::query()->where('status', ShadowPeriod::STATUS_OPEN)->orderBy('opened_at')
                ->get(['uuid', 'agent_key', 'opened_at', 'planned_end_at'])
                ->map(fn (ShadowPeriod $p) => ['uuid' => $p->uuid, 'agent_key' => $p->agent_key, 'opened_at' => $p->opened_at->toIso8601String(), 'planned_end_at' => $p->planned_end_at->toIso8601String()])
                ->all(),
        ];
    }
}
