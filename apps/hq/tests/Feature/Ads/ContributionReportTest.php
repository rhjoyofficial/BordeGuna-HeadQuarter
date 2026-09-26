<?php

use App\Ads\Contribution\ContributionReport;
use App\Ads\Contribution\CostPriceSource;
use App\Ads\Contribution\FactsOrdersDeliveredSales;
use App\Models\Brand;
use App\Models\Business;
use App\Models\FactsAdSpend;
use App\Models\FactsOrder;
use App\Models\PlatformAccount;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;

/*
 | The contribution report (stream S, docs/23, docs/84 C.17): delivered revenue from the business's own
 | records, cost prices from the ERP (a written fixture here: HQ cannot read ERP cost prices yet), ad
 | spend from the read model. Honest before flattering: a missing cost is a blank, Meta's purchases are
 | never revenue, and currencies are never mixed.
 */

/** The ERP's cost prices, as a fixture: HQ's read contract does not carry them yet (docs/88-s-record.md). */
final class FixtureErpCostPrices implements CostPriceSource
{
    public function __construct(private readonly array $costs) {}

    public function unitCost(Business $business, string $sku, CarbonImmutable $on): ?array
    {
        return isset($this->costs[$sku]) ? ['unit_cost' => $this->costs[$sku], 'currency' => 'BDT', 'source' => 'erp (fixture)', 'as_of' => '2026-09-01'] : null;
    }

    public function name(): string
    {
        return 'erp (fixture)';
    }
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->business = Business::where('slug', 'laladia')->sole();
    $this->brand = Brand::where('code', 'LLD')->sole();
    $this->store = Store::where('slug', 'laladia-com')->sole();
    $this->adAccount = PlatformAccount::create(['brand_id' => $this->brand->id, 'platform' => 'meta_ad_account', 'account_ref' => '37981153204862266', 'name' => 'La La Dia Ad Account']);
});

function contributionSpend(string $campaign, string $date, string $spend, string $currency = 'BDT', ?int $purchases = null, ?string $value = null): void
{
    $account = PlatformAccount::where('account_ref', '37981153204862266')->sole();
    FactsAdSpend::create([
        'platform_account_id' => $account->id, 'business_id' => Business::where('slug', 'laladia')->value('id'), 'brand_id' => $account->brand_id,
        'platform' => 'meta_ad_account', 'account_ref' => 'act_37981153204862266', 'level' => 'campaign', 'object_ref' => $campaign,
        'object_name' => "campaign {$campaign}", 'campaign_ref' => $campaign, 'spend_date' => $date, 'currency' => $currency, 'spend' => $spend,
        'purchases' => $purchases, 'purchase_value' => $value, 'source' => 'meta_ads', 'api_version' => 'v26.0', 'fetched_at' => now(),
    ]);
}

function contributionOrder(string $ref, array $attrs): void
{
    FactsOrder::create(array_merge([
        'business_id' => Business::where('slug', 'laladia')->value('id'), 'store_id' => Store::where('slug', 'laladia-com')->value('id'),
        'order_ref' => $ref, 'status' => 'delivered', 'subtotal' => '0', 'discount_total' => '0', 'shipping_cost' => '70', 'gross' => '0',
        'placed_at' => '2026-09-18 10:00:00+06',
    ], $attrs));
}

function contributionReport(array $costs): ContributionReport
{
    return new ContributionReport(new FactsOrdersDeliveredSales, new FixtureErpCostPrices($costs));
}

function seedContributionWeek(): void
{
    contributionSpend('C1', '2026-09-20', '412.35', purchases: 9, value: '99999.00'); // Meta's purchases: never revenue
    contributionSpend('C1', '2026-09-21', '500.00');
    contributionSpend('C2', '2026-09-21', '87.10');

    contributionOrder('O1', ['subtotal' => '1980', 'delivered_at' => '2026-09-20 15:00:00+06', 'campaign_ref' => 'C1',
        'lines' => [['sku' => 'HLS-400', 'name' => 'Hilsa pickle 400g', 'qty' => 2, 'unit_price' => 990, 'total' => 1980]]]);
    contributionOrder('O2', ['subtotal' => '950', 'discount_total' => '50', 'delivered_at' => '2026-09-21 11:00:00+06',
        'lines' => [['sku' => 'MST-1L', 'name' => 'Mustard oil 1L', 'qty' => 1, 'unit_price' => 950, 'total' => 950]]]);
    // Not counted: cancelled after delivery was recorded, never delivered, delivered outside the period.
    contributionOrder('O3', ['subtotal' => '5000', 'delivered_at' => '2026-09-20 12:00:00+06', 'cancelled_at' => '2026-09-20 18:00:00+06', 'campaign_ref' => 'C1',
        'lines' => [['sku' => 'HLS-400', 'qty' => 5]]]);
    contributionOrder('O4', ['subtotal' => '4000', 'status' => 'shipped', 'campaign_ref' => 'C1', 'lines' => [['sku' => 'HLS-400', 'qty' => 4]]]);
    contributionOrder('O5', ['subtotal' => '3000', 'delivered_at' => '2026-09-25 12:00:00+06', 'campaign_ref' => 'C1', 'lines' => [['sku' => 'HLS-400', 'qty' => 3]]]);
}

