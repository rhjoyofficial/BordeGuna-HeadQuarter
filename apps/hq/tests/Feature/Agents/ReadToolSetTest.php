<?php

use App\Models\Business;
use App\Models\FactsErpSnapshot;
use App\Models\FactsOrder;
use App\Models\Store;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;

/*
 | The read tool set the two agents answer from (docs/48 D.6, M5).
 |
 | The point of most of these cases is not that the query runs. It is that the COVERAGE is honest:
 | an answer built on four orders must be able to say it was built on four orders, and a source HQ
 | has never held must report itself as absent rather than as zero.
 */

beforeEach(fn () => $this->seed(DomainSeeder::class));

function ctx(array $overrides = []): ToolContext
{
    return new ToolContext(
        idempotencyKey: $overrides['key'] ?? 'test',
        correlationId: 'corr-'.uniqid(),
        businessId: $overrides['business_id'] ?? null,
        permittedBusinessIds: $overrides['permitted'] ?? null,
    );
}

function tool(string $key)
{
    return app(ToolRegistry::class)->get($key);
}

function order(array $overrides = []): FactsOrder
{
    $store = Store::where('slug', $overrides['store'] ?? 'laladia-com')->sole();
    unset($overrides['store']);

    return FactsOrder::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_ref' => 'REF-'.fake()->unique()->numerify('########'),
        'status' => 'delivered',
        'source' => 'checkout',
        'subtotal' => 1000,
        'discount_total' => 0,
        'shipping_cost' => 60,
        'gross' => 1060,
        'district' => 'Dhaka',
        'placed_at' => now()->subDay(),
        'synced_at' => now(),
        'lines' => [['sku' => 'A', 'name' => 'A', 'qty' => 2, 'unit_price' => 500, 'total' => 1000]],
    ], $overrides));
}

// ── facts.query_orders ──────────────────────────────────────────────────────────────────────────

it('aggregates orders and reports what the answer rests on', function () {
    order();
    order(['gross' => 2000, 'subtotal' => 1940]);

    $result = tool('facts.query_orders')->execute([], ctx());

    expect($result->ok())->toBeTrue();
    expect($result->output['rows'][0]['orders'])->toBe(2);
    expect($result->output['rows'][0]['gross'])->toBe(3060.0);
    expect($result->output['coverage']['rows_matched'])->toBe(2);
    expect($result->output['coverage']['without_line_detail'])->toBe(0);
});

it('reports thin coverage as insufficient for a trend rather than answering confidently', function () {
    order();

    $coverage = tool('facts.query_orders')->execute([], ctx())->output['coverage'];

    // Two orders over one day cannot support a trend, and the tool says so rather than leaving
    // every caller to invent its own floor.
    expect($coverage['sufficient_for_trend'])->toBeFalse();
    expect($coverage['trend_floor']['rows'])->toBeGreaterThan(1);
});

it('counts orders that carry no line detail, because a units figure cannot include them', function () {
    order();
    order(['lines' => null]);
    order(['lines' => []]);

    $out = tool('facts.query_orders')->execute([], ctx())->output;

    expect($out['coverage']['without_line_detail'])->toBe(2);
    expect($out['coverage']['without_line_detail_share'])->toBe(0.667);
    // Units come only from the one order that has lines; the denominator is reported beside them.
    expect($out['rows'][0]['units'])->toBe(2.0);
    expect($out['rows'][0]['orders_with_lines'])->toBe(1);
});

it('reports the range the rows actually cover, never the range that was requested', function () {
    order(['placed_at' => now()->subDays(2)]);

    $coverage = tool('facts.query_orders')
        ->execute(['from' => now()->subYear()->toDateString(), 'to' => now()->toDateString()], ctx())
        ->output['coverage'];

    // A query for a whole year that found two days of data must not look like a year of evidence.
    expect($coverage['span_days'])->toBeLessThan(5);
    expect($coverage['requested_range']['from'])->not->toBeNull();
});

it('groups days in the group timezone, not the server timezone', function () {
    // Both of these instants fall on 8 September in UTC - 17:30 and 18:30 - so a UTC bucket puts
    // them on the same day. In Dhaka they are 23:30 on the 8th and 00:30 on the 9th, which is two
    // different trading days. The container runs UTC and no shop does, so this is the discriminator.
    order(['placed_at' => CarbonImmutable::parse('2026-09-08 17:30', 'UTC')]);
    order(['placed_at' => CarbonImmutable::parse('2026-09-08 18:30', 'UTC')]);

    $rows = tool('facts.query_orders')->execute(['group_by' => 'day'], ctx())->output['rows'];
    $groups = collect($rows)->pluck('group')->all();

    expect($groups)->toHaveCount(2);
    expect($groups)->toContain('2026-09-08')->toContain('2026-09-09');

    // And the same two rows under a UTC calendar collapse into one day. Asserting this pins the
    // discriminator permanently, rather than trusting that somebody once watched it fail.
    config(['hq.timezone' => 'UTC']);
    $utcGroups = collect(tool('facts.query_orders')->execute(['group_by' => 'day'], ctx())->output['rows'])->pluck('group')->all();

    expect($utcGroups)->toBe(['2026-09-08']);
});

it('returns an empty result with its coverage rather than failing when nothing matches', function () {
    $result = tool('facts.query_orders')->execute(['statuses' => ['nonexistent']], ctx());

    // "There are no orders like that" is a true answer. A failure would invite a retry of a query
    // that will keep being correct.
    expect($result->ok())->toBeTrue();
    expect($result->output['rows'])->toBe([]);
    expect($result->output['coverage']['rows_matched'])->toBe(0);
});

