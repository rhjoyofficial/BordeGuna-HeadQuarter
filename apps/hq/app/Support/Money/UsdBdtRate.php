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

    /**
     * A stored USD amount as text an operator reads: "BDT 115.41 (USD 0.8878)", BDT first by Joy's
     * rule, the stored USD figure kept beside it so the ledger value is still visible. With no valid
     * rate the text is "USD 0.8878" alone, never a guessed BDT. Display only: callers keep comparing,
     * capping and storing the USD value.
     */
    public static function display(float|int|string|null $usd, int $usdDecimals = 4): string
    {
        $amount = (float) ($usd ?? 0);
        $usdText = 'USD '.number_format($amount, $usdDecimals);
        $rate = self::current();

        return $rate === null
            ? $usdText
            : 'BDT '.number_format($amount * $rate, 2).' ('.$usdText.')';
    }
}
