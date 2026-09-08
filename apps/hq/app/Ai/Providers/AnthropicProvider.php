<?php

namespace App\Ai\Providers;

use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Gateway\Credentials\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Anthropic Messages API.
 *
 * ⚠ **The API key is never read here.** `CredentialVault::secret()` returns it inside a scope the
 * runtime opened, the same way the ERP and commerce connectors work, so the vault remains the only
 * decryption point in the application.
 *
 * ⚠ **No model id is hard-coded.** The id comes off the `model_tiers` row on the request. This class
 * knows the shape of the API, not which models exist.
 *
 * ⚠ Errors are separated into two classes on purpose. A 429 or 5xx is `ProviderUnavailable`: the
 * request was fine, somebody else may serve it, and the circuit breaker should count it. A 400 or
 * 404 is `ProviderRefused`: the request is broken, and sending it to a second provider would only
 * produce a second refusal while taking a healthy provider out of rotation.
 */
final class AnthropicProvider implements ModelProvider
{
    public const PROVIDER = 'anthropic';

    /** The `integration_credentials.provider` value holding the API key. */
    public const CREDENTIAL_PROVIDER = 'anthropic_api_key';

    /** A single group-wide account, so `account_ref` is a constant rather than a business slug. */
    public const ACCOUNT_REF = 'hq';

    private const DEFAULT_TIMEOUT = 120;

    public function __construct(private readonly CredentialVault $vault) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function credentialPair(): array
    {
        return [self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF];
    }

    public function available(): bool
    {
        // `active()` reads metadata only; it does not decrypt, and it does not need an open scope.
        return $this->vault->active(self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF) !== null;
    }

    public function complete(ModelRequest $request): ModelResponse
    {
        $startedAt = microtime(true);

        $system = $request->cacheSystem && $request->system !== ''
            // A cache_control breakpoint on the system block is what makes a long, stable HQ prompt
            // cost a tenth of its input price from the second call onward.
            ? [['type' => 'text', 'text' => $request->system, 'cache_control' => ['type' => 'ephemeral']]]
            : $request->system;

        $body = array_filter([
            'model' => $request->model->model_id,
            'max_tokens' => $request->maxOutputTokens,
            'system' => $system === '' ? null : $system,
            'messages' => array_map(
                fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
                $request->messages,
            ),
        ], fn ($value) => $value !== null);

        if ($request->jsonSchema !== null) {
            // The canonical wire parameter is `output_config.format`. The older top-level
            // `output_format` is deprecated API-wide, and an unknown top-level key does not fail
            // loudly - the call succeeds UNCONSTRAINED, the reply is merely likely-shaped JSON, and
            // the only symptom is an occasional repair or a StructuredOutputFailed that looks like
            // the model's fault. Verified against the Claude API reference on 8 September 2026.
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $request->jsonSchema]];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->vault->secret(self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout($request->timeoutSeconds ?? self::DEFAULT_TIMEOUT)
                ->post('https://api.anthropic.com/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('anthropic: '.$e->getMessage());
        } catch (Throwable $e) {
            // Class only. A client exception can embed the request, and the request carries the key.
            throw new ProviderUnavailable('anthropic: '.get_class($e));
        }

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            // The body is a provider error object, not our own data, and it does not echo the key
            // header. It is still trimmed, because an error message is not a place to accumulate.
            $detail = substr((string) $response->json('error.message', $response->status()), 0, 200);

            throw $response->status() === 429 || $response->serverError()
                ? new ProviderUnavailable("anthropic {$response->status()}: {$detail}")
                : new ProviderRefused("anthropic {$response->status()}: {$detail}");
        }

        $usage = (array) $response->json('usage', []);

        // Cache reads are reported separately and are NOT included in `input_tokens`. Adding them in
        // here is what makes `cachedTokens` a true subset, which is the contract every cost
        // calculation downstream depends on.
        $cached = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $fresh = (int) ($usage['input_tokens'] ?? 0);
        $cacheWrites = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        return new ModelResponse(
            text: $this->textFrom((array) $response->json('content', [])),
            inputTokens: $fresh + $cached + $cacheWrites,
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cachedTokens: $cached,
            latencyMs: $latencyMs,
            stopReason: (string) $response->json('stop_reason', 'end_turn'),
            cacheWriteTokens: $cacheWrites,
            rawUsage: $usage,
        );
    }

    /** @param list<array<string, mixed>> $content */
    private function textFrom(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $parts[] = (string) $block['text'];
            }
        }

        return implode('', $parts);
    }
}
