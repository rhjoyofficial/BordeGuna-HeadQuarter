<?php

namespace App\Connectors;

/**
 * A request for a CAPABILITY, not for a vendor (docs/97 §6 D2, the ADR-029 shape).
 *
 * ⛔ There is no field for a provider, an adapter, an endpoint or a transport. A caller that could
 * name one would have made the provider decision itself, and that decision belongs to the registry.
 * The scope fields only narrow: they select which connectors may serve and resolve the account a
 * credential is looked up under.
 */
final readonly class ConnectorRequest
{
    public function __construct(
        /** A name from `Capabilities`, e.g. `voice.transcribe`. */
        public string $capability,
        public ?int $businessId = null,
        public ?int $brandId = null,
        /** `platform_accounts.id`, when the capability acts as one connected account. */
        public ?int $platformAccountId = null,
    ) {}
}
