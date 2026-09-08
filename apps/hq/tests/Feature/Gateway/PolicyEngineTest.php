<?php

use App\Gateway\CallerKind;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyEngine;
use App\Gateway\Policy\PolicyOutcome;
use App\Gateway\Policy\PolicyRepository;
use App\Gateway\Policy\PolicyRules;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\Policy;
use App\Models\ToolCall;
use App\Models\User;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Tools\CountingTool;
use Tests\Support\Tools\WriteTool;

/*
 | M2: the real policy engine (docs/48 F, docs/50).
 |
 | Deterministic, versioned, model-free, reproducible. Every test here asks the same question in a
 | different way: can something reach a tool that should not have, and can a past decision still be
 | explained exactly as it was made?
 */

function policyUser(array $permissions = ['*']): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $permissions === ['*'] ? $user->assignRole('owner') : $user->givePermissionTo($permissions);

    return $user->fresh();
}

function policyGateway(): ToolGateway
{
    app(ToolRegistry::class)->register(CountingTool::class);
    app(ToolRegistry::class)->register(WriteTool::class);
    app(ToolAvailability::class)->forget();

    return app(ToolGateway::class);
}

beforeEach(function () {
    Cache::flush();
    CountingTool::reset();
    WriteTool::$executions = 0;
});

// ---------------------------------------------------------------- baselines

it('allows R0 to R3 and requires approval for R4 and R5, with no value threshold', function () {
    $engine = app(PolicyEngine::class);

    foreach ([RiskTier::R0, RiskTier::R1, RiskTier::R2, RiskTier::R3] as $tier) {
        expect($tier->baseline()->value)->toBe('auto', "{$tier->value} should be automatic");
    }
    foreach ([RiskTier::R4, RiskTier::R5] as $tier) {
        expect($tier->baseline()->value)->toBe('approval_required', "{$tier->value} must require approval (decision V.1)");
    }

    // And the engine agrees with the enum, so the two cannot drift.
    expect($engine->baselineFor((new WriteTool)->descriptor())->outcome)->toBe(PolicyOutcome::RequireApproval)
        ->and($engine->baselineFor((new CountingTool)->descriptor())->outcome)->toBe(PolicyOutcome::Allow);
});

it('refuses an R4 call outright, because approvals arrive in M3', function () {
    $outcome = policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.external_write', ['note' => 'x']));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->policy)->toBe(PolicyOutcome::RequireApproval)
        ->and($outcome->error)->toContain('M3')
        ->and(WriteTool::$executions)->toBe(0);

    // Not silently approved, and no fake approval record was invented to make it look handled.
    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->policy_result)->toBe('require_approval')
        ->and($call->approval_id)->toBeNull()
        ->and($call->action_class)->toBe('approval_required');
});

it('classifies every registered tool, so nothing falls through to the default deny', function () {
    $engine = app(PolicyEngine::class);
    $identity = ActingIdentity::system();

    foreach (app(ToolGateway::class)->descriptors() as $key => $descriptor) {
        $decision = $engine->decide($descriptor, ToolRequest::forConsole($key), $identity, [], null);
        expect($decision->rule)->not->toBe('default.fail_closed', "[{$key}] is unclassified");
    }
});

// ---------------------------------------------------------------- guardrails

it('lets a guardrail refuse a call the tier would have allowed', function () {
    $user = policyUser();
    app(PolicyRepository::class)->open('counting.frozen', ['deny' => true, 'deny_reason' => 'under investigation'], 'test.counting');

    $outcome = policyGateway()->execute(ToolRequest::forOperator($user, 'test.counting'));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('under investigation')
        ->and(CountingTool::$executions)->toBe(0);
});

