<?php

use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\DoPlan;
use App\Models\FeatureFlag;
use App\Models\UserBusinessScope;
use App\Models\WorkflowInstance;
use App\OwnerConsole\Do\DoPlanService;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use Database\Seeders\DomainSeeder;

/*
 | docs/143 A: Do mode. A plan is walked one step at a time through the gateway; nothing effectful runs until a person
 | approves THAT step; a refusal halts the rest. Flag OFF in production.
 */

function ocDemoRuns(): int
{
    return AuditLog::query()->where('action', 'demo.approved_action_executed')->count();
}

function ocStep(string $note = 'one'): array
{
    return ['tool_key' => 'demo.request_approval', 'args' => ['note' => $note], 'summary' => "Demonstrate {$note}"];
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    FeatureFlag::query()->where('key', Flag::DO_MODE)->update(['enabled' => true]);
    $this->owner = $this->hqUser('owner');
    $this->approver = $this->hqUser('owner');
    $this->lld = Brand::where('code', 'LLD')->sole()->business_id;
    $this->bnc = Brand::where('code', 'BNC')->sole()->business_id;
    $this->plans = app(DoPlanService::class);
});

it('proposes a plan and runs nothing: a plan is rows, a step is not yet a call', function () {
    $plan = $this->plans->propose($this->lld, 'Demo plan', [ocStep('a'), ocStep('b')], $this->owner);

    expect($plan->state)->toBe('proposed')->and($plan->steps)->toHaveCount(2)->and($plan->steps->first()->effectful)->toBeTrue()
        ->and(Approval::count())->toBe(0)->and(ocDemoRuns())->toBe(0)
        ->and(AuditLog::where('action', 'do_plan.proposed')->exists())->toBeTrue();
});

it('refuses a plan that names an unknown tool, an R3 write policy would run unasked, a bad argument or too many steps', function () {
    expect(fn () => $this->plans->propose($this->lld, 't', [['tool_key' => 'no.such.tool', 'summary' => 's']], $this->owner))->toThrow(Refused::class, 'no tool');
    expect(fn () => $this->plans->propose($this->lld, 't', [['tool_key' => 'briefing.compose', 'args' => [], 'summary' => 's']], $this->owner))->toThrow(Refused::class, 'without asking a person');
    expect(fn () => $this->plans->propose($this->lld, 't', [['tool_key' => 'demo.request_approval', 'args' => ['bogus' => 1], 'summary' => 's']], $this->owner))->toThrow(Refused::class);
    expect(fn () => $this->plans->propose($this->lld, 't', array_fill(0, DoPlanService::MAX_STEPS + 1, ocStep()), $this->owner))->toThrow(Refused::class, 'steps');
    expect(DoPlan::count())->toBe(0);
});

it('parks step 1 on an approval when started, runs nothing, and does not submit step 2 yet', function () {
    $plan = $this->plans->propose($this->lld, 'Two steps', [ocStep('a'), ocStep('b')], $this->owner);
    $plan = $this->plans->start($plan, $this->owner);

    $steps = $plan->steps;
    expect($plan->state)->toBe('running')->and($steps[0]->state)->toBe('awaiting_approval')->and($steps[0]->approval_id)->not->toBeNull()
        ->and($steps[1]->state)->toBe('pending')->and(Approval::count())->toBe(1)->and(ocDemoRuns())->toBe(0)
        ->and(WorkflowInstance::where('instance_key', 'do-plan:'.$plan->uuid)->where('type', 'do.plan')->exists())->toBeTrue();
});

it('walks the plan only as people approve each step, then completes', function () {
    $plan = $this->plans->start($this->plans->propose($this->lld, 'Two steps', [ocStep('a'), ocStep('b')], $this->owner), $this->owner);

    app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($plan->steps[0]->approval_id), $this->approver);
    $plan = $this->plans->advance($plan);
    expect($plan->steps[0]->state)->toBe('succeeded')->and($plan->steps[1]->state)->toBe('awaiting_approval')->and($plan->state)->toBe('running')->and(ocDemoRuns())->toBe(1);

    app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($plan->steps[1]->approval_id), $this->approver);
    $plan = $this->plans->advance($plan);
    expect($plan->state)->toBe('completed')->and(ocDemoRuns())->toBe(2)
        ->and(AuditLog::where('action', 'do_plan.completed')->exists())->toBeTrue();
});

