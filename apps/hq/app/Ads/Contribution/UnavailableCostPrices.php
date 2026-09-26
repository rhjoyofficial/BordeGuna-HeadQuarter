<?php

namespace App\Ads\Contribution;

use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * The cost source HQ has TODAY: none.
 *
 * The ERP holds cost prices (once the owner enters them, `docs/82` O-8), but the HQ read contract
 * (`hq-order-reads.v1`) does not carry them, so HQ cannot read one. Every cost is therefore unknown and
 * every contribution is blank, with the reason, which is the honest answer. An ERP cost read is a
 * contract change on both sides, recorded as an open item in `docs/88-s-record.md`.
 */
final class UnavailableCostPrices implements CostPriceSource
{
    public function unitCost(Business $business, string $sku, CarbonImmutable $on): ?array
    {
        return null;
    }

    public function name(): string
    {
        return 'unavailable: the ERP cost price is not in HQ\'s read contract yet';
    }
}
