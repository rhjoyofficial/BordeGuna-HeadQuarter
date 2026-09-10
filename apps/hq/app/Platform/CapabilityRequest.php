<?php

namespace App\Platform;

/**
 * A request for a BUSINESS CAPABILITY, not for a vendor (ADR-029).
 *
 * ⛔ **There is no field here for a platform, an endpoint, a URL or an API version.** A caller that
 * could name one would be a caller that had already made the routing decision, and the whole point
 * of the registry is that the decision belongs to HQ rather than to whoever is asking.
 *
 * `preferAccountRef` is the one exception and it is deliberately narrow: it disambiguates between
 * accounts on the SAME platform that could both serve the request, which is a real operational need
 * (two Facebook Pages under one brand). It cannot select a platform, and it cannot make an account
 * capable of something the resolver says it cannot do - it only breaks a tie.
 */
final readonly class CapabilityRequest
{
    public function __construct(
        /** The operation, from a `PlatformProvider` contract: MessagingChannel::SEND_TEXT and so on. */
        public string $capability,
        public ?int $businessId = null,
        public ?int $brandId = null,
        /** Breaks a tie between equally-valid accounts. It never widens what one may do. */
        public ?string $preferAccountRef = null,
    ) {}
}
