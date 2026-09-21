<?php

use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\Approval\CanonicalPayload;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\User;
use App\Tools\ToolRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tools\RedactingGatedTool;

/*
 | docs/81 C.6 items 3 and 4: one rule for who may decide, at every door, and an edit redacted
 | exactly as the proposal was.
 |
 | The executor enforced the separation rule and nothing else of "who may decide"; an edit and a
 | rejection checked nothing about the person; the successor of an edit dropped the delegation; and
 | an edited payload was redacted without the tool's own declared paths. The controller covered the
 | first three, and it is the only caller today, so these tests call the service and the executor
 | directly, as the next caller would.
 */

const DECIDE_DEMO = 'demo.request_approval';

function decider(array $permissions = ['*']): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $permissions === ['*'] ? $user->assignRole('owner') : $user->givePermissionTo($permissions);

    return $user->fresh();
}

function parkFor(User $user, string $tool = DECIDE_DEMO, array $args = ['note' => 'demonstration']): Approval
{
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($user, $tool, $args));
    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval);

    return Approval::findOrFail($outcome->approvalId);
}

function demoRuns(): int
{
    return AuditLog::where('action', 'demo.approved_action_executed')->count();
}

beforeEach(function () {
    Cache::flush();
    RedactingGatedTool::$executions = 0;
    app(ToolRegistry::class)->register(RedactingGatedTool::class);

    $this->owner = decider();
    $this->delegate = decider(['hq.tools.diagnostics', 'hq.approvals.view', 'hq.approvals.decide']);
});

// ---------------------------------------------------------------- the executor

it('refuses, at the executor, the approver a delegation took the decision away from', function () {
    $approval = parkFor($this->owner);
    app(ApprovalService::class)->delegate($approval, $this->delegate, $this->owner);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $this->owner);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('delegated to somebody else')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->fresh()->decided_by_user_id)->toBeNull()
        ->and(demoRuns())->toBe(0);
});

it('refuses, at the executor, a person without the decide permission, however they reached it', function () {
    $approval = parkFor($this->owner);
    $noDecide = decider(['hq.tools.diagnostics', 'hq.approvals.view']);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $noDecide);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('permission to decide')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(demoRuns())->toBe(0);
});

it('lets the delegate decide it through the executor, exactly as before', function () {
    $approval = parkFor($this->owner);
    app(ApprovalService::class)->delegate($approval, $this->delegate, $this->owner);

    expect(app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $this->delegate)->ok())->toBeTrue()
        ->and(demoRuns())->toBe(1);
});

// ---------------------------------------------------------------- an edit and a rejection

it('refuses an edit from a person who may not decide it, and writes nothing', function () {
    $approval = parkFor($this->owner);
    app(ApprovalService::class)->delegate($approval, $this->delegate, $this->owner);

    expect(fn () => app(ApprovalService::class)->approveWithEdit($approval->fresh(), ['note' => 'mine instead'], $this->owner))
        ->toThrow(RuntimeException::class, 'delegated to somebody else');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(Approval::count())->toBe(1);
});

it('refuses a rejection from a person who may not decide it, and writes nothing', function () {
    $approval = parkFor($this->owner);
    $noDecide = decider(['hq.tools.diagnostics', 'hq.approvals.view']);

    expect(fn () => app(ApprovalService::class)->reject($approval, $noDecide, 'no'))
        ->toThrow(RuntimeException::class, 'permission to decide');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->fresh()->decided_by_user_id)->toBeNull();
});

it('carries the delegation to the successor of an edit, so the edit does not widen who may decide', function () {
    $approval = parkFor($this->owner);
    app(ApprovalService::class)->delegate($approval, $this->delegate, $this->owner);

    $successor = app(ApprovalService::class)->approveWithEdit($approval->fresh(), ['note' => 'the delegate edited it'], $this->delegate);

    expect($successor->delegated_to_user_id)->toBe($this->delegate->id)
        ->and($successor->delegated_by_user_id)->toBe($this->owner->id)
        ->and($successor->delegated_at)->not->toBeNull()
        ->and($successor->isDecidableBy($this->owner))->toBeFalse()
        ->and($successor->isDecidableBy($this->delegate))->toBeTrue();

    expect(app(ApprovalExecutor::class)->approveAndExecute($successor, $this->owner)->ok())->toBeFalse()
        ->and(app(ApprovalExecutor::class)->approveAndExecute($successor->fresh(), $this->delegate)->ok())->toBeTrue()
        ->and(demoRuns())->toBe(1);
});

it('keeps the owner able to edit and decide what nobody delegated, exactly as before', function () {
    $approval = parkFor($this->owner);

    $successor = app(ApprovalService::class)->approveWithEdit($approval, ['note' => 'closer'], $this->owner);

    expect($successor->delegated_to_user_id)->toBeNull()
        ->and(app(ApprovalExecutor::class)->approveAndExecute($successor, $this->owner)->ok())->toBeTrue();
});

// ---------------------------------------------------------------- the edit's redaction

it('redacts an edited payload with the tool\'s own declared paths, exactly as the proposal was', function () {
    $approval = parkFor($this->owner, 'test.redacting_gated', ['note' => 'call them', 'customer_phone' => '01700000001']);
    expect($approval->payload['customer_phone'])->toBe('[redacted]');

    $successor = app(ApprovalService::class)->approveWithEdit($approval, ['note' => 'call them later', 'customer_phone' => '01700000002'], $this->owner);

    expect($successor->payload['customer_phone'])->toBe('[redacted]')
        ->and($successor->payload['note'])->toBe('call them later')
        // The hash is still taken over what is stored, and nothing about the canonical form moved.
        ->and($successor->payload_hash)->toBe(CanonicalPayload::hash($successor->payload))
        ->and($successor->payloadIntact())->toBeTrue();

    // Nowhere in the approvals table does the edited number appear.
    expect(DB::table('approvals')->whereRaw('payload::text like ?', ['%01700000002%'])->count())->toBe(0);
});

it('refuses to edit an approval whose tool is no longer registered, because it cannot be redacted', function () {
    $approval = parkFor($this->owner);
    DB::table('approvals')->where('id', $approval->id)->update(['tool_key' => 'gone.tool']);

    expect(fn () => app(ApprovalService::class)->approveWithEdit($approval->fresh(), ['note' => 'x'], $this->owner))
        ->toThrow(RuntimeException::class, 'no longer registered');

    expect(Approval::count())->toBe(1);
});
