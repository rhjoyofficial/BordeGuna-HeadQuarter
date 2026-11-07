<?php

use App\Content\ContentDraftService;
use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ContentCalendarEntry;
use App\Models\DecisionRecord;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\OwnerConsole\Do\DoModeActivation;
use App\OwnerConsole\Do\DoPlanService;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\OwnerConsole\Watches\EventWatchService;
use App\Research\ResearchRefused;
use App\Support\Authorization\ApproverRole;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Completion\M4;
use Tests\Support\Content\PhaseSix;
use Tests\Support\OwnerConsole\TestDoModeActivation;

/*
 | docs/149 (architecture ruling 1 Nov 2026): the least-privilege `approver` role. Two managers ("Manager A" and
 | "Manager B" below) can be second approvers without the full-access operator role. They see and decide only approvals
 | of their own businesses, only in the categories assigned to them person by person, never their own proposals, and
 | only with confirmed two-factor authentication. Every existing two-person and independent-approval rule is unchanged.
 */

const AR_APPROVER_EXACT = ['hq.approvals.decide', 'hq.approvals.view', 'hq.dashboard.view'];

/** An approver limited to the given businesses (slugs), with confirmed 2FA, and any extra category permissions. */
function arApproverFor(array $slugs = ['laladia'], array $categories = []): User
{
    $user = test()->hqUser(ApproverRole::NAME);
    if ($categories !== []) {
        $user->givePermissionTo($categories);
    }

    return M4::limit($user, ...$slugs);
}

/** An approval of the demonstration tool, parked by `$requester` and placed in `$businessId`. */
function arApprovalIn(User $requester, int $businessId, string $note = 'second approver'): Approval
{
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($requester, 'demo.request_approval', ['note' => $note]));
    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval);
    DB::table('approvals')->where('id', $outcome->approvalId)->update(['business_id' => $businessId]);

    return Approval::findOrFail($outcome->approvalId);
}

function arDemoRuns(): int
{
    return AuditLog::query()->where('action', 'demo.approved_action_executed')->count();
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->lld = (int) Brand::where('code', 'LLD')->sole()->business_id;
    $this->bnc = (int) Brand::where('code', 'BNC')->sole()->business_id;
});

// ---------------------------------------------------------------- the role itself

it('gives the approver role exactly these permissions and nothing else', function () {
    $declared = RolesAndPermissionsSeeder::ROLES[ApproverRole::NAME];
    sort($declared);
    $seeded = Role::findByName(ApproverRole::NAME, 'web')->permissions->pluck('name')->sort()->values()->all();

    expect($declared)->toBe(AR_APPROVER_EXACT)->and($seeded)->toBe(AR_APPROVER_EXACT);

    // None of the admin, credential, flag, tool, autonomy, connector, policy, audit, customer or consent authority.
    foreach ([
        'hq.users.manage', 'hq.credentials.view', 'hq.credentials.manage', 'hq.flags.view', 'hq.flags.manage', 'hq.tools.view',
        'hq.tools.manage', 'hq.autonomy.view', 'hq.autonomy.grant', 'hq.autonomy.revoke', 'hq.connectors.view', 'hq.policies.view',
        'hq.policies.manage', 'hq.audit.view', 'hq.customers.view', 'hq.customers.manage', 'hq.consent.grant',
        'hq.consent.lift_restriction', 'hq.approvals.delegate', 'hq.agents.manage', 'hq.content.review', 'hq.ingestion.review',
    ] as $forbidden) {
        expect($declared)->not->toContain($forbidden);
    }
    // Every never-assignable permission is a real one, so the list cannot rot into names nothing checks.
    expect(array_diff(ApproverRole::NEVER_ASSIGNABLE, RolesAndPermissionsSeeder::PERMISSIONS))->toBe([]);
});

it('seeds idempotently: running the seeder twice leaves the same roles, permissions and grants', function () {
    $counts = fn () => [Permission::count(), Role::count(), DB::table('role_has_permissions')->count(),
        Role::findByName(ApproverRole::NAME, 'web')->permissions()->count()];
    $first = $counts();
    (new RolesAndPermissionsSeeder)->run();
    (new RolesAndPermissionsSeeder)->run();

    expect($counts())->toBe($first)->and($first[3])->toBe(count(AR_APPROVER_EXACT));
});

