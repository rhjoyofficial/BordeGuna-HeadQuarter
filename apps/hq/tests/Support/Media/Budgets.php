<?php

namespace Tests\Support\Media;

use App\Models\MediaBudget;

/** Test-only: give a business a monthly media budget directly (production sets it only through the owner's screen). */
final class Budgets
{
    public static function grant(int $businessId, string $limitBdt = '1000.00'): MediaBudget
    {
        return MediaBudget::query()->updateOrCreate(['business_id' => $businessId], ['monthly_limit_bdt' => $limitBdt, 'reason' => 'test']);
    }
}
