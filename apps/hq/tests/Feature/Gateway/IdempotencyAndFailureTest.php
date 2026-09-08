<?php

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Gateway\CallerKind;
use App\Gateway\FailureClass;
use App\Gateway\FailureClassifier;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\Recording\IdempotencyKeyFactory;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\ToolCall;
use App\Models\User;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Tools\CountingTool;

/*
 | Idempotency and the failure vocabulary (brief sections 14 and 15).
 |
 | ADR-011 stands: at-least-once execution, idempotent tools, reconciliation when provider state is
 | uncertain. Nothing here claims exactly-once, and the uncertain path deliberately refuses to guess.
 */

function idemUser(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function idemGateway(): ToolGateway
{
    app(ToolRegistry::class)->register(CountingTool::class);
    app(ToolAvailability::class)->forget();

    return app(ToolGateway::class);
}

beforeEach(function () {
    Cache::flush();
    CountingTool::reset();
});

it('replays a recorded result for a redelivered request instead of executing again', function () {
    $gateway = idemGateway();
    $user = idemUser();
    $key = 'job-run:'.Str::uuid();

    $make = fn () => new ToolRequest(
        toolKey: 'test.counting',
        args: ['label' => 'same'],
        caller: CallerKind::Scheduler,
        identity: ActingIdentity::human($user),
        correlationId: (string) Str::uuid(),
        idempotencyKey: $key,
    );

    $first = $gateway->execute($make());
    $second = $gateway->execute($make());

    expect($first->status)->toBe(OutcomeStatus::Succeeded)
        ->and($second->status)->toBe(OutcomeStatus::Replayed)
        ->and($second->replayed)->toBeTrue()
        // The assertion that actually matters. Identical output proves nothing on its own: a
        // deterministic tool that simply ran twice would produce it too.
        ->and(CountingTool::$executions)->toBe(1)
        ->and($second->output['executions'])->toBe(1);

    $row = ToolCall::findOrFail($second->toolCallId);
    expect($row->replayed)->toBeTrue()->and($row->status)->toBe(OutcomeStatus::Replayed->value);
});

it('runs both of two genuinely separate calls that happen to look alike', function () {
    $gateway = idemGateway();
    $user = idemUser();

    // No explicit key and no replay window on this tool: two operator clicks are two requests.
    $gateway->execute(ToolRequest::forOperator($user, 'test.counting', ['label' => 'same']));
    $gateway->execute(ToolRequest::forOperator($user, 'test.counting', ['label' => 'same']));

    expect(CountingTool::$executions)->toBe(2);
});

it('reconciles an uncertain previous call before attempting anything again', function () {
    $gateway = idemGateway();
    $user = idemUser();
    $key = 'job-run:'.Str::uuid();

    // A previous attempt that may or may not have landed.
    ToolCall::create([
        'tool_key' => 'test.counting',
        'idempotency_key' => $key,
        'status' => OutcomeStatus::Uncertain->value,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    // The tool can look the effect up and finds it: HQ adopts that result and executes nothing.
    CountingTool::$reconcileWith = ToolResult::succeeded(['executions' => 99, 'recovered' => true]);

    $outcome = $gateway->execute(new ToolRequest(
        toolKey: 'test.counting',
        args: [],
        caller: CallerKind::Scheduler,
        identity: ActingIdentity::human($user),
        correlationId: (string) Str::uuid(),
        idempotencyKey: $key,
    ));

    expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
        ->and($outcome->output['recovered'])->toBeTrue()
        ->and(CountingTool::$executions)->toBe(0);
});

it('executes again when an idempotent tool has nothing to reconcile', function () {
    $gateway = idemGateway();
    $user = idemUser();
    $key = 'job-run:'.Str::uuid();

    ToolCall::create([
        'tool_key' => 'test.counting',
        'idempotency_key' => $key,
        'status' => OutcomeStatus::Uncertain->value,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    CountingTool::$reconcileWith = null; // nothing outside HQ to look up

    $outcome = $gateway->execute(new ToolRequest(
        toolKey: 'test.counting', args: [], caller: CallerKind::Scheduler,
        identity: ActingIdentity::human($user), correlationId: (string) Str::uuid(), idempotencyKey: $key,
    ));

    expect($outcome->ok())->toBeTrue()->and(CountingTool::$executions)->toBe(1);
});

it('derives the same key for a redelivery and a different one for a new call', function () {
    $factory = app(IdempotencyKeyFactory::class);
    $descriptor = (new CountingTool)->descriptor();
    $user = idemUser();

    $correlation = (string) Str::uuid();
    $a = new ToolRequest('test.counting', ['label' => 'x'], CallerKind::Scheduler, ActingIdentity::human($user), $correlation, jobRunId: 7);
    $b = new ToolRequest('test.counting', ['label' => 'x'], CallerKind::Scheduler, ActingIdentity::human($user), $correlation, jobRunId: 7);
    $c = new ToolRequest('test.counting', ['label' => 'x'], CallerKind::Scheduler, ActingIdentity::human($user), (string) Str::uuid(), jobRunId: 8);

    expect($factory->derive($a, $descriptor, ['label' => 'x']))->toBe($factory->derive($b, $descriptor, ['label' => 'x']))
        ->and($factory->derive($a, $descriptor, ['label' => 'x']))->not->toBe($factory->derive($c, $descriptor, ['label' => 'x']));
});

it('derives the same key however the arguments are ordered', function () {
    $factory = app(IdempotencyKeyFactory::class);
    $descriptor = (new CountingTool)->descriptor();
    $user = idemUser();
    $request = new ToolRequest('test.counting', [], CallerKind::Console, ActingIdentity::human($user), 'corr-1');

    // Canonical encoding, or the key is a coin toss and replay never fires.
    expect($factory->derive($request, $descriptor, ['a' => 1, 'b' => 2]))
        ->toBe($factory->derive($request, $descriptor, ['b' => 2, 'a' => 1]));
});

it('maps every connector failure onto a distinct, honest class', function () {
    $classifier = app(FailureClassifier::class);

    expect($classifier->classify(new Refused('no')))->toBe(FailureClass::Refused)
        ->and($classifier->classify(new Unavailable('down')))->toBe(FailureClass::Unavailable)
        ->and($classifier->classify(new Uncertain('maybe')))->toBe(FailureClass::Uncertain)
        ->and($classifier->classify(new Unsupported('nope')))->toBe(FailureClass::Unsupported)
        ->and($classifier->classify(new ValidationFailed('bad')))->toBe(FailureClass::ValidationFailed)
        // A defect in HQ must look like one, rather than being quietly recorded as the far side
        // saying no.
        ->and($classifier->classify(new RuntimeException('bug')))->toBe(FailureClass::Internal);

    expect(FailureClass::Unavailable->retryable())->toBeTrue()
        ->and(FailureClass::Refused->retryable())->toBeFalse()
        ->and(FailureClass::Uncertain->retryable())->toBeFalse();
});
