<?php

namespace App\Platform;

use App\Platform\Contracts\PlatformProvider;

/**
 * The platform providers this deployment contains, keyed by `platform_accounts.platform`.
 *
 * Empty in Phase 2.5, and that is the correct state: no provider is implemented yet. An empty
 * registry is why `CapabilityResolver` must fail closed rather than fall back to a permissive
 * default - "we do not have code for this platform" and "this account may do anything" must never
 * be the same answer.
 */
final class PlatformRegistry
{
    /** @var array<string, PlatformProvider> */
    private array $providers = [];

    public function register(PlatformProvider $provider): void
    {
        $this->providers[$provider->platform()] = $provider;
    }

    public function has(string $platform): bool
    {
        return isset($this->providers[$platform]);
    }

    public function get(string $platform): ?PlatformProvider
    {
        return $this->providers[$platform] ?? null;
    }

    /** @return list<string> */
    public function platforms(): array
    {
        return array_keys($this->providers);
    }
}
