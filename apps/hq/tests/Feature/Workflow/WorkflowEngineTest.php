<?php

use App\FollowUps\Workflow\FollowUpWorkflow;
use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\OutcomeStatus;
use App\Models\Approval;
use App\Models\DomainEvent;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowSignal;
use App\Models\WorkflowStep;
use App\Support\Events\EventRecorder;
use App\Tools\ToolRegistry;
use App\Workflow\AdvanceWorkflow;
use App\Workflow\Step;
use App\Workflow\WorkflowDefinitions;
use App\Workflow\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Workflow\FarSide;
use Tests\Support\Workflow\LookupOrderTool;
use Tests\Support\Workflow\OrderFollowUpWorkflow;
use Tests\Support\Workflow\RecordNoteTool;
use Tests\Support\Workflow\RecordThenConfirmWorkflow;
use Tests\Support\Workflow\SendMessageTool;

/*
 | docs/102: the HQ-native WorkflowEngine (docs/101 §11 ruling 9.1).
 |
 | Every run here is real: real tables, the real Tool Gateway with its policy, approvals and
 | idempotency, the real event drain and scheduler tick. The queue is `sync`, so a dispatch runs in
 | place; a "worker restart" rebuilds the container's singletons from config (`wfWorker()`) so nothing
 | in memory survives it, while the FarSide (the world outside HQ) does.
 */

const WF_LOOKUP = 'test.wf_lookup';
const WF_RECORD = 'test.wf_record';
const WF_SEND = 'test.wf_send_message';

/** A fresh worker: catalogue and tool registry rebuilt from config, as a new process would. */
function wfWorker(): WorkflowEngine
{
    config([
        'hq.workflows.definitions' => [OrderFollowUpWorkflow::class, RecordThenConfirmWorkflow::class],
        'hq.tools' => [...array_values(config('hq.tools', [])), LookupOrderTool::class, RecordNoteTool::class, SendMessageTool::class],
    ]);
    app()->forgetInstance(WorkflowDefinitions::class);
    app()->forgetInstance(ToolRegistry::class);

    return app(WorkflowEngine::class);
}

