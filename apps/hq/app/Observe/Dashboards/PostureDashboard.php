<?php

namespace App\Observe\Dashboards;

use App\Agents\RoleBoundary;
use App\Agents\RolePolicy;
use App\Agents\Shadow\ShadowPeriods;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\ProviderRegistry;
use App\Ai\ServingProvider;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ReadinessRow;
use App\Gateway\ToolGateway;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Agent;
use App\Models\AiModel;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\AutonomyEvidencePolicy;
use App\Models\AutonomyGrant;
use App\Models\ConsentEvent;
use App\Models\DomainEvent;
use App\Models\EvaluationRun;
use App\Models\ShadowPeriod;
use App\Models\ToolCall;
use App\Models\ToolRegistration;
use App\Models\User;
use App\Platform\PlatformRegistry;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The exact activation posture, on one screen (docs/88 A point 7): whether anything is switched on,
 * what each role may never do, what still stands between the Executive and its A0 shadow week, the
 * gate evidence that decision rests on, which model provider and credentials HQ could use, what the
 * gateway holds and who holds it, what is uncertain or dead, and the audit rows behind the switches.
 *
 * ⛔ Read-only. Every value comes from a class that already decides it (the shadow preconditions from
 * `ShadowPeriods::refusals()`, the roles from `RolePolicy`, readiness from `CapabilityReadiness`), so the
 * page cannot disagree with the console. Nothing here opens, switches, grants or registers anything:
 * those stay console commands under a named person, or deploys.
 *
 * Two parts are shown only to a viewer who already holds the permission of the screen they come from:
 * the credential readiness rows (`hq.credentials.view`) and the audit references (`hq.audit.view`).
 */
final class PostureDashboard
{
    /** Audit actions that change the posture this page reports. */
    private const POSTURE_ACTIONS = [
        'feature_flag.', 'agent.', 'shadow_period.', 'autonomy', 'evidence_policy', 'ai_provider.', 'ai.provider', 'integration_credential.',
    ];

    public function __construct(
        private readonly Features $features,
        private readonly RolePolicy $roles,
        private readonly ShadowPeriods $shadow,
        private readonly ProviderRegistry $providers,
        private readonly ServingProvider $serving,
        private readonly CredentialVault $vault,
        private readonly CredentialHealth $health,
        private readonly CapabilityReadiness $readiness,
        private readonly ToolGateway $gateway,
        private readonly PlatformRegistry $platforms,
        private readonly BusinessScope $scope,
    ) {}

    public function build(?User $user): array
    {
        $this->features->forget();
        $agents = Agent::query()->with('enablingUser:id,name')->orderBy('key')->get();

        return [
            'switches' => [
                'ai_runtime' => $this->features->enabled((string) config('hq.ai.enabled_flag', 'ai.runtime')),
                'meta_inbound' => $this->features->enabled(MetaWebhookController::FLAG),
                'messaging_providers' => $this->platforms->platforms(),
                'take_order_stores' => array_values((array) config('hq.orders.take_order_stores', [])),
                'active_grants' => AutonomyGrant::query()->where('status', 'active')->count(),
                // docs/136: a count of business-owned rows is narrowed to the viewer's businesses.
                'consent_events' => ($user === null ? ConsentEvent::query() : $this->scope->restrict(ConsentEvent::query(), $user))->count(),
                'active_evidence_policy' => $this->activeEvidencePolicy(),
                'open_shadow_periods' => ShadowPeriod::query()->where('status', ShadowPeriod::STATUS_OPEN)->count(),
            ],
            'agents' => $agents->map(fn (Agent $a) => $this->agentRow($a))->all(),
            'role_policy' => [
                'version' => $this->roles->version(),
                'kinds' => RoleBoundary::KINDS,
                'roles' => array_map(fn (string $role) => ['role' => $role, 'forbidden' => $this->roles->forbiddenFor($role)], $this->roles->roles()),
            ],
            'shadow' => $this->shadowReadiness($agents->all()),
            'evidence' => $this->evidence($agents->all()),
            'model' => $this->modelProviders(),
            'readiness' => $user?->can('hq.credentials.view') ? $this->readinessSummary() : null,
            'capability' => $this->capabilityState($agents->all()),
            'reconciliation' => $this->reconciliation($user),
            'audit' => $user?->can('hq.audit.view') ? $this->auditReferences() : null,
        ];
    }

