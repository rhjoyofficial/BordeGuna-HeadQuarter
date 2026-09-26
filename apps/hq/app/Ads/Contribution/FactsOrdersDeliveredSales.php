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
 * Counted: `delivered_at` inside the period, never cancelled. Revenue: subtotal less the order's
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
            ->whereBetween('delivered_at', [$from, $to])
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
