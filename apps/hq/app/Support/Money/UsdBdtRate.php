<?php

namespace App\Support\Money;

/**
 * BDT per USD, for SHOWING a model cost in BDT (Joy's rule: amounts always in BDT).
 *
 * Display only. Model costs are stored, capped and enforced in USD because the providers bill in USD;
 * nothing here converts a stored value, a cap or a check. The rate is the one configured for the ad
 * drafts (`hq.ads.draft.usd_bdt_rate`), so HQ has a single rate and the browser never holds its own.
 * An empty or malformed rate gives null, and the screens then show USD alone rather than a guessed BDT.
 */
final class UsdBdtRate
{
    public static function current(): ?float
    {
        $rate = trim((string) config('hq.ads.draft.usd_bdt_rate', ''));
        if (preg_match('/^\d+(\.\d{1,4})?$/', $rate) !== 1 || (float) $rate <= 0) {
            return null;
        }

        return (float) $rate;
    }
}
