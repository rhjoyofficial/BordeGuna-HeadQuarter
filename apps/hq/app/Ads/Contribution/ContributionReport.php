<?php

namespace App\Ads\Contribution;

use App\Ads\Dto\AdMoney;
use App\Models\Business;
use App\Models\FactsAdSpend;
use Carbon\CarbonImmutable;

/**
 * Delivered contribution against ad spend, for one business and period (`docs/23`, `docs/84` C.17).
 * READS ONLY.
 *
 *   contribution = delivered net goods revenue - cost of those goods - ad spend
 *
 * ⛔ **Three rules that make it honest rather than flattering:**
 *  1. **Delivered, never the platform's purchases.** Revenue comes from a `DeliveredSalesSource` (a
 *     system of record), never from `facts_ad_spend.purchases`.
 *  2. **A missing cost is a blank, never a zero.** If any delivered SKU has no cost price, the
 *     contribution is null and the SKUs are named (`CostPriceSource`).
 *  3. **One currency or no answer.** Spend in a currency other than the revenue's makes the
 *     contribution "not comparable", never converted (`docs/84` C.21).
 *
 * Every part says where it came from: spend is `third_party` (the platform's count, as last read),
 * revenue and cost are the business's (`business` provenance, the named source).
 *
 * Attribution is exact and deliberately narrow: an order counts for a campaign only when the order's
 * recorded `campaign_ref` equals the platform campaign id. Everything else is unattributed, reported
 * beside the campaigns, never spread across them by a guess.
 */
final class ContributionReport
{
    private const SCALE = 4;

    public function __construct(
        private readonly DeliveredSalesSource $sales,
        private readonly CostPriceSource $costs,
    ) {}

