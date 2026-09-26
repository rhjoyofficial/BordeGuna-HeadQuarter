<?php

namespace App\Ads\Contribution;

use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * The cost price of a SKU, from the ERP (`docs/84` C.21, `docs/82` O-8, `docs/23`).
 *
 * Null means UNKNOWN, and an unknown cost makes the contribution blank with the SKU named: a cost is
 * never zeroed, defaulted or guessed, because a contribution computed on a missing cost overstates
 * exactly the campaigns that sell the products nobody has costed.
 */
interface CostPriceSource
{
    /** @return array{unit_cost: string, currency: string, source: string, as_of: ?string}|null */
    public function unitCost(Business $business, string $sku, CarbonImmutable $on): ?array;

    public function name(): string;
}
