<?php

use App\Ai\ModelTier;
use App\Ai\Providers\AnthropicProvider;
use App\Commerce\Support\CommerceRegistry;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\OutcomeClass;
use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ReadinessRow;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\Store;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 | Capability readiness is its own question (ADR-043, docs/74 C.3).
 |
 | Gate 8 found, by accident and on the first live shop call, a token that had never been accepted
 | and two stores with nothing. This is the table that would have said so beforehand: per store,
 | per ERP business and for the model provider, whether HQ could execute and why, learned from the
 | probes and from every live call, and never holding a value. An incident is a supported target
 | that cannot execute; a store with no connector is not one.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
});

function credentialFor(string $provider, string $account, array $attributes = []): IntegrationCredential
{
    return IntegrationCredential::create([
        'provider' => $provider, 'account_ref' => $account, 'credential_type' => 'token',
        'payload' => ['value' => 'never-shown-'.$account], 'status' => 'active',
    ] + $attributes);
}

function readinessRow(string $target, string $provider): ReadinessRow
{
    foreach (app(CapabilityReadiness::class)->inventory() as $row) {
        if ($row->target === $target && $row->provider === $provider) {
            return $row;
        }
    }

    throw new RuntimeException("no readiness row for {$target}/{$provider}");
}

function unsupportedStore(): Store
{
    $store = Store::query()->where('is_active', true)->whereNotIn('adapter', CommerceRegistry::SUPPORTED_ADAPTERS)->first();

    return $store ?? Store::create([
        'business_id' => Business::where('slug', 'econic')->value('id') ?? Business::first()->id,
        'slug' => 'econic-store', 'name' => 'Econic Store', 'adapter' => 'woocommerce', 'base_url' => 'https://econic.example', 'is_active' => true,
    ]);
}

it('lists a supported store with no token as credential_missing and an incident, and a store with no connector as unsupported and not one', function () {
    $missing = readinessRow('laladia-com', CommerceRegistry::ADMIN_PROVIDER);
    expect($missing->supported)->toBeTrue()
        ->and($missing->state)->toBe(ReadinessRow::CREDENTIAL_MISSING)
        ->and($missing->wouldAttempt())->toBeFalse()
        ->and($missing->incident())->toBeTrue()
        ->and($missing->reason)->toContain('no active credential stored');

    $store = unsupportedStore();
    $unsupported = readinessRow($store->slug, CommerceRegistry::ADMIN_PROVIDER);
    expect($unsupported->supported)->toBeFalse()
        ->and($unsupported->state)->toBe(ReadinessRow::UNSUPPORTED)
        ->and($unsupported->incident())->toBeFalse()
        ->and($unsupported->reason)->toContain('unsupported by design');
});

it('is credential_unverified with a stored token nothing has judged, verified once a probe accepts it, and never carries the value', function () {
    $credential = credentialFor(CommerceRegistry::ADMIN_PROVIDER, 'laladia-com');
    credentialFor(CommerceRegistry::ASSISTANT_PROVIDER, 'laladia-com');

    $before = readinessRow('laladia-com', CommerceRegistry::ADMIN_PROVIDER);
    expect($before->state)->toBe(ReadinessRow::CREDENTIAL_UNVERIFIED)->and($before->wouldAttempt())->toBeTrue()
        ->and($before->verified())->toBeFalse()->and($before->incident())->toBeFalse();

    Http::fake(['laladia.com/*' => Http::response(['success' => true, 'data' => []], 200)]);
    $after = app(CapabilityReadiness::class)->verify($before);

    expect($after->state)->toBe(ReadinessRow::VERIFIED)
        ->and($after->verified())->toBeTrue()
        ->and($after->lastResult['ok'])->toBeTrue()
        ->and($after->lastResult['source'])->toBe('health_check')
        ->and($after->lastVerifiedAt)->not->toBeNull();
    expect(json_encode($after->toArray()))->not->toContain('never-shown');
    expect(json_encode($credential->refresh()->meta))->not->toContain('never-shown');
});

