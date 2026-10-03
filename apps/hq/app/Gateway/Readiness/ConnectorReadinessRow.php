<?php

namespace App\Gateway\Readiness;

/**
 * One connector in the readiness table (docs/98 §3): capability, provider, transport and state.
 *
 * A separate row type from `ReadinessRow` on purpose. Those rows are per TARGET (a store, a business,
 * HQ's model key) and drive the incident exit code; these are per CONNECTOR and describe the
 * registry. Keeping them apart is what lets the registry be added without changing a single existing
 * row, incident or exit code.
 *
 * States: the credential states of `ReadinessRow` for a registered connector whose credentials are
 * fixed (`verified`, `credential_unverified`, `credential_missing`, `credential_invalid`, the provider
 * states), plus `ready` (registered, on, needs no credential), `disabled` (its switch is off),
 * `scope_dependent` (its credentials are looked up per business, brand or account, so they are checked
 * at resolution), and `legacy` (served by one of the five legacy shapes, reported only).
 */
final readonly class ConnectorReadinessRow
{
    public const ORIGIN_REGISTRY = 'registry';

    public const ORIGIN_LEGACY = 'legacy';

    public const READY = 'ready';

    public const SCOPE_DEPENDENT = 'scope_dependent';

    public const LEGACY = 'legacy';

    /**
     * @param  list<string>  $credentials  `kind <- account_ref_from`, names only
     */
    public function __construct(
        public string $capability,
        public string $provider,
        public string $transport,
        public string $adapter,
        public string $origin,
        public string $state,
        public string $reason,
        public array $credentials = [],
        public ?int $priority = null,
        public bool $fallback = false,
        /** The legacy class that resolves it, for a legacy row. */
        public ?string $shape = null,
    ) {}

    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'provider' => $this->provider,
            'transport' => $this->transport,
            'adapter' => $this->adapter,
            'origin' => $this->origin,
            'state' => $this->state,
            'reason' => $this->reason,
            'credentials' => $this->credentials,
            'priority' => $this->priority,
            'fallback' => $this->fallback,
            'shape' => $this->shape,
        ];
    }
}
