<?php

use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\ModelTier;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\ModelProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ProviderRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Models\AiModel;
use App\Models\AiRun;
use App\Models\IntegrationCredential;
use App\Platform\PlatformRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Tests\Support\Ai\FixtureProvider;

/*
 | M4 safety posture.
 |
 | ⚠ These tests exist to make a REGRESSION loud. Each asserts something that must stay true while
 | HQ can call a model: no model id outside the driver layer, no credential read outside the vault,
 | no tool made agent-executable, and no model able to reach anything but text.
 */

it('mentions no model id anywhere in app/ outside the driver layer', function () {
    // ADR-019. A model id in a service class is a deploy every time a price changes or a model is
    // retired, and it takes the choice away from the one component able to account for it.
    $allowed = [
        'app/Ai/Providers/AnthropicProvider.php',   // speaks the id to the network
        'app/Ai/Providers/OpenAiProvider.php',      // the same, for OpenAI (docs/81 C.7)
    ];

    // Deliberately loose: any `claude-*`, `gpt-*`, `gemini-*` or `claude.*sonnet` style literal.
    $pattern = '/[\'"](?:claude|gpt|gemini|mistral|llama|o[134])[-_][a-z0-9][a-z0-9.\-]{2,}[\'"]/i';

    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $m)) {
            $offenders[] = "{$relative}: {$m[0]}";
        }
    }

    expect($offenders)->toBe([], 'A model id may only appear in the driver layer. Move it to a model_tiers row.');
});

