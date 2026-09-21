<?php

namespace App\Ai\Providers;

use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Gateway\Credentials\CredentialVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The OpenAI Responses API (docs/81 C.7: the owner chose OpenAI on 21 September while the Anthropic
 * account is blocked; Anthropic stays registered).
 *
 * Every field below was read from OpenAI's API reference and guides on 21 September 2026, not recalled.
 *
 * ⚠ **The API key is never read here.** `CredentialVault::secret()` returns it inside the scope the
 * runtime opened, as for every other driver, and it travels only as the Bearer header.
 *
 * ⚠ **No model id is hard-coded.** The id comes off the `model_tiers` row on the request.
 *
 * ⚠ **`store: false`.** The API keeps a response for at least 30 days when `store` is omitted. HQ keeps
 * its own record in `ai_runs` and asks OpenAI to keep nothing.
 *
 * ⚠ **A 429 is two different things.** OpenAI uses it for throttling (wait and retry) AND for billing:
 * `credit_balance_exhausted`, the organisation or project spend limit, the usage limit, and the broader
 * type `insufficient_quota`. "Retrying billing, spend, or quota errors won't restore API access", so a
 * billing 429 is `ProviderRefused`: not an outage, the breaker is not touched and no other model is
 * asked, exactly as Anthropic's credit refusal (a 400) behaves.
 */
final class OpenAiProvider implements ModelProvider
{
    public const PROVIDER = 'openai';

    /** The `integration_credentials.provider` value holding the API key. */
    public const CREDENTIAL_PROVIDER = 'openai_api_key';

    /** A single group-wide account, as for Anthropic. */
    public const ACCOUNT_REF = 'hq';

    public const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** The name the structured-output format carries; OpenAI requires one. */
    public const FORMAT_NAME = 'hq_reply';

    /** The 429 codes (and the broad type) that mean the account cannot pay, not that it is too fast. */
    public const BILLING_CODES = [
        'credit_balance_exhausted', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded',
        'organization_usage_limit_exceeded', 'insufficient_quota',
    ];

    /** The failure codes of a response that failed on OpenAI's side and may be asked again elsewhere. */
    private const RETRYABLE_FAILURES = ['server_error', 'rate_limit_exceeded'];

    private const DEFAULT_TIMEOUT = 120;

