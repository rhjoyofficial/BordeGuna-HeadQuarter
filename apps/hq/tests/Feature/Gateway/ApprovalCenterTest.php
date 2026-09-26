<?php

use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\Approval\CanonicalPayload;
use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyEngine;
use App\Gateway\Policy\PolicyOutcome;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Registry\ToolSwitch;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\DecisionRecord;
use App\Models\FeatureFlag;
use App\Models\ToolCall;
use App\Models\User;
use App\Support\Features\Features;
use App\Tools\PreviewableTool;
use App\Tools\ToolRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
 | M3: the Approval Center (docs/48 G, ADR-018, docs/51).
 |
 | The demonstration tool is R4 and its only effect is one row in HQ audit log. That is deliberate:
 | proving the approval mechanism must not mean creating or cancelling a customer's order to test it.
 |
 | Almost every test here asserts that something did NOT happen, because that is what an approval
 | gate is for.
 */

const DEMO = 'demo.request_approval';

function approver(array $permissions = ['*']): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $permissions === ['*'] ? $user->assignRole('owner') : $user->givePermissionTo($permissions);

    return $user->fresh();
}

/** Parks a real R4 call through the gateway and returns the approval it created. */
function park(User $user, array $args = ['note' => 'demonstration']): Approval
{
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($user, DEMO, $args));

    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval);

    return Approval::findOrFail($outcome->approvalId);
}

function demoExecutions(): int
{
    return AuditLog::where('action', 'demo.approved_action_executed')->count();
}

beforeEach(function () {
    Cache::flush();
    app(ToolAvailability::class)->forget();
});

// ---------------------------------------------------------------- parking

it('parks an R4 call instead of refusing it, and executes nothing', function () {
    $user = approver();

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($user, DEMO, ['note' => 'hello', 'amount' => 250]));

    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval)
        ->and($outcome->policy)->toBe(PolicyOutcome::RequireApproval)
        ->and($outcome->approvalId)->not->toBeNull()
        // The whole point: nothing happened.
        ->and(demoExecutions())->toBe(0);

    $approval = Approval::findOrFail($outcome->approvalId);
    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->tool_key)->toBe(DEMO)
        ->and($approval->tool_version)->toBe('1.0.0')
        // toEqual, not toBe: a jsonb round trip does not promise that 250.0 comes back as a float
        // rather than an int, and the guarantee under test is that the CONTENT is what was validated.
        ->and($approval->payload)->toEqual(['note' => 'hello', 'amount' => 250.0])
        ->and($approval->payloadIntact())->toBeTrue()
        ->and($approval->risk_tier->value)->toBe('r4')
        ->and($approval->expires_at)->not->toBeNull()
        ->and($approval->sla_due_at)->not->toBeNull();

    // The requesting call is parked, not finished, and points at the approval.
    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->status)->toBe(OutcomeStatus::AwaitingApproval->value)
        ->and($call->approval_id)->toBe($approval->id);
});

it('captures a preview so the approver sees what would happen', function () {
    $approval = park(approver());

    expect($approval->preview['available'])->toBeTrue()
        ->and($approval->preview['result']['external_effect'])->toContain('none')
        // A preview must not perform the effect.
        ->and(demoExecutions())->toBe(0);
});

it('says plainly when a tool cannot preview, rather than pretending', function () {
    // A tool with no preview path produces an honest "not available" rather than a live run.
    $tool = app(ToolRegistry::class)->get('commerce.check_fact_integrity');
    expect($tool)->not->toBeInstanceOf(PreviewableTool::class);
});

// ---------------------------------------------------------------- approve and execute

it('executes exactly the approved payload, once, through the gateway', function () {
    $user = approver();
    $approval = park($user, ['note' => 'the approved note']);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $user, 'looks right');

    expect($outcome->ok())->toBeTrue()
        ->and($outcome->approvalId)->toBe($approval->id)
        ->and(demoExecutions())->toBe(1);

    $fresh = $approval->fresh();
    expect($fresh->status)->toBe(ApprovalStatus::Consumed)
        ->and($fresh->consumed_at)->not->toBeNull()
        ->and($fresh->resulting_tool_call_id)->toBe($outcome->toolCallId)
        ->and($fresh->decided_by_user_id)->toBe($user->id);

    // The executed call went through the gateway like any other, and carries the approval.
    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->approval_id)->toBe($approval->id)
        ->and($call->status)->toBe(OutcomeStatus::Succeeded->value);

    // And what ran is what was approved.
    expect(AuditLog::where('action', 'demo.approved_action_executed')->latest('id')->first()->after['note'])
        ->toBe('the approved note');
});