it('lets a guardrail put an approval in front of an otherwise automatic tool', function () {
    app(PolicyRepository::class)->open('counting.gated', ['require_approval' => true, 'reason' => 'new tool, watching it'], 'test.counting');

    $outcome = policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting'));

    expect($outcome->policy)->toBe(PolicyOutcome::RequireApproval)
        ->and($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and(CountingTool::$executions)->toBe(0);
});

it('refuses arguments a guardrail puts a ceiling on', function () {
    app(PolicyRepository::class)->open('counting.args', ['arg_allowed' => ['label' => ['safe']]], 'test.counting');
    $gateway = policyGateway();

    expect($gateway->execute(ToolRequest::forOperator(policyUser(), 'test.counting', ['label' => 'unsafe']))->status)
        ->toBe(OutcomeStatus::Denied);
    expect($gateway->execute(ToolRequest::forOperator(policyUser(), 'test.counting', ['label' => 'safe']))->ok())
        ->toBeTrue();
});

it('refuses a policy that uses a rule key the engine does not implement', function () {
    // A rule that silently does nothing is worse than no rule: somebody believes it protects them.
    app(PolicyRepository::class)->open('counting.typo', ['maximum_calls_per_hour' => 1], 'test.counting');

    $outcome = policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting'));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('does not implement')
        ->and($outcome->error)->toContain('maximum_calls_per_hour');
});

it('never relaxes an R5 tier, whatever a guardrail says', function () {
    $rules = new PolicyRules(['relax_to' => 'allow', 'relax_reason' => 'we are sure']);

    expect($rules->relaxesTo(RiskTier::R4))->toBe('allow')
        ->and($rules->relaxesTo(RiskTier::R5))->toBeNull();
});

it('ignores a relaxation that gives no reason', function () {
    expect((new PolicyRules(['relax_to' => 'allow']))->relaxesTo(RiskTier::R4))->toBeNull();
});

// ---------------------------------------------------------------- rate limits

it('stops a tool once its policy call rate is used up, and does not charge a refused call', function () {
    app(PolicyRepository::class)->open('counting.rate', ['max_calls_per_hour' => 2], 'test.counting');
    $gateway = policyGateway();
    $user = policyUser();

    expect($gateway->execute(ToolRequest::forOperator($user, 'test.counting'))->ok())->toBeTrue();
    expect($gateway->execute(ToolRequest::forOperator($user, 'test.counting'))->ok())->toBeTrue();

    $third = $gateway->execute(ToolRequest::forOperator($user, 'test.counting'));
    expect($third->status)->toBe(OutcomeStatus::Denied)
        ->and($third->error)->toContain('calls for this hour')
        ->and(CountingTool::$executions)->toBe(2);

    // The refused call must not have consumed quota of its own, or a refusal would tighten the
    // limit for the next legitimate caller.
    $fourth = $gateway->execute(ToolRequest::forOperator($user, 'test.counting'));
    expect($fourth->status)->toBe(OutcomeStatus::Denied);
});

// ---------------------------------------------------------------- specificity and versioning

it('picks the most specific policy: tool and business beats tool beats business beats global', function () {
    $business = Business::create(['name' => 'B', 'slug' => 'b-biz']);
    $repo = app(PolicyRepository::class);

    $repo->open('global', ['deny' => true, 'deny_reason' => 'global'], null, null);
    expect($repo->forCall('test.counting', $business->id)->key)->toBe('global');

    $repo->open('by-business', ['deny' => true, 'deny_reason' => 'business'], null, $business->id);
    expect($repo->forCall('test.counting', $business->id)->key)->toBe('by-business');

    $repo->open('by-tool', ['deny' => true, 'deny_reason' => 'tool'], 'test.counting', null);
    expect($repo->forCall('test.counting', $business->id)->key)->toBe('by-tool');

    $repo->open('by-both', ['deny' => true, 'deny_reason' => 'both'], 'test.counting', $business->id);
    expect($repo->forCall('test.counting', $business->id)->key)->toBe('by-both');

    // A different tool still falls back to the business policy.
    expect($repo->forCall('other.tool', $business->id)->key)->toBe('by-business');
});

it('records which policy version decided a call', function () {
    $policy = app(PolicyRepository::class)->open('counting.watched', ['require_approval' => true, 'reason' => 'v1'], 'test.counting');

    $outcome = policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting'));
    $call = ToolCall::findOrFail($outcome->toolCallId);

    expect($call->policy_id)->toBe($policy->id)->and($call->policy_version)->toBe(1);
});

it('supersedes a policy without rewriting the decision the old version made', function () {
    $repo = app(PolicyRepository::class);
    $v1 = $repo->open('counting.evolving', ['require_approval' => true, 'reason' => 'v1 reason'], 'test.counting');

    $first = policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting'));
    $firstCall = ToolCall::findOrFail($first->toolCallId);

    $v2 = $repo->supersede($v1, [], 'relaxing it');
    Cache::flush();

    $second = policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting'));
    $secondCall = ToolCall::findOrFail($second->toolCallId);

    expect($v2->version)->toBe(2)
        ->and($v1->fresh()->active_to)->not->toBeNull()
        // The old call still points at version 1, and version 1 still says what it said.
        ->and($firstCall->policy_version)->toBe(1)
        ->and($v1->fresh()->rules)->toBe(['require_approval' => true, 'reason' => 'v1 reason'])
        // The new call is governed by version 2 and now proceeds.
        ->and($secondCall->policy_version)->toBe(2)
        ->and($second->ok())->toBeTrue();
});

it('ignores a policy whose window has closed', function () {
    $repo = app(PolicyRepository::class);
    $policy = $repo->open('counting.expired', ['deny' => true, 'deny_reason' => 'old rule'], 'test.counting');
    $repo->close($policy);
    Cache::flush();

    expect(app(PolicyRepository::class)->forCall('test.counting', null))->toBeNull()
        ->and(policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting'))->ok())->toBeTrue();
});

it('ignores a policy that has not started yet', function () {
    Policy::create([
        'key' => 'future', 'version' => 1, 'tool_key' => 'test.counting', 'rules' => ['deny' => true],
        'active_from' => CarbonImmutable::now()->addDay(),
    ]);

    expect(app(PolicyRepository::class)->forCall('test.counting', null))->toBeNull();
});

// ---------------------------------------------------------------- kill switches and identity

it('stops everything when the freeze flag is on', function () {
    FeatureFlag::create(['key' => PolicyEngine::FREEZE_FLAG, 'environment' => '*', 'enabled' => true]);
    app(Features::class)->forget();

    expect(policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting'))->status)
        ->toBe(OutcomeStatus::Denied)
        ->and(CountingTool::$executions)->toBe(0);
});

it('refuses a call for a business that has been marked inactive', function () {
    $business = Business::create(['name' => 'Closed', 'slug' => 'closed-biz', 'is_active' => false]);

    $outcome = policyGateway()->execute(ToolRequest::forOperator(policyUser(), 'test.counting', [], $business->id));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)->and($outcome->error)->toContain('inactive');
});

it('denies a caller without the tool permission, from inside the engine', function () {
    $outcome = policyGateway()->execute(ToolRequest::forOperator(policyUser(['hq.dashboard.view']), 'test.counting'));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->policy)->toBe(PolicyOutcome::Deny)
        ->and($outcome->error)->toContain('hq.tools.diagnostics')
        ->and(CountingTool::$executions)->toBe(0);
});

it('refuses an agent a tool that is not agent-executable before anything else is considered', function () {
    $human = policyUser();
    $agent = Agent::create([
        'key' => 'a1', 'name' => 'A', 'status' => 'active',
        'enabling_user_id' => $human->id, 'allowed_tools' => ['test.counting'],
    ]);

    $outcome = policyGateway()->execute(new ToolRequest(
        toolKey: 'test.counting', args: [], caller: CallerKind::Agent,
        identity: ActingIdentity::agent($agent, $human), correlationId: (string) Str::uuid(),
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)->and($outcome->error)->toContain('not agent-executable');
});

it('still refuses to enable anything at R4 or above, or to give any tool to an agent', function () {
    // The Phase 2 autonomy commitment, unchanged by M2 and enforced rather than promised.
    foreach (app(ToolGateway::class)->descriptors() as $key => $descriptor) {
        expect($descriptor->riskTier->writesOutsideHq())->toBeFalse("[{$key}] must not be enabled at R4 or above");
        expect($descriptor->agentExecutable)->toBeFalse("[{$key}] must not be agent-executable");
    }
});
