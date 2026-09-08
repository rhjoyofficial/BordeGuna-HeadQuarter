<?php

use App\Gateway\CallerKind;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\FailureClass;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\Identity\IdentityKind;
use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyEngine;
use App\Gateway\Policy\PolicyOutcome;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Registry\ToolRegistrySync;
use App\Gateway\Registry\ToolSwitch;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Gateway\Validation\ArgumentValidator;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Authorization\BusinessScope;
use App\Support\Features\Features;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Tools\CountingTool;
use Tests\Support\Tools\WriteTool;

/*
 | Phase 2 M1: the Tool Gateway (docs/48 D, docs/49, brief section 19).
 |
 | Every test here asks one question: can something reach a tool that should not have? The
 | interesting assertions are therefore mostly about what did NOT happen - no execution, no
 | connector call, no credential resolved - which is why the fake tools count their executions.
 */

function gateway(): ToolGateway
{
    return app(ToolGateway::class);
}

/** Registers a test-only tool in the live registry for one test. */
function registerTestTool(string $class): void
{
    app(ToolRegistry::class)->register($class);
    app(ToolAvailability::class)->forget();
}

function operator(array $permissions = ['*']): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    if ($permissions === ['*']) {
        $user->assignRole('owner');
    } else {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

beforeEach(function () {
    Cache::flush();
    CountingTool::reset();
    WriteTool::$executions = 0;
});

// ---------------------------------------------------------------- registry

it('refuses a tool key that is not registered in code, and still records the attempt', function () {
    $outcome = gateway()->execute(new ToolRequest(
        toolKey: 'no.such.tool',
        args: [],
        caller: CallerKind::Console,
        identity: ActingIdentity::human(operator()),
        correlationId: (string) Str::uuid(),
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Unavailable)
        ->and($outcome->error)->toContain('not registered in config/hq.php');

    // An unknown key is a bug or a probe. Recording it is the point: a control plane that only
    // records what succeeded cannot tell you something spent a week being refused.
    $call = ToolCall::where('tool_key', 'no.such.tool')->firstOrFail();
    expect($call->status)->toBe(OutcomeStatus::Unavailable->value)
        ->and($call->failure_class)->toBe(FailureClass::Unsupported->value);
});

it('runs a registered tool and records one complete tool_calls row', function () {
    registerTestTool(CountingTool::class);
    $user = operator();

    $outcome = gateway()->execute(ToolRequest::forOperator($user, 'test.counting', ['label' => 'first']));

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->output['executions'])->toBe(1)
        ->and($outcome->policy)->toBe(PolicyOutcome::Allow);

    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->status)->toBe(OutcomeStatus::Succeeded->value)
        ->and($call->tool_key)->toBe('test.counting')
        ->and($call->tool_version)->toBe('1.0.0')
        ->and($call->risk_tier)->toBe('r1')
        ->and($call->caller_kind)->toBe(CallerKind::HumanUi->value)
        ->and($call->actor_type)->toBe('human')
        ->and($call->enabling_user_id)->toBe($user->id)
        ->and($call->policy_result)->toBe('allow')
        ->and($call->input)->toBe(['label' => 'first'])
        ->and($call->started_at)->not->toBeNull()
        ->and($call->finished_at)->not->toBeNull();
});

it('refuses a tool disabled at runtime, for every caller, and both directions are audited', function () {
    registerTestTool(CountingTool::class);
    $user = operator();
    app(ToolRegistrySync::class)->sync();

    app(ToolSwitch::class)->disable('test.counting', 'misbehaving in production', $user);

    $outcome = gateway()->execute(ToolRequest::forOperator($user, 'test.counting'));
    expect($outcome->status)->toBe(OutcomeStatus::Unavailable)
        ->and($outcome->error)->toContain('misbehaving in production')
        ->and(CountingTool::$executions)->toBe(0);

    app(ToolSwitch::class)->enable('test.counting', 'fixed', $user);
    expect(gateway()->execute(ToolRequest::forOperator($user, 'test.counting'))->ok())->toBeTrue()
        ->and(CountingTool::$executions)->toBe(1);

    expect(AuditLog::where('action', 'tool.disabled')->count())->toBe(1)
        ->and(AuditLog::where('action', 'tool.enabled')->count())->toBe(1);
});

it('will not let a runtime row invent a capability that is absent from the code', function () {
    // The whole asymmetry of the two-switch model: the table can only ever narrow.
    expect(fn () => app(ToolSwitch::class)->enable('not.deployed'))
        ->toThrow(InvalidArgumentException::class, 'only a tool registered in config/hq.php');
});

// ---------------------------------------------------------------- schema

