<?php

use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\Approval\CanonicalPayload;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\User;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Execution\Contexts;
use Tests\Support\Tools\AgentGatedTool;

/*
 | docs/81 P0-4 (C.2): an approval keeps four people and things apart.
 |
 |   requester        the accountable person who asked, from the run's resolved context (ADR-047)
 |   enabler          the person whose authority the agent borrows
 |   acting identity  the agent
 |   approver         whoever decided it
 |
 | Before this, an approval an agent parked named the ENABLING user as its requester, whoever asked.
 |
 | ⚠ No registered tool is both agent-executable and approval-gated, so the tests register their own.
 */

const GATED = 'test.agent_gated';
const REQUESTER_MIGRATION = 'database/migrations/2026_09_21_160000_add_requester_identity_to_approvals.php';

function identityUser(array $permissions = ['*']): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $permissions === ['*'] ? $user->assignRole('owner') : $user->givePermissionTo($permissions);
    // Enrolled, so the screens answer the person rather than sending them to set up 2FA.
    $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

function gatedAgent(User $enabler): Agent
{
    return Agent::create([
        'key' => 'gated-'.Str::lower(Str::random(6)), 'name' => 'Gated', 'status' => 'active',
        'enabling_user_id' => $enabler->id, 'allowed_tools' => [GATED],
    ]);
}

/** Parks a real R4 call AS AN AGENT, under a context the real resolver produced. */
function parkAsAgent(Agent $agent, ?User $asker = null, string $note = 'proposed by an agent'): Approval
{
    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: GATED, args: ['note' => $note], caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($agent, requester: $asker)),
        correlationId: (string) Str::uuid(),
    ));

    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval);

    return Approval::findOrFail($outcome->approvalId);
}

beforeEach(function () {
    Cache::flush();
    AgentGatedTool::$executions = 0;
    app(ToolRegistry::class)->register(AgentGatedTool::class);
    app(ToolAvailability::class)->forget();

    $this->owner = identityUser();
    $this->asker = identityUser(['hq.tools.diagnostics', 'hq.approvals.view', 'hq.approvals.decide', 'hq.approvals.delegate']);
    $this->agent = gatedAgent($this->owner);
});

// ---------------------------------------------------------------- the record

it('records the person who asked as the requester, and the enabling user, the agent and the context apart from them', function () {
    $context = Contexts::for($this->agent, requester: $this->asker);
    $approval = parkAsAgent($this->agent, $this->asker);

    expect($approval->requested_by_user_id)->toBe($this->asker->id)
        ->and($approval->enabled_by_user_id)->toBe($this->owner->id)
        ->and($approval->agent_key)->toBe($this->agent->key)
        ->and($approval->requester_kind)->toBe('human')
        ->and($approval->execution_context_digest)->toBe($context->digest())
        ->and($approval->decided_by_user_id)->toBeNull();
});

it('records one person on both sides when nobody else asked, which is the single-owner setup', function () {
    $approval = parkAsAgent($this->agent);

    expect($approval->requested_by_user_id)->toBe($this->owner->id)
        ->and($approval->enabled_by_user_id)->toBe($this->owner->id)
        ->and($approval->requester_kind)->toBe('console');
});

it('leaves a call a person parks on a screen exactly as it was: that person, and no enabler, kind or digest', function () {
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, GATED, ['note' => 'by hand']));
    $approval = Approval::findOrFail($outcome->approvalId);

    expect($approval->requested_by_user_id)->toBe($this->owner->id)
        ->and($approval->agent_key)->toBeNull()
        ->and($approval->enabled_by_user_id)->toBeNull()
        ->and($approval->requester_kind)->toBeNull()
        ->and($approval->execution_context_digest)->toBeNull();
});

