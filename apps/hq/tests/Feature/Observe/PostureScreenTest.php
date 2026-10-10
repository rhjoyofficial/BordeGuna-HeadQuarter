<?php

use App\Agents\RoleBoundary;
use App\Agents\RolePolicy;
use App\Agents\Shadow\ShadowPeriods;
use App\Models\Agent;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Support\Audit\Auditor;
use Database\Seeders\AgentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/*
 | docs/88 A point 7 (stream V): the exact activation posture on one read-only screen, in Bangla and
 | English. The page reads the classes that already decide each fact; these tests hold that it is
 | reachable only through a permission that already exists, that it says what production holds, that
 | two parts stay with the permission of the screen they come from, and that reading it writes nothing.
 */

function postureOperatorWithout(array $missing): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::findOrCreate('posture_reader', 'web');
    $role->syncPermissions(array_values(array_diff(['hq.agents.view', 'hq.credentials.view', 'hq.audit.view'], $missing)));

    return test()->hqUser('posture_reader');
}

it('registers the page as a GET behind auth, both 2FA gates and a permission that already exists', function () {
    $route = Route::getRoutes()->getByName('observe.posture');

    expect($route)->not->toBeNull()
        ->and('/'.$route->uri())->toBe('/observe/posture')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.agents.view')
        ->and(RolesAndPermissionsSeeder::PERMISSIONS)->toContain('hq.agents.view');
});

it('opens for the roles that may see the agents and for no other', function () {
    $this->get('/observe/posture')->assertRedirect();

    foreach (['owner' => true, 'operator' => true, 'brand_manager' => true, 'finance_viewer' => false, 'support_agent' => false] as $role => $allowed) {
        $response = $this->actingAsHq($this->hqUser($role))->get('/observe/posture');
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }
});

it('says production is inert, and what each role may never do, from the classes that decide it', function () {
    $owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);

    $response = $this->actingAsHq($owner)->get('/observe/posture')->assertOk();

    $response->assertInertia(fn ($page) => $page->component('observe/posture')
        ->where('switches.ai_runtime', false)
        ->where('switches.meta_inbound', false)
        ->where('switches.messaging_providers', [])
        ->where('switches.take_order_stores', [])
        ->where('switches.active_grants', 0)
        ->where('switches.open_shadow_periods', 0)
        ->where('role_policy.version', app(RolePolicy::class)->version())
        ->where('role_policy.kinds', RoleBoundary::KINDS)
        ->has('agents', Agent::count())
        ->has('capability.tools')
        ->has('model.providers')
        ->has('shadow')
        ->has('evidence')
        ->has('reconciliation')
    );

    $props = $response->viewData('page')['props'];
    $agents = collect($props['agents'])->keyBy('key');

    // Every agent is paused, and each carries exactly what the role policy forbids its role.
    foreach ($agents as $key => $row) {
        expect($row['status'])->toBe('paused')
            ->and($row['forbidden'])->toBe(app(RolePolicy::class)->forbiddenFor($row['role']));
    }
    expect($agents['support']['enabling_user'])->toBeNull();

    // The shadow readiness is the console's own check, word for word.
    $shadow = collect($props['shadow'])->keyBy('agent_key');
    expect($shadow['executive']['refusals'])->toBe(app(ShadowPeriods::class)->refusals('executive'))
        ->and($shadow['executive']['may_open'])->toBe($shadow['executive']['refusals'] === []);

    // A tool an agent holds is listed with who holds it.
    $held = collect($props['capability']['tools'])->filter(fn ($t) => in_array('executive', $t['held_by'], true));
    expect($held)->not->toBeEmpty();
});

it('reports a switch that is on, so the page cannot say inert while something runs', function () {
    FeatureFlag::create(['key' => 'ai.runtime', 'environment' => '*', 'enabled' => true]);

    $this->actingAsHq($this->hqUser('owner'))->get('/observe/posture')
        ->assertInertia(fn ($page) => $page->where('switches.ai_runtime', true));
});

it('shows credential readiness only to people who may see credentials, and audit rows only to people who may read the audit log', function () {
    $this->actingAsHq($this->hqUser('owner'))->get('/observe/posture')
        ->assertInertia(fn ($page) => $page->whereType('readiness', 'array')->whereType('audit', 'array'));

    // brand_manager holds hq.credentials.view and not hq.audit.view.
    $this->actingAsHq($this->hqUser('brand_manager'))->get('/observe/posture')
        ->assertInertia(fn ($page) => $page->whereType('readiness', 'array')->where('audit', null));

    $this->actingAsHq(postureOperatorWithout(['hq.credentials.view', 'hq.audit.view']))->get('/observe/posture')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('readiness', null)->where('audit', null));
});

it('never shows an audit row\'s before, after or reason', function () {
    app(Auditor::class)->recordSystem('feature_flag.toggled', 'feature_flag', ['enabled' => false], ['enabled' => true], 'a reason nobody here should read');

    $response = $this->actingAsHq($this->hqUser('owner'))->get('/observe/posture')->assertOk();
    $audit = $response->viewData('page')['props']['audit'];

    expect($audit)->not->toBeEmpty()
        ->and(array_keys($audit[0]))->toBe(['id', 'at', 'action', 'actor', 'target'])
        ->and(json_encode($audit))->not->toContain('a reason nobody here should read');
});