it('halts on a rejection: the remaining steps are skipped and never run', function () {
    $plan = $this->plans->start($this->plans->propose($this->lld, 'Three', [ocStep('a'), ocStep('b'), ocStep('c')], $this->owner), $this->owner);
    app(ApprovalService::class)->reject(Approval::findOrFail($plan->steps[0]->approval_id), $this->approver, 'no');

    $plan = $this->plans->advance($plan);
    expect($plan->state)->toBe('halted')->and($plan->steps->pluck('state')->all())->toBe(['rejected', 'skipped', 'skipped'])
        ->and(Approval::count())->toBe(1)->and(ocDemoRuns())->toBe(0);
});

it('cancelling withdraws the pending approval and skips the rest', function () {
    $plan = $this->plans->start($this->plans->propose($this->lld, 'Cancel me', [ocStep('a'), ocStep('b')], $this->owner), $this->owner);
    $plan = $this->plans->cancel($plan, $this->owner, 'changed my mind');

    expect($plan->state)->toBe('cancelled')->and(Approval::first()->status->value)->toBe('cancelled')->and($plan->steps->pluck('state')->unique()->all())->toBe(['skipped']);
});

it('starts a plan once: a second start is refused and approves nothing', function () {
    $plan = $this->plans->start($this->plans->propose($this->lld, 'Once', [ocStep()], $this->owner), $this->owner);

    expect(fn () => $this->plans->start($plan, $this->owner))->toThrow(Refused::class, 'not waiting');
    expect(Approval::count())->toBe(1);
});

it('is scoped: a person limited to business A cannot plan for B, and another business plan is a 404', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->lld]);

    expect(fn () => $this->plans->propose($this->bnc, 'x', [ocStep()], $restricted))->toThrow(Refused::class, 'may not plan');
    $other = $this->plans->propose($this->bnc, 'B plan', [ocStep()], $this->owner);
    $mine = $this->plans->propose($this->lld, 'A plan', [ocStep()], $this->owner);

    $this->actingAsHq($restricted)->get("/do-plans/{$other->uuid}")->assertNotFound();
    $this->actingAsHq($restricted)->post("/do-plans/{$other->uuid}/start")->assertNotFound();
    $this->actingAsHq($restricted)->get("/do-plans/{$mine->uuid}")->assertOk();
    $this->actingAsHq($restricted)->get('/do-plans')->assertOk()->assertInertia(fn ($page) => $page->has('plans', 1));
    expect(fn () => $this->plans->start($other, $restricted))->toThrow(Refused::class, 'may not act');
});

it('is reachable only with the do permissions and through the routes (store, start)', function () {
    $viewer = $this->hqUser('finance_viewer');
    $this->actingAsHq($viewer)->get('/do-plans')->assertForbidden();

    $this->actingAsHq($this->owner)->post('/do-plans', ['business_id' => $this->lld, 'title' => 'Via route', 'steps' => [['tool_key' => 'demo.request_approval', 'args' => ['note' => 'r'], 'summary' => 'r']]])->assertRedirect();
    $plan = DoPlan::firstOrFail();
    $this->actingAsHq($this->owner)->post("/do-plans/{$plan->uuid}/start")->assertRedirect();
    expect($plan->fresh()->state)->toBe('running')->and(ocDemoRuns())->toBe(0);
});

it('stands still when the flag is turned off mid-plan: nothing more is put to the gateway', function () {
    $plan = $this->plans->start($this->plans->propose($this->lld, 'Pause', [ocStep('a'), ocStep('b')], $this->owner), $this->owner);
    app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($plan->steps[0]->approval_id), $this->approver);
    FeatureFlag::query()->where('key', Flag::DO_MODE)->update(['enabled' => false]);

    $plan = $this->plans->advance($plan);
    expect($plan->steps[1]->state)->toBe('pending')->and(Approval::count())->toBe(1);
});
