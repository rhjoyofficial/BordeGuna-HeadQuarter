<?php

namespace Tests\Support\Money;

use App\Models\FxRate;
use App\Support\Money\FxRates;
use Carbon\CarbonImmutable;

/**
 * Test-only: write an exchange rate row directly (production writes one only through `FxRates::setRate()` on the owner's
 * screen). Rows are append-only, so a test "changes" the rate the way production does: by adding a newer row.
 */
final class Fx
{
    public static function set(string $reference, string $reserve, ?CarbonImmutable $asOf = null, ?CarbonImmutable $validUntil = null): FxRate
    {
        $asOf ??= CarbonImmutable::now()->subDay();
        $validUntil ??= CarbonImmutable::now()->addDays(30);
        $margin = bcmul(bcsub(bcdiv($reserve, $reference, 10), '1', 10), '100', 4);

        $row = FxRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'BDT', 'reference_rate' => $reference, 'reserve_rate' => $reserve,
            'safety_margin_percent' => $margin, 'source' => 'test', 'as_of' => $asOf, 'valid_until' => $validUntil, 'reason' => 'test',
        ]);
        app()->forgetScopedInstances();

        return $row;
    }

    /**
     * A currently valid rate with the ruling's figures (reference 123.99, reserve 136.50), added only when the newest one is not
     * valid today, so a test that reserves USD does not depend on the calendar date the shipped row expires.
     */
    public static function ensureValid(): void
    {
        if (app(FxRates::class)->state() !== FxRates::VALID) {
            self::set('123.99', '136.50');
        }
    }
}
