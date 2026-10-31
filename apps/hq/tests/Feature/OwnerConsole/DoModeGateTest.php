<?php

use App\Gateway\Approval\ApprovalExecutor;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\DoPlan;
use App\Models\FeatureFlag;
use App\Models\UserBusinessScope;
use App\OwnerConsole\Do\DoModeActivation;
use App\OwnerConsole\Do\DoPlanService;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerConsole\TestDoModeActivation;

/*
 | ChatGPT ruling 31 Oct 2026, item 1: Do mode stays in code, DARK. Unavailable in the production UI and refused
 | server-side by an activation gate beyond the flag. When exercised (a test stands the activation in), every effectful
 | step has its own approval bound to its exact payload, target and scope; no approval carries over; standing, scope and
 | the gateway's checks are re-read before each step; effects go through the Tool Gateway only; nothing continues into
 | another effectful step by itself; and nobody approves a step of a plan they started (or that an agent proposed under them).
 */

function dgStep(string $note): array
{
    return ['tool_key' => 'demo.request_approval', 'args' => ['note' => $note], 'summary' => "Do {$note}"];
}

function dgRuns(): int
{
    return AuditLog::query()->where('action', 'demo.approved_action_executed')->count();
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->approver = $this->hqUser('owner');
    $this->lld = Brand::where('code', 'LLD')->sole()->business_id;
});

it('ships with no activation record, pinned in code', function () {
    expect(DoModeActivation::ACTIVATION_RULING)->toBeNull()
        ->and(app(DoModeActivation::class))->toBeInstanceOf(DoModeActivation::class)
        ->and(app(DoModeActivation::class))->not->toBeInstanceOf(TestDoModeActivation::class)
        ->and(app(DoModeActivation::class)->active())->toBeFalse();

    // No production code binds a substitute activation.
    foreach (['app', 'bootstrap', 'config', 'routes'] as $dir) {
        $hits = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'activationRuling')
                && ! str_ends_with($file->getPathname(), 'DoModeActivation.php')) {
                $hits[] = $file->getPathname();
            }
        }
        expect($hits)->toBe([]);
    }
});

it('stays unavailable with the flag row switched ON: service, routes, sidebar and scheduler all refuse', function () {
    FeatureFlag::query()->where('key', Flag::DO_MODE)->update(['enabled' => true]);
    expect(Flag::on(Flag::DO_MODE))->toBeTrue()->and(app(DoModeActivation::class)->active())->toBeFalse();

    expect(fn () => app(DoPlanService::class)->propose($this->lld, 'x', [dgStep('a')], $this->owner))->toThrow(Refused::class, 'not activated');

    $this->actingAsHq($this->owner)->get('/do-plans')->assertNotFound();
    $this->actingAsHq($this->owner)->post('/do-plans', ['business_id' => $this->lld, 'title' => 't', 'steps' => [dgStep('a')]])->assertNotFound();
    $this->actingAsHq($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('nav.features.console_do_mode', false));
    expect(DoPlan::count())->toBe(0);
});

it('lets a plan made while activated stand still once the activation is gone, even with the flag on', function () {
    FeatureFlag::query()->where('key', Flag::DO_MODE)->update(['enabled' => true]);
    app()->instance(DoModeActivation::class, new TestDoModeActivation);
    $plans = app(DoPlanService::class);
    $plan = $plans->start($plans->propose($this->lld, 'Two', [dgStep('a'), dgStep('b')], $this->owner), $this->owner);
    app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($plan->steps[0]->approval_id), $this->approver);

    app()->instance(DoModeActivation::class, new DoModeActivation);
    app()->forgetInstance(DoPlanService::class);
    $this->artisan('hq:do-plans:advance')->assertSuccessful();
    $this->actingAsHq($this->owner)->post("/do-plans/{$plan->uuid}/advance")->assertNotFound();

    $plan->refresh();
    expect($plan->steps[0]->state)->toBe('awaiting_approval')->and($plan->steps[1]->state)->toBe('pending')->and(Approval::count())->toBe(1);
});