it('leaves a scheduled job with an owner exactly as it was: the owner asked', function () {
    $job = ScheduledJob::create([
        'name' => 'gated nightly', 'kind' => 'cron', 'cron_expression' => '0 4 * * *',
        'tool_key' => GATED, 'payload' => ['note' => 'scheduled'], 'owner_user_id' => $this->owner->id,
    ]);
    $run = JobRun::create([
        'scheduled_job_id' => $job->id, 'scheduled_for' => CarbonImmutable::now(), 'trigger' => 'tick',
        'attempt' => 1, 'correlation_id' => (string) Str::uuid(),
    ]);

    $outcome = app(ToolGateway::class)->execute(ToolRequest::forScheduledJob($job->fresh(), $run));
    $approval = Approval::findOrFail($outcome->approvalId);

    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval)
        ->and($approval->requested_by_user_id)->toBe($this->owner->id)
        ->and($approval->agent_key)->toBeNull()
        ->and($approval->enabled_by_user_id)->toBeNull()
        ->and($approval->requester_kind)->toBeNull()
        ->and($approval->execution_context_digest)->toBeNull();
});

it('does not change what the payload hash covers: the same arguments hash the same whoever asked', function () {
    $byAgent = parkAsAgent($this->agent, $this->asker, 'same words');
    $byHand = Approval::findOrFail(app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, GATED, ['note' => 'same words']))->approvalId);

    expect($byAgent->payload_hash)->toBe(CanonicalPayload::hash(['note' => 'same words']))
        ->and($byAgent->payload_hash)->toBe($byHand->payload_hash)
        ->and($byAgent->payloadIntact())->toBeTrue();
});

it('carries the requester, the enabler, the kind and the digest onto the successor of an approve-with-edit', function () {
    $original = parkAsAgent($this->agent, $this->asker);
    $successor = app(ApprovalService::class)->approveWithEdit($original, ['note' => 'edited'], $this->owner, 'closer to this');

    expect($successor->requested_by_user_id)->toBe($this->asker->id)
        ->and($successor->enabled_by_user_id)->toBe($this->owner->id)
        ->and($successor->requester_kind)->toBe('human')
        ->and($successor->execution_context_digest)->toBe($original->execution_context_digest)
        ->and($successor->agent_key)->toBe($this->agent->key);
});

// ---------------------------------------------------------------- who may decide

it('does not let the person who asked through somebody else\'s agent decide their own request, on the screen or past it', function () {
    $approval = parkAsAgent($this->agent, $this->asker);

    expect($approval->isDecidableBy($this->asker))->toBeFalse();

    $this->actingAsHq($this->asker)->post("/approvals/{$approval->id}/approve")->assertForbidden();
    $this->actingAsHq($this->asker)->post("/approvals/{$approval->id}/reject", ['reason' => 'no'])->assertForbidden();

    // The executor is the chokepoint: a caller that skipped the controller is refused too.
    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $this->asker);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('asked for this')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->fresh()->decided_by_user_id)->toBeNull()
        ->and(AgentGatedTool::$executions)->toBe(0);
});

it('lets the enabling user decide what somebody else asked their agent for, so the single approver is never locked out', function () {
    $approval = parkAsAgent($this->agent, $this->asker);

    expect($approval->isDecidableBy($this->owner))->toBeTrue();

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->owner);

    expect($outcome->ok())->toBeTrue()
        ->and(AgentGatedTool::$executions)->toBe(1)
        ->and($approval->fresh()->decided_by_user_id)->toBe($this->owner->id)
        ->and($approval->fresh()->requested_by_user_id)->toBe($this->asker->id);
});

it('lets a third person who holds the decide permission decide it', function () {
    $third = identityUser(['hq.tools.diagnostics', 'hq.approvals.decide']);
    $approval = parkAsAgent($this->agent, $this->asker);

    expect($approval->isDecidableBy($third))->toBeTrue()
        ->and(app(ApprovalExecutor::class)->approveAndExecute($approval, $third)->ok())->toBeTrue();
});

it('keeps the single owner able to decide what his own agent parked when he asked, exactly as before', function () {
    $approval = parkAsAgent($this->agent, $this->owner);

    expect($approval->isDecidableBy($this->owner))->toBeTrue();

    $this->actingAsHq($this->owner)->post("/approvals/{$approval->id}/approve")->assertRedirect();

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Consumed)
        ->and(AgentGatedTool::$executions)->toBe(1);
});

it('keeps a person able to decide the call they parked on a screen themselves, exactly as before', function () {
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, GATED, ['note' => 'by hand']));
    $approval = Approval::findOrFail($outcome->approvalId);

    expect($approval->isDecidableBy($this->owner))->toBeTrue()
        ->and(app(ApprovalExecutor::class)->approveAndExecute($approval, $this->owner)->ok())->toBeTrue();
});