it('keeps the driver layer as the only place that names a provider endpoint', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        if (str_starts_with($relative, 'app/Ai/Providers/')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        // Every provider endpoint HQ knows, Anthropic's and OpenAI's (docs/81 C.7).
        if (str_contains($source, 'api.anthropic.com') || str_contains($source, 'api.openai.com')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('never reads a provider credential outside the vault', function () {
    // Mirrors the M2 guard: the vault is the only decryption point, and a model provider is not an
    // exception to that just because it is new.
    $source = file_get_contents(app_path('Ai/Providers/AnthropicProvider.php'));

    expect($source)->toContain('$this->vault->secret(')
        ->and($source)->not->toContain('IntegrationCredential')
        ->and($source)->not->toContain('->payload')
        ->and($source)->not->toContain("env('");
});

it('lets an agent execute only reads below R3, and nothing that reaches a person', function () {
    // ⚠⚠ REWRITTEN IN M5, and the old assertion is worth recording because it was correct until it
    // was not. Through M4 and Phase 2.5 this read `every tool is non agent-executable`, which was a
    // true statement about a release with no agent in it. M5 is the milestone that ends it: the two
    // agents hold nine read tools, so an assertion that no tool is agent-executable now only says
    // "M5 has not shipped".
    //
    // What replaces it is stronger and does not expire. The property that must hold for the life of
    // this system is not that agents have nothing, it is WHAT they may never have: anything that
    // writes, anything that reaches R3, anything a person outside the group can see, and anything
    // that needs consent. Widening autonomy tool by tool is a legitimate future change; crossing one
    // of these four lines is not, and this fails the build if somebody does.
    $descriptors = collect(app(ToolRegistry::class)->keys())
        ->map(fn (string $key) => app(ToolRegistry::class)->get($key)->descriptor());

    // ⚠ Phase 3 stream 3 (docs/72 §B) crossed the four lines ONCE, deliberately, with
    // `messaging.send_reply`, and the crossing is named here rather than the lines being moved: it
    // is a write, R3, customer-facing, on an outbound channel, consent-gated. Every one of those
    // is what makes the gateway check consent and the runner record a proposal instead of running
    // it at A0; and no messaging provider is registered, so it cannot execute for anyone. Any
    // second tool crossing a line still fails the build.
    $crossing = $descriptors->filter(fn ($d) => $d->agentExecutable && $d->customerFacing)->map(fn ($d) => $d->key)->values()->all();
    expect($crossing)->toBe(['messaging.send_reply']);
    $reply = $descriptors->firstWhere('key', 'messaging.send_reply');
    expect($reply->consentRequired)->not->toBeNull()
        ->and($reply->channel->isOutbound())->toBeTrue()
        ->and(app(PlatformRegistry::class)->platforms())->toBe([]);

    foreach ($descriptors->filter(fn ($d) => $d->agentExecutable && ! $d->customerFacing) as $d) {
        expect($d->readWrite)->toBe(ReadWrite::Read, "[{$d->key}] is agent-executable and writes");
        expect($d->riskTier->atLeast(RiskTier::R3))->toBeFalse("[{$d->key}] is agent-executable at {$d->riskTier->value}");
        expect($d->customerFacing)->toBeFalse("[{$d->key}] is agent-executable and customer-facing");
        expect($d->channel->isOutbound())->toBeFalse("[{$d->key}] is agent-executable on an outbound channel");
        expect($d->consentRequired)->toBeNull("[{$d->key}] is agent-executable and requires consent");
    }

    $highRisk = $descriptors->filter(fn ($d) => in_array($d->riskTier->value, ['r4', 'r5'], true))
        ->map(fn ($d) => $d->key)->values()->all();

    // docs/83 C.3: the second R4 is `commerce.take_order`, named rather than the line moved. It is not
    // agent-executable (so the loops above never reach it) and admits no store, so the gateway
    // refuses every call.
    expect($highRisk)->toBe(['demo.request_approval', 'commerce.take_order']);
    $take = $descriptors->firstWhere('key', 'commerce.take_order');
    expect($take->agentExecutable)->toBeFalse()
        ->and($take->supportedStores)->toBe([]);
});

it('gives the model runtime no way to reach a tool, a shop, the ERP or the database', function () {
    // The runtime's dependencies ARE its capabilities. If a tool registry, an ERP connector or a
    // query builder ever appears here, the model has stopped being a proposer.
    $source = file_get_contents(app_path('Ai/ModelRuntime.php'));

    foreach (['ToolRegistry', 'ToolGateway', 'ErpConnector', 'ErpRegistry', 'CommerceRegistry', 'DB::', 'Artisan::', 'shell_exec', 'proc_open'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('sends the api key as a header and never in the body, url or an error', function () {
    $model = AiModel::create([
        'tier' => 'low', 'provider' => 'anthropic', 'model_id' => 'test-model-id', 'display_name' => 'Test',
        'context_window' => 1000, 'max_output_tokens' => 100, 'capabilities' => [],
        'input_price_per_mtok' => '1.0000', 'output_price_per_mtok' => '1.0000',
    ]);

    IntegrationCredential::create([
        'provider' => AnthropicProvider::CREDENTIAL_PROVIDER,
        'account_ref' => AnthropicProvider::ACCOUNT_REF,
        'credential_type' => 'api_key',
        'payload' => ['value' => 'sk-test-SECRETVALUE-do-not-leak'],
        'status' => 'active',
    ]);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'hello']],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 3, 'cache_creation_input_tokens' => 4],
        'stop_reason' => 'end_turn',
    ])]);

    $provider = app(AnthropicProvider::class);
    $response = app(CredentialVault::class)->openForAccounts(
        [$provider->credentialPair()],
        fn () => $provider->complete(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']])),
    );

    expect($response->text)->toBe('hello')
        // Cache reads AND cache writes are both reported OUTSIDE `input_tokens`, so both are added
        // in to make each a true subset of `inputTokens`: 10 fresh + 3 read + 4 written.
        ->and($response->inputTokens)->toBe(17)
        ->and($response->cachedTokens)->toBe(3)
        ->and($response->cacheWriteTokens)->toBe(4)
        ->and($response->outputTokens)->toBe(5);

    Http::assertSent(function ($request) {
        $bodyAndUrl = $request->url().json_encode($request->data());

        return $request->hasHeader('x-api-key', 'sk-test-SECRETVALUE-do-not-leak')
            && ! str_contains($bodyAndUrl, 'sk-test-SECRETVALUE-do-not-leak');
    });
});

it('separates a retryable outage from a refusal the provider was right to make', function () {
    $model = AiModel::create([
        'tier' => 'low', 'provider' => 'anthropic', 'model_id' => 'test-model-id', 'display_name' => 'Test',
        'context_window' => 1000, 'max_output_tokens' => 100, 'capabilities' => [],
        'input_price_per_mtok' => '1.0000', 'output_price_per_mtok' => '1.0000',
    ]);

    IntegrationCredential::create([
        'provider' => AnthropicProvider::CREDENTIAL_PROVIDER, 'account_ref' => AnthropicProvider::ACCOUNT_REF,
        'credential_type' => 'api_key', 'payload' => ['value' => 'sk-test'], 'status' => 'active',
    ]);

    $provider = app(AnthropicProvider::class);
    $call = fn () => app(CredentialVault::class)->openForAccounts(
        [$provider->credentialPair()],
        fn () => $provider->complete(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']])),
    );

    // ⚠ ONE sequence, not three successive `Http::fake()` calls. `Factory::fake()` MERGES its
    // stubs onto the ones already registered and the FIRST matching stub answers, so a second
    // `Http::fake()` for the same URL never takes effect: all three assertions would have been
    // served the 529, and the ProviderRefused case silently tested nothing.
    Http::fakeSequence('api.anthropic.com/*')
        ->push(['error' => ['message' => 'overloaded']], 529)
        ->push(['error' => ['message' => 'rate limited']], 429)
        // A 400 is HQ's fault. Retrying it elsewhere buys nothing and blames a healthy provider.
        ->push(['error' => ['message' => 'bad request']], 400);

    expect($call)->toThrow(ProviderUnavailable::class);
    expect($call)->toThrow(ProviderUnavailable::class);
    expect($call)->toThrow(ProviderRefused::class);
});

it('reports the anthropic driver as unavailable when no credential row exists', function () {
    expect(app(AnthropicProvider::class)->available())->toBeFalse();

    IntegrationCredential::create([
        'provider' => AnthropicProvider::CREDENTIAL_PROVIDER, 'account_ref' => AnthropicProvider::ACCOUNT_REF,
        'credential_type' => 'api_key', 'payload' => ['value' => 'sk-test'], 'status' => 'active',
    ]);

    expect(app(AnthropicProvider::class)->available())->toBeTrue();
});

it('registers the anthropic driver from config', function () {
    expect(app(ProviderRegistry::class)->has('anthropic'))->toBeTrue()
        ->and(app(ProviderRegistry::class)->get('anthropic'))->toBeInstanceOf(ModelProvider::class);
});

it('sends the structured-output schema in the shape the Messages API actually reads', function () {
    // ⚠⚠ This pins the WIRE CONTRACT, not our own idea of it. The top-level `output_format`
    // key this driver first used is deprecated API-wide; the canonical parameter is
    // `output_config.format`. An unrecognised top-level key is NOT rejected - the call succeeds with
    // the constraint silently dropped, so the only symptom is a model that "sometimes" replies in
    // the wrong shape. That is precisely the failure a fixture written from our own design cannot
    // catch, so the assertion is written against the provider's documented request body.
    $model = AiModel::create([
        'tier' => 'low', 'provider' => 'anthropic', 'model_id' => 'test-model-id', 'display_name' => 'Test',
        'context_window' => 1000, 'max_output_tokens' => 100, 'capabilities' => [],
        'input_price_per_mtok' => '1.0000', 'output_price_per_mtok' => '1.0000',
    ]);

    IntegrationCredential::create([
        'provider' => AnthropicProvider::CREDENTIAL_PROVIDER, 'account_ref' => AnthropicProvider::ACCOUNT_REF,
        'credential_type' => 'api_key', 'payload' => ['value' => 'sk-test'], 'status' => 'active',
    ]);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => '{}']],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        'stop_reason' => 'end_turn',
    ])]);

    $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string']]];
    $provider = app(AnthropicProvider::class);

    app(CredentialVault::class)->openForAccounts(
        [$provider->credentialPair()],
        fn () => $provider->complete(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']], jsonSchema: $schema)),
    );

    Http::assertSent(function ($request) use ($schema) {
        $body = $request->data();

        expect($body)->not->toHaveKey('output_format');

        return data_get($body, 'output_config.format.type') === 'json_schema'
            && data_get($body, 'output_config.format.schema') === $schema;
    });
});

