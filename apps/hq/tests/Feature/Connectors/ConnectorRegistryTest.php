<?php

use App\Ai\Providers\ProviderRegistry;
use App\Commerce\Support\CommerceRegistry;
use App\Connectors\Capabilities;
use App\Connectors\ConnectorOutcome;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Connectors\Legacy\LegacyConnectors;
use App\Erp\ErpRegistry;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeClass;
use App\Gateway\Readiness\CapabilityReadiness;
use App\Gateway\Readiness\ConnectorReadinessRow;
use App\Gateway\Readiness\ReadinessRow;
use App\Models\Brand;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Platform\PlatformRegistry;
use App\Support\Features\Features;
use App\Voice\Providers\GoogleSpeechToText;
use App\Voice\SpeechProviders;
use App\Voice\SpeechToText;
use App\Voice\Transcript;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Http;

/*
 | docs/97 §6 D2 (docs/98): the ConnectorRegistry is the one provider-resolution path for every new
 | adapter. Resolution by capability and scope, priority, fallback, ambiguity refused, a switch that is
 | off, a credential that is not ready, nothing registered as the typed NoConnector; and the readiness
 | view, with the five legacy shapes reported beside it and no existing readiness row changed.
 */

final class ConnectorTestTranscriberA implements SpeechToText
{
    public function key(): string
    {
        return 'test_a';
    }

    public function transcribe(string $audio, string $mime, ?string $languageCode = null): Transcript
    {
        throw new LogicException('never called: resolution builds nothing and calls nothing');
    }
}

final class ConnectorTestTranscriberB implements SpeechToText
{
    public function key(): string
    {
        return 'test_b';
    }

    public function transcribe(string $audio, string $mime, ?string $languageCode = null): Transcript
    {
        throw new LogicException('never called');
    }
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    Http::preventStrayRequests();
});

/** A connector row with sensible defaults; `$over` replaces any field. */
function connectorRow(array $over = []): array
{
    return array_replace([
        'capability' => Capabilities::VOICE_TRANSCRIBE,
        'provider' => 'test_a',
        'adapter' => ConnectorTestTranscriberA::class,
        'transport' => 'direct_api',
        'credentials' => [],
        'priority' => 10,
        'enabled_by' => 'flag:connectors.test_a',
        'fallback' => false,
    ], $over);
}

function connectorRegistry(array $rows): ConnectorRegistry
{
    return new ConnectorRegistry($rows, app(Features::class), app(CredentialVault::class), app(CredentialHealth::class), app(LegacyConnectors::class));
}

function connectorFlag(string $key, bool $on, ?array $rollout = null): void
{
    FeatureFlag::updateOrCreate(['key' => $key, 'environment' => '*'], ['enabled' => $on, 'rollout' => $rollout]);
}

function connectorCredential(string $kind, string $account, array $attributes = []): IntegrationCredential
{
    return IntegrationCredential::create(array_merge([
        'provider' => $kind, 'account_ref' => $account, 'credential_type' => 'token',
        'payload' => ['value' => 'never-shown-'.$account], 'status' => 'active',
    ], $attributes));
}

function connectorTranscribe(?int $businessId = null, ?int $brandId = null): ConnectorRequest
{
    return new ConnectorRequest(Capabilities::VOICE_TRANSCRIBE, $businessId, $brandId);
}

// ── The shipped state ────────────────────────────────────────────────────────────────────────────

it('ships with nothing registered, so every capability resolves to the typed NoConnector', function () {
    $registry = app(ConnectorRegistry::class);

    expect(config('hq-connectors.connectors'))->toBe([])
        ->and($registry->all())->toBe([])
        ->and(app(ConnectorRegistry::class))->toBe($registry);

    foreach (array_keys(Capabilities::CONTRACTS) as $capability) {
        $resolution = $registry->resolve(new ConnectorRequest($capability));
        expect($resolution->outcome)->toBe(ConnectorOutcome::NoConnector)
            ->and($resolution->connector)->toBeNull()
            ->and($resolution->toArray()['reason'])->toContain('No connector is registered');
    }
});

