<?php

namespace App\Ads;

/**
 * The credential an ad platform read needs (`docs/84` C.8).
 *
 * A **system user** token from the official business portfolio, stored on `/credentials` as provider
 * `meta_ads_token` with `account_ref` = the HQ business slug, read only through the CredentialVault
 * inside a gateway call. A personal user token is not an acceptable kind for ads: the connection is
 * the business's, never a person's.
 *
 * ⛔ None exists (gate P5-G0 is the owner storing it). Holding it authorises nothing inside HQ
 * (ADR-050 clarification 10): the gateway, the Effective Execution Context and the approval decide.
 */
final class AdsCredential
{
    public const PROVIDER = 'meta_ads_token';
}
