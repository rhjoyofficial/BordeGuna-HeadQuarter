<?php

namespace App\Platform\Contracts;

use App\Ads\AdsReader;
use App\Models\Business;
use App\Models\PlatformAccount;

/**
 * The READ half of paid media (Phase 5, ADR-050 accepted 22 September, `docs/88` A point 4).
 *
 * A provider that implements this can read one connected ad account: the account itself (currency,
 * time zone, the owner's spending limit), its campaigns, ad sets and ads, and its insights. Nothing
 * above `AdsReader` names a vendor, an endpoint or an API version; a Google Ads or TikTok reader is
 * another implementation of the same two interfaces.
 *
 * ⛔ **There is no write half here, and none is implied.** Preparing, pausing, changing a budget and
 * switching a campaign on are `AdsPlatform` operations with their own tools, approvals and gates
 * (`docs/84` D, gate P5-G2), and none of them exists yet. A provider that implements only this
 * interface declares a `ceiling()` of the two read operations and nothing more, so even a
 * registered provider could never be routed a write.
 */
interface AdsReadPlatform extends AdsPlatform
{
    /**
     * A reader for one ad account of one business. The reader authenticates with the business's
     * credential from the CredentialVault's open scope, so it works only inside a gateway call that
     * declared that credential; outside one it refuses, it never looks the credential up itself.
     */
    public function reader(Business $business, PlatformAccount $account): AdsReader;
}
