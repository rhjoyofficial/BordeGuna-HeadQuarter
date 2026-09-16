<?php

namespace App\Gateway\Credentials;

use App\Gateway\OutcomeClass;
use App\Models\IntegrationCredential;
use Carbon\CarbonImmutable;

/**
 * What HQ knows about whether a stored credential WORKS, kept apart from whether it exists
 * (ADR-043, docs/74 C.3).
 *
 * A credential row says "active" from the moment it is stored until someone revokes it. Gate 8
 * showed the gap: LaLaDia's admin token had been active for a week and had never once been
 * accepted. This records the evidence, in `last_verified_at` and a small `meta.last_verification`
 * block, from two sources: the read-only probes the health command runs, and every live call the
 * gateway makes with the credential open. No value is ever read or written here; only whether the
 * far side accepted it.
 */
final class CredentialHealth
{
    public const META_KEY = 'last_verification';

    /** A rejected credential is treated as invalid until a newer row replaces it or a check passes. */
    public function isKnownInvalid(IntegrationCredential $credential): bool
    {
        $last = $this->lastVerification($credential);

        return $last !== null && $last['ok'] === false && $last['class'] === OutcomeClass::CredentialInvalid->value;
    }

    /** @return array{at: string, ok: bool, class: string|null, message: string|null, source: string}|null */
    public function lastVerification(IntegrationCredential $credential): ?array
    {
        $meta = (array) ($credential->meta ?? []);
        $last = $meta[self::META_KEY] ?? null;

        return is_array($last) && array_key_exists('ok', $last) ? [
            'at' => (string) ($last['at'] ?? ''),
            'ok' => (bool) $last['ok'],
            'class' => isset($last['class']) ? (string) $last['class'] : null,
            'message' => isset($last['message']) ? (string) $last['message'] : null,
            'source' => (string) ($last['source'] ?? 'unknown'),
        ] : null;
    }

    /** The far side accepted the credential: a probe answered, or a live call succeeded. */
    public function recordAccepted(IntegrationCredential $credential, string $source): void
    {
        $this->write($credential, ok: true, class: null, message: null, source: $source);
    }

    /**
     * The far side did not accept it, or could not be asked. Only an authorization answer (401,
     * 403) makes the credential KNOWN INVALID; an unreachable or failing provider says nothing
     * about the token and is recorded as that class without condemning the row.
     */
    public function recordFailed(IntegrationCredential $credential, OutcomeClass $class, ?string $message, string $source): void
    {
        $invalid = $class === OutcomeClass::ProviderUnauthorized || $class === OutcomeClass::CredentialInvalid;

        $this->write($credential, ok: false, class: $invalid ? OutcomeClass::CredentialInvalid->value : $class->value,
            message: $message === null ? null : mb_substr($message, 0, 300), source: $source);
    }

    /**
     * The provider answered something that says nothing against the credential itself: the account
     * has no credit, the key lacks a permission, the provider was down (docs/77 E.1). Recorded as that
     * class and never condemning the row, because "the account is out of money" and "the secret is
     * wrong" are fixed by different people in different places.
     */
    public function recordProviderState(IntegrationCredential $credential, OutcomeClass $class, ?string $message, string $source): void
    {
        $this->write($credential, ok: false, class: $class->value,
            message: $message === null ? null : mb_substr($message, 0, 300), source: $source);
    }

    /**
     * What a live call taught HQ about the credentials it opened (ADR-043): a success verifies
     * them all; an authorization failure condemns them all, because the gateway cannot tell which
     * of two headers the far side rejected and a false "still fine" is the worse error.
     *
     * @param  list<array{provider: string, account_ref: string, credential_id: int}>  $found
     */
    public function learnFromCall(array $found, ?OutcomeClass $class, ?string $message, string $source): void
    {
        if ($found === []) {
            return;
        }

        $ids = array_values(array_map(static fn (array $row) => (int) $row['credential_id'], $found));

        foreach (IntegrationCredential::query()->whereKey($ids)->get() as $credential) {
            if ($class === null) {
                $this->recordAccepted($credential, $source);
            } elseif ($class === OutcomeClass::ProviderUnauthorized || $class === OutcomeClass::CredentialInvalid) {
                $this->recordFailed($credential, $class, $message, $source);
            }
            // Any other external class (the provider was down, timed out, answered 500) says
            // nothing about the credential and leaves its record alone.
        }
    }

    private function write(IntegrationCredential $credential, bool $ok, ?string $class, ?string $message, string $source): void
    {
        $meta = (array) ($credential->meta ?? []);
        $meta[self::META_KEY] = [
            'at' => CarbonImmutable::now()->toIso8601String(),
            'ok' => $ok,
            'class' => $class,
            'message' => $message,
            'source' => $source,
        ];

        $credential->forceFill(['meta' => $meta, 'last_verified_at' => now()])->save();
    }
}
