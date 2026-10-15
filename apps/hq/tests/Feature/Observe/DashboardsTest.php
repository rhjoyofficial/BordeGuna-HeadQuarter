<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Alert;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\RetentionRun;
use App\Models\UserBusinessScope;
use App\Observe\Dashboards\DashboardWindow;
use App\Observe\HostProbe;
use App\Support\Navigation\NavBadges;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/*
 | docs/78 B.2, docs/48 N.3: the four read-only dashboards and the alert page.
 */

const OBSERVE_PAGES = [
    'observe.alerts' => ['/observe/alerts', 'hq.audit.view'],
    'observe.ai-health' => ['/observe/ai-health', 'hq.agents.view'],
    'observe.cost' => ['/observe/cost', 'hq.ai.cost.view'],
    'observe.control-plane' => ['/observe/control-plane', 'hq.tools.view'],
    'observe.platform' => ['/observe/platform', 'hq.horizon.view'],
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00', 'Asia/Dhaka'));

    // The platform page asks Horizon and the host; neither is reachable from a test, and neither should
    // decide what the page shows here.
    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn([(object) ['status' => 'running']]));
    $this->mock(WorkloadRepository::class, fn ($m) => $m->shouldReceive('get')->andReturn([['name' => 'default', 'length' => 3, 'wait' => 4, 'processes' => 2, 'split_queues' => null]]));
    $this->mock(HostProbe::class, function ($m) {
        $m->shouldReceive('disk')->andReturn(['path' => '/', 'total_bytes' => 100 * 1073741824, 'free_bytes' => 40 * 1073741824, 'used_percent' => 60]);
        $m->shouldReceive('memory')->andReturn(['available_mb' => 3000, 'total_mb' => 7800]);
    });
});

function obsBusiness(string $slug): Business
{
    return Business::create(['name' => ucfirst($slug), 'slug' => $slug, 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
}

function obsRun(string $agent, string $status, ?int $businessId = null, ?string $stopReason = null, ?CarbonImmutable $at = null): AgentRun
{
    $run = AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => $agent, 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => $status, 'business_id' => $businessId,
        'stop_reason' => $stopReason, 'shadow' => true]);

    if ($at !== null) {
        $run->forceFill(['created_at' => $at])->save();
    }

    return $run;
}

