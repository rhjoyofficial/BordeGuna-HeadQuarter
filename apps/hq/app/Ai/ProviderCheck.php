<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicCheck;
use App\Ai\Providers\AnthropicProvider;
use App\Gateway\Credentials\CredentialHealth;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeClass;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * The model provider's readiness check (docs/77 E.1): the gate's 8-token call with the vault's key,
 * made on purpose and recorded on the credential as what the provider's answer actually means.
 *
 * ⭐ Five failures, five records (ChatGPT, 16 September): a wrong key (401) condemns the credential;
 * an account with no credit (400 naming the credit balance) and a missing permission (403) are
 * recorded against the provider and never condemn the key; a provider that was down or rate-limited
 * (429, 5xx, 529) and a network failure say nothing about the key at all. Only a 200 verifies it.
 *
 * ⚠ It spends a fraction of a cent, so it runs only when asked: the gate runner's bypass step and
 * `hq:credentials:health --verify-model`. The request itself is `AnthropicCheck`, in the driver layer
 * where provider endpoints live. It does not pass through the model runtime, because its
 * job is to tell "the provider cannot be reached with this key" apart from "HQ's code is wrong", and
 * it does not need `ai.runtime` on, because it asks nothing of any agent. The key is opened in a vault
 * scope and never printed.
 */
final class ProviderCheck
{
    public const SOURCE = 'provider_check';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CredentialHealth $health,
        private readonly AnthropicCheck $check,
    ) {}

    /**
     * @return array{http: int|null, id: string|null, usage: array<string, mixed>|null, error: string|null, class: string}
     */
    public function run(string $source = self::SOURCE): array
    {
        $credential = $this->vault->active(AnthropicProvider::CREDENTIAL_PROVIDER, AnthropicProvider::ACCOUNT_REF);
        if ($credential === null || ! $credential->hasPayload()) {
            return ['http' => null, 'id' => null, 'usage' => null, 'error' => 'no active model provider credential is stored', 'class' => OutcomeClass::CredentialMissing->value];
        }

        try {
            $response = $this->check->send();
        } catch (ConnectionException $e) {
            $this->health->recordProviderState($credential, OutcomeClass::NetworkError, class_basename($e).': '.$e->getMessage(), $source);

            return ['http' => null, 'id' => null, 'usage' => null, 'error' => $e->getMessage(), 'class' => OutcomeClass::NetworkError->value];
        } catch (Throwable $e) {
            // Class only, as the driver does: a client exception can embed the request, and the request carries the key.
            $this->health->recordProviderState($credential, OutcomeClass::ConnectorError, get_class($e), $source);

            return ['http' => null, 'id' => null, 'usage' => null, 'error' => get_class($e), 'class' => OutcomeClass::ConnectorError->value];
        }

        $status = $response->status();
        $message = $response->json('error.message');
        $message = is_string($message) ? $message : null;
        $class = self::classify($status, $message);

        if ($class === null) {
            $this->health->recordAccepted($credential, $source);
        } elseif ($class === OutcomeClass::CredentialInvalid) {
            $this->health->recordFailed($credential, $class, "HTTP {$status}: ".($message ?? 'rejected'), $source);
        } else {
            $this->health->recordProviderState($credential, $class, "HTTP {$status}: ".($message ?? 'no message'), $source);
        }

        return [
            'http' => $status,
            'id' => is_string($response->json('id')) ? $response->json('id') : null,
            'usage' => is_array($response->json('usage')) ? $response->json('usage') : null,
            'error' => $message,
            'class' => $class?->value ?? 'accepted',
        ];
    }

    /** What an HTTP answer from the provider means for readiness; null when the key was accepted. */
    public static function classify(int $status, ?string $message): ?OutcomeClass
    {
        $text = mb_strtolower((string) $message);

        return match (true) {
            $status >= 200 && $status < 300 => null,
            $status === 401 => OutcomeClass::CredentialInvalid,
            $status === 400 && (str_contains($text, 'credit balance') || str_contains($text, 'purchase credits')) => OutcomeClass::ProviderBlockedByCredit,
            $status === 403 => OutcomeClass::ProviderUnauthorized,
            $status === 429, $status >= 500 => OutcomeClass::ProviderUnavailable,
            default => OutcomeClass::ConnectorError,
        };
    }
}