function wfOwner(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function wfRun(string $key): WorkflowInstance
{
    return WorkflowInstance::query()->where('instance_key', $key)->firstOrFail();
}

function wfStep(string $key, string $step): WorkflowStep
{
    return WorkflowStep::query()->where('workflow_instance_id', wfRun($key)->id)->where('step', $step)->firstOrFail();
}

/** An order follow-up that has been delivered and slept, so its next advance reaches the lookup. */
function wfDelivered(WorkflowEngine $engine, User $owner, string $key = 'follow-up:1234', int $waitDays = 0): void
{
    $engine->start(OrderFollowUpWorkflow::TYPE, $key, ['order_id' => '1234', 'wait_days' => $waitDays], $owner);
    $engine->signal($key, 'event:test.order_delivered', ['carrier' => 'test'], 'delivery-1');
}

beforeEach(function () {
    Cache::flush();
    FarSide::reset();
});

// ── start, persist, inspect ──────────────────────────────────────────────────────────────────────

it('lists only definitions with no tool step, so production can start nothing that has an effect; an unlisted type still cannot start', function () {
    // docs/102 shipped this list empty. docs/113 (S4 part 1) lists the first type, `followup.await_person`,
    // which sleeps and then waits for a person: a listed type may be started by a person's approval, but
    // nothing listed here may carry a tool step until an activation ruling says so (docs/113 Q1, Q10).
    // Adding a type to this list, or a tool step to a listed type, is a reviewed change of this pin.
    $config = require config_path('hq.php');

    expect($config['workflows']['definitions'])->toBe([FollowUpWorkflow::class]);
    foreach ($config['workflows']['definitions'] as $class) {
        $steps = (new $class)->steps();
        expect($steps)->not->toBeEmpty()
            ->and(array_filter($steps, fn (Step $s) => $s->kind === Step::TOOL))->toBe([], "{$class} declares a tool step");
    }

    expect(fn () => app(WorkflowEngine::class)->start(OrderFollowUpWorkflow::TYPE, 'x', ['order_id' => '1']))
        ->toThrow(InvalidArgumentException::class, 'Unknown workflow type');
});

it('starts once per business key, persists the run and parks it on the event wait', function () {
    $engine = wfWorker();
    $owner = wfOwner();

    $state = $engine->start(OrderFollowUpWorkflow::TYPE, 'follow-up:1234', ['order_id' => '1234'], $owner);
    $again = $engine->start(OrderFollowUpWorkflow::TYPE, 'follow-up:1234', ['order_id' => '9999'], $owner);

    expect($state->status)->toBe(WorkflowInstance::WAITING)
        ->and($state->wait)->toMatchArray(['kind' => 'signal', 'for' => 'event:test.order_delivered'])
        ->and($state->wait['until'])->not->toBeNull()
        ->and($state->currentStep)->toBe('await_delivery')
        ->and($again->runId)->toBe($state->runId)
        ->and(WorkflowInstance::query()->count())->toBe(1);

    $run = wfRun('follow-up:1234');
    expect($run->input)->toBe(['order_id' => '1234'])
        ->and($run->correlation_key)->toBe('order:1234')
        ->and($run->requested_by_user_id)->toBe($owner->id)
        ->and($run->definition_version)->toBe(1)
        ->and($run->lease_token)->toBeNull();

    expect(fn () => $engine->start(RecordThenConfirmWorkflow::TYPE, 'follow-up:1234'))
        ->toThrow(InvalidArgumentException::class, 'already a run of');
});

// ── the whole shape, across a worker restart ─────────────────────────────────────────────────────

it('resumes after a worker restart: event from the outbox, durable sleep, gateway lookup, approval wait, done once', function () {
    $owner = wfOwner();
    wfWorker()->start(OrderFollowUpWorkflow::TYPE, 'follow-up:1234', ['order_id' => '1234', 'wait_days' => 3], $owner);

    // The worker process is gone; a new one boots. Nothing in memory carries the run.
    $engine = wfWorker();

    // The shop says the order was delivered: an outbox row, drained by the ordinary event drain.
    app(EventRecorder::class)->record('test.order_delivered', ['carrier' => 'pathao'], source: 'test', direction: 'inbound',
        aggregateType: 'order', aggregateId: '1234');
    $this->artisan('hq:events:process')->assertSuccessful();

    $state = $engine->inspect('follow-up:1234');
    expect($state->step('await_delivery')['status'])->toBe(WorkflowStep::SUCCEEDED)
        ->and($state->step('await_delivery')['outcome']['payload']['payload']['carrier'])->toBe('pathao')
        ->and($state->wait['kind'])->toBe('timer')
        ->and($state->currentStep)->toBe('cool_off');

    // A replayed event (a second drain of the same row) is not a second signal.
    DomainEvent::query()->update(['status' => DomainEvent::STATUS_PENDING]);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(WorkflowSignal::query()->count())->toBe(1);

    // Not due: the tick hands nothing to a worker.
    expect($engine->reconcile())->toBe(0);

    $this->travel(3 * 86400 + 1)->seconds();
    $engine = wfWorker();
    $this->artisan('hq:scheduler:tick')->assertSuccessful();

    $state = $engine->inspect('follow-up:1234');
    expect($state->status)->toBe(WorkflowInstance::WAITING)
        ->and($state->wait['kind'])->toBe('approval')
        ->and($state->step('check_order')['status'])->toBe(WorkflowStep::SUCCEEDED)
        ->and($state->step('propose_message')['status'])->toBe(WorkflowStep::WAITING)
        ->and(FarSide::count(WF_LOOKUP))->toBe(1)
        // The R4 message is parked for a person; nothing has been sent.
        ->and(FarSide::count(WF_SEND))->toBe(0);

    // The lookup went through the gateway as the run's requester, under the step's own key.
    $run = wfRun('follow-up:1234');
    $call = ToolCall::query()->findOrFail($state->step('check_order')['tool_call_id']);
    expect($call->caller_kind)->toBe('workflow')
        ->and($call->actor_id)->toBe($owner->id)
        ->and($call->idempotency_key)->toBe("wf:{$run->uuid}:check_order")
        ->and($call->correlation_id)->toBe($run->correlation_id);

    $approval = Approval::query()->findOrFail($state->step('propose_message')['approval_id']);
    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->requested_by_user_id)->toBe($owner->id)
        ->and($approval->payload['text'])->toBe('Your order was delivered. How was it?')
        ->and((string) $approval->id)->toBe($state->wait['for']);

    // The person approves in the Approval Center: the one executor, through the gateway.
    expect(app(ApprovalExecutor::class)->approveAndExecute($approval, $owner, 'send it')->ok())->toBeTrue()
        ->and(FarSide::count(WF_SEND))->toBe(1);

    expect($engine->reconcile())->toBe(1);
    $state = $engine->inspect('follow-up:1234');
    expect($state->status)->toBe(WorkflowInstance::COMPLETED)
        ->and($state->step('propose_message')['outcome']['approval_id'])->toBe($approval->id)
        ->and($state->output['propose_message']['status'])->toBe(WorkflowStep::SUCCEEDED);

    // Idempotent replay: redelivered jobs, resumes and sweeps after the end change nothing outside.
    AdvanceWorkflow::dispatch($run->id);
    $engine->resume('follow-up:1234');
    expect($engine->reconcile())->toBe(0)
        ->and(FarSide::count(WF_LOOKUP))->toBe(1)
        ->and(FarSide::count(WF_SEND))->toBe(1)
        ->and($engine->signal('follow-up:1234', 'event:test.order_delivered', [], 'late'))->toBeFalse();
});

