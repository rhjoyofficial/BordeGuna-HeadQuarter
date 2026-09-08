<?php

use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/*
 | M3: the Approval Center screen and its endpoints.
 |
 | ⚠ The security property being tested is negative: the browser can name an approval and a decision,
 | and it can do NOTHING ELSE. There is no endpoint that accepts a tool key and arguments, and an
 | edited payload is validated against the tool's own schema before it is stored.
 */

function screenApproval(User $user, array $args = ['note' => 'from the screen']): Approval
{
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($user, 'demo.request_approval', $args));

    return Approval::findOrFail($outcome->approvalId);
}

beforeEach(fn () => Cache::flush());

it('shows the Approval Center to a permitted operator and refuses everyone else', function () {
    $owner = $this->hqUser('owner');
    $this->actingAsHq($owner)->get('/approvals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('approvals/index', false)->has('approvals'));

    $this->actingAsHq($this->hqUser('support_agent'))->get('/approvals')->assertForbidden();
});

it('lets a viewer see approvals but not decide them', function () {
    $viewer = $this->hqUser('brand_manager');
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner);

    $this->actingAsHq($viewer)->get('/approvals')->assertOk();
    $this->actingAsHq($viewer)->post("/approvals/{$approval->id}/approve")->assertForbidden();
    $this->actingAsHq($viewer)->post("/approvals/{$approval->id}/reject", ['reason' => 'no'])->assertForbidden();

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(AuditLog::where('action', 'demo.approved_action_executed')->count())->toBe(0);
});

it('approves and executes from the screen using only the approval id', function () {
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner, ['note' => 'screen approved']);

    $this->actingAsHq($owner)->post("/approvals/{$approval->id}/approve", ['note' => 'fine'])->assertRedirect();

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Consumed)
        ->and(AuditLog::where('action', 'demo.approved_action_executed')->sole()->after['note'])->toBe('screen approved');
});

it('ignores any tool key or payload the browser tries to smuggle into an approve request', function () {
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner, ['note' => 'the real one']);

    // A browser posting extra fields cannot redirect the execution: the server loads the stored
    // approval and executes the payload a human read.
    $this->actingAsHq($owner)->post("/approvals/{$approval->id}/approve", [
        'tool_key' => 'commerce.reconcile_facts',
        'args' => ['store_slug' => 'laladia-com'],
        'payload' => ['note' => 'smuggled'],
    ])->assertRedirect();

    $executed = AuditLog::where('action', 'demo.approved_action_executed')->sole();
    expect($executed->after['note'])->toBe('the real one');
});

it('validates an edited payload against the tool schema before storing it', function () {
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner);

    $this->actingAsHq($owner)->post("/approvals/{$approval->id}/approve", [
        'edited_payload' => ['note' => 'ok', 'not_a_field' => 'x'],
    ])->assertSessionHasErrors('edited_payload');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(AuditLog::where('action', 'demo.approved_action_executed')->count())->toBe(0);
});

it('requires a reason to reject, because the reason is the feedback', function () {
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner);

    $this->actingAsHq($owner)->post("/approvals/{$approval->id}/reject", [])->assertSessionHasErrors('reason');
    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('lets the requester cancel their own pending request', function () {
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner);

    $this->actingAsHq($owner)->post("/approvals/{$approval->id}/cancel", ['reason' => 'changed my mind'])->assertRedirect();

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Cancelled);
});

it('exposes no endpoint that executes an arbitrary tool from the browser', function () {
    // Route-level proof: nothing under /approvals accepts a tool key as an execution instruction.
    $routes = collect(app('router')->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'approvals'))
        ->values()
        ->all();

    expect($routes)->toEqualCanonicalizing([
        'approvals',
        'approvals/{approval}',
        'approvals/{approval}/approve',
        'approvals/{approval}/reject',
        'approvals/{approval}/cancel',
        'approvals/{approval}/delegate',
    ]);
});

it('links the Approval Center from the sidebar', function () {
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("url: '/approvals'")
        ->and($sidebar)->toContain("permission: 'hq.approvals.view'")
        ->and(file_exists(resource_path('js/pages/approvals/index.tsx')))->toBeTrue()
        ->and(file_exists(resource_path('js/pages/approvals/show.tsx')))->toBeTrue();
});

it('keeps the parked call visible as awaiting approval rather than finished', function () {
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner);

    expect(ToolCall::find($approval->requesting_tool_call_id)->status)
        ->toBe(OutcomeStatus::AwaitingApproval->value);
});

it('never claims an action executed when the approval was spent on a refused call', function () {
    // Found by the M3 production UI check: `consumed` means the approval was SPENT, and a policy
    // denial at execution time spends it without the action running. Reading the raw status label
    // there told a person the action had executed when it had not, on the one screen whose entire
    // job is to report what happened.
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner);

    $denied = ToolCall::create([
        'tool_key' => $approval->tool_key,
        'status' => 'denied',
        'error' => 'The global AI freeze flag is on.',
        'caller_kind' => 'human',
        'attempt' => 1,
    ]);

    $approval->forceFill([
        'status' => ApprovalStatus::Consumed,
        'consumed_at' => now(),
        'resulting_tool_call_id' => $denied->id,
    ])->save();

    $card = $approval->fresh()->toCard();

    expect($card['status'])->toBe('consumed')
        ->and($card['status_label'])->not->toBe('approved and executed')
        ->and($card['status_label'])->toContain('refused at execution time')
        ->and($card['execution_status'])->toBe('denied')
        ->and($card['execution_error'])->toContain('freeze');
});

it('does say executed when the approval actually produced a successful call', function () {
    $owner = $this->hqUser('owner');
    $approval = screenApproval($owner);

    $ok = ToolCall::create([
        'tool_key' => $approval->tool_key,
        'status' => 'succeeded',
        'caller_kind' => 'human',
        'attempt' => 1,
    ]);

    $approval->forceFill([
        'status' => ApprovalStatus::Consumed,
        'consumed_at' => now(),
        'resulting_tool_call_id' => $ok->id,
    ])->save();

    expect($approval->fresh()->toCard()['status_label'])->toBe('approved and executed');
});

it('sends the decision outcome to the browser so a refusal is never silent', function () {
    // The controller flashed `outcome`, the Inertia middleware dropped it, and the page rendered
    // nothing: clicking approve on a refused action looked exactly like clicking a broken button.
    $shared = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
    expect($shared)->toContain("'outcome' => \$request->session()->get('outcome')");

    foreach (['index', 'show'] as $page) {
        $source = file_get_contents(resource_path("js/pages/approvals/{$page}.tsx"));
        expect($source)->toContain('flash')
            ->and($source)->toContain('Nothing ran.');
    }
});