it('stops malformed arguments at the gateway, before policy or any execution', function () {
    registerTestTool(CountingTool::class);

    $outcome = gateway()->execute(ToolRequest::forOperator(operator(), 'test.counting', ['label' => str_repeat('x', 40)]));

    expect($outcome->status)->toBe(OutcomeStatus::Invalid)
        ->and($outcome->failure)->toBe(FailureClass::ValidationFailed)
        ->and($outcome->error)->toContain('at most 32 characters')
        ->and(CountingTool::$executions)->toBe(0);

    // Validation runs before policy, so there is no policy result to record yet. A row that showed
    // a policy decision here would mean the order of the steps had quietly changed.
    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->policy_result)->toBeNull();
});

it('refuses an argument the tool does not accept', function () {
    registerTestTool(CountingTool::class);

    $outcome = gateway()->execute(ToolRequest::forOperator(operator(), 'test.counting', ['sneaky' => 'value']));

    expect($outcome->status)->toBe(OutcomeStatus::Invalid)
        ->and($outcome->error)->toContain('[sneaky] is not accepted')
        ->and(CountingTool::$executions)->toBe(0);
});

// ---------------------------------------------------------------- permission and identity

it('denies a human who does not hold the tool permission', function () {
    registerTestTool(CountingTool::class);
    $user = operator(['hq.dashboard.view']);

    $outcome = gateway()->execute(ToolRequest::forOperator($user, 'test.counting'));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('hq.tools.diagnostics')
        ->and(CountingTool::$executions)->toBe(0);
});

it('gives the system identity only the narrow configured list, and nothing else', function () {
    registerTestTool(CountingTool::class);
    registerTestTool(WriteTool::class);
    (new RolesAndPermissionsSeeder)->run();

    $system = ActingIdentity::system();

    expect($system->holds('hq.tools.erp.read', 'erp.poll_overview'))->toBeTrue()
        ->and($system->holds('hq.tools.commerce.read', 'commerce.reconcile_facts'))->toBeTrue()
        // Not in config('hq.gateway.system_permissions'), so unattended work cannot reach it.
        ->and($system->holds('hq.credentials.manage', 'anything'))->toBeFalse()
        ->and($system->holds('hq.users.manage', 'anything'))->toBeFalse();
});

it('gives an agent the intersection of its own list and its enabling human, never a union', function () {
    registerTestTool(CountingTool::class);
    $human = operator(['hq.tools.diagnostics']);

    $agent = Agent::create([
        'key' => 'test-agent', 'name' => 'Test', 'status' => 'active',
        'enabling_user_id' => $human->id, 'allowed_tools' => ['test.counting'],
    ]);
    $identity = ActingIdentity::agent($agent, $human);

    expect($identity->holds('hq.tools.diagnostics', 'test.counting'))->toBeTrue();

    // The human holds the permission but the agent was never granted the tool: refused.
    expect($identity->holds('hq.tools.diagnostics', 'some.other.tool'))->toBeFalse();

    // The agent lists the tool but the human lacks the permission: still refused. An agent cannot
    // hold more than the person it borrows from.
    $weak = operator(['hq.dashboard.view']);
    expect(ActingIdentity::agent($agent, $weak)->holds('hq.tools.diagnostics', 'test.counting'))->toBeFalse();

    // A paused agent does nothing, whatever its list says.
    $agent->update(['status' => 'paused']);
    expect(ActingIdentity::agent($agent->fresh(), $human)->holds('hq.tools.diagnostics', 'test.counting'))->toBeFalse();
});

it('never lets a credential identity satisfy a permission', function () {
    // A credential is who HQ is at a provider. It grants nothing inside HQ, and there is no branch
    // in which it can. This is the E.1 rule expressed as a test rather than a paragraph.
    //
    // There is deliberately no factory that produces one, so the constructor is invoked directly:
    // the point of the test is that even if such an identity were somehow built, it authorises
    // nothing.
    $reflection = new ReflectionClass(ActingIdentity::class);
    $identity = $reflection->newInstanceWithoutConstructor();
    $constructor = $reflection->getConstructor();
    $constructor->setAccessible(true);
    $constructor->invoke($identity, IdentityKind::Credential, 'credential:shop_admin_token');

    expect($identity->holds('hq.tools.diagnostics', 'test.counting'))->toBeFalse()
        ->and($identity->holds('anything.at.all', 'any.tool'))->toBeFalse();
});

it('gives ToolContext nowhere to carry a secret', function () {
    // Structural, not behavioural, and that is the point: the boundary holds because there is no
    // field to put a credential in, rather than because every future author remembers not to.
    $names = array_map(
        static fn (ReflectionProperty $p) => strtolower($p->getName()),
        (new ReflectionClass(ToolContext::class))->getProperties(),
    );

    foreach (['token', 'secret', 'credential', 'apikey', 'api_key', 'password'] as $forbidden) {
        expect($names)->not->toContain($forbidden);
    }
});

