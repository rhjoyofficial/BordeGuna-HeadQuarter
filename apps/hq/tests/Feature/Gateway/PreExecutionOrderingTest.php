<?php

use App\Conversation\Language\LanguagePolicies;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\ToolCall;
use App\Models\User;
use App\Tools\ToolRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Tools\ScriptedReplyConsentTool;
use Tests\Support\Tools\ScriptedReplyCredentialTool;
use Tests\Support\Tools\ScriptedReplyTool;

/*
 | docs/97 §6 D4 (ChatGPT ruling of 3 October 2026, superseding docs/92 A.6's ordering): the reply-script
 | check is a PRE-EXECUTION check of the Tool Gateway. It runs after every authorization step (availability,
 | arguments, scope and the execution context, policy and permission, consent, idempotency and replay,
 | credential pre-flight) and before execution, never after it. Authorization and content are separate
 | questions: a valid script never makes a call permitted, a refusal at any authorization step wins over a
 | wrong script, and a call the script check refuses is never executed and is its own outcome class.
 */

const PEO_ROMANISED = 'Dukkhoito, order-er obostha ekhane theke nischit korte parchi na.';

const PEO_BENGALI = 'দুঃখিত, অর্ডারের অবস্থা এখান থেকে নিশ্চিত করতে পারছি না।';

beforeEach(function () {
    Cache::flush();
    ScriptedReplyTool::$executions = 0;
    foreach ([ScriptedReplyTool::class, ScriptedReplyCredentialTool::class, ScriptedReplyConsentTool::class] as $class) {
        app(ToolRegistry::class)->register($class);
    }
    app(ToolAvailability::class)->forget();
});

function peoUser(bool $permitted = true): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $permitted ? $user->assignRole('owner') : $user->givePermissionTo(['hq.inbox.view']);

    return $user->fresh();
}

/** A request carrying the requirement the runtime resolves for a mixed Bangla/English thread (v1: Bangla in Bengali script). */
function peoRequest(User $user, string $tool, array $args, bool $withRequirement = true): ToolRequest
{
    return new ToolRequest(
        toolKey: $tool,
        args: $args,
        caller: CallerKind::Console,
        identity: ActingIdentity::human($user),
        correlationId: (string) Str::uuid(),
        languageRequirement: $withRequirement ? app(LanguagePolicies::class)->requirementFor('banglish')->toArray() : null,
    );
}

function peoGateway(): ToolGateway
{
    return app(ToolGateway::class);
}

it('runs the D4 order exactly: each authorization step refuses before the script check, the script check refuses before execution, then it executes', function () {
    $owner = peoUser();
    $nobody = peoUser(permitted: false);

    // Each row: a call with a WRONG script and one earlier step that also refuses. The earlier step wins.
    $layers = [
        'arguments' => [peoRequest($owner, 'test.scripted_reply', ['text' => PEO_ROMANISED, 'extra' => 'x']), OutcomeClass::InvalidArguments],
        'permission' => [peoRequest($nobody, 'test.scripted_reply', ['text' => PEO_ROMANISED]), OutcomeClass::PermissionDenied],
        'consent' => [peoRequest($owner, 'test.scripted_reply_consent', ['text' => PEO_ROMANISED]), OutcomeClass::ConsentDenied],
        'credential pre-flight' => [peoRequest($owner, 'test.scripted_reply_credential', ['text' => PEO_ROMANISED, 'account' => 'none']), OutcomeClass::CredentialMissing],
        // Every authorization step passes: the script check is what refuses, after them and before execution.
        'script check' => [peoRequest($owner, 'test.scripted_reply', ['text' => PEO_ROMANISED]), OutcomeClass::ContentPolicyRejected],
    ];

    foreach ($layers as $step => [$request, $class]) {
        $outcome = peoGateway()->execute($request);

        expect($outcome->ok())->toBeFalse($step)
            ->and($outcome->class)->toBe($class, $step)
            ->and(ScriptedReplyTool::$executions)->toBe(0, $step)
            ->and(ToolCall::findOrFail($outcome->toolCallId)->outcome_class)->toBe($class->value, $step);

        // Only the call that reached the script check carries its verdict.
        expect($outcome->preExecution === null)->toBe($step !== 'script check', $step);
    }

    // The script refusal was authorized first: policy allowed it on the row, and it is not a permission denial.
    $rejected = ToolCall::where('outcome_class', OutcomeClass::ContentPolicyRejected->value)->sole();
    expect($rejected->policy_result)->toBe('allow')
        ->and(OutcomeClass::ContentPolicyRejected->isControlPlane())->toBeTrue()
        ->and($rejected->status)->toBe(OutcomeStatus::Denied->value);

    // And the same call in the required script executes, once, with the passed verdict on the outcome.
    $outcome = peoGateway()->execute(peoRequest($owner, 'test.scripted_reply', ['text' => PEO_BENGALI]));
    expect($outcome->ok())->toBeTrue()
        ->and(ScriptedReplyTool::$executions)->toBe(1)
        ->and($outcome->preExecution)->toBeNull();
});

