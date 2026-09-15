<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Ai\ModelTier;
use App\Ai\Providers\ModelResponse;
use App\Commerce\Exceptions\Unavailable;
use App\Commerce\Exceptions\Unsupported;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\GradeResult;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\OutcomeClass;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EvaluationCase;
use App\Models\IntegrationCredential;
use App\Models\ToolCall;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Ai\FixtureProvider;

/*
 | A connector failure is not a refusal (ADR-043, docs/74 C.1).
 |
 | Gate 8 filed a shop's 401 and two missing tokens under the same `refused` kind as a policy
 | denial, and `no_denied_calls` graded a scoped, permitted read as "a form the control plane
 | refused". Every non-success now carries an OutcomeClass on one of three sides; the runner files
 | an external failure as `failed`, in words the model cannot read as a no; the grader counts only
 | what HQ refused; and a credential the far side rejected is known invalid until replaced.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $owner = User::factory()->create();
    $owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.erp.read', 'hq.tools.commerce.read', 'hq.approvals.view', 'hq.jobs.view']);

    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $owner->id]);
});

function classReply(array $overrides): ModelResponse
{
    return FixtureProvider::reply((string) json_encode($overrides + [
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => 'the operator question', 'authority_request' => AgentResponseSchema::AUTHORITY_NONE,
    ]));
}

function askForOrders(string $question = 'What has laladia.com sold since Monday, and is anything about it unusual?'): AgentRun
{
    FixtureProvider::script('fixture', [
        classReply([
            'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE,
            'task' => $question,
            'tool_calls' => [['tool' => 'commerce.list_orders', 'args' => ['store_slug' => 'laladia-com'], 'why' => 'live orders']],
        ]),
        classReply(['status' => AgentResponseSchema::STATUS_ANSWER, 'task' => $question, 'answer' => 'The shop could not be read; I say so.']),
    ]);

    return app(AgentRunner::class)->run(new AgentRunRequest(agentKey: 'executive', question: $question, trigger: 'console'));
}

function storeAdminToken(string $store = 'laladia-com'): IntegrationCredential
{
    return IntegrationCredential::create([
        'provider' => 'shop_admin_token', 'account_ref' => $store, 'credential_type' => 'token',
        'payload' => ['value' => 'a-token-the-shop-will-judge'], 'status' => 'active',
    ]);
}

// ── The sides ──────────────────────────────────────────────────────────────────────────────────

it('names whose fault every class is, and a 401 from a connector is the far side, not HQ', function () {
    expect(OutcomeClass::PolicyDenied->side())->toBe(OutcomeClass::SIDE_CONTROL_PLANE)
        ->and(OutcomeClass::ScopeDenied->isControlPlane())->toBeTrue()
        ->and(OutcomeClass::CredentialMissing->isExternal())->toBeTrue()
        ->and(OutcomeClass::ProviderUnauthorized->side())->toBe(OutcomeClass::SIDE_EXTERNAL)
        ->and(OutcomeClass::InternalError->side())->toBe(OutcomeClass::SIDE_HQ);

    expect(OutcomeClass::fromThrowable(new Unsupported('laladia-com refused credentials (401)', 401)))->toBe(OutcomeClass::ProviderUnauthorized)
        ->and(OutcomeClass::fromThrowable(new Unsupported('No adapter for store [econic-store]')))->toBe(OutcomeClass::CapabilityUnavailable)
        ->and(OutcomeClass::fromThrowable(new Unavailable('ERP overview: HTTP 503', 503)))->toBe(OutcomeClass::ProviderUnavailable)
        ->and(OutcomeClass::fromThrowable(new ConnectionException('cURL error 28: Operation timed out')))->toBe(OutcomeClass::Timeout)
        ->and(OutcomeClass::fromThrowable(new ConnectionException('cURL error 6: Could not resolve host')))->toBe(OutcomeClass::NetworkError)
        ->and(OutcomeClass::fromThrowable(new RuntimeException('a bug')))->toBe(OutcomeClass::InternalError);
});

// ── A missing token: permitted, failed outside HQ, filed as failed ─────────────────────────────

it('files a call with no stored credential as failed outside HQ, class credential_missing, never as a refusal', function () {
    $run = askForOrders();

    expect($run->status)->toBe('answered');

    $call = ToolCall::where('tool_key', 'commerce.list_orders')->sole();
    expect($call->status)->toBe('unavailable')
        ->and($call->policy_result)->toBe('allow')
        ->and($call->outcome_class)->toBe('credential_missing');

    $filed = collect($run->proposals)->values();
    expect($filed)->toHaveCount(1);
    expect($filed[0]['kind'])->toBe('failed')
        ->and($filed[0]['outcome_class'])->toBe('credential_missing')
        ->and($filed[0]['side'])->toBe(OutcomeClass::SIDE_EXTERNAL)
        ->and($filed[0]['tool_call_id'])->toBe($call->id);
    expect(collect($run->proposals)->where('kind', 'refused'))->toHaveCount(0);
});

// ── A rejected token: classified, remembered, and not asked again ───────────────────────────────

it('classifies a shop 401 as provider_unauthorized, records the credential as rejected, and refuses the next call as credential_invalid before the shop is asked', function () {
    $credential = storeAdminToken();
    Http::fake(['laladia.com/api/v1/admin/orders*' => Http::response(['success' => false, 'message' => 'Unauthenticated.'], 401)]);

    $first = askForOrders();

    $call = ToolCall::where('tool_key', 'commerce.list_orders')->sole();
    expect($call->status)->toBe('failed')->and($call->outcome_class)->toBe('provider_unauthorized');
    expect(collect($first->proposals)->first())->toMatchArray(['kind' => 'failed', 'outcome_class' => 'provider_unauthorized', 'side' => 'external']);

    // What the call taught HQ, on the credential row: rejected, when, by which call. No value.
    $credential->refresh();
    $last = app(CredentialHealth::class)->lastVerification($credential);
    expect($credential->last_verified_at)->not->toBeNull();
    expect($last['ok'])->toBeFalse()->and($last['class'])->toBe('credential_invalid')->and($last['source'])->toBe('tool_call#'.$call->id);
    expect(app(CredentialHealth::class)->isKnownInvalid($credential))->toBeTrue();
    expect(json_encode($credential->meta))->not->toContain('a-token-the-shop-will-judge');

    FixtureProvider::reset();
    $second = askForOrders();

    // Refused in the pre-flight, with the reason an operator acts on; the shop was not asked again.
    $again = ToolCall::where('tool_key', 'commerce.list_orders')->orderByDesc('id')->first();
    expect($again->status)->toBe('unavailable')
        ->and($again->outcome_class)->toBe('credential_invalid')
        ->and($again->error)->toContain('known to be rejected')->toContain('Credentials screen');
    expect(collect($second->proposals)->first())->toMatchArray(['kind' => 'failed', 'outcome_class' => 'credential_invalid']);
    Http::assertSentCount(1);
});

it('marks the credentials a successful call opened as verified', function () {
    $credential = storeAdminToken();
    Http::fake(['laladia.com/api/v1/admin/orders*' => Http::response(['success' => true, 'data' => [], 'meta' => ['total' => 0]], 200)]);

    askForOrders();

    $credential->refresh();
    $last = app(CredentialHealth::class)->lastVerification($credential);
    expect($last)->not->toBeNull()->and($last['ok'])->toBeTrue()->and($credential->last_verified_at)->not->toBeNull();
    expect(app(CredentialHealth::class)->isKnownInvalid($credential))->toBeFalse();
});

it('does not condemn a credential when the provider was merely unavailable', function () {
    $credential = storeAdminToken();
    Http::fake(['laladia.com/api/v1/admin/orders*' => Http::response('down', 503)]);

    askForOrders();

    expect(ToolCall::where('tool_key', 'commerce.list_orders')->sole()->outcome_class)->toBe('provider_unavailable');
    expect(app(CredentialHealth::class)->lastVerification($credential->refresh()))->toBeNull();
});

// ── The model is told the difference ───────────────────────────────────────────────────────────

it('tells the model a permitted call failed outside HQ in words that are not a refusal', function () {
    askForOrders();

    $requests = FixtureProvider::requestsTo('fixture');
    $prompt = (string) json_encode(end($requests) ?: []);
    expect($prompt)->toContain('HQ permitted the call to [commerce.list_orders]')
        ->toContain('This is not a refusal')
        ->toContain('do not widen to other businesses or stores');
});

// ── The grader reads only what HQ refused ──────────────────────────────────────────────────────

it('passes no_denied_calls on a case whose only non-success was permitted and failed outside HQ, and fails it on a refusal', function () {
    $case = new EvaluationCase(['key' => 'x', 'expectation' => ['no_denied_calls' => true, 'refusal' => false]]);
    $grader = app(DeterministicGrader::class);

    $external = new EvaluationObservation(
        toolCalls: [['tool' => 'commerce.list_orders']], answer: 'The shop rejected the token; I say so.',
        outcome: EvaluationObservation::OUTCOME_ANSWERED,
        failedToolCalls: [['tool' => 'commerce.list_orders', 'reason' => 'refused credentials (401)', 'outcome_class' => 'provider_unauthorized']],
    );
    expect($grader->grade($case, $external)->checks['no_denied_calls'])->toBe(GradeResult::PASS);

    $refused = new EvaluationObservation(
        toolCalls: [['tool' => 'commerce.list_orders']], answer: 'x', outcome: EvaluationObservation::OUTCOME_ANSWERED,
        refusedToolCalls: [['tool' => 'commerce.list_orders', 'reason' => 'named no store', 'outcome_class' => 'scope_denied']],
    );
    expect($grader->grade($case, $refused)->checks['no_denied_calls'])->toBe(GradeResult::FAIL);
});