it('refuses an agent a tool that is not agent-executable, before any other consideration', function () {
    registerTestTool(CountingTool::class);
    $human = operator();
    $agent = Agent::create([
        'key' => 'ax', 'name' => 'Ax', 'status' => 'active',
        'enabling_user_id' => $human->id, 'allowed_tools' => ['test.counting'],
    ]);

    $outcome = gateway()->execute(new ToolRequest(
        toolKey: 'test.counting',
        args: [],
        caller: CallerKind::Agent,
        identity: ActingIdentity::agent($agent, $human),
        correlationId: (string) Str::uuid(),
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('not agent-executable')
        ->and(CountingTool::$executions)->toBe(0);
});

// ---------------------------------------------------------------- business scope

it('restricts a scoped user to their businesses and leaves an unscoped user group-wide', function () {
    registerTestTool(CountingTool::class);
    $a = Business::create(['name' => 'A', 'slug' => 'a-biz']);
    $b = Business::create(['name' => 'B', 'slug' => 'b-biz']);
    $user = operator();

    $scope = app(BusinessScope::class);

    // No rows: group-wide. This is what keeps every account that exists today working unchanged.
    expect($scope->all($user))->toBeNull()
        ->and($scope->allows($user, $a->id))->toBeTrue()
        ->and($scope->allows($user, $b->id))->toBeTrue();

    UserBusinessScope::create(['user_id' => $user->id, 'business_id' => $a->id, 'access' => 'read']);
    $scope->forget($user);

    expect($scope->allows($user, $a->id))->toBeTrue()
        ->and($scope->allows($user, $b->id))->toBeFalse();

    $outcome = gateway()->execute(ToolRequest::forOperator($user, 'test.counting', [], $b->id));
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('not scoped to business')
        ->and(CountingTool::$executions)->toBe(0);
});

it('refuses a business-scoped tool that names no business', function () {
    $outcome = gateway()->execute(ToolRequest::forOperator(operator(), 'erp.poll_overview'));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('named neither a business nor a store');
});

// ---------------------------------------------------------------- policy

it('refuses every R4 and R5 call, because approval is required and the Approval Center is M3', function () {
    registerTestTool(WriteTool::class);
    $user = operator();

    $outcome = gateway()->execute(ToolRequest::forOperator($user, 'test.external_write', ['note' => 'x']));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->policy)->toBe(PolicyOutcome::RequireApproval)
        ->and($outcome->error)->toContain('human approval is required')
        ->and($outcome->error)->toContain('M3')
        // Decision V.1: no value threshold, and nothing at these tiers executes in Phase 2.
        ->and(WriteTool::$executions)->toBe(0);

    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->policy_result)->toBe('require_approval')
        ->and($call->risk_tier)->toBe('r4');
});

it('stops every tool at once when the AI freeze flag is on', function () {
    registerTestTool(CountingTool::class);
    FeatureFlag::create(['key' => PolicyEngine::FREEZE_FLAG, 'environment' => '*', 'enabled' => true]);
    app(Features::class)->forget();

    $outcome = gateway()->execute(ToolRequest::forOperator(operator(), 'test.counting'));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('global AI freeze')
        ->and(CountingTool::$executions)->toBe(0);
});

it('classifies every registered production tool, so nothing falls through to the default deny', function () {
    // The completeness test. A tool nobody classified should be caught here rather than by
    // production discovering it, and every descriptor must carry the fields the gateway reads.
    foreach (gateway()->descriptors() as $key => $descriptor) {
        expect($descriptor->key)->toBe($key)
            ->and($descriptor->version)->not->toBe('')
            ->and($descriptor->permission)->toStartWith('hq.')
            ->and($descriptor->auditAction)->toStartWith('tool.')
            ->and($descriptor->riskTier)->toBeInstanceOf(RiskTier::class);

        $decision = app(PolicyEngine::class)->decide(
            $descriptor,
            ToolRequest::forConsole($key),
            ActingIdentity::system(),
        );
        expect($decision->rule)->not->toBe('default.fail_closed');

        // The validator refuses a schema keyword it does not understand rather than ignoring it,
        // so a tool using one would be permanently uncallable. Better to learn that here than from
        // a scheduled job failing at 02:00.
        $result = app(ArgumentValidator::class)
            ->validate([], app(ToolRegistry::class)->get($key)->inputSchema());
        expect($result->message())->not->toContain('unsupported keyword');
    }
});

it('enables nothing at R4 or above, and gives no production tool to an agent', function () {
    // The Phase 2 autonomy commitment, as a test rather than a promise (docs/48 Q.2).
    foreach (gateway()->descriptors() as $key => $descriptor) {
        expect($descriptor->riskTier->writesOutsideHq())->toBeFalse("[{$key}] is R4 or above and must not be enabled in Phase 2");
        expect($descriptor->agentExecutable)->toBeFalse("[{$key}] must not be agent-executable in Phase 2");
    }
});