it('refuses an unknown or reserved capability as data, not as an exception', function () {
    $registry = connectorRegistry([]);

    expect($registry->resolve(new ConnectorRequest('send.anything'))->outcome)->toBe(ConnectorOutcome::UnknownCapability)
        ->and($registry->resolve(new ConnectorRequest(Capabilities::MEDIA_IMAGE_GENERATE))->outcome)->toBe(ConnectorOutcome::UnknownCapability)
        ->and($registry->resolve(new ConnectorRequest(Capabilities::MEDIA_IMAGE_GENERATE))->reason)->toContain('reserved');
});

// ── Resolution ───────────────────────────────────────────────────────────────────────────────────

it('resolves the one enabled, credential-ready connector, and returns the credential ids for the vault', function () {
    connectorFlag('connectors.test_a', true);
    $credential = connectorCredential('test_stt_key', 'hq');

    $resolution = connectorRegistry([connectorRow(['credentials' => [['kind' => 'test_stt_key', 'account_ref_from' => '=hq']]])])
        ->resolve(connectorTranscribe());

    expect($resolution->outcome)->toBe(ConnectorOutcome::Resolved)
        ->and($resolution->connector->provider)->toBe('test_a')
        ->and($resolution->credentials)->toBe([['provider' => 'test_stt_key', 'account_ref' => 'hq', 'credential_id' => $credential->id]])
        ->and(json_encode($resolution->toArray()))->not->toContain('never-shown');
});

it('picks the better priority, lower first, whatever order the config lists them in', function () {
    connectorFlag('connectors.test_a', true);
    connectorFlag('connectors.test_b', true);

    $resolution = connectorRegistry([
        connectorRow(['priority' => 20]),
        connectorRow(['provider' => 'test_b', 'adapter' => ConnectorTestTranscriberB::class, 'enabled_by' => 'flag:connectors.test_b', 'priority' => 5]),
    ])->resolve(connectorTranscribe());

    expect($resolution->outcome)->toBe(ConnectorOutcome::Resolved)
        ->and($resolution->connector->provider)->toBe('test_b');
});

it('refuses two ready connectors at the same priority rather than choosing one', function () {
    connectorFlag('connectors.test_a', true);
    connectorFlag('connectors.test_b', true);

    $resolution = connectorRegistry([
        connectorRow(),
        connectorRow(['provider' => 'test_b', 'adapter' => ConnectorTestTranscriberB::class, 'enabled_by' => 'flag:connectors.test_b']),
    ])->resolve(connectorTranscribe());

    expect($resolution->outcome)->toBe(ConnectorOutcome::Ambiguous)
        ->and($resolution->connector)->toBeNull()
        ->and($resolution->reason)->toContain('test_a, test_b');
});

it('does not call a tie below the best ready priority ambiguous', function () {
    connectorFlag('connectors.test_a', true);
    connectorFlag('connectors.test_b', true);
    connectorFlag('connectors.test_c', true);

    $resolution = connectorRegistry([
        connectorRow(['priority' => 1]),
        connectorRow(['provider' => 'test_b', 'adapter' => ConnectorTestTranscriberB::class, 'enabled_by' => 'flag:connectors.test_b', 'priority' => 9]),
        connectorRow(['provider' => 'test_c', 'adapter' => ConnectorTestTranscriberB::class, 'enabled_by' => 'flag:connectors.test_c', 'priority' => 9]),
    ])->resolve(connectorTranscribe());

    expect($resolution->connector->provider)->toBe('test_a');
});

it('uses a fallback only when no primary is ready, and says it is one', function () {
    connectorFlag('connectors.test_b', true);

    $rows = [
        // A fallback with the BETTER priority still waits behind a ready primary.
        connectorRow(['provider' => 'test_b', 'adapter' => ConnectorTestTranscriberB::class, 'enabled_by' => 'flag:connectors.test_b', 'priority' => 1, 'fallback' => true]),
        connectorRow(['priority' => 50]),
    ];

    // Primary off: the fallback serves, and the primary's reason travels with the answer.
    $resolution = connectorRegistry($rows)->resolve(connectorTranscribe());
    expect($resolution->outcome)->toBe(ConnectorOutcome::Resolved)
        ->and($resolution->connector->provider)->toBe('test_b')
        ->and($resolution->toArray()['fallback'])->toBeTrue()
        ->and($resolution->rejected)->toBe([['provider' => 'test_a', 'reason' => 'switched off (flag:connectors.test_a)']]);

    // Primary on: it wins over the better-numbered fallback.
    connectorFlag('connectors.test_a', true);
    expect(connectorRegistry($rows)->resolve(connectorTranscribe())->connector->provider)->toBe('test_a');
});

