<?php

namespace App\Ads\Draft;

use App\Ads\Draft\Exceptions\AdsDraftRefused;
use App\Ads\Draft\Exceptions\AdsDraftUnavailable;

/**
 * Preparing a PAUSED ad campaign draft at the platform (capability `ads.draft.create`, docs/144-meta-ads-drafts;
 * `AdsPlatform::CREATE_DRAFT` of ADR-050).
 *
 * ⛔ **Creates, never switches on.** The interface has one method and it makes objects that are paused. There is no
 * activate, resume, publish, budget-change, delete or spending-limit method, so no implementation can be asked to;
 * a person switches a draft on in Ads Manager (`AdsHumanOnly`, ADR-050 clarifications 2, 3 and 6).
 * ⛔ The spec is checked by `AdsDraftGuard` BEFORE the platform is called: the per-campaign daily budget cap, the
 * Bangla-only copy rule and the health-claims check cannot be skipped by an implementation.
 *
 * An implementation authenticates only through the `CredentialVault` scope the caller opened.
 *
 * ⭐ Contract tests (`tests/Feature/Ads/AdsDraftContractTest`): every object created reports status PAUSED; a
 * rejected token is `AdsDraftRefused`; an unreachable platform is `AdsDraftUnavailable`.
 */
interface AdsDraftPlatform
{
    public function key(): string;

    /**
     * @param  string  $accountRef  the business slug the credential is stored under
     * @param  string  $adAccountId  the platform ad account id (digits)
     *
     * @throws AdsDraftRefused
     * @throws AdsDraftUnavailable
     */
    public function createPausedDraft(string $accountRef, string $adAccountId, AdsDraftSpec $spec): AdsDraftResult;
}
