<?php

namespace App\Platform\Contracts;

use App\Platform\Capabilities;

/**
 * The base every platform integration implements (ADR-022).
 *
 * `ceiling()` is the MOST this provider's code can ever do, declared statically the way
 * `CommercePlatform::capabilities()` already is. It is not what a particular connected account can
 * do: that is the account row's business, and `CapabilityResolver` combines the two.
 *
 * `requiresApproval()` names the operations a platform gates behind its own review process. For
 * Meta that is most of the interesting ones, and it will stay pending for weeks at a time.
 */
interface PlatformProvider
{
    /** Matches `platform_accounts.platform`. */
    public function platform(): string;

    public function ceiling(): Capabilities;

    /** @return list<string> operations the PLATFORM must approve before an account may use them */
    public function requiresApproval(): array;
}