// ── signals ──────────────────────────────────────────────────────────────────────────────────────

it('buffers a signal that arrives before its wait, takes it once, and refuses duplicates and late ones', function () {
    $engine = wfWorker();
    FarSide::$plan[WF_RECORD] = ['fail'];

    $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:1', ['note' => 'hello'], wfOwner());
    expect($engine->inspect('record:1')->step('record')['status'])->toBe(WorkflowStep::PENDING);

    // The confirmation lands while the first step is still in its retry backoff.
    expect($engine->signal('record:1', 'confirmed', ['by' => 'shop'], 'c-1'))->toBeTrue()
        ->and($engine->signal('record:1', 'confirmed', ['by' => 'shop'], 'c-1'))->toBeFalse()
        ->and($engine->inspect('record:1')->pendingSignals)->toBe(['confirmed']);

    $this->travel(11)->seconds();
    expect($engine->reconcile())->toBe(1);

    $state = $engine->inspect('record:1');
    expect($state->status)->toBe(WorkflowInstance::COMPLETED)
        ->and($state->step('record')['attempt'])->toBe(2)
        ->and($state->step('confirm')['outcome']['payload'])->toBe(['by' => 'shop'])
        ->and($state->pendingSignals)->toBe([])
        ->and(FarSide::count(WF_RECORD))->toBe(1)
        ->and($engine->signal('record:1', 'confirmed', [], 'c-2'))->toBeFalse()
        ->and($engine->signal('no-such-run', 'confirmed'))->toBeFalse();
});

it('times out when the event never comes and skips every step that depended on it', function () {
    $engine = wfWorker();
    $engine->start(OrderFollowUpWorkflow::TYPE, 'follow-up:77', ['order_id' => '77'], wfOwner());

    $this->travel(14 * 86400 - 60)->seconds();
    expect($engine->reconcile())->toBe(0);

    $this->travel(61)->seconds();
    $this->artisan('hq:scheduler:tick')->assertSuccessful();

    $state = $engine->inspect('follow-up:77');
    expect($state->status)->toBe(WorkflowInstance::COMPLETED)
        ->and(array_column($state->steps, 'status', 'step'))->toBe([
            'await_delivery' => WorkflowStep::TIMED_OUT, 'cool_off' => WorkflowStep::SKIPPED,
            'check_order' => WorkflowStep::SKIPPED, 'propose_message' => WorkflowStep::SKIPPED,
        ])
        ->and(ToolCall::query()->count())->toBe(0);
});