it('groups by store and district using slugs a reader recognises', function () {
    order(['district' => 'Barguna']);
    order(['district' => 'Dhaka']);

    $byDistrict = collect(tool('facts.query_orders')->execute(['group_by' => 'district'], ctx())->output['rows'])->pluck('group');
    $byStore = collect(tool('facts.query_orders')->execute(['group_by' => 'store'], ctx())->output['rows'])->pluck('group');

    expect($byDistrict)->toContain('Barguna', 'Dhaka');
    expect($byStore)->toContain('laladia-com');
});

it('refuses a business slug HQ does not have instead of silently widening to every business', function () {
    order();

    $result = tool('facts.query_orders')->execute(['business_slug' => 'not-a-business'], ctx());

    expect($result->ok())->toBeFalse();
    expect($result->error)->toContain('not-a-business');
});

it('honours the business restriction the gateway resolved for the caller', function () {
    order(['store' => 'laladia-com']);
    order(['store' => 'bionic-garden']);

    $laladia = Store::where('slug', 'laladia-com')->sole()->business_id;

    $all = tool('facts.query_orders')->execute([], ctx())->output['coverage'];
    $restricted = tool('facts.query_orders')->execute([], ctx(['permitted' => [$laladia]]))->output['coverage'];

    // A restricted human must not be able to total across businesses they cannot see, and the tool
    // cannot forget to look the restriction up because the gateway hands it over.
    expect($all['rows_matched'])->toBe(2);
    expect($restricted['rows_matched'])->toBe(1);
});

// ── facts.snapshot_freshness ────────────────────────────────────────────────────────────────────

it('calls a source HQ has never held stale, and says so in words', function () {
    order();

    $sources = tool('facts.snapshot_freshness')->execute([], ctx())->output['sources'];
    $orders = collect($sources)->firstWhere('source', 'facts_orders:laladia-com');

    expect($orders)->not->toBeNull();
    expect($orders['age_minutes'])->toBeLessThan(5);
    expect($orders['stale'])->toBeFalse();
});

it('reports an ERP snapshot at the older of its as_of and its poll time', function () {
    $business = Business::where('slug', 'bor-de-guna')->sole();

    FactsErpSnapshot::create([
        'business_id' => $business->id,
        'kind' => 'overview',
        'payload' => ['ok' => true],
        // Polled a minute ago, but the ERP said the data was six hours old. A fresh poll of a stale
        // report is still stale, so the honest age is six hours.
        'as_of' => now()->subHours(6),
        'polled_at' => now()->subMinute(),
    ]);

    $sources = tool('facts.snapshot_freshness')->execute([], ctx())->output['sources'];
    $erp = collect($sources)->firstWhere('source', 'erp:bor-de-guna/overview');

    expect($erp['age_minutes'])->toBeGreaterThan(300);
    expect($erp['stale'])->toBeTrue();
});

// ── erp.read_snapshot ───────────────────────────────────────────────────────────────────────────

it('reads the stored ERP snapshot without calling the ERP', function () {
    $business = Business::where('slug', 'bor-de-guna')->sole();
    FactsErpSnapshot::create([
        'business_id' => $business->id, 'kind' => 'overview',
        'payload' => ['sales' => 12], 'as_of' => now()->subMinutes(5), 'polled_at' => now()->subMinutes(5),
    ]);

    $out = tool('erp.read_snapshot')->execute(['business_slug' => 'bor-de-guna'], ctx(['business_id' => $business->id]))->output;

    expect($out['payload'])->toBe(['sales' => 12]);
    expect($out['age_minutes'])->toBeGreaterThanOrEqual(4);
});

it('answers that HQ holds no snapshot rather than failing, and warns against reading it as zero', function () {
    $business = Business::where('slug', 'bor-de-guna')->sole();

    $result = tool('erp.read_snapshot')->execute(['business_slug' => 'bor-de-guna'], ctx(['business_id' => $business->id]));

    expect($result->ok())->toBeTrue();
    expect($result->output['payload'])->toBeNull();
    expect($result->output['note'])->toContain('rather than as zero');
});

// ── erp.run_report ──────────────────────────────────────────────────────────────────────────────

it('refuses a report key that is not whitelisted, and names the ones that are', function () {
    $business = Business::where('slug', 'bor-de-guna')->sole();

    $result = tool('erp.run_report')->execute(
        ['business_slug' => 'bor-de-guna', 'report_key' => 'purchasing_ledger'],
        ctx(['business_id' => $business->id]),
    );

    // An open report key space handed to a model is an interface nobody has enumerated.
    expect($result->ok())->toBeFalse();
    expect($result->error)->toContain('purchasing_ledger');
    expect($result->error)->toContain('sales_by_brand');
});

// ── hq.* ────────────────────────────────────────────────────────────────────────────────────────

it('lists jobs and events without returning any payload', function () {
    $jobs = tool('hq.list_jobs')->execute([], ctx())->output;
    $events = tool('hq.recent_events')->execute([], ctx())->output;

    expect($jobs)->toHaveKeys(['jobs', 'failing_count', 'recent_failures']);
    expect($events)->toHaveKeys(['by_type', 'undelivered']);
    // An event payload is a whole order: names, phones, addresses. It never reaches a prompt.
    expect(json_encode($events))->not->toContain('payload');
});

it('lists approvals by shape and never by payload', function () {
    $out = tool('hq.list_approvals')->execute([], ctx())->output;

    expect($out)->toHaveKeys(['approvals', 'pending_count', 'overdue_count']);
    expect(json_encode($out))->not->toContain('payload');
});
