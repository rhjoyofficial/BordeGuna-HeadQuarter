<?php

use App\Ai\ModelTier;
use App\Ai\ProviderCheck;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\OutcomeClass;
use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ReadinessRow;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 | Readiness follows the provider that serves (docs/81 C.7).
 |
 | The model provider's readiness row, its 8-token check and the gate's bypass step used to name
 | Anthropic by construction. With a second driver they must check the provider a gate would
 | actually run on, and nothing else: a check that proves a key HQ is not using certifies nothing.
 | ⚠ OpenAI says "no credit" with a 429, the same status it uses for "too fast". The first means a
 | person must act on the account and the key is fine; the second means wait. Confusing them
 | either condemns a good key or hides a blocked account behind "try again".
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
});

function oarKey(string $provider): IntegrationCredential
{
    IntegrationCredential::where('provider', $provider)->update(['status' => 'revoked']);

    return IntegrationCredential::create([
        'provider' => $provider, 'account_ref' => 'hq', 'credential_type' => 'api_key',
        'payload' => ['value' => 'never-shown-'.$provider], 'status' => 'active',
    ]);
}

/** @return list<ReadinessRow> */
function oarHqRows(): array
{
    return array_values(array_filter(app(CapabilityReadiness::class)->inventory(), fn (ReadinessRow $r) => $r->targetKind === 'hq'));
}

function oarServeOpenAi(): void
{
    aiModel('openai', 'gpt-5.6-luna', ModelTier::Low);
    aiModel('openai', 'gpt-5.6-terra', ModelTier::Medium);
}

it('gives the model provider row to the provider that serves, and keeps Anthropic when nothing else does', function () {
    expect(array_map(fn (ReadinessRow $r) => $r->provider, oarHqRows()))->toBe([AnthropicProvider::CREDENTIAL_PROVIDER]);

    oarServeOpenAi();

    expect(array_map(fn (ReadinessRow $r) => $r->provider, oarHqRows()))->toBe([OpenAiProvider::CREDENTIAL_PROVIDER])
        ->and(oarHqRows()[0]->state)->toBe(ReadinessRow::CREDENTIAL_MISSING);
});

it('shows a row per provider when more than one serves, so the table does not hide the mix', function () {
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low);
    oarServeOpenAi();

    expect(array_map(fn (ReadinessRow $r) => $r->provider, oarHqRows()))
        ->toBe([AnthropicProvider::CREDENTIAL_PROVIDER, OpenAiProvider::CREDENTIAL_PROVIDER]);
});

it('checks the OpenAI key with one tiny call to its own low model, and tells a billing block from throttling', function (int $status, array $body, string $state, bool $condemned, bool $incident) {
    oarServeOpenAi();
    $key = oarKey(OpenAiProvider::CREDENTIAL_PROVIDER);
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

    $row = app(CapabilityReadiness::class)->verifyModel(oarHqRows()[0]);

    expect($row->state)->toBe($state)
        ->and(app(CredentialHealth::class)->isKnownInvalid($key->refresh()))->toBe($condemned)
        ->and($row->incident())->toBe($incident)
        ->and(json_encode($row->toArray()))->not->toContain('never-shown');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer never-shown-openai_api_key')
            && $body['model'] === 'gpt-5.6-luna'
            && $body['max_output_tokens'] === 16
            && $body['store'] === false
            && $body['reasoning'] === ['effort' => 'none'];
    });
})->with([
    'accepted' => [200, ['id' => 'resp_1', 'status' => 'completed', 'usage' => ['input_tokens' => 9]], ReadinessRow::VERIFIED, false, false],
    'wrong key' => [401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Incorrect API key provided']], ReadinessRow::CREDENTIAL_INVALID, true, true],
    'ip not allowed' => [401, ['error' => ['message' => 'IP not authorized']], ReadinessRow::PROVIDER_UNAUTHORIZED, false, true],
    'region' => [403, ['error' => ['code' => 'unsupported_country_region_territory', 'message' => 'Country, region, or territory not supported']], ReadinessRow::PROVIDER_UNAUTHORIZED, false, true],
    'credit exhausted' => [429, ['error' => ['type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted', 'message' => 'no credits']], ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT, false, true],
    'insufficient quota' => [429, ['error' => ['type' => 'insufficient_quota', 'message' => 'You exceeded your current quota, please check your plan and billing details.']], ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT, false, true],
    'spend limit' => [429, ['error' => ['code' => 'project_spend_limit_exceeded', 'message' => 'limit']], ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT, false, true],
    'throttled' => [429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached']], ReadinessRow::PROVIDER_UNAVAILABLE, false, false],
    'overloaded' => [503, ['error' => ['code' => 'server_is_overloaded', 'message' => 'overloaded']], ReadinessRow::PROVIDER_UNAVAILABLE, false, false],
]);

it('verifies a key before the lever moves traffic to it, using that provider\'s own row', function () {
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low);
    aiModel('openai', 'gpt-5.6-luna', ModelTier::Low)->update(['is_active' => false]);
    oarKey(OpenAiProvider::CREDENTIAL_PROVIDER);
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp_1', 'status' => 'completed'])]);

    $result = app(ProviderCheck::class)->run('provider_check', 'openai');

    expect($result['http'])->toBe(200)
        ->and($result['provider'])->toBe('openai');
    Http::assertSent(fn ($request) => $request->data()['model'] === 'gpt-5.6-luna');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic'));
});

it('sends the Anthropic check only an Anthropic model, whatever else is registered at the low tier', function () {
    aiModel('openai', 'gpt-5.6-luna', ModelTier::Low, priority: 1);
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low, priority: 10);
    oarKey(AnthropicProvider::CREDENTIAL_PROVIDER);
    Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'usage' => ['input_tokens' => 9]])]);

    app(ProviderCheck::class)->run('provider_check', 'anthropic');

    Http::assertSent(fn ($request) => $request->data()['model'] === 'claude-haiku-4-5-20251001');
});

it('refuses to check anything, and spends nothing, when more than one provider serves', function () {
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low);
    oarServeOpenAi();
    oarKey(AnthropicProvider::CREDENTIAL_PROVIDER);
    oarKey(OpenAiProvider::CREDENTIAL_PROVIDER);
    Http::fake();

    $result = app(ProviderCheck::class)->run();

    expect($result['http'])->toBeNull()
        ->and($result['error'])->toContain('more than one model provider serves')
        ->and($result['class'])->toBe(OutcomeClass::ConnectorError->value);
    Http::assertNothingSent();
});

it('has the gate bypass check the provider that serves, with the JSON still opening on the HTTP status', function () {
    oarServeOpenAi();
    oarKey(OpenAiProvider::CREDENTIAL_PROVIDER);
    FeatureFlag::updateOrCreate(['key' => 'ai.runtime', 'environment' => '*'], ['enabled' => false]);
    $this->seed(AgentSeeder::class);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted', 'message' => 'no credits']], 429)]);

    putenv('GATE_ACTION=bypass');
    ob_start();
    try {
        require base_path('../../deploy/gate/toggle.php');
    } finally {
        $out = trim((string) ob_get_clean());
        putenv('GATE_ACTION');
    }

    expect($out)->toStartWith('{"http":429')
        ->and($out)->toContain('"class":"provider_blocked_by_credit"')
        ->and($out)->toContain('"provider":"openai"')
        ->and($out)->not->toContain('never-shown')
        ->and(oarHqRows()[0]->state)->toBe(ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT)
        ->and(oarHqRows()[0]->lastResult['source'])->toBe('gate_bypass');
});
