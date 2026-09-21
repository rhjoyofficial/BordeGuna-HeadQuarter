<?php

use App\Agents\AgentResponseSchema;
use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Providers\ModelProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ModelResponse;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\OpenAiStrictSchema;
use App\Ai\Providers\ProviderRegistry;
use App\Gateway\Credentials\CredentialVault;
use App\Models\AiModel;
use App\Models\IntegrationCredential;
use App\Scheduling\Nl\JobSpecSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 | The OpenAI driver (docs/81 C.7, the owner's decision of 21 September).
 |
 | ⚠ Every assertion here is written against OpenAI's documented Responses API, read on
 | developers.openai.com on 21 September, not against our own idea of it: the request body a driver
 | sends is the contract, and a fixture written from our own design cannot catch a key the provider
 | ignores. The driver is held to what the Anthropic driver is held to: the key only through the
 | vault and only in a header, the neutral stop reasons, cached and written tokens as subsets of the
 | input, and a retryable outage kept apart from a refusal. One thing is new and is the reason this
 | file is long: OpenAI answers a billing block with the same 429 it uses for throttling, and the two
 | must never be confused.
 */

function oaiModel(array $attributes = []): AiModel
{
    return AiModel::create($attributes + [
        'tier' => 'medium', 'provider' => OpenAiProvider::PROVIDER, 'model_id' => 'test-openai-model', 'display_name' => 'Test OpenAI',
        'context_window' => 272000, 'max_output_tokens' => 128000, 'capabilities' => ['json_schema'],
        'input_price_per_mtok' => '2.0000', 'output_price_per_mtok' => '12.0000',
        'cache_read_price_per_mtok' => '0.2000', 'cache_write_price_per_mtok' => '2.5000',
    ]);
}

function oaiKey(string $value = 'sk-proj-SECRETVALUE-do-not-leak'): IntegrationCredential
{
    return IntegrationCredential::create([
        'provider' => OpenAiProvider::CREDENTIAL_PROVIDER, 'account_ref' => OpenAiProvider::ACCOUNT_REF,
        'credential_type' => 'api_key', 'payload' => ['value' => $value], 'status' => 'active',
    ]);
}

/** One call through the vault scope, the way ModelRuntime makes it. */
function oaiCall(ModelRequest $request): ModelResponse
{
    $provider = app(OpenAiProvider::class);

    return app(CredentialVault::class)->openForAccounts(
        [$provider->credentialPair()],
        fn () => $provider->complete($request),
    );
}

/** A completed Responses API reply, shaped as the API reference documents it. */
function oaiReply(array $overrides = []): array
{
    return array_replace([
        'id' => 'resp_test_1',
        'object' => 'response',
        'status' => 'completed',
        'incomplete_details' => null,
        'error' => null,
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
            ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed', 'content' => [
                ['type' => 'output_text', 'text' => 'hel', 'annotations' => []],
                ['type' => 'output_text', 'text' => 'lo', 'annotations' => []],
            ]],
        ],
        'usage' => [
            'input_tokens' => 17,
            'input_tokens_details' => ['cached_tokens' => 3, 'cache_write_tokens' => 4],
            'output_tokens' => 9,
            'output_tokens_details' => ['reasoning_tokens' => 4],
            'total_tokens' => 26,
        ],
    ], $overrides);
}

it('sends the key as a Bearer header only, and the documented Responses API body', function () {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply())]);

    $response = oaiCall(new ModelRequest($model, 'a stable HQ system prompt', [
        ['role' => 'user', 'content' => 'hi'],
        ['role' => 'assistant', 'content' => 'earlier reply'],
        ['role' => 'user', 'content' => 'and now'],
    ], maxOutputTokens: 777));

    expect($response->text)->toBe('hello');

    Http::assertSent(function ($request) {
        $body = $request->data();
        $bodyAndUrl = $request->url().json_encode($body);

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer sk-proj-SECRETVALUE-do-not-leak')
            && ! str_contains($bodyAndUrl, 'SECRETVALUE')
            && $body['model'] === 'test-openai-model'
            && $body['max_output_tokens'] === 777
            // Nothing kept at OpenAI: the default is to store the response for 30 days.
            && $body['store'] === false
            && $body['reasoning'] === ['effort' => 'low']
            && ! array_key_exists('text', $body)
            // The stable prefix is written once and read after; the changing turns are not written.
            && $body['prompt_cache_options'] === ['mode' => 'explicit']
            && ! array_key_exists('instructions', $body)
            && $body['input'] === [
                ['role' => 'developer', 'content' => [[
                    'type' => 'input_text',
                    'text' => 'a stable HQ system prompt',
                    'prompt_cache_breakpoint' => ['mode' => 'explicit'],
                ]]],
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => 'earlier reply'],
                ['role' => 'user', 'content' => 'and now'],
            ];
    });
});