it('refuses a second use of the same approval', function () {
    $user = approver();
    $approval = park($user);

    expect(app(ApprovalExecutor::class)->approveAndExecute($approval, $user)->ok())->toBeTrue();

    $second = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user);

    // The guard refuses it as already decided; either way it cannot run twice, and the count is
    // the assertion that actually matters.
    expect($second->status)->toBe(OutcomeStatus::Denied)
        ->and($second->error)->toContain('cannot be decided again')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Consumed)
        ->and(demoExecutions())->toBe(1);
});

it('produces exactly one execution when two approvals race', function () {
    $user = approver();
    $approval = park($user);
    $service = app(ApprovalService::class);

    // The claim is a conditional UPDATE, so of two simultaneous callers exactly one matches a row
    // still in `pending`. This is a database guarantee rather than a timing assumption.
    $first = $service->claim($approval, $user);
    $second = $service->claim($approval->fresh(), $user);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();

    // And the losing caller, going through the executor, executes nothing.
    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user);
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and(demoExecutions())->toBe(0);
});

// ---------------------------------------------------------------- integrity

it('refuses to execute when the stored payload no longer matches its hash', function () {
    $user = approver();
    $approval = park($user, ['note' => 'original']);

    // Tampering that cannot happen through the application: a direct write to the row.
    DB::table('approvals')->where('id', $approval->id)->update(['payload' => json_encode(['note' => 'tampered'])]);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('no longer matches the hash')
        ->and(demoExecutions())->toBe(0)
        // Recorded loudly, and the approval is NOT repaired.
        ->and(AuditLog::where('action', 'approval.integrity_failed')->count())->toBe(1)
        ->and(Approval::find($approval->id)->payload)->toBe(['note' => 'tampered']);
});

it('hashes canonically, so a payload survives storage without looking tampered with', function () {
    $h = fn (array $p) => CanonicalPayload::hash($p);

    // Things that MUST hash the same, or the tamper check accuses an untouched approval.
    expect($h(['b' => 1, 'a' => 2]))->toBe($h(['a' => 2, 'b' => 1]))
        // ⚠ The one that was a real defect: jsonb does not promise to return 250.0 as a float.
        ->and($h(['a' => 250]))->toBe($h(['a' => 250.0]))
        ->and($h(['a' => 250.0]))->toBe($h(['a' => 250.00]))
        ->and($h(['a' => 0.1 + 0.2]))->toBe($h(['a' => 0.3]));

    // And things that MUST differ, or the hash stops meaning anything.
    expect($h(['xs' => [1, 2]]))->not->toBe($h(['xs' => [2, 1]]))
        ->and($h(['a' => 1]))->not->toBe($h(['a' => '1']))
        ->and($h(['a' => true]))->not->toBe($h(['a' => '1']))
        ->and($h(['a' => null]))->not->toBe($h(['a' => 'z']))
        ->and($h(['a' => 1]))->not->toBe($h(['a' => 2]));
});

it('keeps a stored payload verifiable after a database round trip', function () {
    // The regression this protects: hash on the way in, re-hash on the way out, with jsonb in
    // between. A numeric payload is the case that broke.
    $approval = park(approver(), ['note' => 'round trip', 'amount' => 250]);

    expect($approval->fresh()->payloadIntact())->toBeTrue();
});

it('refuses an approval whose tool has had a breaking version change', function () {
    $user = approver();
    $approval = park($user);

    // The approved arguments were agreed against version 1. The payload is never migrated.
    DB::table('approvals')->where('id', $approval->id)->update(['tool_version' => '0.9.0']);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('different contract')
        ->and(demoExecutions())->toBe(0);
});

// ---------------------------------------------------------------- authority is re-checked

it('refuses an old approval for a tool that has since been disabled', function () {
    $user = approver();
    $approval = park($user);

    app(ToolSwitch::class)->disable(DEMO, 'turned off after the request', $user);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('disabled at runtime')
        ->and(demoExecutions())->toBe(0);
});

