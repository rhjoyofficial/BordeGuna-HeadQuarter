<?php

use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\ModelProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ProviderRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use App\Models\IntegrationCredential;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

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
        if (str_contains((string) file_get_contents($file->getPathname()), 'api.anthropic.com')) {
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

it('leaves every tool non agent-executable and nothing enabled above R3', function () {
    // M4 gives a model the ability to produce TEXT. It gives it no ability to act, and this is the
    // assertion that will fail the day somebody assumes otherwise.
    $descriptors = collect(app(ToolRegistry::class)->keys())
        ->map(fn (string $key) => app(ToolRegistry::class)->get($key)->descriptor());

    expect($descriptors->every(fn ($d) => $d->agentExecutable === false))->toBeTrue();

    $highRisk = $descriptors->filter(fn ($d) => in_array($d->riskTier->value, ['r4', 'r5'], true))
        ->map(fn ($d) => $d->key)->values()->all();

    expect($highRisk)->toBe(['demo.request_approval']);
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
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'cache_read_input_tokens' => 3],
        'stop_reason' => 'end_turn',
    ])]);

    $provider = app(AnthropicProvider::class);
    $response = app(CredentialVault::class)->openForAccounts(
        [$provider->credentialPair()],
        fn () => $provider->complete(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']])),
    );

    expect($response->text)->toBe('hello')
        // cache reads are reported OUTSIDE input_tokens, so they must be added in to make
        // `cachedTokens` a true subset of `inputTokens`.
        ->and($response->inputTokens)->toBe(13)
        ->and($response->cachedTokens)->toBe(3)
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

    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);
    expect($call)->toThrow(ProviderUnavailable::class);

    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);
    expect($call)->toThrow(ProviderUnavailable::class);

    // A 400 is HQ's fault. Retrying it elsewhere buys nothing and blames a healthy provider.
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad request']], 400)]);
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
