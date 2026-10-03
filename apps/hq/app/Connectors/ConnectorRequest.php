<?php

namespace App\Connectors;

use InvalidArgumentException;

/**
 * A request for a CAPABILITY, not for a vendor (docs/97 §6 D2, the ADR-029 shape).
 *
 * ⛔ There is no field for an adapter, an endpoint or a transport. A caller that could name one would
 * have made the provider decision itself, and that decision belongs to the registry. The scope fields
 * only narrow: they select which connectors may serve and resolve the account a credential is looked
 * up under.
 *
 * `preferProvider` is the one exception, and it is as narrow as `CapabilityRequest::preferAccountRef`
 * (docs/101 §11, ruling 9.2, "approved narrowly"). It only chooses AMONG the connectors that are
 * already ready in the tier that would serve anyway, which includes breaking a tie that would
 * otherwise be refused. It can never reach a connector that is not ready, never lifts a fallback over
 * a ready primary, and never touches policy, permission, consent, credentials, readiness or scope:
 * those were decided before the preference is read (docs/98 §2.4).
 *
 * ⛔ It is set by HQ code from trusted configuration or routing (`model_tiers.provider`,
 * `content.research.provider`), NEVER from a model's tool arguments: a vendor a model could pick
 * would be vendor selection by prompt. `ConnectorPreferenceGuardTest` holds that structurally.
 */
final readonly class ConnectorRequest
{
    private const PROVIDER = '/^[a-z0-9][a-z0-9_.\-]*$/';

    public function __construct(
        /** A name from `Capabilities`, e.g. `voice.transcribe`. */
        public string $capability,
        public ?int $businessId = null,
        public ?int $brandId = null,
        /** `platform_accounts.id`, when the capability acts as one connected account. */
        public ?int $platformAccountId = null,
        /** A provider key from trusted HQ config; chooses only among ready connectors of the serving tier. */
        public ?string $preferProvider = null,
    ) {
        if ($preferProvider !== null && preg_match(self::PROVIDER, $preferProvider) !== 1) {
            throw new InvalidArgumentException("preferProvider [{$preferProvider}] must be a lower-case provider key.");
        }
    }
}
