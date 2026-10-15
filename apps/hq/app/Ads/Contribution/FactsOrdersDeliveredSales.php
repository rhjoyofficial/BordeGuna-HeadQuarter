<?php

namespace App\Ads\Contribution;

use App\Ads\Dto\AdMoney;
use App\Models\Business;
use App\Models\FactsOrder;
use Carbon\CarbonImmutable;

/**
 * Delivered orders as HQ recorded them from the shops' own events (`facts_orders`, Business
 * provenance: the shop is the system of record for its orders).
 *
 * Counted: `delivered_at` inside the period (bounds of any zone, compared as UTC instants), never cancelled. Revenue: subtotal less the order's
 * discount, in BDT (the shops sell in taka); delivery charges are not goods revenue. Lines carry SKU
 * and quantity for costing. An order without lines is still revenue but cannot be costed, which the
 * report says.
 */
final class FactsOrdersDeliveredSales implements DeliveredSalesSource
{
    public const CURRENCY = 'BDT';

    public function delivered(Business $business, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return FactsOrder::query()
            ->where('business_id', $business->id)
            ->whereNotNull('delivered_at')
            ->whereNull('cancelled_at')
            // W5-TZ (docs/124 §5): a bound Carbon reaches Postgres as `Y-m-d H:i:s` WITHOUT its offset and the session
            // zone is UTC, so a Dhaka-local day [00:00, 23:59:59] would be read as a UTC day, six hours off. The
            // bounds are converted to the UTC instants they name first; `delivered_at` is stored in UTC.
            ->whereBetween('delivered_at', [$from->utc(), $to->utc()])
            ->orderBy('delivered_at')->orderBy('id')
            ->get()
            ->map(function (FactsOrder $o) {
                $net = AdMoney::toMinor((string) $o->subtotal, self::CURRENCY) - AdMoney::toMinor((string) $o->discount_total, self::CURRENCY);
                $lines = [];
                foreach ((array) ($o->lines ?? []) as $line) {
                    if (is_array($line) && isset($line['sku']) && $line['sku'] !== '') {
                        $lines[] = ['sku' => (string) $line['sku'], 'qty' => (string) ($line['qty'] ?? '1')];
                    }
                }

                return new DeliveredSale(
                    orderRef: (string) $o->order_ref,
                    storeId: $o->store_id,
                    deliveredAt: $o->delivered_at->toIso8601String(),
                    campaignRef: $o->campaign_ref !== null && $o->campaign_ref !== '' ? (string) $o->campaign_ref : null,
                    currency: self::CURRENCY,
                    netRevenue: (string) AdMoney::fromMinor((string) $net, self::CURRENCY),
                    lines: $lines,
                );
            })->all();
    }

    public function name(): string
    {
        return 'facts_orders';
    }
}