describe('when exercised under a stand-in activation', function () {
    beforeEach(function () {
        FeatureFlag::query()->where('key', Flag::DO_MODE)->update(['enabled' => true]);
        app()->instance(DoModeActivation::class, new TestDoModeActivation);
        $this->plans = app(DoPlanService::class);
    });

    it('binds each step approval to that step exact payload, tool and business, and gives every step its own', function () {
        $plan = $this->plans->start($this->plans->propose($this->lld, 'Bound', [dgStep('first'), dgStep('second')], $this->owner), $this->owner);
        $one = Approval::findOrFail($plan->steps[0]->approval_id);
        expect($one->payload)->toBe(['note' => 'first'])->and($one->tool_key)->toBe('demo.request_approval')
            ->and((int) $one->business_id)->toBe($this->lld)->and($one->payloadIntact())->toBeTrue();

        app(ApprovalExecutor::class)->approveAndExecute($one, $this->approver);
        $plan = $this->plans->advance($plan, $this->owner);
        $two = Approval::findOrFail($plan->steps[1]->approval_id);

        // Step 1's approval authorized step 1 only: step 2 waits on a new approval of its own, and nothing of step 2 ran.
        expect($two->id)->not->toBe($one->id)->and($two->payload)->toBe(['note' => 'second'])->and($two->status->value)->toBe('pending')
            ->and(dgRuns())->toBe(1);
    });

    it('never continues into another effectful step by itself, and a person continues one effectful step per act', function () {
        $plan = $this->plans->start($this->plans->propose($this->lld, 'Three', [dgStep('a'), dgStep('b'), dgStep('c')], $this->owner), $this->owner);
        app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($plan->steps[0]->approval_id), $this->approver);

        // The scheduler: reads the decision back, submits nothing.
        $this->artisan('hq:do-plans:advance')->assertSuccessful();
        $this->artisan('hq:do-plans:advance')->assertSuccessful();
        $plan->refresh();
        expect($plan->steps->pluck('state')->all())->toBe(['succeeded', 'pending', 'pending'])->and(Approval::count())->toBe(1);

        // A person continues: exactly the next step is put up for approval, not the one after.
        $this->actingAsHq($this->owner)->post("/do-plans/{$plan->uuid}/advance")->assertRedirect();
        $plan->refresh();
        expect($plan->steps->pluck('state')->all())->toBe(['succeeded', 'awaiting_approval', 'pending'])->and(Approval::count())->toBe(2);

        // Continuing again while step 2 waits submits nothing more.
        $this->plans->advance($plan, $this->owner);
        expect(Approval::count())->toBe(2)->and(dgRuns())->toBe(1);
    });

    it('lets nobody approve a step of a plan they started, nor the person an agent proposed it under', function () {
        $plan = $this->plans->start($this->plans->propose($this->lld, 'Mine', [dgStep('a')], $this->owner), $this->owner);
        $approval = Approval::findOrFail($plan->steps[0]->approval_id);

        expect($approval->decisionRefusalReason($this->owner))->toContain('Do plan');
        $this->actingAsHq($this->owner)->post("/approvals/{$approval->id}/approve")->assertForbidden();
        expect(app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $this->owner)->ok())->toBeFalse();
        expect(dgRuns())->toBe(0)->and($approval->fresh()->status->value)->toBe('pending');

        // An agent's plan, proposed under person A and started by person B: neither A nor B decides its steps.
        $authority = $this->hqUser('owner');
        $starter = $this->hqUser('owner');
        $agentPlan = $this->plans->start($this->plans->propose($this->lld, 'Agent plan', [dgStep('b')], $authority, 'executive'), $starter);
        $agentApproval = Approval::findOrFail($agentPlan->steps[0]->approval_id);
        expect($agentApproval->isDecidableBy($authority))->toBeFalse()->and($agentApproval->isDecidableBy($starter))->toBeFalse()
            ->and($agentApproval->isDecidableBy($this->approver))->toBeTrue();

        // A model holds no approval path at all: the plan service never decides or executes an approval itself.
        foreach (['OwnerConsole/Do/DoPlanService.php', 'OwnerConsole/Do/DoPlanWorkflow.php', 'Console/Commands/DoPlansAdvance.php'] as $file) {
            $source = implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents(app_path($file)))));
            expect($source)->not->toContain('ApprovalExecutor')->and($source)->not->toContain('approveAndExecute');
        }
    });

    it('re-checks the starter and the continuing person before the next step, and halts instead of running', function () {
        $plan = $this->plans->start($this->plans->propose($this->lld, 'Recheck', [dgStep('a'), dgStep('b')], $this->owner), $this->owner);
        app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($plan->steps[0]->approval_id), $this->approver);
        $this->plans->advance($plan);

        // A continuing person who may not act for the business is refused outright.
        $outsider = $this->hqUser('operator');
        UserBusinessScope::create(['user_id' => $outsider->id, 'business_id' => Brand::where('code', 'BNC')->sole()->business_id]);
        expect(fn () => $this->plans->advance($plan, $outsider))->toThrow(Refused::class);

        // The starter loses the business: the next step is not submitted, the plan halts.
        UserBusinessScope::create(['user_id' => $this->owner->id, 'business_id' => Brand::where('code', 'BNC')->sole()->business_id]);
        $plan = $this->plans->advance($plan, $this->approver);
        expect($plan->state)->toBe('halted')->and($plan->steps[1]->state)->toBe('failed')->and(Approval::count())->toBe(1)->and(dgRuns())->toBe(1);
    });

    it('halts when the approval read back is not the one the step asked for', function () {
        $plan = $this->plans->start($this->plans->propose($this->lld, 'Tamper', [dgStep('a')], $this->owner), $this->owner);
        DB::table('approvals')->where('id', $plan->steps[0]->approval_id)->update(['payload' => json_encode(['note' => 'changed underneath'])]);

        $plan = $this->plans->advance($plan);
        expect($plan->state)->toBe('halted')->and(dgRuns())->toBe(0);
    });

    it('reaches every effect through the Tool Gateway only', function () {
        $source = file_get_contents(app_path('OwnerConsole/Do/DoPlanService.php'));
        preg_match_all('/->execute\(/', $source, $calls);
        expect(count($calls[0]))->toBe(substr_count($source, '$this->gateway->execute('))
            ->and(substr_count($source, '$this->gateway->execute('))->toBe(1)
            ->and($source)->not->toMatch('/->get\([^)]*\)->execute\(/');
    });
});
