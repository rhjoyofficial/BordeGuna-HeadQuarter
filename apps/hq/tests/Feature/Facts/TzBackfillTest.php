<?php

use App\Ads\Contribution\FactsOrdersDeliveredSales;
use App\Facts\TimeZone\TzBackfillPlanner;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/133 (ruling docs/131 section 2 item 8): `hq:facts:tz-backfill`. A dry run writes nothing; only a
 | timestamp whose stored value is explained by the old code's wrong reading of a shop string WITH an offset is
 | corrected; a `Z` row, an offsetless row and a row without evidence are never changed; the apply needs the
 | batch key, --by and --reason, logs every change, and a second apply changes nothing.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->store = Store::where('slug', 'laladia-com')->sole();
    $this->business = Business::where('slug', 'laladia')->sole();
    $this->econic = Store::where('slug', 'econic-store')->sole();
    $this->owner = $this->hqUser('owner');
});

/** A processed inbound shop event, as the webhook controller records it and the drain marks it. */
function tzEvent(Store $store, string $type, array $data): void
{
    DomainEvent::create([
        'event_id' => (string) Str::uuid(), 'type' => $type, 'direction' => 'inbound', 'source' => "shop:{$store->slug}",
        'aggregate_type' => 'order', 'aggregate_id' => $data['order_number'] ?? $data['order']['order_number'] ?? null, 'business_id' => $store->business_id,
        'payload' => $data + ['_store' => $store->slug, '_schema_version' => 1], 'dedupe_key' => (string) Str::uuid(),
        'occurred_at' => now(), 'status' => DomainEvent::STATUS_PROCESSED, 'processed_at' => now(),
    ]);
}

/**
 * A facts row written the way the OLD code wrote it: `CarbonImmutable::parse()` of the shop string handed to
 * Eloquent, which drops the offset (W5-TZ, docs/125).
 */
function tzOldFact(Store $store, string $ref, array $times, string $subtotal = '500.00', ?string $sourceSystem = null): FactsOrder
{
    return FactsOrder::create(['business_id' => $store->business_id, 'store_id' => $store->id, 'order_ref' => $ref, 'status' => isset($times['delivered_at']) ? 'delivered' : 'pending',
        'subtotal' => $subtotal, 'discount_total' => 0, 'gross' => $subtotal, 'source_system' => $sourceSystem]
        + array_map(fn ($s) => CarbonImmutable::parse($s), $times));
}

function tzStoredValue(int $id, string $column): ?string
{
    return DB::selectOne("SELECT to_char({$column} AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS v FROM facts_orders WHERE id = ?", [$id])->v;
}

/** A digest of every row of the tables the backfill could touch. */
function tzHashes(): array
{
    $out = [];
    foreach (['facts_orders', 'facts_tz_corrections', 'audit_logs', 'events', 'customers'] as $t) {
        $out[$t] = DB::selectOne("SELECT count(*) AS n, md5(coalesce(string_agg(t::text, '|' ORDER BY t.id), '')) AS h FROM {$t} t")->h;
    }

    return $out;
}

