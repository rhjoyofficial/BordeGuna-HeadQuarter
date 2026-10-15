<?php

use App\Ads\Contribution\ContributionReport;
use App\Ads\Contribution\CostPriceSource;
use App\Ads\Contribution\FactsOrdersDeliveredSales;
use App\Facts\FactsOrderUpserter;
use App\Models\Business;
use App\Models\FactsOrder;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;

/*
 | W5-TZ (docs/120 plan, docs/124 §5, docs/125): a shop timestamp is stored as the UTC instant it names, whatever
 | offset the shop sends, and the contribution report's Dhaka-local day is converted to UTC before it reaches
 | Postgres. The shops' OrderResource sends `...Z` (tests/Pest.php fixtures, captured from laladia.com); status
 | listeners and the ERP read-back can send `+06:00`. Both must land on the same instant.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->store = Store::where('slug', 'laladia-com')->sole();
    $this->business = Business::where('slug', 'laladia')->sole();
    $this->facts = app(FactsOrderUpserter::class);
});

/** The stored instant, read back in UTC by Postgres itself (no PHP cast in the way). */
function tzStored(int $id, string $column): string
{
    return (string) DB::selectOne("SELECT to_char({$column} AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS v FROM facts_orders WHERE id = ?", [$id])->v;
}

function tzPayload(string $ref, array $extra): array
{
    return array_merge(liveWebhookOrderPayload($ref), $extra);
}

it('stores a +06:00 shop timestamp as the UTC instant it names, the same as the Z form of that instant', function () {
    $dhaka = $this->facts->upsertFromShopPayload($this->store, tzPayload('LLD-TZ-1', [
        'placed_at' => '2026-09-20T10:00:00+06:00', 'order_status' => 'delivered', 'delivered_at' => '2026-09-20T23:30:00+06:00',
    ]));
    $zulu = $this->facts->upsertFromShopPayload($this->store, tzPayload('LLD-TZ-2', [
        'placed_at' => '2026-09-20T04:00:00.000000Z', 'order_status' => 'delivered', 'delivered_at' => '2026-09-20T17:30:00.000000Z',
    ]));

    expect(tzStored($dhaka->id, 'delivered_at'))->toBe('2026-09-20 17:30:00')
        ->and(tzStored($dhaka->id, 'placed_at'))->toBe('2026-09-20 04:00:00')
        ->and(tzStored($zulu->id, 'delivered_at'))->toBe('2026-09-20 17:30:00')
        ->and(tzStored($zulu->id, 'placed_at'))->toBe('2026-09-20 04:00:00');

    // A status event with a +06:00 `changed_at` (the shop listeners' shape) lands on its instant too.
    $this->facts->upsertFromShopPayload($this->store, tzPayload('LLD-TZ-3', ['placed_at' => '2026-09-20T04:00:00.000000Z']));
    $fact = $this->facts->applyStatusEvent($this->store, 'order.status_changed',
        ['order_number' => 'LLD-TZ-3', 'old_status' => 'shipped', 'new_status' => 'delivered', 'changed_at' => '2026-09-21T00:30:00+06:00']);
    expect(tzStored($fact->id, 'delivered_at'))->toBe('2026-09-20 18:30:00');
});

it('counts a delivery in the Dhaka day it happened in, at both edges of the day', function () {
    $deliver = fn (string $ref, string $at, string $subtotal) => $this->facts->upsertFromShopPayload($this->store, tzPayload($ref, [
        'placed_at' => '2026-09-18T04:00:00.000000Z', 'order_status' => 'delivered', 'delivered_at' => $at, 'subtotal' => $subtotal, 'discount_total' => 0,
    ]));
    $deliver('LLD-EDGE-START', '2026-09-19T18:00:00.000000Z', '100');  // 20 Sep 00:00:00 Dhaka: the first second of the day
    $deliver('LLD-EDGE-END', '2026-09-20T23:59:59+06:00', '200');      // 20 Sep 23:59:59 Dhaka: the last second
    $deliver('LLD-NEXT-DAY', '2026-09-20T18:30:00.000000Z', '400');    // 21 Sep 00:30 Dhaka: a UTC "20 Sep" that is not
    $deliver('LLD-DAY-BEFORE', '2026-09-19T17:59:59.000000Z', '800');  // 19 Sep 23:59:59 Dhaka

    $tz = 'Asia/Dhaka';
    $day = CarbonImmutable::parse('2026-09-20', $tz);
    $sales = (new FactsOrdersDeliveredSales)->delivered($this->business, $day->startOfDay(), $day->endOfDay());
    expect(array_map(fn ($s) => $s->orderRef, $sales))->toBe(['LLD-EDGE-START', 'LLD-EDGE-END']);

    $costs = new class implements CostPriceSource
    {
        public function unitCost(Business $business, string $sku, CarbonImmutable $on): ?array
        {
            return null;
        }

        public function name(): string
        {
            return 'none';
        }
    };
    $report = (new ContributionReport(new FactsOrdersDeliveredSales, $costs))->build($this->business, $day, $day);
    expect($report['delivered']['orders'])->toBe(2)
        ->and($report['delivered']['net_revenue'])->toBe('300.00')
        ->and($report['period']['timezone'])->toBe($tz);

    expect(FactsOrder::count())->toBe(4);
});
