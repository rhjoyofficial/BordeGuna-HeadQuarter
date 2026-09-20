<?php

use App\Models\Business;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\ScheduledJob;
use App\Models\Store;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/81 P1-1 (C.2): a read an agent answers from returns the same rows in the same order for the
 | same facts, whatever order they were written in.
 |
 | `facts.query_orders` ordered grouped rows by the metric alone, so tied groups came back in the
 | order the plan happened to produce, and with a limit a tie at the cut decided by chance which
 | group was IN the answer. The rule now: the metric descending, then the group key ascending (the
 | id for a business or a store, the text under the "C" collation for everything else).
 |
 | Every case here builds deliberate ties and runs several rounds with the insertion order shuffled.
 */

const ORDERING_ROUNDS = 6;

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'Asia/Dhaka'));
});

function orderingContext(): ToolContext
{
    return new ToolContext(idempotencyKey: 'ordering', correlationId: 'corr-'.uniqid());
}

/** @param  list<array<string, mixed>>  $orders  written in a fresh random order each round */
function writeShuffled(array $orders): void
{
    DB::table('facts_orders')->delete();
    shuffle($orders);

    foreach ($orders as $i => $overrides) {
        $store = Store::where('slug', $overrides['store'] ?? 'laladia-com')->sole();
        unset($overrides['store']);

        FactsOrder::create(array_merge([
            'business_id' => $store->business_id, 'store_id' => $store->id, 'order_ref' => 'T-'.$i.'-'.Str::random(6),
            'status' => 'delivered', 'source' => 'checkout', 'payment_method' => 'cod', 'district' => 'Dhaka',
            'subtotal' => 1000, 'discount_total' => 0, 'shipping_cost' => 60, 'gross' => 1060,
            'placed_at' => now()->subDay(), 'synced_at' => now(),
            'lines' => [['sku' => 'A', 'name' => 'A', 'qty' => 2, 'unit_price' => 500, 'total' => 1000]],
        ], $overrides));
    }
}

/** @return list<string> the group labels, in the order the tool returned them */
function groupsOf(array $payload): array
{
    $result = app(ToolRegistry::class)->get('facts.query_orders')->execute($payload, orderingContext());
    expect($result->ok())->toBeTrue();

    return array_column($result->output['rows'], 'group');
}

/*
 | One order per group, every order identical in money and lines, so EVERY metric ties across every
 | group. The expected order is therefore the secondary key alone.
 */
dataset('tied groupings', [
    'district (text, "C" collation: capitals before lower case)' => ['district', 'district', ['Barishal', 'Dhaka', 'Zila', 'chattogram', 'sylhet'], ['Barishal', 'Dhaka', 'Zila', 'chattogram', 'sylhet']],
    'status' => ['status', 'status', ['pending', 'delivered', 'cancelled', 'confirmed', 'returned'], ['cancelled', 'confirmed', 'delivered', 'pending', 'returned']],
    'source' => ['source', 'source', ['landing', 'checkout', 'admin', 'hq_ai'], ['admin', 'checkout', 'hq_ai', 'landing']],
    'payment method' => ['payment_method', 'payment_method', ['sslcommerz', 'cod', 'bkash', 'nagad'], ['bkash', 'cod', 'nagad', 'sslcommerz']],
    'day' => ['day', 'placed_at', ['2026-09-18 12:00', '2026-09-15 12:00', '2026-09-19 12:00', '2026-09-16 12:00'], ['2026-09-15', '2026-09-16', '2026-09-18', '2026-09-19']],
    'week' => ['week', 'placed_at', ['2026-09-16 12:00', '2026-08-26 12:00', '2026-09-09 12:00', '2026-09-02 12:00'], ['2026-W35', '2026-W36', '2026-W37', '2026-W38']],
    'month' => ['month', 'placed_at', ['2026-08-10 12:00', '2026-06-10 12:00', '2026-09-10 12:00', '2026-07-10 12:00'], ['2026-06', '2026-07', '2026-08', '2026-09']],
]);

it('returns tied groups by the group key, for every grouping and every metric, whatever order the facts were written in', function (string $groupBy, string $column, array $values, array $expected) {
    $orders = array_map(fn ($value) => [$column => $column === 'placed_at' ? CarbonImmutable::parse($value, 'Asia/Dhaka') : $value], $values);

    for ($round = 0; $round < ORDERING_ROUNDS; $round++) {
        writeShuffled($orders);

        foreach (['orders', 'gross', 'subtotal', 'units', 'avg_order_value'] as $metric) {
            expect(groupsOf(['group_by' => $groupBy, 'metric' => $metric]))->toBe($expected, "{$groupBy} by {$metric}, round {$round}");
        }
    }
})->with('tied groupings');

it('returns tied businesses and tied stores by their id, which is the group key behind the slug', function () {
    $stores = Store::query()->orderBy('id')->get();
    $orders = $stores->map(fn (Store $s) => ['store' => $s->slug])->all();

    $byStoreId = $stores->pluck('slug')->all();
    $byBusinessId = $stores->sortBy('business_id')->map(fn (Store $s) => Business::findOrFail($s->business_id)->slug)->values()->all();

    expect(count($byStoreId))->toBeGreaterThan(2);

    for ($round = 0; $round < ORDERING_ROUNDS; $round++) {
        writeShuffled($orders);

        expect(groupsOf(['group_by' => 'store', 'metric' => 'gross']))->toBe($byStoreId, "store, round {$round}")
            ->and(groupsOf(['group_by' => 'business', 'metric' => 'gross']))->toBe($byBusinessId, "business, round {$round}");
    }
});