it('refuses to delegate a decision to the person who asked for it', function () {
    $approval = parkAsAgent($this->agent, $this->asker);

    expect(fn () => app(ApprovalService::class)->delegate($approval, $this->asker, $this->owner))
        ->toThrow(InvalidArgumentException::class, 'asked for this');

    expect($approval->fresh()->delegated_to_user_id)->toBeNull();
});

it('lets the person who asked withdraw their own request, and the enabling user withdraw what their agent parked', function () {
    $enablerWithoutDecide = identityUser(['hq.tools.diagnostics', 'hq.approvals.view']);
    $agent = gatedAgent($enablerWithoutDecide);
    $stranger = identityUser(['hq.approvals.view']);

    $first = parkAsAgent($agent, $this->asker, 'one');
    $this->actingAsHq($stranger)->post("/approvals/{$first->id}/cancel")->assertForbidden();
    $this->actingAsHq($this->asker)->post("/approvals/{$first->id}/cancel")->assertRedirect();
    expect($first->fresh()->status)->toBe(ApprovalStatus::Cancelled);

    $second = parkAsAgent($agent, $this->asker, 'two');
    $this->actingAsHq($enablerWithoutDecide)->post("/approvals/{$second->id}/cancel")->assertRedirect();
    expect($second->fresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(AgentGatedTool::$executions)->toBe(0);
});

it('shows the person who asked and the enabling user on the approval screen, and says when a row predates the split', function () {
    $approval = parkAsAgent($this->agent, $this->asker);

    $this->actingAsHq($this->owner)->get("/approvals/{$approval->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('approval.requested_by', $this->asker->email)
            ->where('approval.enabled_by', $this->owner->email)
            ->where('approval.requester_kind', 'human')
            ->where('approval.requester_recorded', true)
            ->where('approval.decidable', true));

    $this->actingAsHq($this->asker)->get("/approvals/{$approval->id}")
        ->assertInertia(fn ($page) => $page->where('approval.decidable', false));

    // A row from before the split: an agent key, and no enabler recorded.
    DB::table('approvals')->where('id', $approval->id)->update(['enabled_by_user_id' => null, 'requester_kind' => null, 'execution_context_digest' => null]);

    $this->actingAsHq($this->owner)->get("/approvals/{$approval->id}")
        ->assertInertia(fn ($page) => $page->where('approval.requester_recorded', false));
});

// ---------------------------------------------------------------- history

it('leaves a row that existed before the migration untouched, and readable', function () {
    $migration = require base_path(REQUESTER_MIGRATION);
    $migration->down();

    expect(Schema::hasColumn('approvals', 'enabled_by_user_id'))->toBeFalse();

    // A historical agent row, written the old way: the enabling user in the requester column.
    $id = DB::table('approvals')->insertGetId([
        'uuid' => (string) Str::uuid(), 'tool_key' => GATED, 'tool_version' => '1.0.0',
        'payload' => json_encode(['note' => 'old']), 'payload_hash' => CanonicalPayload::hash(['note' => 'old']),
        'risk_tier' => 'R4', 'action_class' => 'approval_required', 'requested_by_user_id' => $this->owner->id,
        'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(), 'status' => 'pending',
        'approver_role' => 'operator', 'escalate_to_role' => 'owner',
        'created_at' => '2026-09-10 10:00:00', 'updated_at' => '2026-09-10 10:00:00',
    ]);
    $before = (array) DB::table('approvals')->find($id);

    $migration->up();

    $after = (array) DB::table('approvals')->find($id);

    expect(array_intersect_key($after, $before))->toBe($before)
        ->and($after['enabled_by_user_id'])->toBeNull()
        ->and($after['requester_kind'])->toBeNull()
        ->and($after['execution_context_digest'])->toBeNull();

    // And it decides as it always did: no enabler was recorded, so the new rule has nothing to say.
    $approval = Approval::findOrFail($id);
    expect($approval->payloadIntact())->toBeTrue()
        ->and($approval->isDecidableBy($this->owner))->toBeTrue();
});
