<?php

namespace App\Ai\Providers;

use App\Ai\Exceptions\ProviderRefused;
use App\Ai\Exceptions\ProviderUnavailable;

/**
 * One model provider, behind one interface.
 *
 * Adding a provider is a class implementing this plus rows in `model_tiers` and one
 * `integration_credentials` row. It is never a refactor, which is the point of ADR-019: a provider
 * outage must not be an HQ outage, and that is only true if a second provider is cheap to add
 * before it is needed rather than during the incident.
 *
 * ⚠ **A driver never reads a credential itself.** It asks `CredentialVault::secret()` inside a scope
 * the runtime opened, exactly as the ERP and commerce connectors do. A filesystem-scanning test
 * enforces that the vault stays the only decryption point.
 *
 * ⚠ **This is the only layer allowed to name a model id**, and a grep test enforces it.
 */
interface ModelProvider
{
    /** Matches `model_tiers.provider`. */
    public function key(): string;

    /** Whether a usable credential is present right now. No network call. */
    public function available(): bool;

    /**
     * @throws ProviderUnavailable transport failure, timeout, 5xx, rate limit
     * @throws ProviderRefused the provider rejected the request itself (4xx)
     */
    public function complete(ModelRequest $request): ModelResponse;

    /** The (provider, account_ref) pair whose credential this driver needs. */
    public function credentialPair(): array;
}