it('treats a connector whose flag is off, missing or rolled out to another business as unavailable', function () {
    $laladia = Business::where('slug', 'laladia')->sole();
    $bionic = Business::where('slug', 'bionic')->sole();
    $registry = connectorRegistry([connectorRow()]);

    // No flag row at all: off, never "on by default".
    $off = $registry->resolve(connectorTranscribe());
    expect($off->outcome)->toBe(ConnectorOutcome::Unavailable)
        ->and($off->rejected[0]['reason'])->toContain('switched off');

    connectorFlag('connectors.test_a', true, ['businesses' => [$laladia->id]]);
    expect($registry->resolve(connectorTranscribe($laladia->id))->outcome)->toBe(ConnectorOutcome::Resolved)
        ->and($registry->resolve(connectorTranscribe($bionic->id))->outcome)->toBe(ConnectorOutcome::Unavailable);
});

it('reads a config switch only when it is exactly true', function () {
    config(['hq.test_connector_switch' => 'yes']);
    $registry = connectorRegistry([connectorRow(['enabled_by' => 'config:hq.test_connector_switch'])]);
    expect($registry->resolve(connectorTranscribe())->outcome)->toBe(ConnectorOutcome::Unavailable);

    config(['hq.test_connector_switch' => true]);
    expect($registry->resolve(connectorTranscribe())->outcome)->toBe(ConnectorOutcome::Resolved);
});

it('is not ready while a required credential is missing, revoked, valueless, expired or known rejected', function (Closure $arrange, string $why) {
    connectorFlag('connectors.test_a', true);
    $arrange();

    $resolution = connectorRegistry([connectorRow(['credentials' => [['kind' => 'test_stt_key', 'account_ref_from' => '=hq']]])])
        ->resolve(connectorTranscribe());

    expect($resolution->outcome)->toBe(ConnectorOutcome::Unavailable)
        ->and($resolution->rejected[0]['reason'])->toContain($why)
        ->and($resolution->credentials)->toBe([]);
})->with([
    'missing' => [fn () => null, 'missing or not active'],
    'revoked' => [fn () => connectorCredential('test_stt_key', 'hq', ['status' => 'revoked']), 'missing or not active'],
    'no value' => [fn () => connectorCredential('test_stt_key', 'hq', ['payload' => null]), 'no stored value'],
    'expired' => [fn () => connectorCredential('test_stt_key', 'hq', ['expires_at' => now()->subDay()]), 'expired'],
    'known rejected' => [fn () => app(CredentialHealth::class)->recordFailed(connectorCredential('test_stt_key', 'hq'), OutcomeClass::CredentialInvalid, '401', 'test'), 'known to be rejected'],
]);

it('lets an optional credential be absent, and opens it only when it is there', function () {
    connectorFlag('connectors.test_a', true);
    $registry = connectorRegistry([connectorRow(['credentials' => [['kind' => 'test_stt_key', 'account_ref_from' => '=hq', 'optional' => true]]])]);

    expect($registry->resolve(connectorTranscribe())->credentials)->toBe([]);

    $credential = connectorCredential('test_stt_key', 'hq');
    expect($registry->resolve(connectorTranscribe())->credentials[0]['credential_id'])->toBe($credential->id);
});

it('looks a business-scoped credential up under the business, and a brand implies its business', function () {
    connectorFlag('connectors.test_a', true);
    $laladia = Business::where('slug', 'laladia')->sole();
    $brand = Brand::where('slug', 'laladia')->sole();
    connectorCredential('test_stt_key', 'laladia');
    $registry = connectorRegistry([connectorRow(['credentials' => [['kind' => 'test_stt_key', 'account_ref_from' => 'business.slug']]])]);

    // No business named: the account reference resolves to nothing, which is not ready.
    expect($registry->resolve(connectorTranscribe())->rejected[0]['reason'])->toContain('resolved to nothing')
        ->and($registry->resolve(connectorTranscribe($laladia->id))->outcome)->toBe(ConnectorOutcome::Resolved)
        ->and($registry->resolve(connectorTranscribe(brandId: $brand->id))->credentials[0]['account_ref'])->toBe('laladia')
        ->and($registry->resolve(connectorTranscribe(Business::where('slug', 'bionic')->value('id')))->outcome)->toBe(ConnectorOutcome::Unavailable);
});

