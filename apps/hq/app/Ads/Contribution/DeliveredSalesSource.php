<?php

namespace App\Ads\Contribution;

use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * Orders a business actually DELIVERED in a period, from a system of record (`docs/84` C.14, C.17).
 *
 * ⛔ Never the ad platform's purchase count: a COD order counts when the business says it was
 * delivered, and about a third were cancelled historically (`docs/16`). Every sale carries its source
 * so a report can say where each figure came from.
 */
interface DeliveredSalesSource
{
    /** @return list<DeliveredSale> delivered between the two instants, cancelled orders excluded */
    public function delivered(Business $business, CarbonImmutable $from, CarbonImmutable $to): array;

    /** For provenance: what this source is ("facts_orders", "erp", ...). */
    public function name(): string;
}
