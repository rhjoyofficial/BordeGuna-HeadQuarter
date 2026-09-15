<?php

use App\Ai\Providers\AnthropicProvider;
use App\Commerce\Support\CommerceRegistry;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\OutcomeClass;
use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ReadinessRow;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\Store;
use Database\Seeders\DomainSeeder;
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
        ->and($missing->executable())->toBeFalse()
        ->and($missing->incident())->toBeTrue()
        ->and($missing->reason)->toContain('no active credential stored');

    $store = unsupportedStore();
    $unsupported = readinessRow($store->slug, CommerceRegistry::ADMIN_PROVIDER);
    expect($unsupported->supported)->toBeFalse()
        ->and($unsupported->state)->toBe(ReadinessRow::UNSUPPORTED)
        ->and($unsupported->incident())->toBeFalse()
        ->and($unsupported->reason)->toContain('unsupported by design');
});

it('is unverified with a stored token nothing has judged, ready once a probe accepts it, and never carries the value', function () {
    $credential = credentialFor(CommerceRegistry::ADMIN_PROVIDER, 'laladia-com');
    credentialFor(CommerceRegistry::ASSISTANT_PROVIDER, 'laladia-com');

    $before = readinessRow('laladia-com', CommerceRegistry::ADMIN_PROVIDER);
    expect($before->state)->toBe(ReadinessRow::UNVERIFIED)->and($before->executable())->toBeTrue()->and($before->incident())->toBeFalse();

    Http::fake(['laladia.com/*' => Http::response(['success' => true, 'data' => []], 200)]);
    $after = app(CapabilityReadiness::class)->verify($before);

    expect($after->state)->toBe(ReadinessRow::READY)
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

    expect($row->state)->toBe(ReadinessRow::UNVERIFIED)
        ->and($row->executable())->toBeTrue()
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