    /** @return array<string, mixed> */
    public function build(Business $business, CarbonImmutable $since, CarbonImmutable $until): array
    {
        $tz = (string) config('hq.timezone', 'Asia/Dhaka');
        $from = CarbonImmutable::parse($since->toDateString(), $tz)->startOfDay();
        $to = CarbonImmutable::parse($until->toDateString(), $tz)->endOfDay();

        $spendRows = FactsAdSpend::query()
            ->where('business_id', $business->id)
            ->where('level', 'campaign')
            ->whereBetween('spend_date', [$from->toDateString(), $to->toDateString()])
            ->get(['campaign_ref', 'object_ref', 'object_name', 'currency', 'spend', 'fetched_at']);

        $sales = $this->sales->delivered($business, $from, $to);
        $revenueCurrency = $sales[0]->currency ?? FactsOrdersDeliveredSales::CURRENCY;

        // Spend per campaign, per currency.
        $spendByCurrency = [];
        $campaigns = [];
        foreach ($spendRows as $row) {
            $currency = (string) $row->currency;
            $spendByCurrency[$currency][] = (string) $row->spend;
            $ref = (string) ($row->campaign_ref ?? $row->object_ref);
            $campaigns[$ref]['name'] ??= $row->object_name;
            $campaigns[$ref]['currency'] = $currency;
            $campaigns[$ref]['spend'][] = (string) $row->spend;
        }
        $spendTotals = [];
        foreach ($spendByCurrency as $currency => $amounts) {
            $spendTotals[$currency] = AdMoney::sum($amounts, $currency);
        }
        $otherCurrencies = array_values(array_filter(array_keys($spendTotals), fn ($c) => $c !== $revenueCurrency));

        // Revenue and cost, per order, costed line by line.
        $missing = [];
        $orders = [];
        foreach ($sales as $sale) {
            $cost = '0';
            $costed = $sale->lines !== [];
            foreach ($sale->lines as $line) {
                $unit = $this->costs->unitCost($business, $line['sku'], CarbonImmutable::parse($sale->deliveredAt));
                if ($unit === null || strtoupper($unit['currency']) !== $sale->currency) {
                    $missing[$line['sku']] = true;
                    $costed = false;

                    continue;
                }
                $cost = bcadd($cost, bcmul(AdMoney::major($unit['unit_cost'], $sale->currency), $line['qty'], self::SCALE), self::SCALE);
            }
            if ($sale->lines === []) {
                $missing['(an order without lines: '.$sale->orderRef.')'] = true;
            }
            $orders[] = ['sale' => $sale, 'cost' => $costed ? $cost : null];
        }

        $revenue = AdMoney::sum(array_map(fn ($o) => $o['sale']->netRevenue, $orders), $revenueCurrency);
        $allCosted = $missing === [] && $orders !== [];
        $cogs = $allCosted ? self::money(array_reduce($orders, fn ($c, $o) => bcadd($c, $o['cost'], self::SCALE), '0')) : null;
        $spend = $spendTotals[$revenueCurrency] ?? AdMoney::fromMinor('0', $revenueCurrency);

        [$contribution, $reason] = match (true) {
            $otherCurrencies !== [] => [null, 'ad spend in '.implode(', ', $otherCurrencies).' cannot be set against revenue in '.$revenueCurrency.'; never converted'],
            $orders === [] => [null, 'no delivered orders in the period'],
            ! $allCosted => [null, 'cost price unknown for: '.implode(', ', array_keys($missing))],
            default => [self::money(bcsub(bcsub($revenue, $cogs, self::SCALE), $spend, self::SCALE)), null],
        };

        return [
            'business' => $business->slug,
            'period' => ['since' => $from->toDateString(), 'until' => $to->toDateString(), 'timezone' => $tz],
            'currency' => $revenueCurrency,
            'ad_spend' => ['by_currency' => $spendTotals, 'provenance' => 'third_party', 'source' => 'facts_ad_spend (the platform\'s count, as last read)'],
            'delivered' => ['orders' => count($orders), 'net_revenue' => $revenue, 'provenance' => 'business', 'source' => $this->sales->name()],
            'cost_of_goods' => ['amount' => $cogs, 'missing' => array_keys($missing), 'provenance' => 'business', 'source' => $this->costs->name()],
            'contribution' => $contribution,
            'contribution_blank_because' => $reason,
            'campaigns' => $this->perCampaign($campaigns, $orders, $revenueCurrency),
            'unattributed' => $this->unattributed($campaigns, $orders, $revenueCurrency),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function perCampaign(array $campaigns, array $orders, string $currency): array
    {
        $out = [];
        ksort($campaigns);
        foreach ($campaigns as $ref => $c) {
            $mine = array_values(array_filter($orders, fn ($o) => $o['sale']->campaignRef === (string) $ref));
            $spend = AdMoney::sum($c['spend'], $c['currency']);
            $revenue = AdMoney::sum(array_map(fn ($o) => $o['sale']->netRevenue, $mine), $currency);
            // A campaign with spend and no attributed order is a loss of its spend, not a blank: nothing
            // is missing, there is simply no revenue against it.
            $costed = ! in_array(null, array_column($mine, 'cost'), true);
            $cogs = $costed ? self::money(array_reduce($mine, fn ($s, $o) => bcadd($s, $o['cost'], self::SCALE), '0')) : null;

            $out[] = [
                'campaign_ref' => (string) $ref,
                'name' => $c['name'] ?? null,
                'currency' => $c['currency'],
                'spend' => $spend,
                'attributed_orders' => count($mine),
                'net_revenue' => $revenue,
                'cost_of_goods' => $cogs,
                'contribution' => $c['currency'] === $currency && $costed
                    ? self::money(bcsub(bcsub($revenue, $cogs, self::SCALE), $spend, self::SCALE))
                    : null,
            ];
        }

        return $out;
    }

    /** @return array{orders: int, net_revenue: string} */
    private function unattributed(array $campaigns, array $orders, string $currency): array
    {
        $known = array_map('strval', array_keys($campaigns));
        $rest = array_values(array_filter($orders, fn ($o) => $o['sale']->campaignRef === null || ! in_array($o['sale']->campaignRef, $known, true)));

        return [
            'orders' => count($rest),
            'net_revenue' => AdMoney::sum(array_map(fn ($o) => $o['sale']->netRevenue, $rest), $currency),
        ];
    }

    /** A bcmath value rounded half up to two places ("12.345" -> "12.35", "-0.005" -> "-0.01"). */
    private static function money(string $value): string
    {
        $shift = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $shift, self::SCALE), '0', 2);
    }
}