it('marks a token the shop rejects as credential_invalid, an incident, with the time and source on the row', function () {
    credentialFor(CommerceRegistry::ADMIN_PROVIDER, 'laladia-com');
    Http::fake([
        'laladia.com/api/v1/admin/*' => Http::response(['message' => 'Unauthenticated.'], 401),
        'laladia.com/*' => Http::response(['success' => true, 'data' => []], 200),
    ]);

    $row = app(CapabilityReadiness::class)->verify(readinessRow('laladia-com', CommerceRegistry::ADMIN_PROVIDER));

    expect($row->state)->toBe(ReadinessRow::CREDENTIAL_INVALID)
        ->and($row->incident())->toBeTrue()
        ->and($row->reason)->toContain('rejected by the provider')->toContain('health_check')->toContain('Credentials screen')
        ->and($row->lastResult['class'])->toBe(OutcomeClass::CredentialInvalid->value);
});

it('does not condemn a token because the shop was down, and says so on the row', function () {
    $credential = credentialFor(CommerceRegistry::ADMIN_PROVIDER, 'laladia-com');
    Http::fake(['laladia.com/*' => Http::response('down', 503)]);

    $row = app(CapabilityReadiness::class)->verify(readinessRow('laladia-com', CommerceRegistry::ADMIN_PROVIDER));

    expect($row->state)->toBe(ReadinessRow::PROVIDER_UNAVAILABLE)
        ->and($row->wouldAttempt())->toBeTrue()
        ->and($row->verified())->toBeFalse()
        ->and($row->incident())->toBeFalse()
        ->and($row->reason)->toContain('provider_unavailable')->toContain('says nothing about the credential');
    expect(app(CredentialHealth::class)->isKnownInvalid($credential->refresh()))->toBeFalse();
});

it('exits non-zero from hq:credentials:health while a supported target cannot execute, and zero when the only gaps are unsupported by design', function () {
    unsupportedStore();

    $this->artisan('hq:credentials:health')
        ->expectsOutputToContain('incident')
        ->assertExitCode(1);

    foreach (Store::query()->where('is_active', true)->whereIn('adapter', CommerceRegistry::SUPPORTED_ADAPTERS)->get() as $store) {
        credentialFor(CommerceRegistry::ADMIN_PROVIDER, $store->slug);
        credentialFor(CommerceRegistry::ASSISTANT_PROVIDER, $store->slug);
    }
    if (IntegrationCredential::where('provider', AnthropicProvider::CREDENTIAL_PROVIDER)->where('status', 'active')->doesntExist()) {
        credentialFor(AnthropicProvider::CREDENTIAL_PROVIDER, AnthropicProvider::ACCOUNT_REF);
    }

    $this->artisan('hq:credentials:health')
        ->expectsOutputToContain('0 incidents')
        ->assertExitCode(0);

    $this->artisan('hq:credentials:health --json')->assertExitCode(0);
});

/*
 | docs/77 E.1: the model provider's own check, and the states ChatGPT listed on 16 September.
 | Bad key, no credit, no permission, provider down and network failure are five different records,
 | and only the first condemns the credential.
 */

function modelRow(): ReadinessRow
{
    return readinessRow('hq', AnthropicProvider::CREDENTIAL_PROVIDER);
}

function anthropicKey(): IntegrationCredential
{
    IntegrationCredential::where('provider', AnthropicProvider::CREDENTIAL_PROVIDER)->update(['status' => 'revoked']);

    return credentialFor(AnthropicProvider::CREDENTIAL_PROVIDER, AnthropicProvider::ACCOUNT_REF);
}

it('records a credit refusal as provider_blocked_by_credit: an incident, never an invalid key', function () {
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low);
    $key = anthropicKey();
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
        'message' => 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.']], 400)]);

    expect(modelRow()->state)->toBe(ReadinessRow::CREDENTIAL_UNVERIFIED);

    $row = app(CapabilityReadiness::class)->verifyModel(modelRow());

    expect($row->state)->toBe(ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT)
        ->and($row->incident())->toBeTrue()
        ->and($row->verified())->toBeFalse()
        ->and($row->wouldAttempt())->toBeTrue()
        ->and($row->reason)->toContain('no credit')->toContain('not judged')
        ->and($row->lastResult['class'])->toBe(OutcomeClass::ProviderBlockedByCredit->value)
        ->and($row->lastResult['message'])->toContain('HTTP 400')
        ->and($row->lastResult['source'])->toBe('provider_check')
        ->and(app(CredentialHealth::class)->isKnownInvalid($key->refresh()))->toBeFalse();
    expect(json_encode($row->toArray()))->not->toContain('never-shown');
});