it('still puts the metric first: the key only settles what the metric leaves tied', function () {
    writeShuffled([
        ['district' => 'Alpha'], ['district' => 'Bravo'], ['district' => 'Charlie'],
        ['district' => 'Charlie'], ['district' => 'Delta', 'gross' => 9000, 'subtotal' => 8940],
    ]);

    expect(groupsOf(['group_by' => 'district', 'metric' => 'orders']))->toBe(['Charlie', 'Alpha', 'Bravo', 'Delta'])
        ->and(groupsOf(['group_by' => 'district', 'metric' => 'gross']))->toBe(['Delta', 'Charlie', 'Alpha', 'Bravo']);
});

it('keeps the same groups when a tie sits at the limit, and says in the coverage that a tie was cut', function () {
    $orders = array_map(fn ($d) => ['district' => $d], ['Echo', 'Bravo', 'Delta', 'Alpha', 'Charlie']);
    $orders[] = ['district' => 'Zulu'];
    $orders[] = ['district' => 'Zulu']; // two orders: alone at the top, so the tie is among the rest

    for ($round = 0; $round < ORDERING_ROUNDS; $round++) {
        writeShuffled($orders);

        $out = app(ToolRegistry::class)->get('facts.query_orders')->execute(['group_by' => 'district', 'metric' => 'orders', 'limit' => 3], orderingContext())->output;

        expect(array_column($out['rows'], 'group'))->toBe(['Zulu', 'Alpha', 'Bravo'], "round {$round}")
            ->and($out['coverage']['tie_at_limit'])->toMatchArray([
                'metric' => 'orders', 'limit' => 3, 'groups_sharing_the_value' => 5, 'of_which_returned' => 2,
            ])
            ->and($out['coverage']['tie_at_limit']['chosen_by'])->toContain('group key');
    }
});

it('says nothing about a tie when the limit cut no tie, so a tie-free answer is byte for byte what it was', function () {
    writeShuffled([['district' => 'Alpha'], ['district' => 'Bravo'], ['district' => 'Bravo'], ['district' => 'Charlie'], ['district' => 'Charlie'], ['district' => 'Charlie']]);

    $tool = app(ToolRegistry::class)->get('facts.query_orders');

    // Cut, but between different values: no tie decided who is in.
    $cut = $tool->execute(['group_by' => 'district', 'limit' => 2], orderingContext())->output;
    // Not cut at all.
    $whole = $tool->execute(['group_by' => 'district'], orderingContext())->output;

    expect(array_column($cut['rows'], 'group'))->toBe(['Charlie', 'Bravo'])
        ->and($cut['coverage'])->not->toHaveKey('tie_at_limit')
        ->and($whole['coverage'])->not->toHaveKey('tie_at_limit')
        ->and(array_keys($whole['rows'][0]))->toBe(['group', 'orders', 'gross', 'subtotal', 'units', 'orders_with_lines', 'avg_order_value']);
});

// ── the same defect in the other reads ──────────────────────────────────────────────────────────

it('lists order-fact sources in facts.snapshot_freshness by business then store, whatever order they were written in', function () {
    $orders = Store::query()->get()->map(fn (Store $s) => ['store' => $s->slug])->all();
    $expected = Store::query()->join('businesses as b', 'b.id', '=', 'stores.business_id')
        ->orderBy('b.slug')->orderBy('stores.slug')->pluck('stores.slug')->map(fn ($slug) => "facts_orders:{$slug}")->all();

    for ($round = 0; $round < ORDERING_ROUNDS; $round++) {
        writeShuffled($orders);

        $sources = app(ToolRegistry::class)->get('facts.snapshot_freshness')->execute([], orderingContext())->output['sources'];
        $facts = array_values(array_filter(array_column($sources, 'source'), fn ($s) => str_starts_with($s, 'facts_orders:')));

        expect($facts)->toBe($expected, "round {$round}");
    }
});

it('lists jobs that share a name in hq.list_jobs in a fixed order, so a limit never cuts between them by chance', function () {
    $ids = [];
    foreach (range(1, 4) as $i) {
        $ids[] = ScheduledJob::create(['name' => 'same name', 'kind' => 'cron', 'cron_expression' => "{$i} 4 * * *", 'tool_key' => 'hq.list_jobs'])->id;
    }
    // Rewrite the rows so the heap order is not the id order.
    DB::table('scheduled_jobs')->whereIn('id', [$ids[0], $ids[1]])->update(['updated_at' => now()->addMinute()]);

    $jobs = app(ToolRegistry::class)->get('hq.list_jobs')->execute(['limit' => 3], orderingContext())->output['jobs'];

    expect(array_column($jobs, 'schedule'))->toBe(['1 4 * * *', '2 4 * * *', '3 4 * * *']);
});

it('reports the statuses of one event type in hq.recent_events in a fixed order', function () {
    foreach (['processed', 'dead', 'pending', 'failed'] as $status) {
        DomainEvent::create([
            'event_id' => (string) Str::uuid(), 'type' => 'order.created', 'direction' => 'inbound', 'status' => $status, 'source' => 'test',
            'payload' => [], 'dedupe_key' => 'k-'.$status, 'occurred_at' => now(),
        ]);
    }
    DB::table('events')->where('status', 'dead')->update(['attempts' => 3]);

    $out = app(ToolRegistry::class)->get('hq.recent_events')->execute([], orderingContext())->output;
    $type = collect($out['by_type'])->firstWhere('type', 'order.created');

    expect(array_keys($type['by_status']))->toBe(['dead', 'failed', 'pending', 'processed']);
});