/** The fixture: one +06:00 delivery, one Z delivery, one offsetless delivery, one row without evidence, one ERP row. */
function tzFixture(Store $store, Store $econic): array
{
    $plus6 = '2026-09-20T23:30:00+06:00';
    tzEvent($store, 'order.created', ['order' => array_merge(liveWebhookOrderPayload('LLD-TZ-PLUS6'), ['placed_at' => '2026-09-20T04:00:00.000000Z'])]);
    tzEvent($store, 'order.status_changed', ['order_number' => 'LLD-TZ-PLUS6', 'old_status' => 'shipped', 'new_status' => 'delivered', 'changed_at' => $plus6]);
    $a = tzOldFact($store, 'LLD-TZ-PLUS6', ['placed_at' => '2026-09-20T04:00:00.000000Z', 'delivered_at' => $plus6]);

    tzEvent($store, 'order.created', ['order' => array_merge(liveWebhookOrderPayload('LLD-TZ-ZULU'), ['placed_at' => '2026-09-20T04:00:00.000000Z', 'delivered_at' => '2026-09-20T17:30:00.000000Z', 'order_status' => 'delivered'])]);
    $z = tzOldFact($store, 'LLD-TZ-ZULU', ['placed_at' => '2026-09-20T04:00:00.000000Z', 'delivered_at' => '2026-09-20T17:30:00.000000Z']);

    tzEvent($store, 'order.status_changed', ['order_number' => 'LLD-TZ-BARE', 'new_status' => 'delivered', 'changed_at' => '2026-09-20 23:30:00']);
    $bare = tzOldFact($store, 'LLD-TZ-BARE', ['delivered_at' => '2026-09-20 23:30:00']);

    $none = tzOldFact($econic, 'ECO-TZ-NONE', ['placed_at' => '2026-09-20T10:00:00+06:00']);
    $erp = tzOldFact($store, 'LLD-TZ-ERP', ['placed_at' => '2026-09-20T10:00:00+06:00'], sourceSystem: 'erp');

    return compact('a', 'z', 'bare', 'none', 'erp');
}

it('writes nothing on a dry run, text or JSON (row hashes before and after)', function () {
    tzFixture($this->store, $this->econic);
    $before = tzHashes();

    $this->artisan('hq:facts:tz-backfill')->expectsOutputToContain('DRY RUN')->assertSuccessful();
    Artisan::call('hq:facts:tz-backfill', ['--json' => true]);
    $plan = json_decode(Artisan::output(), true);

    expect(tzHashes())->toBe($before)
        ->and($plan['dry_run'])->toBeTrue()
        ->and($plan['written'])->toBe(0)
        ->and($plan['totals']['correctable'])->toBe(1);
});

it('lists the evidence, corrects a +06:00 row by exactly six hours, and leaves Z, offsetless and evidence-less rows untouched', function () {
    $f = tzFixture($this->store, $this->econic);
    expect(tzStoredValue($f['a']->id, 'delivered_at'))->toBe('2026-09-20 23:30:00'); // the old code's six-hours-late value

    $plan = app(TzBackfillPlanner::class)->plan();
    expect($plan['correctable'])->toHaveCount(1);
    $c = $plan['correctable'][0];
    expect($c['facts_order_id'])->toBe($f['a']->id)
        ->and($c['column'])->toBe('delivered_at')
        ->and($c['source_string'])->toBe('2026-09-20T23:30:00+06:00')
        ->and($c['stored_utc'])->toBe('2026-09-20 23:30:00')
        ->and($c['expected_utc'])->toBe('2026-09-20 17:30:00')
        ->and($c['shift_seconds'])->toBe(21600);

    $ambiguous = collect($plan['ambiguous'])->mapWithKeys(fn ($x) => [$x['facts_order_id'].'.'.$x['column'] => $x['reason']])->all();
    expect($ambiguous)->toBe([
        $f['bare']->id.'.delivered_at' => TzBackfillPlanner::AMBIGUOUS_NO_OFFSET,
        $f['none']->id.'.placed_at' => TzBackfillPlanner::AMBIGUOUS_NO_EVIDENCE,
        $f['erp']->id.'.placed_at' => TzBackfillPlanner::AMBIGUOUS_ERP_DIGEST_ONLY,
    ]);
    // The +06:00 row's placed_at (Z) and both Z columns are right by evidence.
    expect($plan['totals']['verified_correct'])->toBe(3);

    // No personal value in the output.
    $json = (string) json_encode($plan);
    expect($json)->not->toContain('Karim')->not->toContain('01711000000')->not->toContain('House 1');

    $untouched = fn () => [tzStoredValue($f['z']->id, 'delivered_at'), tzStoredValue($f['z']->id, 'placed_at'), tzStoredValue($f['bare']->id, 'delivered_at'),
        tzStoredValue($f['none']->id, 'placed_at'), tzStoredValue($f['erp']->id, 'placed_at'), tzStoredValue($f['a']->id, 'placed_at')];
    $was = $untouched();

    $this->artisan('hq:facts:tz-backfill', ['--apply' => true, '--key' => $plan['batch_key'], '--by' => $this->owner->email, '--reason' => 'Joy authorized after reading the dry run'])
        ->expectsOutputToContain('applied, 1 timestamp(s) corrected')->assertSuccessful();

    expect(tzStoredValue($f['a']->id, 'delivered_at'))->toBe('2026-09-20 17:30:00')
        ->and($untouched())->toBe($was);

    $log = DB::table('facts_tz_corrections')->sole();
    expect((int) $log->facts_order_id)->toBe($f['a']->id)
        ->and($log->column_name)->toBe('delivered_at')
        ->and($log->source_string)->toBe('2026-09-20T23:30:00+06:00')
        ->and(CarbonImmutable::parse($log->old_value)->utc()->diffInSeconds(CarbonImmutable::parse($log->new_value)->utc(), false))->toEqual(-21600.0)
        ->and((int) $log->applied_by)->toBe($this->owner->id);
    $audit = AuditLog::where('action', 'facts.tz_backfill.applied')->sole();
    expect($audit->after['changed'])->toBe(1)->and($audit->after['verification']['ok'])->toBeTrue()->and($audit->actor_id)->toBe($this->owner->id);

    // The log is append-only.
    expect(fn () => DB::table('facts_tz_corrections')->update(['reason' => 'changed']))->toThrow(Exception::class, 'append-only');
});