it('tells a wrong key, a missing permission, a provider outage and an unusable answer apart', function (int $status, array $body, string $state, bool $condemned, bool $incident) {
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low);
    $key = anthropicKey();
    Http::fake(['api.anthropic.com/*' => Http::response($body, $status)]);

    $row = app(CapabilityReadiness::class)->verifyModel(modelRow());

    expect($row->state)->toBe($state)
        ->and(app(CredentialHealth::class)->isKnownInvalid($key->refresh()))->toBe($condemned)
        ->and($row->incident())->toBe($incident);
})->with([
    'accepted' => [200, ['id' => 'msg_1', 'usage' => ['input_tokens' => 14]], ReadinessRow::VERIFIED, false, false],
    'wrong key' => [401, ['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], ReadinessRow::CREDENTIAL_INVALID, true, true],
    'no permission' => [403, ['error' => ['type' => 'permission_error', 'message' => 'not allowed']], ReadinessRow::PROVIDER_UNAUTHORIZED, false, true],
    'rate limited' => [429, ['error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], ReadinessRow::PROVIDER_UNAVAILABLE, false, false],
    'overloaded' => [529, ['error' => ['type' => 'overloaded_error', 'message' => 'overloaded']], ReadinessRow::PROVIDER_UNAVAILABLE, false, false],
    'bad request' => [400, ['error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens is wrong']], ReadinessRow::CONNECTOR_ERROR, false, false],
]);

it('records a network failure without judging the key, and spends nothing unless asked', function () {
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low);
    $key = anthropicKey();

    Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('could not resolve host')]);
    $this->artisan('hq:credentials:health --verify')->run();
    Http::assertNothingSent();

    $this->artisan('hq:credentials:health --verify-model')->expectsOutputToContain('would be attempted')->run();
    $row = modelRow();
    expect($row->state)->toBe(ReadinessRow::PROVIDER_UNAVAILABLE)
        ->and($row->lastResult['class'])->toBe(OutcomeClass::NetworkError->value)
        ->and(app(CredentialHealth::class)->isKnownInvalid($key->refresh()))->toBeFalse();
});

it('reports would-attempt and verified as two separate facts on the screen payload', function () {
    credentialFor(CommerceRegistry::ADMIN_PROVIDER, 'laladia-com');

    $array = readinessRow('laladia-com', CommerceRegistry::ADMIN_PROVIDER)->toArray();

    expect($array)->toHaveKeys(['would_attempt', 'verified', 'incident'])
        ->and($array)->not->toHaveKey('executable')
        ->and($array['would_attempt'])->toBeTrue()
        ->and($array['verified'])->toBeFalse();
});

it('has the gate runner record its bypass call on the credential, with the JSON still opening on the HTTP status', function () {
    aiModel('anthropic', 'claude-haiku-4-5-20251001', ModelTier::Low);
    anthropicKey();
    FeatureFlag::updateOrCreate(['key' => 'ai.runtime', 'environment' => '*'], ['enabled' => false]);
    $this->seed(AgentSeeder::class);
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'Your credit balance is too low to access the Anthropic API.']], 400)]);

    putenv('GATE_ACTION=bypass');
    ob_start();
    try {
        require base_path('../../deploy/gate/toggle.php');
    } finally {
        $out = trim((string) ob_get_clean());
        putenv('GATE_ACTION');
    }

    expect($out)->toStartWith('{"http":400')
        ->and($out)->toContain('"class":"provider_blocked_by_credit"')
        ->and($out)->not->toContain('never-shown')
        ->and(modelRow()->state)->toBe(ReadinessRow::PROVIDER_BLOCKED_BY_CREDIT)
        ->and(modelRow()->lastResult['source'])->toBe('gate_bypass');
});
