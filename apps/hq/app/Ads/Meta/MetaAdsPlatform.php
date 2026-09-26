<?php

namespace App\Ads\Meta;

use App\Ads\AdsCredential;
use App\Ads\AdsReader;
use App\Commerce\Support\ConnectorCallLogger;
use App\Gateway\Credentials\CredentialVault;
use App\Models\Business;
use App\Models\PlatformAccount;
use App\Platform\Capabilities;
use App\Platform\Contracts\AdsPlatform;
use App\Platform\Contracts\AdsReadPlatform;
use InvalidArgumentException;

/**
 * Meta ad accounts, READ side only (Phase 5, stream S, `docs/88` A point 4).
 *
 * ⛔ **Registered nowhere.** This class is not in `PlatformRegistry` (the registry is built empty in
 * `AppServiceProvider` and nothing adds to it), so `CapabilityRouter` answers "HQ has no provider
 * implementation for [meta_ad_account]" for every ads request, whatever credential or account row
 * exists. Registering it is part of gate P5-G1, with the owner's system-user token, and is a
 * reviewed release, never a switch. A test holds that the application's registry does not contain it.
 *
 * ⛔ **Its ceiling is the two reads.** `ceiling()` is the most this CODE can ever do (ADR-022), and this
 * code can only read, so even a registered provider could never be routed a write.
 *
 * The platform name is `meta_ad_account`, the value the seeded account row already carries
 * (`config/hq-seed.php`, `BordeGuna Ads (BDT)`); `docs/84` C.2 wrote `meta_ads`, and the existing
 * row wins so no data has to move.
 */
final class MetaAdsPlatform implements AdsReadPlatform
{
    public const PLATFORM = 'meta_ad_account';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ConnectorCallLogger $logger,
    ) {}

    public function platform(): string
    {
        return self::PLATFORM;
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([AdsPlatform::READ_INSIGHTS, AdsPlatform::READ_CAMPAIGNS]);
    }

    /**
     * The operations Meta itself gates behind its access tiers or review (`docs/84` C.6): every write.
     * None of them is in the ceiling above; they are named so the day a write adapter exists, an
     * account that is not approved cannot be routed one.
     */
    public function requiresApproval(): array
    {
        return [AdsPlatform::CREATE_DRAFT, AdsPlatform::CHANGE_BUDGET, AdsPlatform::PAUSE, AdsPlatform::PUBLISH];
    }

    public function reader(Business $business, PlatformAccount $account): AdsReader
    {
        if ($account->platform !== self::PLATFORM) {
            throw new InvalidArgumentException("Account [{$account->account_ref}] is [{$account->platform}], not a Meta ad account.");
        }

        // From the gateway's open scope only; outside a gateway call that declared the credential
        // this throws, by design (CredentialVault rule 1).
        $token = $this->vault->secret(AdsCredential::PROVIDER, $business->slug);

        return new MetaAdsReader(
            businessSlug: $business->slug,
            accountRef: (string) $account->account_ref,
            token: $token,
            logger: $this->logger,
            baseUrl: (string) config('hq.ads.meta.base_url', 'https://graph.facebook.com'),
            version: (string) config('hq.ads.meta.graph_version', 'v26.0'),
            timeoutSeconds: (int) config('hq.ads.meta.timeout_seconds', 20),
            backoffAtPercent: (int) config('hq.ads.meta.backoff_at_percent', 80),
        );
    }
}