function obsAiRun(array $attributes): int
{
    return DB::table('ai_runs')->insertGetId($attributes + [
        'task' => 'agent.turn', 'provider' => 'anthropic', 'model' => 'fixture-model', 'model_tier' => 'medium', 'status' => 'succeeded',
        'input_tokens' => 100, 'output_tokens' => 50, 'latency_ms' => 1000, 'estimated_cost_usd' => '0.010000',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('registers the five pages behind auth, both 2FA gates and a permission that already exists', function () {
    $seeded = RolesAndPermissionsSeeder::PERMISSIONS;

    foreach (OBSERVE_PAGES as $name => [$uri, $permission]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and('/'.$route->uri())->toBe($uri)
            ->and($route->methods())->toBe(['GET', 'HEAD'])
            ->and($route->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', "permission:{$permission}")
            ->and($seeded)->toContain($permission);
    }
});

it('opens each page for the roles that hold its permission and no other', function () {
    // Nobody signed in: every page sends the visitor to the login.
    foreach (OBSERVE_PAGES as [$uri]) {
        $this->get($uri)->assertRedirect();
    }

    $expect = [
        'owner' => ['observe.alerts', 'observe.ai-health', 'observe.cost', 'observe.control-plane', 'observe.platform'],
        'brand_manager' => ['observe.ai-health', 'observe.control-plane'],
        'finance_viewer' => ['observe.alerts', 'observe.cost'],
        'support_agent' => [],
    ];

    foreach ($expect as $role => $allowed) {
        $user = $this->hqUser($role);
        foreach (OBSERVE_PAGES as $name => [$uri]) {
            $response = $this->actingAsHq($user)->get($uri);
            in_array($name, $allowed, true) ? $response->assertOk() : $response->assertForbidden();
        }
    }
});

it('shows how agent runs ended, model latency by tier, policy refusals and stops', function () {
    Agent::create(['key' => 'executive', 'name' => 'Executive', 'status' => 'paused']);
    obsRun('executive', 'answered');
    obsRun('executive', 'refused');
    obsRun('executive', 'stopped', stopReason: 'loop_bound');
    obsRun('executive', 'failed', stopReason: 'model_error');
    obsRun('executive', 'answered', at: now()->subDays(9)->toImmutable());

    foreach ([500, 1000, 1500, 2000] as $ms) {
        obsAiRun(['latency_ms' => $ms, 'model_tier' => 'medium']);
    }
    obsAiRun(['status' => 'failed', 'stop_reason' => 'max_tokens', 'model_tier' => 'low']);

    $policy = DB::table('policies')->insertGetId(['key' => 'no-writes-at-night', 'version' => 1, 'tool_key' => 'facts.query_orders', 'rules' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('tool_calls')->insert([
        ['tool_key' => 'facts.query_orders', 'agent_key' => 'executive', 'status' => 'denied', 'policy_id' => $policy, 'outcome_class' => null, 'created_at' => now()],
        ['tool_key' => 'facts.query_orders', 'agent_key' => 'executive', 'status' => 'denied', 'policy_id' => $policy, 'outcome_class' => null, 'created_at' => now()],
        ['tool_key' => 'erp.run_report', 'agent_key' => 'executive', 'status' => 'denied', 'policy_id' => null, 'outcome_class' => null, 'created_at' => now()],
        ['tool_key' => 'erp.run_report', 'agent_key' => 'executive', 'status' => 'failed', 'policy_id' => null, 'outcome_class' => 'external', 'created_at' => now()],
    ]);

    $this->actingAsHq($this->hqUser())->get('/observe/ai-health')->assertOk()
        ->assertInertia(fn ($page) => $page->component('observe/ai-health')
            ->where('window.key', '7d')
            ->where('ai_runtime', false)
            ->where('agents.0.key', 'executive')
            ->where('agents.0.has_enabling_user', false)
            ->where('runs_by_agent.0.total', 4)
            ->where('runs_by_agent.0.by_status.answered', 1)
            ->where('runs_by_agent.0.ended_normally_percent', 50)
            ->where('latency_by_tier.1.tier', 'medium')
            ->where('latency_by_tier.1.calls', 4)
            ->where('latency_by_tier.1.p50_ms', 1250)
            ->where('latency_by_tier.1.p95_ms', 1925)
            ->where('model_failures.0.stop_reason', 'max_tokens')
            ->where('tool_failures', fn ($rows) => collect($rows)->pluck('count', 'status')->all() === ['denied' => 3, 'failed' => 1])
            ->where('denials_by_policy.0.policy', 'no-writes-at-night')
            ->where('denials_by_policy.0.count', 2)
            ->where('denials_by_policy.1.policy', null)
            ->where('stops.loop_bound', 1)
            ->where('loop_bound_hits', 1)
            // Waiting for a reader is not windowed: the nine-day-old answered run still waits.
            ->where('shadow_runs_to_review', 4));

    // A day's window leaves out nothing from today, and a nonsense window falls back to seven days.
    $this->get('/observe/ai-health?window=24h')->assertInertia(fn ($page) => $page->where('window.key', '24h')->where('runs_by_agent.0.total', 4));
    $this->get('/observe/ai-health?window=30d')->assertInertia(fn ($page) => $page->where('runs_by_agent.0.total', 5));
    $this->get('/observe/ai-health?window=forever')->assertInertia(fn ($page) => $page->where('window.key', '7d'));
});

it('keeps a restricted operator inside their businesses', function () {
    $mine = obsBusiness('mine');
    $theirs = obsBusiness('theirs');
    obsRun('executive', 'answered', $mine->id);
    obsRun('executive', 'answered', $theirs->id);
    obsRun('executive', 'answered', $theirs->id);
    obsAiRun(['business_id' => $mine->id, 'estimated_cost_usd' => '1.000000']);
    obsAiRun(['business_id' => $theirs->id, 'estimated_cost_usd' => '5.000000']);

    $manager = $this->hqUser('brand_manager');
    UserBusinessScope::create(['user_id' => $manager->id, 'business_id' => $mine->id]);
    $manager->givePermissionTo('hq.ai.cost.view');

    $this->actingAsHq($manager)->get('/observe/ai-health')
        ->assertInertia(fn ($page) => $page->where('runs_by_agent.0.total', 1));
    $this->actingAsHq($manager)->get('/observe/cost')
        ->assertInertia(fn ($page) => $page->where('totals.usd', 1)->where('by_business', fn ($rows) => collect($rows)->pluck('business')->all() === ['Mine']));

    $this->actingAsHq($this->hqUser())->get('/observe/ai-health')
        ->assertInertia(fn ($page) => $page->where('runs_by_agent.0.total', 3));
});

it('adds up spend by Dhaka day, model, agent and business, with the unit costs and the budget', function () {
    $business = obsBusiness('bordeguna');
    // 20:00 UTC on 16 September is 02:00 on 17 September in Dhaka.
    obsAiRun(['agent_key' => 'executive', 'estimated_cost_usd' => '0.100000', 'created_at' => CarbonImmutable::parse('2026-09-16 20:00', 'UTC')]);
    obsAiRun(['agent_key' => 'executive', 'estimated_cost_usd' => '0.200000', 'actual_cost_usd' => '0.300000', 'cost_source' => 'provider',
        'business_id' => $business->id, 'created_at' => CarbonImmutable::parse('2026-09-16 17:00', 'UTC')]);
    obsAiRun(['task' => 'probe', 'estimated_cost_usd' => '0.050000', 'degraded_from_tier' => 'high', 'model_tier' => 'medium',
        'created_at' => CarbonImmutable::parse('2026-09-15 03:00', 'UTC')]);
    obsRun('executive', 'answered');
    obsRun('executive', 'answered');

    $this->actingAsHq($this->hqUser())->get('/observe/cost')->assertOk()
        ->assertInertia(fn ($page) => $page->component('observe/cost')
            ->where('totals.runs', 3)
            ->where('totals.usd', 0.45)
            ->where('totals.per_run_usd', 0.15)
            ->where('by_day', [
                ['day' => '2026-09-15', 'runs' => 1, 'usd' => 0.05],
                ['day' => '2026-09-16', 'runs' => 1, 'usd' => 0.3],
                ['day' => '2026-09-17', 'runs' => 1, 'usd' => 0.1],
            ])
            ->where('by_agent.0.agent_key', 'executive')
            ->where('by_agent.0.usd', 0.4)
            ->where('by_business', fn ($rows) => collect($rows)->pluck('usd', 'business')->all() === ['Bordeguna' => 0.3, '' => 0.15])
            ->where('by_cost_source', fn ($rows) => collect($rows)->pluck('usd', 'cost_source')->all() === ['provider' => 0.3, '' => 0.15])
            ->where('unit_costs.agent_runs', 2)
            ->where('unit_costs.per_agent_run_usd', 0.2)
            ->where('unit_costs.conversations', 0)
            ->where('unit_costs.per_conversation_usd', null)
            ->where('unit_costs.per_briefing_usd', null)
            ->where('budget.scope', 'global')
            ->where('budget.cap_usd', 50)
            ->where('degradations', [['from' => 'high', 'to' => 'medium', 'count' => 1]]));
});

it('shows what the gateway was asked, what policy said, how long approvals took, and every switch', function () {
    DB::table('tool_calls')->insert([
        ['tool_key' => 'erp.poll_overview', 'risk_tier' => 'r3', 'caller_kind' => 'scheduler', 'status' => 'succeeded', 'policy_result' => 'allow', 'created_at' => now()],
        ['tool_key' => 'erp.poll_overview', 'risk_tier' => 'r3', 'caller_kind' => 'scheduler', 'status' => 'failed', 'policy_result' => 'allow', 'created_at' => now()],
        ['tool_key' => 'facts.query_orders', 'risk_tier' => 'r1', 'caller_kind' => 'agent', 'status' => 'denied', 'policy_result' => 'deny', 'created_at' => now()],
    ]);
    $approval = fn (string $status, int $minutes) => DB::table('approvals')->insert([
        'uuid' => (string) Str::uuid(), 'tool_key' => 'diagnostics.approval_demo', 'tool_version' => '1.0.0', 'payload' => '{}',
        'payload_hash' => str_repeat('c', 64), 'risk_tier' => 'r4', 'action_class' => 'approval_required', 'status' => $status,
        'created_at' => now()->subMinutes($minutes), 'decided_at' => now(), 'updated_at' => now(),
    ]);
    $approval('approved', 10);
    $approval('approved', 30);
    $approval('rejected', 60);
    FeatureFlag::create(['key' => 'ai.runtime', 'environment' => '*', 'enabled' => false, 'description' => 'the model runtime']);
    DB::table('tools')->insert(['key' => 'commerce.reconcile_facts', 'enabled' => false, 'disabled_reason' => 'shop maintenance', 'disabled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAsHq($this->hqUser())->get('/observe/control-plane')->assertOk()
        ->assertInertia(fn ($page) => $page->component('observe/control-plane')
            ->where('calls_total', 3)
            ->where('calls_by_tier', [
                ['risk_tier' => 'r1', 'calls' => 1, 'succeeded' => 0, 'denied' => 1, 'failed' => 0],
                ['risk_tier' => 'r3', 'calls' => 2, 'succeeded' => 1, 'denied' => 0, 'failed' => 1],
            ])
            ->where('policy_outcomes', [['value' => 'allow', 'count' => 2], ['value' => 'deny', 'count' => 1]])
            ->where('approval_decisions', [
                ['status' => 'approved', 'count' => 2, 'p50_minutes' => 20, 'p95_minutes' => 29],
                ['status' => 'rejected', 'count' => 1, 'p50_minutes' => 60, 'p95_minutes' => 60],
            ])
            ->where('tools_disabled.0.key', 'commerce.reconcile_facts')
            ->where('tools_disabled.0.reason', 'shop maintenance')
            ->where('flags.0.key', 'ai.runtime')
            ->where('flags.0.enabled', false)
            ->where('active_grants', 0)
            ->where('open_shadow_periods', []));
});

it('shows the machinery: queues, scheduler, events, snapshots, the host, retention and open alerts', function () {
    $business = obsBusiness('bordeguna');
    DB::table('facts_erp_snapshots')->insert([
        ['business_id' => $business->id, 'kind' => 'overview', 'payload' => '{}', 'polled_at' => now()->subHours(3), 'as_of' => now()->subHours(3), 'created_at' => now(), 'updated_at' => now()],
        ['business_id' => $business->id, 'kind' => 'low_stock', 'payload' => '{}', 'polled_at' => now()->subHours(30), 'as_of' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('events')->insert([
        'event_id' => (string) Str::uuid(), 'type' => 'order.created', 'direction' => 'inbound', 'source' => 'laladia', 'payload' => '{}',
        'dedupe_key' => 'd-1', 'occurred_at' => now()->subMinutes(20), 'created_at' => now()->subMinutes(20), 'status' => 'pending',
    ]);
    DB::table('connector_calls')->insert([
        ['connector' => 'erp', 'operation' => 'overview', 'status' => 'succeeded', 'duration_ms' => 100, 'created_at' => now()],
        ['connector' => 'erp', 'operation' => 'overview', 'status' => 'failed', 'duration_ms' => 300, 'created_at' => now()],
    ]);
    RetentionRun::create(['uuid' => (string) Str::uuid(), 'policy_version' => 'retention.v1', 'policy' => [], 'status' => 'succeeded',
        'summary' => ['tables' => ['tool_calls' => ['rows_aggregated_and_deleted' => 4]], 'event_payloads' => ['compacted' => 2]],
        'started_at' => now(), 'finished_at' => now()]);
    Alert::create(['rule' => 'host.disk', 'fingerprint' => 'host.disk:storage', 'severity' => 'warning', 'subject' => 'Disk 85% used', 'body' => 'b',
        'paged_by' => 'hq-monitor.sh', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->actingAsHq($this->hqUser())->get('/observe/platform')->assertOk()
        ->assertInertia(fn ($page) => $page->component('observe/platform')
            ->where('queues.masters', 1)
            ->where('queues.workload.0.name', 'default')
            ->where('queues.workload.0.length', 3)
            ->where('scheduler.stale', true)
            ->where('events.waiting', 1)
            ->where('events.oldest_waiting_minutes', 20)
            ->where('connectors_24h.0.calls', 2)
            ->where('connectors_24h.0.failed', 1)
            ->where('snapshots.0.kind', 'low_stock')
            ->where('snapshots.0.stale', true)
            ->where('snapshots.1.stale', false)
            ->where('host.disk.used_percent', 60)
            ->where('host.memory.available_mb', 3000)
            ->where('retention.last_run.rows_removed', 4)
            ->where('retention.last_run.payloads_compacted', 2)
            ->where('retention.policy.event_payload_keep_days', 365)
            ->where('alerts_open', ['warning' => 1])
            ->where('readiness', fn ($rows) => collect($rows)->contains(fn ($r) => $r['target_kind'] === 'hq' && $r['incident'] === true)));
});

it('lists open alerts worst first, what cleared this week, and every rule with who sends it', function () {
    $alert = fn (string $fingerprint, string $severity, ?CarbonImmutable $resolved = null, ?string $pagedBy = null) => Alert::create([
        'rule' => 'test', 'fingerprint' => $fingerprint, 'severity' => $severity, 'subject' => $fingerprint, 'body' => 'b',
        'paged_by' => $pagedBy, 'first_seen_at' => now()->subHour(), 'last_seen_at' => now(), 'resolved_at' => $resolved,
    ]);
    $alert('a-warning', 'warning');
    $alert('b-critical', 'critical', pagedBy: 'hq-monitor.sh');
    $alert('c-cleared', 'warning', now()->subDays(2)->toImmutable());
    $alert('d-old', 'warning', now()->subDays(9)->toImmutable());

    $this->actingAsHq($owner = $this->hqUser())->get('/observe/alerts')->assertOk()
        ->assertInertia(fn ($page) => $page->component('observe/alerts')
            ->where('open', fn ($rows) => collect($rows)->pluck('fingerprint')->all() === ['b-critical', 'a-warning'])
            ->where('resolved', fn ($rows) => collect($rows)->pluck('fingerprint')->all() === ['c-cleared'])
            ->has('rules', 17) // docs/123: + followups.overdue
            ->where('rules', fn ($rows) => collect($rows)->firstWhere('key', 'host.disk')['paged_by'] === 'hq-monitor.sh'));

    // The badge counts what HQ itself sends; the host monitor's alert is on the page only.
    expect(app(NavBadges::class)->for($owner)['alerts'])->toBe(1)
        ->and(app(NavBadges::class)->for($this->hqUser('support_agent')))->not->toHaveKey('alerts');
});

it('reads a window from the request and falls back to a week', function () {
    $now = CarbonImmutable::parse('2026-09-17 06:00', 'UTC');

    expect(DashboardWindow::from('24h', $now)->since->toIso8601String())->toBe('2026-09-16T06:00:00+00:00')
        ->and(DashboardWindow::from('30d', $now)->since->toIso8601String())->toBe('2026-08-18T06:00:00+00:00')
        ->and(DashboardWindow::from(null, $now)->key)->toBe('7d')
        ->and(DashboardWindow::from('1y', $now)->key)->toBe('7d')
        ->and(DashboardWindow::from('7d', $now)->toArray()['options'])->toBe(['24h', '7d', '30d']);
});