it('changes nothing on a second apply', function () {
    tzFixture($this->store, $this->econic);
    $key = app(TzBackfillPlanner::class)->plan()['batch_key'];
    $args = ['--apply' => true, '--key' => $key, '--by' => $this->owner->email, '--reason' => 'Joy authorized after reading the dry run'];
    $this->artisan('hq:facts:tz-backfill', $args)->assertSuccessful();
    $after = tzHashes();

    $this->artisan('hq:facts:tz-backfill', $args)->expectsOutputToContain('already_applied, 0 timestamp(s)')->assertSuccessful();
    expect(tzHashes())->toBe($after)
        ->and(app(TzBackfillPlanner::class)->plan()['totals']['correctable'])->toBe(0);
});

it('refuses an apply without --key, --by or --reason, with a wrong key, or with an unknown person, and writes nothing', function () {
    tzFixture($this->store, $this->econic);
    $key = app(TzBackfillPlanner::class)->plan()['batch_key'];
    $before = tzHashes();
    $ok = ['--apply' => true, '--key' => $key, '--by' => $this->owner->email, '--reason' => 'Joy authorized after reading the dry run'];

    foreach (['--by', '--reason', '--key'] as $missing) {
        $args = $ok;
        unset($args[$missing]);
        $this->artisan('hq:facts:tz-backfill', $args)->expectsOutputToContain('--apply needs')->assertFailed();
    }
    $this->artisan('hq:facts:tz-backfill', ['--key' => str_repeat('0', 64)] + $ok)->expectsOutputToContain('data changed since the dry run')->assertFailed();
    $this->artisan('hq:facts:tz-backfill', ['--by' => 'nobody@example.test'] + $ok)->expectsOutputToContain('No HQ user')->assertFailed();
    $this->artisan('hq:facts:tz-backfill', ['--reason' => 'short'] + $ok)->expectsOutputToContain('at least ten characters')->assertFailed();

    expect(tzHashes())->toBe($before);
});