it('creates an approver only with a named business, and limits them to it', function () {
    $this->artisan('hq:user:create', ['email' => 'manager-a@example.test', '--role' => 'approver', '--generate-password' => true])->assertFailed();
    expect(User::where('email', 'manager-a@example.test')->exists())->toBeFalse();

    $this->artisan('hq:user:create', ['email' => 'manager-a@example.test', '--role' => 'approver', '--business' => ['nope'], '--generate-password' => true])->assertFailed();
    $this->artisan('hq:user:create', ['email' => 'manager-a@example.test', '--role' => 'no-such-role', '--generate-password' => true])->assertFailed();

    $this->artisan('hq:user:create', ['email' => 'manager-a@example.test', '--name' => 'Manager A', '--role' => 'approver', '--business' => ['laladia'], '--generate-password' => true])
        ->assertSuccessful();
    $user = User::where('email', 'manager-a@example.test')->sole();
    expect($user->hasRole('approver'))->toBeTrue()->and($user->hasRole('operator'))->toBeFalse()
        ->and(UserBusinessScope::where('user_id', $user->id)->pluck('business_id')->all())->toBe([$this->lld])
        ->and(AuditLog::where('action', 'user.created')->where('target_id', $user->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- two-factor authentication

it('requires two-factor authentication: at the routes and again at the decision', function () {
    $approver = arApproverFor(['laladia'], ['hq.tools.diagnostics']);
    $approval = arApprovalIn($this->owner, $this->lld);

    $unenrolled = $approver->fresh();
    $unenrolled->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $this->actingAs($unenrolled)->get('/approvals')->assertRedirect('/security/two-factor');
    $this->actingAs($unenrolled)->post("/approvals/{$approval->id}/approve")->assertRedirect('/security/two-factor');
    expect($approval->decisionRefusalReason($unenrolled->fresh()))->toContain('two-factor');

    // Enrolled but not challenged this session: still refused.
    $enrolled = $unenrolled->fresh();
    $enrolled->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();
    $this->flushSession();
    $this->actingAs($enrolled->fresh())->get('/approvals')->assertRedirect('/two-factor/challenge');
    expect(arDemoRuns())->toBe(0)->and($approval->fresh()->status->value)->toBe('pending');
});

// ---------------------------------------------------------------- admin surfaces

it('refuses an approver every admin surface by direct request', function () {
    $approver = arApproverFor(['laladia']);
    $as = fn () => $this->actingAsHq($approver);

    foreach (['/tools', '/flags', '/credentials', '/audit', '/customers', '/connectors', '/autonomy', '/agents', '/inbox',
        '/customers/quarantine', '/playbooks', '/content', '/ingestion', '/observe/posture', '/observe/control-plane'] as $uri) {
        $as()->get($uri)->assertForbidden();
    }
    $as()->post('/tools/demo.request_approval/toggle')->assertForbidden();
    $as()->post('/flags', ['key' => 'x', 'enabled' => true])->assertForbidden();
    $as()->post('/credentials', ['name' => 'x'])->assertForbidden();
    $as()->post('/autonomy/grants', [])->assertForbidden();
    $as()->post('/autonomy/thresholds', [])->assertForbidden();
    $as()->post('/connectors/meta/check')->assertForbidden();
    $as()->post("/connectors/media-budget/{$this->lld}", [])->assertForbidden();
    $as()->post('/ai/resume')->assertForbidden();
    $as()->post('/customers/quarantine/repair', [])->assertForbidden();

    // No HTTP route manages users at all; the role cannot, by permission, either.
    expect($approver->can('hq.users.manage'))->toBeFalse();

    // What it is for, it can reach.
    $as()->get('/approvals')->assertOk();
    $as()->get('/dashboard')->assertOk();
});

// ---------------------------------------------------------------- business scope

it('lets a restricted approver neither see nor decide another business\'s approval', function () {
    $approver = arApproverFor(['laladia'], ['hq.tools.diagnostics']);
    $other = arApprovalIn($this->owner, $this->bnc, 'other business');

    $this->actingAsHq($approver)->get("/approvals/{$other->id}")->assertNotFound();
    $this->actingAsHq($approver)->post("/approvals/{$other->id}/approve")->assertNotFound();
    $this->actingAsHq($approver)->post("/approvals/{$other->id}/reject", ['reason' => 'no'])->assertNotFound();
    expect($other->decisionRefusalReason($approver))->not->toBeNull();
    expect(app(ApprovalExecutor::class)->approveAndExecute($other->fresh(), $approver)->ok())->toBeFalse()
        ->and($other->fresh()->status->value)->toBe('pending')->and(arDemoRuns())->toBe(0);
});

it('lets an approver with no business rows decide nothing, since no rows would mean group-wide', function () {
    $groupWide = $this->hqUser(ApproverRole::NAME);
    $groupWide->givePermissionTo('hq.tools.diagnostics');
    $approval = arApprovalIn($this->owner, $this->lld);

    expect($approval->decisionRefusalReason($groupWide))->toContain('limited to named businesses');
    $this->actingAsHq($groupWide)->post("/approvals/{$approval->id}/approve")->assertForbidden();
    expect(arDemoRuns())->toBe(0);
});

// ---------------------------------------------------------------- categories and the decision

it('decides only an assigned category, and a decision is audited with actor, decision, reason and time', function () {
    $unassigned = arApproverFor(['laladia']);
    $approval = arApprovalIn($this->owner, $this->lld);

    expect($approval->decisionRefusalReason($unassigned))->toContain('not assigned');
    $this->actingAsHq($unassigned)->post("/approvals/{$approval->id}/approve")->assertForbidden();
    expect($approval->fresh()->status->value)->toBe('pending')->and(arDemoRuns())->toBe(0);

    // A category that is never an approver's stays refused even when granted by mistake.
    expect(app(ApproverRole::class)->refusal($unassigned, $this->lld, [], 'hq.consent.lift_restriction'))->toContain('never');

    $assigned = arApproverFor(['laladia'], ['hq.tools.diagnostics']);
    $this->actingAsHq($assigned)->post("/approvals/{$approval->id}/approve", ['note' => 'checked the evidence'])->assertRedirect();

    $fresh = $approval->fresh();
    expect($fresh->decided_by_user_id)->toBe($assigned->id)->and(arDemoRuns())->toBe(1);
    $audit = AuditLog::where('action', 'approval.approved')->where('target_id', $approval->id)->sole();
    expect((int) $audit->actor_id)->toBe($assigned->id)->and($audit->reason)->toBe('checked the evidence')
        ->and($audit->occurred_at)->not->toBeNull();
    expect(DecisionRecord::where('context_id', (string) $approval->id)->where('user_id', $assigned->id)->value('decision'))->toBe('approved');

    // A rejection is audited the same way, with its reason.
    $second = arApprovalIn($this->owner, $this->lld, 'reject me');
    $this->actingAsHq($assigned)->post("/approvals/{$second->id}/reject", ['reason' => 'wrong amount'])->assertRedirect();
    $rejected = AuditLog::where('action', 'approval.rejected')->where('target_id', $second->id)->sole();
    expect((int) $rejected->actor_id)->toBe($assigned->id)->and($rejected->reason)->toBe('wrong amount');
});

it('never lets an approver decide what they asked for, even where the tool lets a requester decide', function () {
    $approver = arApproverFor(['laladia'], ['hq.tools.diagnostics']);
    $own = arApprovalIn($approver, $this->lld, 'my own');

    expect($own->decisionRefusalReason($approver))->toContain('somebody else decides');
    $this->actingAsHq($approver)->post("/approvals/{$own->id}/approve")->assertForbidden();
    expect(fn () => app(ApprovalService::class)->reject($own->fresh(), $approver, 'no'))->toThrow(RuntimeException::class);

    // The owner's existing rule is unchanged: the owner may still decide what the owner asked for.
    expect(arApprovalIn($this->owner, $this->lld, 'owner own')->isDecidableBy($this->owner))->toBeTrue();
});

it('never lets the proposer of a Do plan approve its step; a second approver may', function () {
    FeatureFlag::query()->where('key', Flag::DO_MODE)->update(['enabled' => true]);
    app()->instance(DoModeActivation::class, new TestDoModeActivation);
    $plans = app(DoPlanService::class);

    $managerA = arApproverFor(['laladia'], ['hq.tools.diagnostics', 'hq.do.propose']);
    $managerB = arApproverFor(['laladia'], ['hq.tools.diagnostics']);
    $plan = $plans->start($plans->propose($this->lld, 'Plan', [['tool_key' => 'demo.request_approval', 'args' => ['note' => 'step'], 'summary' => 'Do it']], $managerA), $this->owner);
    $step = Approval::findOrFail($plan->steps[0]->approval_id);

    expect($step->decisionRefusalReason($managerA))->not->toBeNull()
        ->and($step->decisionRefusalReason($this->owner))->toContain('Do plan');
    $this->actingAsHq($managerA)->post("/approvals/{$step->id}/approve")->assertForbidden();
    expect(arDemoRuns())->toBe(0);

    expect($step->isDecidableBy($managerB))->toBeTrue();
    expect(app(ApprovalExecutor::class)->approveAndExecute($step->fresh(), $managerB)->ok())->toBeTrue()->and(arDemoRuns())->toBe(1);
});

// ---------------------------------------------------------------- other approval categories

it('never lets the proposer enable their own action watch; an assigned approver of that business may', function () {
    FeatureFlag::query()->where('key', Flag::EVENT_WATCHES)->update(['enabled' => true]);
    $watches = app(EventWatchService::class);
    $action = ['tool_key' => 'demo.request_approval', 'args' => ['note' => 'follow up']];

    // The existing rule, unchanged: the proposer (here the owner) does not enable their own action watch.
    $watch = $watches->propose($this->owner, $this->lld, 'Big orders', 'order.created', [['path' => 'total', 'op' => 'gte', 'value' => 500]], $action);
    expect(fn () => $watches->enable($watch, $this->owner))->toThrow(Refused::class, 'somebody else enables it');

    // An approver who proposed a watch never enables it either, with or without an action.
    $managerA = arApproverFor(['laladia'], ['hq.watches.propose', 'hq.watches.enable']);
    $mine = $watches->propose($managerA, $this->lld, 'Mine', 'order.created', [['path' => 'total', 'op' => 'gte', 'value' => 500]], null);
    expect(fn () => $watches->enable($mine, $managerA))->toThrow(Refused::class);

    // Another business's watch is not there for a restricted approver.
    $bncWatch = $watches->propose($this->owner, $this->bnc, 'Other', 'order.created', [['path' => 'total', 'op' => 'gte', 'value' => 500]], $action);
    $this->actingAsHq($managerA)->post("/watches/{$bncWatch->uuid}/enable")->assertNotFound();

    // An approver without the category is refused at the route; one with it enables the owner's proposal.
    $this->actingAsHq(arApproverFor(['laladia']))->post("/watches/{$watch->uuid}/enable")->assertForbidden();
    $managerB = arApproverFor(['laladia'], ['hq.watches.enable']);
    $this->actingAsHq($managerB)->post("/watches/{$watch->uuid}/enable")->assertRedirect();
    expect($watch->fresh()->state)->toBe('enabled')->and($watch->fresh()->enabled_by_user_id)->toBe($managerB->id);
});

it('makes a second eligible approver end content self-approval, and never lets an approver approve their own draft', function () {
    Http::preventStrayRequests();
    $lld = Brand::where('code', 'LLD')->sole();
    PhaseSix::brief($lld, $this->owner);
    M4::on('content.drafting');
    $entry = function () use ($lld): ContentCalendarEntry {
        $this->actingAsHq($this->owner)->post('/content/calendar', [
            'brand_id' => $lld->id, 'planned_for' => now()->addDays(3)->toDateString(), 'platform' => 'facebook_page',
            'product_lines' => 'mustard_oil', 'idea' => 'A winter recipe',
        ])->assertSessionHasNoErrors();
        $e = ContentCalendarEntry::query()->latest('id')->firstOrFail();
        $this->actingAsHq($this->owner)->post("/content/calendar/{$e->uuid}/draft", ['text' => 'সরিষার তেল, শীতের রান্নায়।'])->assertSessionHasNoErrors();

        return $e->fresh();
    };

    // Manager A: an approver of this business, with content review assigned. Now the owner is not the sole reviewer.
    $managerA = arApproverFor(['laladia'], ['hq.content.review']);
    $first = $entry();
    $this->actingAsHq($this->owner)->post("/content/calendar/{$first->uuid}/review", ['verdict' => 'approved', 'self_reason' => 'only me'])->assertSessionHasErrors('form');
    expect($first->fresh()->status)->toBe('drafted');

    $this->actingAsHq($managerA)->post("/content/calendar/{$first->uuid}/review", ['verdict' => 'approved'])->assertSessionHasNoErrors();
    expect($first->fresh()->draft->reviewed_by)->toBe($managerA->id)->and($first->fresh()->draft->self_decided)->toBeFalse();

    // An approver never approves a draft they wrote, not even as the sole reviewer.
    $second = $entry();
    $draft = $second->draft;
    $draft->forceFill(['created_by' => $managerA->id])->save();
    expect(fn () => app(ContentDraftService::class)->review($draft->fresh(), 'approved', $managerA, 'sole'))->toThrow(ResearchRefused::class);
});
