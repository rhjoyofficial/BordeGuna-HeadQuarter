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

    public function __construct(private readonly Auditor $auditor) {}

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

    /** Store a token set obtained from a provider. The payload is encrypted by the model cast. */
    public function store(string $provider, string $accountRef, array $tokenSet, array $scopes = [], ?int $businessId = null, ?int $brandId = null, ?\DateTimeInterface $expiresAt = null): IntegrationCredential
    {
        $credential = IntegrationCredential::updateOrCreate(
            ['provider' => $provider, 'account_ref' => $accountRef, 'business_id' => $businessId, 'brand_id' => $brandId],
            ['credential_type' => 'oauth2', 'payload' => $tokenSet, 'scopes' => $scopes, 'status' => 'active', 'expires_at' => $expiresAt, 'last_refreshed_at' => now()],
        );
        $this->auditor->record('credential.stored', $credential, after: $credential->toSafeArray());

        return $credential;
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