it('moves a delivery across the Dhaka day boundary in the before and after report, and the report counts it there after the apply', function () {
    // Delivered 20 Sep 19:00 Dhaka, sent as +06:00. The old code stored 19:00 UTC, which is 21 Sep 01:00 Dhaka.
    tzEvent($this->store, 'shipment.delivered', ['order_number' => 'LLD-TZ-EDGE', 'delivered_at' => '2026-09-20T19:00:00+06:00']);
    $edge = tzOldFact($this->store, 'LLD-TZ-EDGE', ['delivered_at' => '2026-09-20T19:00:00+06:00'], '700.00');
    // A correct delivery on 21 Sep so that day is not empty after.
    tzEvent($this->store, 'order.created', ['order' => array_merge(liveWebhookOrderPayload('LLD-TZ-STAY'), ['delivered_at' => '2026-09-21T03:00:00.000000Z', 'order_status' => 'delivered'])]);
    tzOldFact($this->store, 'LLD-TZ-STAY', ['delivered_at' => '2026-09-21T03:00:00.000000Z'], '300.00');

    $plan = app(TzBackfillPlanner::class)->plan();
    expect($plan['correctable'][0]['stored_dhaka_day'])->toBe('2026-09-21')
        ->and($plan['correctable'][0]['expected_dhaka_day'])->toBe('2026-09-20')
        ->and($plan['by_business_day'])->toHaveCount(1)
        ->and(array_intersect_key($plan['by_business_day'][0], array_flip(['rows', 'business', 'stored_dhaka_day', 'timestamps', 'by_column', 'moving_to_another_day'])))
        ->toEqual(['rows' => 1, 'business' => 'laladia', 'stored_dhaka_day' => '2026-09-21', 'timestamps' => 1, 'by_column' => ['delivered_at' => 1], 'moving_to_another_day' => 1])
        ->and($plan['delivered_sales'])->toBe([
            ['business' => 'laladia', 'dhaka_day' => '2026-09-20', 'before' => ['orders' => 0, 'net_revenue' => '0.00'], 'after' => ['orders' => 1, 'net_revenue' => '700.00']],
            ['business' => 'laladia', 'dhaka_day' => '2026-09-21', 'before' => ['orders' => 2, 'net_revenue' => '1000.00'], 'after' => ['orders' => 1, 'net_revenue' => '300.00']],
        ]);

    $this->artisan('hq:facts:tz-backfill', ['--apply' => true, '--key' => $plan['batch_key'], '--by' => $this->owner->email, '--reason' => 'Joy authorized after reading the dry run'])->assertSuccessful();

    $day = fn (string $d) => array_map(fn ($s) => $s->orderRef, (new FactsOrdersDeliveredSales)->delivered($this->business,
        CarbonImmutable::parse($d, 'Asia/Dhaka')->startOfDay(), CarbonImmutable::parse($d, 'Asia/Dhaka')->endOfDay()));
    expect($day('2026-09-20'))->toBe(['LLD-TZ-EDGE'])
        ->and($day('2026-09-21'))->toBe(['LLD-TZ-STAY'])
        ->and(tzStoredValue($edge->id, 'delivered_at'))->toBe('2026-09-20 13:00:00');
});

it('treats conflicting evidence as ambiguous and never changes it', function () {
    // Two strings explain the stored value: one as the old code's wrong reading, one as a correct instant.
    tzEvent($this->store, 'order.status_changed', ['order_number' => 'LLD-TZ-CONFLICT', 'new_status' => 'delivered', 'changed_at' => '2026-09-20T23:30:00+06:00']);
    tzEvent($this->store, 'shipment.delivered', ['order_number' => 'LLD-TZ-CONFLICT', 'delivered_at' => '2026-09-20T23:30:00.000000Z']);
    $row = tzOldFact($this->store, 'LLD-TZ-CONFLICT', ['delivered_at' => '2026-09-20T23:30:00+06:00']);

    $plan = app(TzBackfillPlanner::class)->plan();
    expect($plan['correctable'])->toBe([])
        ->and($plan['ambiguous'][0]['facts_order_id'])->toBe($row->id)
        ->and($plan['ambiguous'][0]['reason'])->toBe(TzBackfillPlanner::AMBIGUOUS_CONFLICT);
});