it('never lets a valid script make a call permitted: permission and consent refuse a correct-script reply exactly as a wrong one', function () {
    $nobody = peoUser(permitted: false);
    $owner = peoUser();

    $denied = peoGateway()->execute(peoRequest($nobody, 'test.scripted_reply', ['text' => PEO_BENGALI]));
    $noConsent = peoGateway()->execute(peoRequest($owner, 'test.scripted_reply_consent', ['text' => PEO_BENGALI]));

    expect($denied->class)->toBe(OutcomeClass::PermissionDenied)
        ->and($noConsent->class)->toBe(OutcomeClass::ConsentDenied)
        ->and($denied->preExecution)->toBeNull()
        ->and($noConsent->preExecution)->toBeNull()
        ->and(ScriptedReplyTool::$executions)->toBe(0);

    // The read-only authorization says the same, before any content is looked at.
    expect(peoGateway()->authorize(peoRequest($nobody, 'test.scripted_reply', ['text' => PEO_BENGALI]))->permitsProposal())->toBeFalse()
        ->and(peoGateway()->authorize(peoRequest($nobody, 'test.scripted_reply', ['text' => PEO_BENGALI]))->class)->toBe(OutcomeClass::PermissionDenied);
});

it('authorizes read-only and checks content with the same code the execution uses, recording and running nothing', function () {
    $owner = peoUser();
    $request = peoRequest($owner, 'test.scripted_reply', ['text' => PEO_ROMANISED]);

    $authorization = peoGateway()->authorize($request);
    $verdict = peoGateway()->preExecutionChecks($authorization->descriptor, $request, $authorization->args);

    expect($authorization->authorized)->toBeTrue()
        ->and($verdict->passed)->toBeFalse()
        ->and($verdict->check)->toBe('reply_script')
        ->and($verdict->result)->toMatchArray(['valid' => false, 'reason' => 'other_script', 'detected_script' => 'latin', 'required_script' => 'bengali', 'policy_version' => 1])
        ->and($verdict->reason)->toContain('refused before execution')
        ->and(ToolCall::count())->toBe(0)
        ->and(ScriptedReplyTool::$executions)->toBe(0);

    // The execution path reaches the same verdict for the same request.
    $outcome = peoGateway()->execute($request);
    expect($outcome->class)->toBe(OutcomeClass::ContentPolicyRejected)
        ->and($outcome->preExecution['result'])->toEqual($verdict->result);
});

it('checks nothing when the request carries no requirement, and nothing for a tool that does not opt in', function () {
    $owner = peoUser();

    expect(peoGateway()->execute(peoRequest($owner, 'test.scripted_reply', ['text' => PEO_ROMANISED], withRequirement: false))->ok())->toBeTrue()
        ->and(ScriptedReplyTool::$executions)->toBe(1)
        ->and(app(ToolRegistry::class)->get('messaging.send_reply')->descriptor()->preExecutionChecks)->toBe(['reply_script'])
        ->and(app(ToolRegistry::class)->get('messaging.send_whatsapp_reply')->descriptor()->preExecutionChecks)->toBe(['reply_script']);
});