it('marks the system prompt as cacheable, which is where most of the input cost goes', function () {
    $model = AiModel::create([
        'tier' => 'low', 'provider' => 'anthropic', 'model_id' => 'test-model-id', 'display_name' => 'Test',
        'context_window' => 1000, 'max_output_tokens' => 100, 'capabilities' => [],
        'input_price_per_mtok' => '1.0000', 'output_price_per_mtok' => '1.0000',
    ]);

    IntegrationCredential::create([
        'provider' => AnthropicProvider::CREDENTIAL_PROVIDER, 'account_ref' => AnthropicProvider::ACCOUNT_REF,
        'credential_type' => 'api_key', 'payload' => ['value' => 'sk-test'], 'status' => 'active',
    ]);

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'ok']],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        'stop_reason' => 'end_turn',
    ])]);

    $provider = app(AnthropicProvider::class);

    app(CredentialVault::class)->openForAccounts(
        [$provider->credentialPair()],
        fn () => $provider->complete(new ModelRequest($model, 'a stable HQ system prompt', [['role' => 'user', 'content' => 'hi']])),
    );

    Http::assertSent(fn ($request) => data_get($request->data(), 'system.0.cache_control.type') === 'ephemeral');
});

/*
 | The safety gate itself. `hq:ai:probe` is the first thing anybody runs against a live provider, so
 | the one thing it must never do is spend money because somebody typed the command to look at it.
 */

it('sends nothing and spends nothing when hq:ai:probe is run without --live', function () {
    FixtureProvider::reset();
    aiProviders();
    aiModel('fixture', 'a', ModelTier::Low);

    $this->artisan('hq:ai:probe')
        ->expectsOutputToContain('Dry run: nothing was sent')
        ->assertSuccessful();

    // ⚠ The assertion that matters is not the wording, it is that the provider was never called and
    // no run exists. A dry run that quietly made a call would still print this line.
    expect(FixtureProvider::callsTo('fixture'))->toBe(0)
        ->and(AiRun::query()->count())->toBe(0);
});

it('refuses a --live probe while the runtime flag is off, which is how HQ ships', function () {
    FixtureProvider::reset();
    aiProviders();
    aiModel('fixture', 'a', ModelTier::Low);

    // No enableAiRuntime() here on purpose: absence of the flag row is the shipped state.
    $this->artisan('hq:ai:probe --live')->assertFailed();

    expect(FixtureProvider::callsTo('fixture'))->toBe(0);
});

it('reports a cost breakdown without a model call', function () {
    $this->artisan('hq:ai:cost')->assertSuccessful();
});
