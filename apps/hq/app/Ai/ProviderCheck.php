<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicCheck;
use App\Ai\Providers\OpenAiCheck;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\ProviderRegistry;
use App\Ai\Providers\ReadinessCall;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeClass;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * The model provider's readiness check (docs/77 E.1): the gate's tiny call with the vault's key,
 * made on purpose and recorded on the credential as what the provider's answer actually means.
 *
 * ⭐ Five failures, five records (ChatGPT, 16 September): a wrong key (401) condemns the credential;
 * an account with no credit and a missing permission (403) are recorded against the provider and
 * never condemn the key; a provider that was down or rate-limited (429, 5xx, 529) and a network
 * failure say nothing about the key at all. Only a 200 verifies it.
 *
 * ⭐ docs/81 C.7: it checks the provider that SERVES (`ServingProvider`), or the one named, so a gate's
 * bypass step proves the key the gate will actually use. When more than one provider serves it checks
 * nothing and says so: a measurement runs on exactly one. "No credit" is a 400 at Anthropic (a low
 * balance, or the monthly spend limit reached, docs/81 C.9) and a 429
 * at OpenAI (credit exhausted, a spend limit, the usage limit, `insufficient_quota`), the same status
 * OpenAI uses for throttling, so the code is read, never the status alone.
 *
 * ⚠ It spends a fraction of a cent, so it runs only when asked: the gate runner's bypass step and
 * `hq:credentials:health --verify-model`. The request itself is the provider's `ReadinessCall`, in the
 * driver layer where provider endpoints live. It does not pass through the model runtime, because its
 * job is to tell "the provider cannot be reached with this key" apart from "HQ's code is wrong", and
 * it does not need `ai.runtime` on, because it asks nothing of any agent. The key is opened in a vault
 * scope and never printed.
 */
final class ProviderCheck
{
    public const SOURCE = 'provider_check';

    /** @var array<string, ReadinessCall> */
    private array $calls;

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CredentialHealth $health,
        private readonly ProviderRegistry $providers,
        private readonly ServingProvider $serving,
        AnthropicCheck $anthropic,
        OpenAiCheck $openai,
    ) {
        $this->calls = [$anthropic->provider() => $anthropic, $openai->provider() => $openai];
    }

    /**
     * @param  string|null  $provider  the provider to check; the one that serves when null
     * @return array{http: int|null, id: string|null, usage: array<string, mixed>|null, error: string|null, class: string, provider: string|null}
     */
    public function run(string $source = self::SOURCE, ?string $provider = null): array
    {
        if ($provider === null && $this->serving->state() === ServingProvider::MIXED) {
            return $this->refused(null, $this->serving->describe().'; nothing was checked', OutcomeClass::ConnectorError);
        }

        $provider ??= $this->serving->current() ?? ServingProvider::DEFAULT;
        $call = $this->calls[$provider] ?? null;
        if ($call === null || ! $this->providers->has($provider)) {
            return $this->refused($provider, "no readiness check exists for the model provider [{$provider}]", OutcomeClass::ConnectorError);
        }

        [$credentialProvider, $account] = $this->providers->get($provider)->credentialPair();
        $credential = $this->vault->active($credentialProvider, $account);
        if ($credential === null || ! $credential->hasPayload()) {
            return $this->refused($provider, 'no active model provider credential is stored', OutcomeClass::CredentialMissing);
        }
        if ($call->modelId() === null) {
            return $this->refused($provider, "no model row is registered for [{$provider}] at the low tier; run the ModelTierSeeder", OutcomeClass::ConnectorError);
        }

        try {
            $response = $call->send();
        } catch (ConnectionException $e) {
            $this->health->recordProviderState($credential, OutcomeClass::NetworkError, class_basename($e).': '.$e->getMessage(), $source);

            return ['http' => null, 'id' => null, 'usage' => null, 'error' => $e->getMessage(), 'class' => OutcomeClass::NetworkError->value, 'provider' => $provider];
        } catch (Throwable $e) {
            // Class only, as the driver does: a client exception can embed the request, and the request carries the key.
            $this->health->recordProviderState($credential, OutcomeClass::ConnectorError, get_class($e), $source);

            return ['http' => null, 'id' => null, 'usage' => null, 'error' => get_class($e), 'class' => OutcomeClass::ConnectorError->value, 'provider' => $provider];
        }

        $status = $response->status();
        $message = $response->json('error.message');
        $message = is_string($message) ? $message : null;
        $code = $response->json('error.code');
        $type = $response->json('error.type');
        $class = self::classify($status, $message, is_string($code) ? $code : null, is_string($type) ? $type : null);

        if ($class === null) {
            $this->health->recordAccepted($credential, $source);
        } elseif ($class === OutcomeClass::CredentialInvalid) {
            $this->health->recordFailed($credential, $class, "HTTP {$status}: ".($message ?? 'rejected'), $source);
        } else {
            $this->health->recordProviderState($credential, $class, "HTTP {$status}".(is_string($code) ? " {$code}" : '').': '.($message ?? 'no message'), $source);
        }

        return [
            'http' => $status,
            'id' => is_string($response->json('id')) ? $response->json('id') : null,
            'usage' => is_array($response->json('usage')) ? $response->json('usage') : null,
            'error' => $message,
            'class' => $class?->value ?? 'accepted',
            'provider' => $provider,
        ];
    }

    /** What an HTTP answer from a model provider means for readiness; null when the key was accepted. */
    public static function classify(int $status, ?string $message, ?string $code = null, ?string $type = null): ?OutcomeClass
    {
        $text = mb_strtolower((string) $message);

        return match (true) {
            $status >= 200 && $status < 300 => null,
            // A project IP allow-list refuses with a 401 too; that is a setting, not a bad key.
            $status === 401 && str_contains($text, 'ip not authorized') => OutcomeClass::ProviderUnauthorized,
            $status === 401 => OutcomeClass::CredentialInvalid,
            $status === 400 && (str_contains($text, 'credit balance') || str_contains($text, 'purchase credits')) => OutcomeClass::ProviderBlockedByCredit,
            // Anthropic's monthly spend limit, as production received it during gate 6 (docs/69): "You
            // have reached your specified API usage limits. You will regain access on 2026-10-01". The
            // account is capped, like OpenAI's spend limit below; the key is fine (docs/81 C.9).
            $status === 400 && str_contains($text, 'api usage limit') => OutcomeClass::ProviderBlockedByCredit,
            OpenAiProvider::isBillingBlock($status, $code, $type) => OutcomeClass::ProviderBlockedByCredit,
            $status === 403 => OutcomeClass::ProviderUnauthorized,
            $status === 429, $status >= 500 => OutcomeClass::ProviderUnavailable,
            default => OutcomeClass::ConnectorError,
        };
    }

    /** @return array{http: null, id: null, usage: null, error: string, class: string, provider: string|null} */
    private function refused(?string $provider, string $why, OutcomeClass $class): array
    {
        return ['http' => null, 'id' => null, 'usage' => null, 'error' => $why, 'class' => $class->value, 'provider' => $provider];
    }
}