it('refuses an approved action once the global freeze is on', function () {
    $user = approver();
    $approval = park($user);

    FeatureFlag::create(['key' => PolicyEngine::FREEZE_FLAG, 'environment' => '*', 'enabled' => true]);
    app(Features::class)->forget();

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user);

    // The freeze is caught by the gateway's own policy step, because an approved call runs the
    // same pipeline. A human approval is not an exemption from the rules in force at execution.
    expect($outcome->ok())->toBeFalse()->and(demoExecutions())->toBe(0);
});

it('refuses when the approver has lost the permission the tool needs', function () {
    $user = approver();
    $approval = park($user);

    $user->removeRole('owner');
    $user->givePermissionTo('hq.approvals.decide');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $user->fresh());

    expect($outcome->ok())->toBeFalse()->and(demoExecutions())->toBe(0);
});

// ---------------------------------------------------------------- reject, cancel, expire

it('never executes a rejected approval, and records the reason as feedback', function () {
    $user = approver();
    $approval = park($user);

    app(ApprovalService::class)->reject($approval, $user, 'the amount is wrong');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and(demoExecutions())->toBe(0)
        ->and(DecisionRecord::where('decision', 'rejected')->count())->toBe(1);

    // The parked call is closed out rather than left waiting forever.
    expect(ToolCall::find($approval->requesting_tool_call_id)->status)->toBe(OutcomeStatus::Denied->value);

    // And it cannot then be approved.
    expect(app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user)->ok())->toBeFalse();
});

it('never executes a cancelled approval', function () {
    $user = approver();
    $approval = park($user);

    app(ApprovalService::class)->cancel($approval, $user, 'withdrawn');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $user)->ok())->toBeFalse()
        ->and(demoExecutions())->toBe(0);
});

it('expires an ignored approval rather than approving it', function () {
    $user = approver();
    $approval = park($user);
    $approval->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(app(ApprovalService::class)->expireLapsed())->toBe(1);

    $fresh = $approval->fresh();
    expect($fresh->status)->toBe(ApprovalStatus::Expired)
        // The sentence that matters: an ignored approval is a refusal.
        ->and($fresh->status)->not->toBe(ApprovalStatus::Approved)
        ->and(app(ApprovalExecutor::class)->approveAndExecute($fresh, $user)->ok())->toBeFalse()
        ->and(demoExecutions())->toBe(0);
});

it('escalates an overdue approval without changing its state', function () {
    $user = approver();
    $approval = park($user);
    $approval->forceFill(['sla_due_at' => now()->subHour()])->save();

    expect(app(ApprovalService::class)->escalateOverdue())->toBe(1);

    $fresh = $approval->fresh();
    expect($fresh->escalated_at)->not->toBeNull()
        ->and($fresh->status)->toBe(ApprovalStatus::Pending)
        ->and(AuditLog::where('action', 'approval.escalated')->count())->toBe(1);
});

// ---------------------------------------------------------------- approve with edit

it('creates a successor on approve-with-edit and never mutates the original', function () {
    $user = approver();
    $original = park($user, ['note' => 'original note', 'amount' => 100]);
    $originalHash = $original->payload_hash;

    $successor = app(ApprovalService::class)->approveWithEdit($original, ['note' => 'edited note', 'amount' => 50], $user, 'reduced it');

    $originalFresh = $original->fresh();
    expect($successor->id)->not->toBe($original->id)
        ->and($successor->supersedes_id)->toBe($original->id)
        // A new proposal, which the same human then claims through the ordinary path: an edit must
        // not create a second route to execution that skips the atomic claim.
        ->and($successor->status)->toBe(ApprovalStatus::Pending)
        ->and($successor->payload)->toEqual(['note' => 'edited note', 'amount' => 50])
        ->and($successor->payload_hash)->not->toBe($originalHash)
        ->and($successor->payloadIntact())->toBeTrue()
        // The original is untouched in the way that matters: its payload and hash still say what
        // the person first read.
        ->and($originalFresh->payload)->toEqual(['note' => 'original note', 'amount' => 100.0])
        ->and($originalFresh->payload_hash)->toBe($originalHash)
        ->and($originalFresh->status)->toBe(ApprovalStatus::Cancelled);

    $record = DecisionRecord::where('decision', 'edited')->sole();
    expect($record->delta)->toHaveKeys(['note', 'amount'])
        ->and($record->delta['amount']['to'])->toBe(50);

    // Only the edited payload executes.
    app(ApprovalExecutor::class)->approveAndExecute($successor, $user);
    expect(AuditLog::where('action', 'demo.approved_action_executed')->sole()->after['note'])->toBe('edited note');
});