// ── retry ────────────────────────────────────────────────────────────────────────────────────────

it('retries a failed step with backoff, fails the run when the budget is spent, and resumes on a person\'s retry', function () {
    $engine = wfWorker();
    $owner = wfOwner();
    FarSide::$plan[WF_LOOKUP] = ['fail', 'fail', 'fail'];

    wfDelivered($engine, $owner, 'follow-up:5');
    $step = fn () => $engine->inspect('follow-up:5')->step('check_order');
    expect($step()['attempt'])->toBe(1)
        ->and($step()['status'])->toBe(WorkflowStep::PENDING)
        ->and($engine->inspect('follow-up:5')->wait['kind'])->toBe('timer');

    $this->travel(29)->seconds();
    expect($engine->reconcile())->toBe(0);          // 30 s backoff not over
    $this->travel(2)->seconds();
    expect($engine->reconcile())->toBe(1)
        ->and($step()['attempt'])->toBe(2);
    $this->travel(61)->seconds();                    // 60 s: the backoff doubled
    $engine->reconcile();

    $state = $engine->inspect('follow-up:5');
    expect($state->status)->toBe(WorkflowInstance::FAILED)
        ->and($state->step('check_order')['attempt'])->toBe(3)
        ->and($state->step('check_order')['status'])->toBe(WorkflowStep::FAILED)
        ->and($state->error)->toContain('step [check_order]: failed')
        ->and(FarSide::count(WF_LOOKUP))->toBe(0)
        // A failed run is not swept again: only a person moves it.
        ->and($engine->reconcile())->toBe(0);

    $state = $engine->retry('follow-up:5', $owner);
    expect($state->status)->toBe(WorkflowInstance::WAITING)
        ->and($state->wait['kind'])->toBe('approval')
        ->and($state->step('check_order')['attempt'])->toBe(4)
        ->and(FarSide::count(WF_LOOKUP))->toBe(1);
    expect(fn () => $engine->retry('follow-up:5', $owner))->toThrow(RuntimeException::class, 'Only a failed run');
});

// ── approvals ────────────────────────────────────────────────────────────────────────────────────

it('a rejected approval declines the step, the run completes, and nothing is sent', function () {
    $engine = wfWorker();
    $owner = wfOwner();
    wfDelivered($engine, $owner, 'follow-up:9');

    $approval = Approval::query()->findOrFail($engine->inspect('follow-up:9')->step('propose_message')['approval_id']);
    app(ApprovalService::class)->reject($approval, $owner, 'not this week');
    expect($engine->reconcile())->toBe(1);

    $state = $engine->inspect('follow-up:9');
    expect($state->status)->toBe(WorkflowInstance::COMPLETED)
        ->and($state->step('propose_message')['status'])->toBe(WorkflowStep::DECLINED)
        ->and($state->step('propose_message')['outcome'])->toMatchArray(['approval_status' => 'rejected', 'note' => 'not this week'])
        ->and(FarSide::count(WF_SEND))->toBe(0);
});