it('sends an uncached system prompt as instructions, with no breakpoint and no cache write', function () {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply())]);

    oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']], cacheSystem: false));
    oaiCall(new ModelRequest($model, '', [['role' => 'user', 'content' => 'hi']]));

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();

    expect($sent[0]['instructions'])->toBe('sys')
        ->and($sent[0]['input'])->toBe([['role' => 'user', 'content' => 'hi']])
        ->and($sent[0]['prompt_cache_options'])->toBe(['mode' => 'explicit'])
        ->and($sent[1])->not->toHaveKey('instructions')
        ->and($sent[1]['input'])->toBe([['role' => 'user', 'content' => 'hi']]);
});

it('takes the reasoning effort from config, because it is part of what a gate measures', function () {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply())]);
    config()->set('hq.ai.openai.reasoning_effort', 'none');

    oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']]));

    Http::assertSent(fn ($request) => $request->data()['reasoning'] === ['effort' => 'none']);
});

it('maps the reply into the neutral contract: text, input with cached and written as subsets, output with reasoning', function () {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply())]);

    $response = oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']]));

    expect($response->text)->toBe('hello')
        // OpenAI's input_tokens already includes the cached and the written tokens.
        ->and($response->inputTokens)->toBe(17)
        ->and($response->cachedTokens)->toBe(3)
        ->and($response->cacheWriteTokens)->toBe(4)
        // Reasoning tokens are billed as output, so they are counted as output.
        ->and($response->outputTokens)->toBe(9)
        ->and($response->stopReason)->toBe(ModelResponse::STOP_END_TURN)
        ->and($response->truncated())->toBeFalse()
        ->and($response->rawUsage['output_tokens_details']['reasoning_tokens'])->toBe(4);
});

it('reports a reply cut off at the output limit as max_tokens, so the ADR-038 raise works unchanged', function () {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply([
        'status' => 'incomplete',
        'incomplete_details' => ['reason' => 'max_output_tokens'],
        'output' => [['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]],
        'usage' => ['input_tokens' => 50, 'output_tokens' => 64, 'output_tokens_details' => ['reasoning_tokens' => 64]],
    ]))]);

    $response = oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']], maxOutputTokens: 64));

    expect($response->truncated())->toBeTrue()
        ->and($response->stopReason)->toBe(ModelResponse::STOP_MAX_TOKENS)
        ->and($response->text)->toBe('')
        ->and($response->cachedTokens)->toBe(0)
        ->and($response->cacheWriteTokens)->toBe(0);
});

it('records a content filter stop and a refusal as what they are, not as a finished reply', function (array $reply, string $stop) {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply($reply))]);

    $response = oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']]));

    expect($response->stopReason)->toBe($stop)
        ->and($response->truncated())->toBeFalse()
        ->and($response->text)->toBe('');
})->with([
    'content filter' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter'], 'output' => []], 'content_filter'],
    'refusal' => [['output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]]]], 'refusal'],
]);

it('sends a schema inside the documented strict subset as strict, and the agent schema as not strict', function () {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply())]);

    oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']], jsonSchema: JobSpecSchema::schema()));
    oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']], jsonSchema: AgentResponseSchema::schema()));

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();

    expect($sent[0]['text'])->toBe(['format' => ['type' => 'json_schema', 'name' => 'hq_reply', 'schema' => JobSpecSchema::schema(), 'strict' => true]])
        // The agent schema's arrays carry no `items`, and `strict: true` with an unsupported schema is
        // an error, so it goes without the guarantee; ArgumentValidator and one repair stay the check.
        ->and($sent[1]['text'])->toBe(['format' => ['type' => 'json_schema', 'name' => 'hq_reply', 'schema' => AgentResponseSchema::schema(), 'strict' => false]]);
});

it('knows the strict subset from the provider guide, and assumes nothing it does not list', function (array $schema, bool $strict) {
    expect(OpenAiStrictSchema::compatible($schema))->toBe($strict);
})->with([
    'the NL scheduler schema' => [JobSpecSchema::schema(), true],
    'the agent turn schema (arrays without items)' => [AgentResponseSchema::schema(), false],
    'no additionalProperties false' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']], false],
    'a property not required' => [['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'required' => ['a']], false],
    'an array with typed items' => [['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5]]], true],
    'an array of strict objects' => [['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'array', 'items' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['x'], 'properties' => ['x' => ['type' => ['string', 'null'], 'description' => 'nullable']],
    ]]]], true],
    'an open object inside' => [['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'object']]], false],
    'minLength, not in the listed keywords' => [['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string', 'minLength' => 1]]], false],
    'a supported string format' => [['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string', 'format' => 'date-time', 'pattern' => '^2']]], true],
    'an unlisted string format' => [['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string', 'format' => 'uri']]], false],
    'anyOf at the root' => [['anyOf' => [['type' => 'object', 'additionalProperties' => false, 'properties' => [], 'required' => []]]], false],
    'allOf anywhere' => [['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['allOf' => [['type' => 'string']]]]], false],
    'a root that is not an object' => [['type' => 'array', 'items' => ['type' => 'string']], false],
]);