it('refuses a request whose brand belongs to another business, and serves only the businesses a connector names', function () {
    connectorFlag('connectors.test_a', true);
    $bionic = Business::where('slug', 'bionic')->sole();
    $laladiaBrand = Brand::where('slug', 'laladia')->sole();
    $registry = connectorRegistry([connectorRow(['businesses' => ['laladia']])]);

    expect($registry->resolve(connectorTranscribe($bionic->id, $laladiaBrand->id))->rejected[0]['reason'])->toContain('does not belong to business')
        ->and($registry->resolve(connectorTranscribe($bionic->id))->rejected[0]['reason'])->toContain('does not serve business [bionic]')
        ->and($registry->resolve(connectorTranscribe())->rejected[0]['reason'])->toContain('names none')
        ->and($registry->resolve(connectorTranscribe(brandId: $laladiaBrand->id))->outcome)->toBe(ConnectorOutcome::Resolved);
});

it('builds the resolved adapter through the container, and nothing for a refusal', function () {
    connectorFlag('connectors.test_a', true);
    $registry = connectorRegistry([connectorRow()]);

    expect($registry->adapter($registry->resolve(connectorTranscribe())))->toBeInstanceOf(ConnectorTestTranscriberA::class);

    $registry->adapter(connectorRegistry([])->resolve(connectorTranscribe()));
})->throws(InvalidArgumentException::class, 'did not resolve (no_connector)');

// ── Loading ──────────────────────────────────────────────────────────────────────────────────────

it('refuses a malformed connector row at load instead of skipping it', function (array $over, string $message) {
    $this->expectExceptionMessage($message);
    connectorRegistry([connectorRow($over)]);
})->throws(InvalidArgumentException::class)->with([
    'unknown capability' => [['capability' => 'voice.anything'], 'not a capability'],
    'reserved capability' => [['capability' => Capabilities::MEDIA_IMAGE_GENERATE], 'reserved'],
    'adapter missing' => [['adapter' => 'App\\Nope\\Adapter'], 'does not exist'],
    'adapter off contract' => [['adapter' => CommerceRegistry::class], 'does not implement'],
    'bad transport' => [['transport' => 'carrier_pigeon'], 'transport'],
    'always on' => [['enabled_by' => 'always'], 'enabled_by'],
    'no priority' => [['priority' => '10'], 'priority'],
    'bad credential source' => [['credentials' => [['kind' => 'k', 'account_ref_from' => 'args.store_slug']]], 'account_ref_from'],
    'empty businesses' => [['businesses' => []], 'businesses'],
]);

it('names the reason a row was refused', function () {
    try {
        connectorRegistry([connectorRow(['adapter' => CommerceRegistry::class])]);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('connectors[0]')->toContain(SpeechToText::class);

        return;
    }

    $this->fail('the row was accepted');
});

it('refuses the same provider registered twice for one capability', function () {
    connectorRegistry([connectorRow(), connectorRow(['priority' => 99])]);
})->throws(InvalidArgumentException::class, 'twice');

// ── Readiness ────────────────────────────────────────────────────────────────────────────────────

it('adds the connector view beside the readiness inventory without changing a single existing row', function () {
    $readiness = app(CapabilityReadiness::class);
    $before = array_map(fn (ReadinessRow $r) => (array) $r, $readiness->inventory());

    connectorFlag('connectors.test_a', true);
    app()->instance(ConnectorRegistry::class, connectorRegistry([connectorRow()]));
    $after = app(CapabilityReadiness::class);

    expect(array_map(fn (ReadinessRow $r) => (array) $r, $after->inventory()))->toBe($before)
        ->and(collect($after->connectors())->where('origin', ConnectorReadinessRow::ORIGIN_REGISTRY)->count())->toBe(1);
});