    private function agentRow(Agent $agent): array
    {
        $role = (string) ($agent->role_key ?: $agent->key);
        $open = ShadowPeriod::openFor($agent->key);

        return [
            'key' => $agent->key,
            'name' => $agent->name,
            'role' => $role,
            'status' => $agent->status,
            'autonomy' => $agent->max_autonomy_level?->value,
            'enabling_user' => $agent->enablingUser?->name,
            'forbidden' => $this->roles->forbiddenFor($role),
            'tools' => array_values((array) $agent->allowed_tools),
            'shadow' => $open === null ? null : [
                'id' => $open->id,
                'opened_at' => $open->opened_at?->toIso8601String(),
                'planned_end_at' => $open->planned_end_at?->toIso8601String(),
                'opened_by' => $open->opened_by_label,
            ],
        ];
    }

    private function activeEvidencePolicy(): ?array
    {
        $policy = AutonomyEvidencePolicy::query()->where('status', AutonomyEvidencePolicy::ACTIVE)->orderByDesc('id')->first();

        return $policy === null ? null : ['version' => $policy->version, 'name' => $policy->name, 'activated_at' => $policy->activated_at?->toIso8601String()];
    }

    /**
     * What still stands between each agent this milestone may shadow and its period, read from the
     * same check `hq:shadow:open` refuses on.
     *
     * @param  list<Agent>  $agents
     */
    private function shadowReadiness(array $agents): array
    {
        $out = [];
        foreach ((array) config('hq.shadow.agents', ['executive']) as $key) {
            $key = (string) $key;
            try {
                $refusals = $this->shadow->refusals($key);
            } catch (Throwable $e) {
                $refusals = ['The check itself could not complete: '.class_basename($e).'.'];
            }
            $out[] = ['agent_key' => $key, 'may_open' => $refusals === [], 'refusals' => $refusals];
        }

        return $out;
    }

    /**
     * The latest finished evaluation run per agent and suite: the gate evidence a decision rests on.
     *
     * @param  list<Agent>  $agents
     */
    private function evidence(array $agents): array
    {
        $rows = [];
        foreach ($agents as $agent) {
            foreach ($this->shadow->evidenceFor($agent) as $suite => $e) {
                $run = $e['run_id'] === null ? null : EvaluationRun::query()->find($e['run_id']);
                $rows[] = [
                    'agent_key' => $agent->key,
                    'suite' => $suite,
                    'run_id' => $e['run_id'],
                    'status' => $e['status'],
                    'cases_total' => $run?->cases_total,
                    'cases_passed' => $run?->cases_passed,
                    'cases_failed' => $run?->cases_failed,
                    'unmeasured' => $e['unmeasured'],
                    'model' => $run?->model,
                    'triggered_by' => $e['triggered_by'],
                    'finished_at' => $e['finished_at'],
                ];
            }
        }

        return $rows;
    }

    private function modelProviders(): array
    {
        $providers = [];
        foreach ($this->providers->keys() as $key) {
            [$credentialProvider, $account] = $this->providers->get($key)->credentialPair();
            $credential = $this->vault->active($credentialProvider, $account);
            $last = $credential === null ? null : $this->health->lastVerification($credential);

            $providers[] = [
                'key' => $key,
                'credential' => $credential === null ? null : ['id' => $credential->id, 'status' => $credential->status],
                'last_check' => $last === null ? null : ['ok' => (bool) $last['ok'], 'class' => $last['class'], 'source' => $last['source'], 'at' => $last['at']],
                'models' => AiModel::query()->where('provider', $key)->orderBy('tier')->orderBy('priority')->get()
                    ->map(fn (AiModel $m) => ['tier' => $m->tier, 'model' => $m->model_id, 'on' => (bool) $m->is_active, 'priority' => (int) $m->priority])
                    ->all(),
            ];
        }

        $current = $this->serving->current();

        return [
            'serving_state' => $this->serving->state(),
            'serving' => $current,
            'describe' => $this->serving->describe(),
            'reasoning_effort' => $current === OpenAiProvider::PROVIDER ? OpenAiProvider::reasoningEffort() : null,
            'providers' => $providers,
        ];
    }