it('sets delivered revenue less cost of goods against ad spend, exactly, per campaign and in total', function () {
    seedContributionWeek();

    $r = contributionReport(['HLS-400' => '310', 'MST-1L' => '250.00'])
        ->build($this->business, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));

    expect($r['currency'])->toBe('BDT')
        ->and($r['ad_spend']['by_currency'])->toBe(['BDT' => '999.45'])
        ->and($r['delivered'])->toMatchArray(['orders' => 2, 'net_revenue' => '2880.00', 'provenance' => 'business', 'source' => 'facts_orders'])
        ->and($r['cost_of_goods'])->toMatchArray(['amount' => '870.00', 'missing' => [], 'source' => 'erp (fixture)'])
        ->and($r['contribution'])->toBe('1010.55')
        ->and($r['contribution_blank_because'])->toBeNull();

    expect($r['campaigns'][0])->toMatchArray(['campaign_ref' => 'C1', 'spend' => '912.35', 'attributed_orders' => 1, 'net_revenue' => '1980.00',
        'cost_of_goods' => '620.00', 'contribution' => '447.65'])
        // Spend with no attributed order is a loss of that spend, not a blank.
        ->and($r['campaigns'][1])->toMatchArray(['campaign_ref' => 'C2', 'spend' => '87.10', 'attributed_orders' => 0, 'contribution' => '-87.10'])
        ->and($r['unattributed'])->toBe(['orders' => 1, 'net_revenue' => '900.00']);
});

it('leaves the contribution blank and names the SKU when a cost price is unknown, never costing it at zero', function () {
    seedContributionWeek();

    $r = contributionReport(['HLS-400' => '310'])->build($this->business, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));

    expect($r['contribution'])->toBeNull()
        ->and($r['contribution_blank_because'])->toBe('cost price unknown for: MST-1L')
        ->and($r['cost_of_goods'])->toMatchArray(['amount' => null, 'missing' => ['MST-1L']])
        // The campaign whose orders are all costed still has its own answer.
        ->and($r['campaigns'][0]['contribution'])->toBe('447.65');
});

it('answers blank on production today, because HQ cannot read ERP cost prices yet, and says so', function () {
    seedContributionWeek();

    $r = app(ContributionReport::class)->build($this->business, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));

    expect($r['contribution'])->toBeNull()
        ->and($r['contribution_blank_because'])->toContain('cost price unknown for: HLS-400, MST-1L')
        ->and($r['cost_of_goods']['source'])->toContain('not in HQ\'s read contract');
});

it('never sets spend in one currency against revenue in another', function () {
    seedContributionWeek();
    contributionSpend('C9', '2026-09-21', '10.00', 'USD');

    $r = contributionReport(['HLS-400' => '310', 'MST-1L' => '250'])->build($this->business, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));

    expect($r['contribution'])->toBeNull()
        ->and($r['contribution_blank_because'])->toContain('USD cannot be set against revenue in BDT')
        ->and($r['ad_spend']['by_currency'])->toBe(['BDT' => '999.45', 'USD' => '10.00'])
        ->and(collect($r['campaigns'])->firstWhere('campaign_ref', 'C9')['contribution'])->toBeNull();
});

it('writes nothing while it reports', function () {
    seedContributionWeek();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|merge|truncate)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    contributionReport(['HLS-400' => '310', 'MST-1L' => '250'])->build($this->business, CarbonImmutable::parse('2026-09-20'), CarbonImmutable::parse('2026-09-21'));

    expect($writes)->toBe([]);
});