it('reports a registered connector by capability, provider, transport and state', function () {
    $rows = [
        connectorRow(['credentials' => [['kind' => 'test_stt_key', 'account_ref_from' => '=hq']]]),
        connectorRow(['provider' => 'test_b', 'adapter' => ConnectorTestTranscriberB::class, 'enabled_by' => 'flag:connectors.test_b', 'transport' => 'n8n',
            'credentials' => [['kind' => 'test_stt_key', 'account_ref_from' => 'business.slug']]]),
        connectorRow(['provider' => 'test_c', 'adapter' => ConnectorTestTranscriberB::class, 'enabled_by' => 'flag:connectors.test_c', 'transport' => 'internal', 'priority' => 30]),
    ];
    connectorFlag('connectors.test_b', true);
    connectorFlag('connectors.test_c', true);
    app()->instance(ConnectorRegistry::class, connectorRegistry($rows));

    $state = fn () => collect(app(CapabilityReadiness::class)->connectors())
        ->where('origin', ConnectorReadinessRow::ORIGIN_REGISTRY)
        ->mapWithKeys(fn (ConnectorReadinessRow $r) => [$r->provider => [$r->capability, $r->transport, $r->state]])->all();

    expect($state())->toBe([
        'test_a' => ['voice.transcribe', 'direct_api', ReadinessRow::DISABLED],
        'test_b' => ['voice.transcribe', 'n8n', ConnectorReadinessRow::SCOPE_DEPENDENT],
        'test_c' => ['voice.transcribe', 'internal', ConnectorReadinessRow::READY],
    ]);

    connectorFlag('connectors.test_a', true);
    expect($state()['test_a'][2])->toBe(ReadinessRow::CREDENTIAL_MISSING);

    $credential = connectorCredential('test_stt_key', 'hq');
    expect($state()['test_a'][2])->toBe(ReadinessRow::CREDENTIAL_UNVERIFIED);

    app(CredentialHealth::class)->recordAccepted($credential, 'test');
    expect($state()['test_a'][2])->toBe(ReadinessRow::VERIFIED);
});

it('reports the legacy providers as legacy rows, read-only, naming the shape that resolves each', function () {
    $rows = collect(app(CapabilityReadiness::class)->connectors());
    $legacy = $rows->where('origin', ConnectorReadinessRow::ORIGIN_LEGACY);

    // Shops: one row per adapter key CommerceRegistry supports. ERP: one. Models: one per driver.
    expect($legacy->where('capability', Capabilities::COMMERCE_SHOP)->pluck('provider')->all())->toBe(CommerceRegistry::SUPPORTED_ADAPTERS)
        ->and($legacy->where('capability', Capabilities::ERP_READ)->sole()->shape)->toBe(ErpRegistry::class)
        ->and($legacy->where('capability', Capabilities::AI_COMPLETE)->pluck('provider')->all())->toBe(app(ProviderRegistry::class)->keys())
        // The speech slots and the platform registry are empty, and report nothing.
        ->and($legacy->whereIn('capability', [Capabilities::VOICE_TRANSCRIBE, Capabilities::VOICE_SYNTHESIZE])->count())->toBe(0)
        ->and($legacy->every(fn (ConnectorReadinessRow $r) => $r->state === ConnectorReadinessRow::LEGACY))->toBeTrue()
        ->and($rows->where('origin', ConnectorReadinessRow::ORIGIN_REGISTRY)->count())->toBe(0);

    // Reporting changed nothing: the shapes are as empty as they shipped.
    expect(app(SpeechProviders::class)->isEmpty())->toBeTrue()
        ->and(json_encode($rows->map->toArray()->all()))->not->toContain('never-shown');
});

it('reports a registered speech slot as a legacy voice row with its credential kind', function () {
    $providers = new SpeechProviders;
    $providers->registerSpeechToText(new GoogleSpeechToText(app(CredentialVault::class)));
    $legacy = new LegacyConnectors(app(PlatformRegistry::class), app(ProviderRegistry::class), $providers);

    $row = collect($legacy->speech())->sole();

    expect($row->capability)->toBe(Capabilities::VOICE_TRANSCRIBE)
        ->and($row->credentials)->toBe([GoogleSpeechToText::CREDENTIAL_PROVIDER.' <- =hq'])
        ->and($row->shape)->toBe(SpeechProviders::class);
});