it('writes nothing when it is read', function () {
    $owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);

    $tables = ['audit_logs', 'feature_flags', 'agents', 'autonomy_grants', 'shadow_periods', 'tool_calls', 'integration_credentials', 'approvals', 'consent_events'];
    // Rows added show in the count and the highest id; a row changed in place shows in the full rows of
    // the two tables whose rows are the switches themselves.
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count().':'.DB::table($t)->max('id')])->all()
        + ['agents_rows' => DB::table('agents')->orderBy('id')->get()->toJson(), 'flag_rows' => DB::table('feature_flags')->orderBy('id')->get()->toJson()];

    $before = $snapshot();
    $this->actingAsHq($owner)->get('/observe/posture')->assertOk();

    expect($snapshot())->toBe($before);
});

it('links the page from the sidebar under the permission it is served behind', function () {
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("{ title: 'Activation posture', url: '/observe/posture', icon: Gauge, permission: 'hq.agents.view' }")
        ->and(file_exists(resource_path('js/pages/observe/posture.tsx')))->toBeTrue();
});

it('adds no write path: every non-GET web route is one a reviewed screen already had', function () {
    // docs/88 A point 7: the operator screens are read-only and add no shortcut around the Tool Gateway
    // or an approval. A new write route must be added here on purpose, by the change that needs it.
    $known = [
        'DELETE security/two-factor', 'DELETE settings/profile', 'DELETE settings/sessions/others',
        'PATCH settings/profile', 'PUT settings/password',
        'POST approvals/{approval}/approve', 'POST approvals/{approval}/cancel', 'POST approvals/{approval}/delegate', 'POST approvals/{approval}/reject',
        'POST agents/runs/{run}/review',
        'POST ask',
        'POST credentials', 'POST credentials/{credential}/revoke',
        'POST customers/candidates/{candidate}/dismiss', 'POST customers/merge',
        // docs/108 (S7): records a consent EVENT in the append-only ledger as the acting person, behind
        // hq.consent.restrict or hq.consent.grant. It reaches no provider and bypasses no gateway step.
        'POST customers/{customer}/consent',
        // docs/112 (S6): the playbook store's human acts, each behind its own permission (draft, review,
        // activate) and the business scope. A playbook is read-only guidance in context; these routes write
        // versions and append-only events, reach no provider and bypass no gateway step.
        'POST playbooks', 'PUT playbooks/{version}', 'POST playbooks/{version}/submit', 'POST playbooks/{version}/withdraw',
        'POST playbooks/{version}/review', 'POST playbooks/{version}/activate', 'POST playbooks/{version}/retire',
        'POST flags', 'POST flags/{flag}/toggle',
        'POST inbox/{conversation}/assign', 'POST inbox/{conversation}/close', 'POST inbox/{conversation}/comparisons/{comparison}/verdict',
        'POST inbox/{conversation}/memory/{memory}/forget', 'POST inbox/{conversation}/note', 'POST inbox/{conversation}/proposals/{run}/decide',
        'POST inbox/{conversation}/remember', 'POST inbox/{conversation}/reply', 'POST inbox/{conversation}/resolve',
        'POST inbox/{conversation}/return-to-agent', 'POST inbox/{conversation}/take-over', 'POST inbox/{conversation}/toggle-ai',
        'POST scheduler', 'POST scheduler/nl/confirm', 'POST scheduler/nl/preview', 'POST scheduler/runs/{run}/retry',
        'POST scheduler/{job}/approve', 'POST scheduler/{job}/cancel', 'POST scheduler/{job}/pause', 'POST scheduler/{job}/reject',
        'POST scheduler/{job}/resume', 'POST scheduler/{job}/run-now',
        'POST security/two-factor/confirm', 'POST security/two-factor/enable', 'POST security/two-factor/recovery-codes',
        'POST tools/{key}/toggle',
        'POST two-factor/challenge',
    ];

    $web = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('web', $r->gatherMiddleware(), true))
        ->reject(fn ($r) => str_starts_with($r->uri(), 'horizon') || str_starts_with($r->uri(), '_ignition'))
        // Route::redirect() answers every method with a redirect; it writes nothing.
        ->reject(fn ($r) => str_contains($r->getActionName(), 'RedirectController'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['GET', 'HEAD'], true))->map(fn ($m) => $m.' '.$r->uri()));

    // Authentication (login, logout, password reset, email verification) belongs to the starter kit.
    $unexpected = $web->reject(fn ($pair) => in_array($pair, $known, true))
        ->reject(fn ($pair) => preg_match('#^(POST|PUT) (login|logout|register|forgot-password|reset-password|email/verification-notification|confirm-password)$#', $pair) === 1)
        ->values()->all();

    expect($unexpected)->toBe([]);
});