it('refuses strict past the documented nesting and enum limits', function () {
    $deep = ['type' => 'string'];
    for ($i = 0; $i < 11; $i++) {
        $deep = ['type' => 'object', 'additionalProperties' => false, 'required' => ['n'], 'properties' => ['n' => $deep]];
    }
    $manyEnums = ['type' => 'object', 'additionalProperties' => false, 'required' => ['a'], 'properties' => ['a' => ['type' => 'string', 'enum' => array_map(fn ($i) => "v{$i}", range(1, 1001))]]];

    expect(OpenAiStrictSchema::compatible($deep))->toBeFalse()
        ->and(OpenAiStrictSchema::compatible($manyEnums))->toBeFalse();
});

it('separates an outage from throttling from a billing block from a request the provider was right to refuse', function (int $status, array $body, string $class) {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

    expect(fn () => oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']])))->toThrow($class);
})->with([
    'server error' => [500, ['error' => ['type' => 'server_error', 'message' => 'The server had an error']], ProviderUnavailable::class],
    'overloaded' => [503, ['error' => ['type' => 'service_unavailable_error', 'code' => 'server_is_overloaded', 'message' => 'overloaded']], ProviderUnavailable::class],
    'throttled' => [429, ['error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached for requests']], ProviderUnavailable::class],
    'slow down' => [429, ['error' => ['type' => 'rate_limit_error', 'code' => 'slow_down', 'message' => 'slow down']], ProviderUnavailable::class],
    // ⚠ The billing 429s: retrying cannot restore access, so they are refusals, never an outage that
    // would trip the breaker and send the call to another provider.
    'credit exhausted' => [429, ['error' => ['type' => 'insufficient_quota', 'code' => 'credit_balance_exhausted', 'message' => 'no credits']], ProviderRefused::class],
    'insufficient quota' => [429, ['error' => ['type' => 'insufficient_quota', 'code' => 'insufficient_quota', 'message' => 'You exceeded your current quota']], ProviderRefused::class],
    'organisation spend limit' => [429, ['error' => ['type' => 'insufficient_quota', 'code' => 'organization_spend_limit_exceeded', 'message' => 'limit']], ProviderRefused::class],
    'project spend limit' => [429, ['error' => ['code' => 'project_spend_limit_exceeded', 'message' => 'limit']], ProviderRefused::class],
    'usage limit' => [429, ['error' => ['code' => 'organization_usage_limit_exceeded', 'message' => 'limit']], ProviderRefused::class],
    'bad request' => [400, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid schema']], ProviderRefused::class],
    'wrong key' => [401, ['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_api_key', 'message' => 'Incorrect API key provided']], ProviderRefused::class],
    'region' => [403, ['error' => ['code' => 'unsupported_country_region_territory', 'message' => 'Country, region, or territory not supported']], ProviderRefused::class],
]);

it('treats a response that failed on the provider side as an outage, and any other failed response as refused', function (string $code, string $class) {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(oaiReply(['status' => 'failed', 'output' => [], 'error' => ['code' => $code, 'message' => 'failed']]))]);

    expect(fn () => oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']])))->toThrow($class);
})->with([
    'server error' => ['server_error', ProviderUnavailable::class],
    'rate limited' => ['rate_limit_exceeded', ProviderUnavailable::class],
    'invalid prompt' => ['invalid_prompt', ProviderRefused::class],
]);

it('keeps the key out of every error, including a transport failure', function () {
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('could not resolve host')]);

    try {
        oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']]));
        $this->fail('expected an outage');
    } catch (ProviderUnavailable $e) {
        expect($e->getMessage())->toStartWith('openai')->not->toContain('SECRETVALUE');
    }
});

it('keeps the key out of a refusal too', function () {
    // A separate test on purpose: a second `Http::fake()` for the same URL never takes effect, the
    // first matching stub answers (see the Anthropic driver's test of the same shape).
    $model = oaiModel();
    oaiKey();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-proj-****leak']], 401)]);

    try {
        oaiCall(new ModelRequest($model, 'sys', [['role' => 'user', 'content' => 'hi']]));
        $this->fail('expected a refusal');
    } catch (ProviderRefused $e) {
        expect($e->getMessage())->toStartWith('openai 401')->not->toContain('SECRETVALUE');
    }
});

it('reports the openai driver as unavailable when no credential row exists, and is registered from config', function () {
    expect(app(OpenAiProvider::class)->available())->toBeFalse();

    oaiKey();

    expect(app(OpenAiProvider::class)->available())->toBeTrue()
        ->and(app(ProviderRegistry::class)->has('openai'))->toBeTrue()
        ->and(app(ProviderRegistry::class)->get('openai'))->toBeInstanceOf(ModelProvider::class)
        // The Anthropic driver stays registered: returning to it is an operator step, not a rebuild.
        ->and(app(ProviderRegistry::class)->has('anthropic'))->toBeTrue();
});

it('reads the key only through the vault, like every other driver', function () {
    $source = file_get_contents(app_path('Ai/Providers/OpenAiProvider.php'));

    expect($source)->toContain('$this->vault->secret(')
        ->and($source)->not->toContain('IntegrationCredential')
        ->and($source)->not->toContain('->payload')
        ->and($source)->not->toContain("env('");
});
