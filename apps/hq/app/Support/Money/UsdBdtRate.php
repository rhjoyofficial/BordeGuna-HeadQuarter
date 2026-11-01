<?php

namespace App\Support\Money;

/**
 * BDT per USD, for SHOWING a model cost in BDT (Joy's rule: amounts always in BDT).
 *
 * Display only. Model costs are stored, capped and enforced in USD because the providers bill in USD; nothing here converts a
 * stored value, a cap or a check. Since the ruling of 1 Nov 2026 the rate is the owner's dated reference rate (`FxRates`, the
 * Exchange rate screen), the same row the media budget reserves against, shown as an estimate. A missing or stale rate gives
 * null, and the text and the screens then show USD alone rather than a guessed BDT.
 */
final class UsdBdtRate
{
    public static function current(): ?float
    {
        return app(FxRates::class)->displayRate();
    }

    /**
     * A stored USD amount as text an operator reads: "BDT 115.41 (USD 0.8878)", BDT first by Joy's rule, the stored USD figure
     * kept beside it so the ledger value is still visible. With no valid rate the text is "USD 0.8878" alone, never a guessed BDT.
     * Display only: callers keep comparing, capping and storing the USD value.
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
