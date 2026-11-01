<?php

namespace Tests\Support\Media;

use App\Models\MediaBudget;
use Tests\Support\Money\Fx;

/** Test-only: give a business a monthly media budget directly (production sets it only through the owner's screen). */
final class Budgets
{
    public static function grant(int $businessId, string $limitBdt = '1000.00'): MediaBudget
    {
        // A budget is only usable with a valid exchange rate for USD-priced providers; keep the tests independent of the date
        // the shipped rate expires (ruling 1 Nov 2026).
        Fx::ensureValid();

        return MediaBudget::query()->updateOrCreate(['business_id' => $businessId], ['monthly_limit_bdt' => $limitBdt, 'reason' => 'test']);
    }
}