it('cancel withdraws the parked approval and stops the run for good', function () {
    $engine = wfWorker();
    $owner = wfOwner();
    wfDelivered($engine, $owner, 'follow-up:11');
    $approval = Approval::query()->findOrFail($engine->inspect('follow-up:11')->step('propose_message')['approval_id']);

    $state = $engine->cancel('follow-up:11', $owner, 'customer asked not to be contacted');

    expect($state->status)->toBe(WorkflowInstance::CANCELLED)
        ->and($state->error)->toContain('customer asked not to be contacted')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Cancelled)
        ->and(app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $owner)->ok())->toBeFalse()
        ->and($engine->signal('follow-up:11', 'event:test.order_delivered', [], 'again'))->toBeFalse()
        ->and($engine->reconcile())->toBe(0)
        ->and($engine->cancel('follow-up:11')->status)->toBe(WorkflowInstance::CANCELLED)
        ->and(FarSide::count(WF_SEND))->toBe(0);
    expect(fn () => $engine->retry('follow-up:11', $owner))->toThrow(RuntimeException::class);
});

// ── crash, uncertainty and replay ────────────────────────────────────────────────────────────────

it('a worker that dies after the effect landed: the step is reconciled through the gateway, never repeated', function () {
    $engine = wfWorker();
    // The effect lands, then the gateway's completion write throws: the process "dies" holding a
    // `started` tool call and a `running` step, which is exactly what a killed worker leaves.
    FarSide::$plan[WF_RECORD] = ['unrecordable'];

    expect(fn () => $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:crash', ['note' => 'x'], wfOwner()))->toThrow(JsonEncodingException::class);

    $step = wfStep('record:crash', 'record');
    $orphan = ToolCall::query()->where('idempotency_key', $step->idempotency_key)->sole();
    expect($step->status)->toBe(WorkflowStep::RUNNING)
        ->and($orphan->status)->toBe(OutcomeStatus::Started->value)
        ->and(FarSide::count(WF_RECORD))->toBe(1);

    // A killed worker never releases its lease: nothing touches the run until it expires.
    wfRun('record:crash')->forceFill(['lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(300)])->save();
    expect(wfWorker()->reconcile())->toBe(0);

    $this->travel(301)->seconds();
    $engine = wfWorker();
    expect($engine->reconcile())->toBe(1);

    $state = $engine->inspect('record:crash');
    expect($state->step('record')['status'])->toBe(WorkflowStep::SUCCEEDED)
        ->and($state->step('record')['outcome'])->toMatchArray(['reconciled' => true])
        ->and($state->wait)->toMatchArray(['kind' => 'signal', 'for' => 'confirmed'])
        ->and($orphan->fresh()->status)->toBe(OutcomeStatus::Uncertain->value)
        // One effect on the far side, although the step "ran" twice.
        ->and(FarSide::count(WF_RECORD))->toBe(1);
});

it('an uncertain step is retried only through the gateway, which reconciles it instead of executing again', function () {
    $engine = wfWorker();
    FarSide::$plan[WF_RECORD] = ['uncertain'];

    $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:u', ['note' => 'x'], wfOwner());
    expect($engine->inspect('record:u')->step('record')['status'])->toBe(WorkflowStep::UNCERTAIN)
        ->and(FarSide::count(WF_RECORD))->toBe(1);

    $this->travel(11)->seconds();
    $engine->reconcile();

    expect($engine->inspect('record:u')->step('record'))->toMatchArray(['status' => WorkflowStep::SUCCEEDED, 'attempt' => 2])
        ->and($engine->inspect('record:u')->step('record')['outcome']['reconciled'])->toBeTrue()
        ->and(FarSide::count(WF_RECORD))->toBe(1);
});

it('an uncertain call older than the gateway replay ceiling waits for a person who accepts the risk', function () {
    $engine = wfWorker();
    $owner = wfOwner();
    FarSide::$plan[WF_RECORD] = ['uncertain'];
    $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:old', ['note' => 'x'], $owner);

    // The retry comes due only after the gateway would no longer reconcile the earlier call.
    $this->travel(86400 + 60)->seconds();
    $engine->reconcile();

    $state = $engine->inspect('record:old');
    expect($state->status)->toBe(WorkflowInstance::FAILED)
        ->and($state->error)->toContain('older than the gateway\'s replay ceiling')
        ->and(FarSide::count(WF_RECORD))->toBe(1);

    // A plain retry asks the same question and gets the same answer.
    expect($engine->retry('record:old', $owner)->status)->toBe(WorkflowInstance::FAILED)
        ->and(FarSide::count(WF_RECORD))->toBe(1);

    // The person checked the far side by hand and accepts that it runs again.
    $state = $engine->retry('record:old', $owner, acceptUncertain: true);
    expect($state->step('record')['status'])->toBe(WorkflowStep::SUCCEEDED)
        ->and(FarSide::count(WF_RECORD))->toBe(2);
});

it('a completed step is read from the ledger, never called again, even past the replay ceiling', function () {
    $engine = wfWorker();
    $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:done', ['note' => 'x'], wfOwner());
    $key = wfStep('record:done', 'record')->idempotency_key;
    expect(ToolCall::query()->where('idempotency_key', $key)->count())->toBe(1);

    // Two days later a worker dies between the gateway's success and the ledger write (the step
    // row still reads `running`). The gateway's 24 h window is long gone.
    $this->travel(2 * 86400)->seconds();
    wfStep('record:done', 'record')->forceFill(['status' => WorkflowStep::RUNNING])->save();
    wfRun('record:done')->forceFill(['status' => WorkflowInstance::RUNNING])->save();

    expect(wfWorker()->reconcile())->toBe(1)
        ->and(wfStep('record:done', 'record')->status)->toBe(WorkflowStep::SUCCEEDED)
        ->and(ToolCall::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(FarSide::count(WF_RECORD))->toBe(1)
        // The confirmation's deadline (an hour, persisted on the first visit) passed long ago.
        ->and(wfStep('record:done', 'confirm')->status)->toBe(WorkflowStep::TIMED_OUT)
        ->and(wfRun('record:done')->status)->toBe(WorkflowInstance::COMPLETED);
});

// ── authority ────────────────────────────────────────────────────────────────────────────────────

it('runs every step with the requester\'s authority and no more', function () {
    $engine = wfWorker();
    (new RolesAndPermissionsSeeder)->run();
    $nobody = User::factory()->create()->fresh();

    $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:nobody', ['note' => 'x'], $nobody);
    $state = $engine->inspect('record:nobody');
    $call = ToolCall::query()->findOrFail($state->step('record')['tool_call_id']);

    expect($state->status)->toBe(WorkflowInstance::FAILED)
        // A refusal is not retried: one attempt, the gateway's own denial on record.
        ->and($state->step('record')['attempt'])->toBe(1)
        ->and($state->error)->toContain('denied')
        ->and($call->status)->toBe(OutcomeStatus::Denied->value)
        ->and($call->actor_id)->toBe($nobody->id)
        ->and(FarSide::count(WF_RECORD))->toBe(0);

    // An owner deactivated mid-run takes the run's authority with them.
    $owner = wfOwner();
    FarSide::$plan[WF_RECORD] = ['fail'];
    $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:leaver', ['note' => 'x'], $owner);
    $owner->forceFill(['is_active' => false])->save();
    $this->travel(11)->seconds();
    $engine->reconcile();

    expect($engine->inspect('record:leaver')->error)->toContain('no longer an active user')
        ->and(FarSide::count(WF_RECORD))->toBe(0);
    expect(fn () => $engine->start(RecordThenConfirmWorkflow::TYPE, 'record:never', [], $owner->fresh()))
        ->toThrow(InvalidArgumentException::class, 'inactive');
});

it('refuses to advance a run under a changed definition version, visibly', function () {
    $engine = wfWorker();
    $engine->start(OrderFollowUpWorkflow::TYPE, 'follow-up:drift', ['order_id' => '3'], wfOwner());
    wfRun('follow-up:drift')->forceFill(['definition_version' => 99])->save();

    $state = $engine->resume('follow-up:drift');

    expect($state->status)->toBe(WorkflowInstance::FAILED)
        ->and($state->error)->toContain('Definition drift');
});