    private function readinessSummary(): array
    {
        $rows = $this->readiness->inventory();
        $count = fn (callable $test) => count(array_filter($rows, $test));

        return [
            'total' => count($rows),
            'verified' => $count(fn (ReadinessRow $r) => $r->verified()),
            'would_attempt' => $count(fn (ReadinessRow $r) => $r->wouldAttempt()),
            'unsupported' => $count(fn (ReadinessRow $r) => ! $r->supported),
            'incidents' => array_values(array_map(fn (ReadinessRow $r) => [
                'target' => $r->targetName,
                'provider' => $r->provider,
                'state' => $r->state,
                'reason' => $r->reason,
            ], array_filter($rows, fn (ReadinessRow $r) => $r->incident()))),
        ];
    }

    /**
     * What the gateway holds and who holds it. `held_by` is read from the agents' tool lists; holding a
     * tool is not being allowed to run it (the policy, the autonomy ceiling and the runtime still decide).
     *
     * @param  list<Agent>  $agents
     */
    private function capabilityState(array $agents): array
    {
        $enabled = ToolRegistration::query()->pluck('enabled', 'key')->all();
        $tools = [];
        foreach ($this->gateway->descriptors() as $key => $d) {
            $tools[] = [
                'key' => $key,
                'title' => $d->title,
                'risk' => $d->riskTier->value,
                'writes' => $d->readWrite->value !== 'read',
                'customer_facing' => $d->customerFacing,
                'agent_executable' => $d->agentExecutable,
                'human_only_forever' => $d->permanentlyHumanOnly,
                'enabled' => array_key_exists($key, $enabled) ? (bool) $enabled[$key] : true,
                'held_by' => array_values(array_map(
                    fn (Agent $a) => $a->key,
                    array_filter($agents, fn (Agent $a) => in_array($key, (array) $a->allowed_tools, true)),
                )),
            ];
        }
        usort($tools, fn (array $a, array $b) => [$a['risk'], $a['key']] <=> [$b['risk'], $b['key']]);

        return ['tools' => $tools];
    }

    private function reconciliation(?User $user): array
    {
        $calls = ToolCall::query();
        if ($user !== null) {
            $calls = $this->scope->restrict($calls, $user);
        }

        return [
            'uncertain_calls' => (clone $calls)->where('status', 'uncertain')->count(),
            'latest_uncertain' => (clone $calls)->where('status', 'uncertain')->orderByDesc('id')->limit(10)
                ->get(['id', 'tool_key', 'created_at', 'outcome_class'])
                ->map(fn (ToolCall $c) => ['id' => $c->id, 'tool_key' => $c->tool_key, 'at' => $c->created_at?->toIso8601String(), 'outcome_class' => $c->outcome_class])
                ->all(),
            'dead_events' => ($user === null ? DomainEvent::query() : $this->scope->restrict(DomainEvent::query(), $user))->where('status', DomainEvent::STATUS_DEAD)->count(),
            'open_alerts' => Alert::open()->count(),
            'failed_queue_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];
    }

    /** The latest audit rows that changed the posture: id, time, action, actor, target. Never before/after or reason. */
    private function auditReferences(): array
    {
        return AuditLog::query()
            ->where(function ($q) {
                foreach (self::POSTURE_ACTIONS as $prefix) {
                    $q->orWhere('action', 'like', $prefix.'%');
                }
            })
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'occurred_at', 'action', 'actor_type', 'actor_label', 'target_type', 'target_id'])
            ->map(fn (AuditLog $a) => [
                'id' => $a->id,
                'at' => $a->occurred_at?->toIso8601String(),
                'action' => $a->action,
                'actor' => $a->actor_label ?: $a->actor_type,
                'target' => $a->target_type === null ? null : class_basename((string) $a->target_type).($a->target_id !== null ? " #{$a->target_id}" : ''),
            ])
            ->all();
    }
}