// ---------------------------------------------------------------- lifecycle rules

it('forbids illegal lifecycle transitions', function () {
    expect(ApprovalStatus::Consumed->canTransitionTo(ApprovalStatus::Approved))->toBeFalse()
        ->and(ApprovalStatus::Rejected->canTransitionTo(ApprovalStatus::Approved))->toBeFalse()
        ->and(ApprovalStatus::Expired->canTransitionTo(ApprovalStatus::Consumed))->toBeFalse()
        ->and(ApprovalStatus::Cancelled->canTransitionTo(ApprovalStatus::Approved))->toBeFalse()
        ->and(ApprovalStatus::Pending->canTransitionTo(ApprovalStatus::Approved))->toBeTrue()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Consumed))->toBeTrue();
});

// ---------------------------------------------------------------- delegation

it('delegates only to somebody who can already decide, and narrows who may', function () {
    $owner = approver();
    (new RolesAndPermissionsSeeder)->run();
    $other = User::factory()->create();
    $other->givePermissionTo('hq.approvals.decide');
    $stranger = User::factory()->create();

    $approval = park($owner);

    expect(fn () => app(ApprovalService::class)->delegate($approval, $stranger->fresh(), $owner))
        ->toThrow(InvalidArgumentException::class, 'cannot grant authority');
    expect(fn () => app(ApprovalService::class)->delegate($approval, $owner, $owner))
        ->toThrow(InvalidArgumentException::class, 'yourself');

    app(ApprovalService::class)->delegate($approval, $other->fresh(), $owner);
    $fresh = $approval->fresh();

    // Delegation narrows: the delegate may decide, and the original approver no longer may.
    expect($fresh->isDecidableBy($other->fresh()))->toBeTrue()
        ->and($fresh->isDecidableBy($owner))->toBeFalse();
});

// ---------------------------------------------------------------- audit

it('audits every step of the lifecycle', function () {
    $user = approver();
    $approval = park($user);
    app(ApprovalExecutor::class)->approveAndExecute($approval, $user, 'ok');

    foreach (['approval.requested', 'approval.approved', 'approval.consumed'] as $action) {
        expect(AuditLog::where('action', $action)->count())->toBeGreaterThan(0, "missing audit for {$action}");
    }
});

// ---------------------------------------------------------------- the standing commitments

it('still enables no R4 or R5 tool for an agent, and keeps the demo tool out of agent hands', function () {
    // ⚠ Narrowed in M5. It used to assert that NO tool was agent-executable, which stopped being a
    // statement about approvals the moment M5 gave two agents a read set. What it was really
    // guarding is that nothing needing an approval is ever handed to an agent, and that is what it
    // now says: an agent may propose an R4 action and can never be the thing that performs it.
    foreach (app(ToolGateway::class)->descriptors() as $key => $descriptor) {
        if ($descriptor->riskTier->writesOutsideHq()) {
            expect($descriptor->agentExecutable)->toBeFalse("[{$key}] is R4 or above and must not be agent-executable");
        }
    }

    expect(app(ToolGateway::class)->descriptors()[DEMO]->agentExecutable)->toBeFalse();
});

it('keeps every real Phase 1 tool below the approval tier', function () {
    // The demonstration tool is the ONLY R4 registration, and it touches nothing outside HQ.
    $r4OrAbove = [];
    foreach (app(ToolGateway::class)->descriptors() as $key => $descriptor) {
        if ($descriptor->riskTier->writesOutsideHq()) {
            $r4OrAbove[] = $key;
        }
    }

    // docs/83 C.3: the second is `commerce.take_order`, which writes a draft and then an order waiting
    // for approval in the ERP, and is inert by construction: no store is admitted, so the gateway
    // refuses every call. docs/88 A point 3: the third is its compensating tool, under the same list.
    expect($r4OrAbove)->toBe([DEMO, 'commerce.take_order', 'commerce.cancel_sales_order'])
        ->and(app(ToolGateway::class)->descriptors()['commerce.take_order']->supportedStores)->toBe([])
        ->and(app(ToolGateway::class)->descriptors()['commerce.cancel_sales_order']->supportedStores)->toBe([]);
});
