<?php

namespace App\Support\Credentials;

use App\Models\IntegrationCredential;
use App\Support\Audit\Auditor;
use InvalidArgumentException;

/**
 * Skeleton for OAuth-based providers (Meta, Google, TikTok, LinkedIn, YouTube...).
 * Phase 0 defines the contract and the storage/refresh/revoke bookkeeping only; no provider
 * is registered and no production account is connected.
 */
final class OAuthManager
{
    /** @var array<string, OAuthProvider> */
    private array $providers = [];

    public function __construct(private readonly Auditor $auditor, private readonly CredentialWriter $credentials) {}

    public function register(OAuthProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function provider(string $key): OAuthProvider
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException("No OAuth provider registered for [{$key}].");
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Store a token set obtained from a provider. The payload is encrypted by the model cast.
     *
     * ⚠ This used to `updateOrCreate` on provider + account_ref + business_id + brand_id. Those
     * four columns are not the identity the vault resolves by - it asks for provider and
     * account_ref - so reconnecting the same account against a different business quietly produced
     * a SECOND active row for one identity. `CredentialWriter` replaces by the identity that is
     * actually looked up, and audits the revocation of what it replaced.
     */
    public function store(string $provider, string $accountRef, array $tokenSet, array $scopes = [], ?int $businessId = null, ?int $brandId = null, ?\DateTimeInterface $expiresAt = null): IntegrationCredential
    {
        return $this->credentials->replace($provider, $accountRef, $tokenSet, [
            'credential_type' => 'oauth2', 'scopes' => $scopes, 'business_id' => $businessId,
            'brand_id' => $brandId, 'expires_at' => $expiresAt, 'last_refreshed_at' => now(),
        ]);
    }

    public function refresh(IntegrationCredential $credential): IntegrationCredential
    {
        $provider = $this->provider($credential->provider);
        $tokenSet = $provider->refresh($credential->payload ?? []);
        $credential->forceFill(['payload' => $tokenSet['tokens'], 'expires_at' => $tokenSet['expires_at'] ?? null, 'last_refreshed_at' => now(), 'status' => 'active'])->save();
        $this->auditor->record('credential.refreshed', $credential, after: $credential->toSafeArray());

        return $credential;
    }

    public function revoke(IntegrationCredential $credential, ?string $reason = null): void
    {
        if (isset($this->providers[$credential->provider])) {
            $this->providers[$credential->provider]->revoke($credential->payload ?? []);
        }
        $before = $credential->toSafeArray();
        $credential->forceFill(['status' => 'revoked', 'payload' => null])->save();
        $this->auditor->record('credential.revoked', $credential, before: $before, after: $credential->toSafeArray(), reason: $reason);
    }
}