    private const DEFAULT_EFFORT = 'low';

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
        return $this->vault->active(self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF) !== null;
    }

    /**
     * How much the model reasons before it answers, from `hq.ai.openai.reasoning_effort`. It is part of
     * what a gate measures, so it is a setting fixed before a gate, never a per-call choice.
     */
    public static function reasoningEffort(): string
    {
        $effort = (string) config('hq.ai.openai.reasoning_effort', self::DEFAULT_EFFORT);

        return $effort !== '' ? $effort : self::DEFAULT_EFFORT;
    }

    /** Whether a failed HTTP answer is a billing block rather than throttling or a broken request. */
    public static function isBillingBlock(int $status, ?string $code, ?string $type): bool
    {
        return $status === 429
            && (in_array((string) $code, self::BILLING_CODES, true) || $type === 'insufficient_quota');
    }

    public function complete(ModelRequest $request): ModelResponse
    {
        $startedAt = microtime(true);

        try {
            $response = Http::withToken($this->vault->secret(self::CREDENTIAL_PROVIDER, self::ACCOUNT_REF))
                ->acceptJson()
                ->timeout($request->timeoutSeconds ?? self::DEFAULT_TIMEOUT)
                ->post(self::ENDPOINT, $this->body($request));
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('openai: '.$e->getMessage());
        } catch (Throwable $e) {
            // Class only. A client exception can embed the request, and the request carries the key.
            throw new ProviderUnavailable('openai: '.get_class($e));
        }

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($response->failed()) {
            throw $this->failure($response);
        }

        $status = (string) $response->json('status', 'completed');

        if ($status === 'failed') {
            $code = (string) $response->json('error.code', 'unknown');
            $detail = substr((string) $response->json('error.message', 'failed'), 0, 200);

            throw in_array($code, self::RETRYABLE_FAILURES, true)
                ? new ProviderUnavailable("openai failed {$code}: {$detail}")
                : new ProviderRefused("openai failed {$code}: {$detail}");
        }

        if (! in_array($status, ['completed', 'incomplete'], true)) {
            // `queued` or `in_progress` would mean a background response, which HQ never asks for.
            throw new ProviderUnavailable("openai: unexpected response status [{$status}]");
        }

        $usage = (array) $response->json('usage', []);
        [$text, $refused] = $this->textFrom((array) $response->json('output', []));

        return new ModelResponse(
            text: $text,
            // `input_tokens` already INCLUDES the cached and the written tokens ("input tokens use the
            // uncached-input, cached-input, or cache-write rate"), so both stay subsets as the
            // contract requires, without adding them in as the Anthropic driver must.
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            // Reasoning tokens are part of `output_tokens` and billed as output, so they are counted.
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cachedTokens: (int) data_get($usage, 'input_tokens_details.cached_tokens', 0),
            latencyMs: $latencyMs,
            stopReason: $this->stopReason($status, $response->json('incomplete_details.reason'), $refused && $text === ''),
            // GPT-5.6 bills cache writes at 1.25x the input rate, reported here.
            cacheWriteTokens: (int) data_get($usage, 'input_tokens_details.cache_write_tokens', 0),
            rawUsage: $usage,
        );
    }

    /** @return array<string, mixed> */
    private function body(ModelRequest $request): array
    {
        $messages = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => $m['content']],
            $request->messages,
        );

        $body = [
            'model' => $request->model->model_id,
            'max_output_tokens' => $request->maxOutputTokens,
            'store' => false,
            'reasoning' => ['effort' => self::reasoningEffort()],
            // Explicit mode: nothing is written to the cache except where HQ places a breakpoint.
            // Implicit mode would write the whole prompt, up to the latest user message, on every call
            // at 1.25x, and a different question would never read it back.
            'prompt_cache_options' => ['mode' => 'explicit'],
        ];

        if ($request->system !== '' && $request->cacheSystem) {
            // The stable system prompt as a developer message with ONE breakpoint at its end: written
            // once, read by later calls at a tenth of the input price. "Top-level `instructions`
            // cannot contain an explicit breakpoint", which is why it is not sent as instructions.
            array_unshift($messages, ['role' => 'developer', 'content' => [[
                'type' => 'input_text',
                'text' => $request->system,
                'prompt_cache_breakpoint' => ['mode' => 'explicit'],
            ]]]);
        } elseif ($request->system !== '') {
            $body['instructions'] = $request->system;
        }

        $body['input'] = $messages;

        if ($request->jsonSchema !== null) {
            $body['text'] = ['format' => [
                'type' => 'json_schema',
                'name' => self::FORMAT_NAME,
                'schema' => $request->jsonSchema,
                // Strict only inside the documented subset: strict with anything else is an error.
                'strict' => OpenAiStrictSchema::compatible($request->jsonSchema),
            ]];
        }

        return $body;
    }

    private function failure(Response $response): ProviderRefused|ProviderUnavailable
    {
        $status = $response->status();
        $code = $response->json('error.code');
        $type = $response->json('error.type');
        $code = is_string($code) ? $code : null;
        $type = is_string($type) ? $type : null;
        // The body is OpenAI's error object; it does not echo the header. Trimmed all the same.
        $detail = substr((string) $response->json('error.message', $status), 0, 200);
        $label = trim("openai {$status} ".($code ?? $type ?? '')).": {$detail}";

        if (self::isBillingBlock($status, $code, $type)) {
            return new ProviderRefused($label.' (a billing block: add credit or raise the limit; retrying cannot help)');
        }

        return $status === 429 || $response->serverError()
            ? new ProviderUnavailable($label)
            : new ProviderRefused($label);
    }

    private function stopReason(string $status, mixed $incompleteReason, bool $refusedOnly): string
    {
        if ($status === 'incomplete') {
            return match ($incompleteReason) {
                'max_output_tokens' => ModelResponse::STOP_MAX_TOKENS,
                'content_filter' => 'content_filter',
                default => 'incomplete',
            };
        }

        return $refusedOnly ? 'refusal' : ModelResponse::STOP_END_TURN;
    }

    /**
     * The visible text of the reply: every `output_text` part of every assistant message, in order.
     * Reasoning items carry no visible text. `output_text` at the top level is an SDK convenience the
     * HTTP API does not send.
     *
     * @param  list<array<string, mixed>>  $output
     * @return array{0: string, 1: bool} the text, and whether a refusal part was present
     */
    private function textFrom(array $output): array
    {
        $parts = [];
        $refused = false;

        foreach ($output as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    $parts[] = (string) ($content['text'] ?? '');
                } elseif (($content['type'] ?? null) === 'refusal') {
                    $refused = true;
                }
            }
        }

        return [implode('', $parts), $refused];
    }
}
