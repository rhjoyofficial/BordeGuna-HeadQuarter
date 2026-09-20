<?php

use App\Briefing\BundleCollector;
use App\Briefing\BundleSection;
use App\Briefing\BundleValidator;
use App\Briefing\EvidenceBundle;
use App\Briefing\EvidenceKind;
use App\Briefing\ExecutiveBriefingContract;
use App\Briefing\Lineage;
use App\Models\Alert;
use App\Models\Approval;
use App\Models\Business;
use App\Models\DomainEvent;
use App\Models\FactsErpSnapshot;
use App\Models\FactsOrder;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\Store;
use App\Models\ToolCall;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | The Executive Briefing's evidence bundle (docs/48 M, docs/80 C.1).
 |
 | The bundle is not business truth; it is what a narrator may speak from. So these tests ask two
 | things of every number: is it the right reading of the read model, and can it be walked back,
 | item to read-model rows to the shop's own order numbers, without taking anything on trust.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    // 09:00 on 20 September in Dhaka. "Yesterday" is 19 September, Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00', 'Asia/Dhaka'));
});

function briefingOrder(array $overrides = []): FactsOrder
{
    $store = Store::where('slug', $overrides['store'] ?? 'laladia-com')->sole();
    unset($overrides['store']);

    return FactsOrder::create(array_merge([
        'business_id' => $store->business_id,
        'store_id' => $store->id,
        'order_ref' => 'REF-'.Str::upper(Str::random(10)),
        'status' => 'pending',
        'source' => 'checkout',
        'subtotal' => '1000.00',
        'discount_total' => '0.00',
        'shipping_cost' => '60.00',
        'gross' => '1060.00',
        'placed_at' => CarbonImmutable::parse('2026-09-19 12:00', 'Asia/Dhaka'),
        'synced_at' => now(),
        'last_event_id' => (string) Str::uuid(),
    ], $overrides));
}

function briefingBundle(array $args = [], ?array $permitted = null, array $mayRead = ['approvals' => true, 'jobs' => true]): EvidenceBundle
{
    $request = app(ExecutiveBriefingContract::class)->request($args, CarbonImmutable::now('UTC'), $permitted, $mayRead);

    return app(BundleCollector::class)->collect($request);
}

/** The read models a complete production morning has: both shops reconciled, the integrity check run. */
function briefingHealthySources(): void
{
    foreach (['laladia-com', 'bionic-garden'] as $slug) {
        ToolCall::create(['tool_key' => 'commerce.reconcile_facts', 'status' => 'succeeded', 'input' => ['store_slug' => $slug],
            'output' => ['store' => $slug], 'started_at' => now()->subHours(6), 'finished_at' => now()->subHours(6)]);
    }
    ToolCall::create(['tool_key' => 'commerce.check_fact_integrity', 'status' => 'succeeded', 'input' => [],
        'output' => ['store' => 'all', 'hours' => 48, 'checked' => 12, 'discrepancies' => 0], 'started_at' => now()->subHours(5), 'finished_at' => now()->subHours(5)]);
}

it('counts yesterday as a Dhaka calendar day, not a UTC one', function () {
    briefingHealthySources();
    // 23:30 on the 19th in Dhaka is 17:30 UTC on the 19th: yesterday. 00:30 on the 20th in Dhaka is
    // 18:30 UTC on the 19th: today in Dhaka, and still "the 19th" to a UTC calendar.
    briefingOrder(['placed_at' => CarbonImmutable::parse('2026-09-19 17:30', 'UTC')]);
    briefingOrder(['placed_at' => CarbonImmutable::parse('2026-09-19 18:30', 'UTC')]);
    // 00:10 on the 19th in Dhaka is 18:10 UTC on the 18th: yesterday in Dhaka, the day before in UTC.
    briefingOrder(['placed_at' => CarbonImmutable::parse('2026-09-18 18:10', 'UTC')]);

    $bundle = briefingBundle();

    expect($bundle->yesterday)->toBe(['date' => '2026-09-19', 'from' => '2026-09-18T18:00:00+00:00', 'to' => '2026-09-19T18:00:00+00:00'])
        ->and($bundle->item('orders.placed.store:laladia-com')->value)->toBe(2)
        ->and($bundle->item('orders.gross.store:laladia-com')->value)->toBe('2120.00');

    // The same rows under a UTC calendar give a different day, which pins the discriminator.
    config(['hq.timezone' => 'UTC']);
    expect(briefingBundle()->item('orders.placed.store:laladia-com')->value)->toBe(2)
        ->and(briefingBundle()->yesterday['from'])->toBe('2026-09-19T00:00:00+00:00');
});

it('walks one number from the bundle item to the read-model rows to the shop\'s own order numbers', function () {
    briefingHealthySources();
    $a = briefingOrder(['gross' => '1060.40', 'order_ref' => 'LLD-20260919-AAAA']);
    $b = briefingOrder(['gross' => '2000.10', 'order_ref' => 'LLD-20260919-BBBB']);
    briefingOrder(['store' => 'bionic-garden', 'gross' => '500.00', 'order_ref' => 'BNC-20260919-CCCC']);
    briefingOrder(['placed_at' => CarbonImmutable::parse('2026-09-17 12:00', 'Asia/Dhaka')]); // not yesterday

    $stored = briefingBundle()->toArray();
    $items = collect($stored['items'])->keyBy('id');

    // 1. The group figure names the business figures it sums, and nothing else.
    $group = $items['orders.gross.group'];
    expect($group['value'])->toBe('3560.50')
        ->and($group['kind'])->toBe('metric')
        ->and($group['lineage']['formula'])->toBe('sum_of_items')
        ->and($group['lineage']['input_items'])->toEqualCanonicalizing(['orders.gross.business:laladia', 'orders.gross.business:bionic']);

    // 2. The business figure names its store figures.
    $business = $items['orders.gross.business:laladia'];
    expect($business['value'])->toBe('3060.50')->and($business['lineage']['input_items'])->toBe(['orders.gross.store:laladia-com']);

    // 3. The store figure names the read-model rows, by id, and the filter that selects them.
    $store = $items['orders.gross.store:laladia-com'];
    expect($store['lineage']['formula'])->toBe('sum_gross')
        ->and($store['lineage']['table'])->toBe('facts_orders')
        ->and($store['lineage']['rows']['mode'])->toBe('listed')
        ->and($store['lineage']['rows']['ids'])->toBe([$a->id, $b->id])
        ->and($store['lineage']['filter'])->toBe(['store' => 'laladia-com', 'column' => 'placed_at', 'from' => '2026-09-18T18:00:00+00:00', 'to_exclusive' => '2026-09-19T18:00:00+00:00']);

    // 4. Reading those rows again gives the number. Nothing was aggregated out of sight.
    $again = DB::table('facts_orders')->whereIn('id', $store['lineage']['rows']['ids'])->pluck('gross')
        ->sum(fn ($g) => (int) round(((float) $g) * 100));
    expect($again)->toBe(306050);

    // 5. And each row carries its own source: the shop's order number and the event that wrote it.
    expect(collect($store['lineage']['rows']['source_refs'])->pluck('order_ref')->all())->toBe(['LLD-20260919-AAAA', 'LLD-20260919-BBBB'])
        ->and($store['lineage']['rows']['source_refs'][0])->toMatchArray(['id' => $a->id, 'store' => 'laladia-com', 'last_event_id' => $a->last_event_id])
        ->and($store['source'])->toBe(['system' => 'shop', 'read_model' => 'facts_orders', 'ref' => 'facts_orders:laladia-com']);
});

it('replaces a long id list with its range, count and digest, and the filter still selects the same rows', function () {
    briefingHealthySources();
    for ($i = 0; $i < Lineage::MAX_LISTED_ROWS + 5; $i++) {
        briefingOrder();
    }

    $lineage = briefingBundle()->item('orders.placed.store:laladia-com')->lineage->toArray();

    expect($lineage['rows']['mode'])->toBe('digest')->and($lineage['rows']['count'])->toBe(55);

    $ids = DB::table('facts_orders as f')->join('stores as s', 's.id', '=', 'f.store_id')->where('s.slug', $lineage['filter']['store'])
        ->where('f.placed_at', '>=', $lineage['filter']['from'])->where('f.placed_at', '<', $lineage['filter']['to_exclusive'])
        ->orderBy('f.id')->pluck('f.id')->all();

    expect(Lineage::digest($ids))->toBe($lineage['rows']['ids_digest'])
        ->and(min($ids))->toBe($lineage['rows']['id_min'])->and(max($ids))->toBe($lineage['rows']['id_max'])
        ->and($lineage['rows']['source_refs_digest'])->toHaveLength(64);
});

it('counts confirmations, deliveries and cancellations by their own day, not by the day the order was placed', function () {
    briefingHealthySources();
    $lastWeek = CarbonImmutable::parse('2026-09-12 10:00', 'Asia/Dhaka');
    $yesterday = CarbonImmutable::parse('2026-09-19 15:00', 'Asia/Dhaka');
    briefingOrder(['placed_at' => $lastWeek, 'status' => 'delivered', 'delivered_at' => $yesterday]);
    briefingOrder(['placed_at' => $lastWeek, 'status' => 'cancelled', 'cancelled_at' => $yesterday]);
    briefingOrder(['status' => 'confirmed', 'confirmed_at' => $yesterday]);

    $bundle = briefingBundle();

    expect($bundle->item('orders.placed.store:laladia-com')->value)->toBe(1)
        ->and($bundle->item('orders.confirmed.store:laladia-com')->value)->toBe(1)
        ->and($bundle->item('orders.delivered.store:laladia-com')->value)->toBe(1)
        ->and($bundle->item('orders.cancelled.store:laladia-com')->value)->toBe(1)
        ->and($bundle->item('orders.delivered.group')->value)->toBe(1);
});

it('puts freshness first, lists stale and missing sources, and never reports an unknown store as zero', function () {
    // laladia: reconciled two days ago and nothing since, so stale. bionic: never written, so missing.
    ToolCall::create(['tool_key' => 'commerce.reconcile_facts', 'status' => 'succeeded', 'input' => ['store_slug' => 'laladia-com'],
        'output' => [], 'started_at' => now()->subHours(50), 'finished_at' => now()->subHours(50)]);
    briefingOrder(['synced_at' => now()->subHours(60)]);

    $bundle = briefingBundle();
    $stored = $bundle->toArray();

    expect(array_keys($stored))->toContain('freshness')
        ->and(array_search('freshness', array_keys($stored), true))->toBeLessThan(array_search('items', array_keys($stored), true))
        ->and($stored['freshness']['stale'])->toBe(['facts_orders:laladia-com'])
        ->and($stored['freshness']['missing'])->toContain('facts_orders:bionic-garden')->toContain('hq:integrity_check')
        // The stores HQ has no adapter for are stated, and are not a fault.
        ->and($stored['freshness']['not_connected'])->toContain('facts_orders:econic-store')->toContain('facts_orders:erp-counter');

    // The stale store still reports, and every figure of it says it is stale.
    expect($bundle->item('orders.placed.store:laladia-com')->freshness->state->value)->toBe('stale')
        ->and($bundle->item('orders.placed.store:laladia-com')->freshness->budgetMinutes)->toBe(1560)
        // The unknown store has no figure at all, and a note saying so.
        ->and($bundle->item('orders.placed.store:bionic-garden'))->toBeNull()
        ->and(collect($bundle->section('yesterday')->notes)->where('status', 'unavailable')->pluck('store')->all())->toBe(['bionic-garden'])
        // And no group total is made out of the stores that did report.
        ->and($bundle->item('orders.placed.group'))->toBeNull()
        ->and(collect($bundle->section('yesterday')->notes)->where('status', 'total_withheld')->count())->toBeGreaterThan(0);

    $report = app(BundleValidator::class)->validate($bundle);
    expect($report->state())->toBe('degraded')
        ->and($report->codes())->toContain('source_stale')->toContain('source_missing')
        ->and($report->blocksNarration())->toBeFalse();
});

it('reads a quiet shop as fresh when the reconciliation ran, although no order was written', function () {
    briefingHealthySources();

    $bundle = briefingBundle();
    $source = collect($bundle->sources)->firstWhere('key', 'facts_orders:laladia-com');

    expect($source->freshness->state->value)->toBe('fresh')
        ->and($source->rowsHeld)->toBe(0)
        ->and($bundle->item('orders.placed.store:laladia-com')->value)->toBe(0)
        ->and($bundle->item('orders.placed.store:laladia-com')->lineage->toArray()['rows'])->toMatchArray(['mode' => 'listed', 'count' => 0, 'ids' => []]);
});

it('gives no order-volume band below the history floor, and says insufficient history', function () {
    briefingHealthySources();
    briefingOrder();
    briefingOrder(['placed_at' => CarbonImmutable::parse('2026-09-15 12:00', 'Asia/Dhaka')]);

    $bundle = briefingBundle();

    expect($bundle->item('orders.band.low.store:laladia-com'))->toBeNull()
        ->and($bundle->item('orders.band.outside.store:laladia-com'))->toBeNull()
        ->and(collect($bundle->section('anomalies')->notes)->where('status', 'insufficient_history')->pluck('store')->all())
        ->toContain('laladia-com');
});

it('gives the band as the lowest and highest daily count of the fourteen days before yesterday, once the history is there', function () {
    briefingHealthySources();
    briefingOrder(['placed_at' => CarbonImmutable::parse('2026-08-20 12:00', 'Asia/Dhaka')]); // the read model reaches back
    // 5 to 18 September: three orders on every day but the 10th, which has none.
    for ($d = 5; $d <= 18; $d++) {
        for ($n = 0; $n < ($d === 10 ? 0 : 3); $n++) {
            briefingOrder(['placed_at' => CarbonImmutable::parse(sprintf('2026-09-%02d 1%d:00', $d, $n), 'Asia/Dhaka')]);
        }
    }
    // Yesterday: seven orders, above anything in the band.
    for ($n = 0; $n < 7; $n++) {
        briefingOrder();
    }

    $bundle = briefingBundle();

    expect($bundle->item('orders.band.low.store:laladia-com')->value)->toBe(0)
        ->and($bundle->item('orders.band.high.store:laladia-com')->value)->toBe(3)
        ->and($bundle->item('orders.band.outside.store:laladia-com')->value)->toBeTrue()
        ->and($bundle->item('orders.band.high.store:laladia-com')->lineage->toArray()['rows']['count'])->toBe(39)
        ->and($bundle->item('orders.band.outside.store:laladia-com')->lineage->inputItems)
        ->toBe(['orders.placed.store:laladia-com', 'orders.band.low.store:laladia-com', 'orders.band.high.store:laladia-com']);
});

it('reports ERP low stock from the stored snapshot, with the ERP\'s own as_of, and never polls', function () {
    briefingHealthySources();
    $laladia = Business::where('slug', 'laladia')->sole();
    $snapshot = FactsErpSnapshot::create(['business_id' => $laladia->id, 'kind' => 'low_stock', 'job_run_ref' => '77',
        'as_of' => now()->subHours(5), 'polled_at' => now()->subHours(4),
        'payload' => [
            ['product_id' => 9, 'sku' => 'PIC-HIL-V1', 'name' => 'Hilsa Fish Pickle', 'on_hand' => 4.0, 'threshold' => 10.0],
            ['product_id' => 3, 'sku' => 'GHE-500', 'name' => 'Ghee', 'on_hand' => 1.5, 'threshold' => null],
        ]]);

    // bionic has a poll scheduled and no snapshot, so it is missing; azmion has neither, so it is not connected.
    ScheduledJob::create(['name' => 'Low stock bionic', 'kind' => 'cron', 'cron_expression' => '5 4 * * *',
        'tool_key' => 'erp.poll_low_stock', 'payload' => ['business_slug' => 'bionic'], 'state' => 'active', 'approval_state' => 'approved']);

    $bundle = briefingBundle();
    $stored = $bundle->toArray();

    expect($bundle->item('erp.low_stock.count.business:laladia')->value)->toBe(2)
        ->and($bundle->item('erp.low_stock.count.business:laladia')->lineage->toArray()['rows'])
        ->toMatchArray(['ids' => [$snapshot->id], 'source_refs' => [['id' => $snapshot->id, 'business' => 'laladia', 'kind' => 'low_stock', 'job_run_ref' => '77']]])
        ->and($bundle->item('erp.low_stock.on_hand:laladia:PIC-HIL-V1')->value)->toBe(4)
        ->and($bundle->item('erp.low_stock.threshold:laladia:PIC-HIL-V1')->value)->toBe(10)
        ->and($bundle->item('erp.low_stock.on_hand:laladia:GHE-500')->value)->toBe('1.5')
        ->and($bundle->item('erp.low_stock.threshold:laladia:GHE-500'))->toBeNull()
        // The older of the ERP's as_of and HQ's poll time is the honest age.
        ->and($bundle->item('erp.low_stock.count.business:laladia')->asOf->toIso8601String())->toBe(now()->subHours(5)->utc()->toIso8601String())
        ->and($bundle->item('erp.low_stock.count.business:laladia')->source['system'])->toBe('erp')
        ->and($stored['freshness']['missing'])->toContain('erp:bionic/low_stock')
        ->and($stored['freshness']['not_connected'])->toContain('erp:azmion/low_stock');
});

it('carries pending approvals with their clocks and never their payload, reason or evidence', function () {
    briefingHealthySources();
    $approval = Approval::create(['uuid' => (string) Str::uuid(), 'tool_key' => 'demo.request_approval', 'tool_version' => '1.0.0',
        'payload' => ['customer_phone' => '01711000000', 'amount' => 98765], 'payload_hash' => str_repeat('a', 64), 'risk_tier' => 'r4',
        'action_class' => 'approval_required', 'reason' => 'refund for customer 01711000000', 'status' => 'pending',
        'sla_due_at' => now()->subHour(), 'expires_at' => now()->addDay()]);
    DB::table('approvals')->where('id', $approval->id)->update(['created_at' => now()->subHours(27)->subMinutes(10)]);

    $bundle = briefingBundle();
    $json = json_encode($bundle->toArray());

    expect($bundle->item('approvals.pending.count')->value)->toBe(1)
        ->and($bundle->item('approvals.overdue.count')->value)->toBe(1)
        ->and($bundle->item("approvals.pending.waiting_hours:{$approval->uuid}")->value)->toBe(27)
        ->and($bundle->item("approvals.pending.waiting_hours:{$approval->uuid}")->details)->toMatchArray(['tool' => 'demo.request_approval', 'risk_tier' => 'r4', 'overdue' => true])
        ->and($bundle->item("approvals.pending.due_at:{$approval->uuid}")->unit)->toBe('datetime')
        ->and($bundle->section('decisions')->itemIds)->toBe(["approvals.pending.waiting_hours:{$approval->uuid}"])
        ->and($json)->not->toContain('01711000000')->not->toContain('98765')->not->toContain('refund for customer');
});

it('reports failed runs, dead events, open alerts and the integrity result as anomalies, each with its rows', function () {
    briefingHealthySources();
    ToolCall::create(['tool_key' => 'commerce.check_fact_integrity', 'status' => 'succeeded', 'input' => [],
        'output' => ['store' => 'all', 'hours' => 48, 'checked' => 40, 'discrepancies' => 3], 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);

    $job = ScheduledJob::create(['name' => 'Reconcile laladia 02:00', 'kind' => 'cron', 'cron_expression' => '0 2 * * *',
        'tool_key' => 'commerce.reconcile_facts', 'state' => 'active', 'approval_state' => 'approved']);
    // The scheduler's own stored next slot: 22:00 tonight in Dhaka.
    DB::table('scheduled_jobs')->where('id', $job->id)->update(['next_run_at' => CarbonImmutable::parse('2026-09-20 22:00', 'Asia/Dhaka')->utc()]);
    $failed = JobRun::create(['scheduled_job_id' => $job->id, 'scheduled_for' => now()->subHours(7), 'trigger' => 'tick', 'status' => 'failed',
        'finished_at' => now()->subHours(7), 'correlation_id' => (string) Str::uuid()]);
    JobRun::create(['scheduled_job_id' => $job->id, 'scheduled_for' => now()->subDays(5), 'trigger' => 'tick', 'status' => 'failed', 'finished_at' => now()->subDays(5)]);

    $dead = DomainEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'order.created', 'direction' => 'inbound', 'source' => 'laladia-com',
        'payload' => [], 'dedupe_key' => 'k1', 'occurred_at' => now()->subDays(9), 'status' => 'dead']);

    Alert::create(['rule' => 'facts.snapshot_stale', 'fingerprint' => 'facts.snapshot_stale:erp:laladia/overview', 'severity' => 'warning',
        'subject' => 'The ERP overview snapshot for laladia is 30 hours old', 'body' => 'x', 'first_seen_at' => now()->subHours(4), 'last_seen_at' => now()->subMinutes(3)]);

    $bundle = briefingBundle();

    expect($bundle->item('integrity.discrepancies')->value)->toBe(3)
        ->and($bundle->item('integrity.discrepancies')->kind)->toBe(EvidenceKind::Fact)
        ->and($bundle->item('integrity.checked')->value)->toBe(40)
        ->and($bundle->item('jobs.failed.count')->value)->toBe(1)
        ->and($bundle->item('jobs.failed.count')->lineage->toArray()['rows']['source_refs'][0])->toMatchArray(['id' => $failed->id, 'job_uuid' => $job->uuid])
        ->and($bundle->item("jobs.failed.run:{$failed->id}")->subject)->toBe('Reconcile laladia 02:00')
        ->and($bundle->item('events.dead.count')->value)->toBe(1)
        ->and($bundle->item('events.dead.count')->lineage->toArray()['rows']['source_refs'][0]['event_id'])->toBe($dead->event_id)
        ->and($bundle->item('alerts.open.count')->value)->toBe(1)
        ->and($bundle->item('alerts.open:facts.snapshot_stale:erp:laladia/overview')->freshness->state->value)->toBe('fresh')
        ->and($bundle->item('sources.degraded.count')->value)->toBe(0)
        ->and($bundle->section('decisions')->itemIds)->toBe(['integrity.discrepancies'])
        // What is scheduled today comes from the scheduler's own next slot.
        ->and($bundle->item('schedule.today.count')->value)->toBe(1)
        ->and($bundle->item("schedule.today.job:{$job->uuid}")->value)->toBe('2026-09-20T16:00:00+00:00');
});

it('renders marketing and finance as not connected yet, with the reason, and never omits a section', function () {
    briefingHealthySources();
    $bundle = briefingBundle();

    expect(array_map(fn (BundleSection $s) => $s->key, $bundle->sections))->toBe(array_keys(ExecutiveBriefingContract::SECTIONS))
        ->and($bundle->section('marketing')->status)->toBe('not_connected')
        ->and($bundle->section('marketing')->reason)->toContain('Not connected yet')
        ->and($bundle->section('finance')->status)->toBe('not_connected')
        ->and($bundle->section('finance')->reason)->toContain('Phase 5');
});

it('produces facts and metrics only, and is complete when every source is inside its budget', function () {
    briefingHealthySources();
    briefingOrder();
    briefingOrder(['store' => 'bionic-garden']);

    $bundle = briefingBundle();
    $kinds = array_unique(array_map(fn ($i) => $i->kind->value, $bundle->items));

    expect($kinds)->toEqualCanonicalizing(['fact', 'metric'])
        ->and(app(BundleValidator::class)->validate($bundle)->toArray())->toBe(['state' => 'complete', 'blocks_narration' => false, 'reasons' => []]);
});

it('is deterministic: the same read models at the same moment give the same digest', function () {
    briefingHealthySources();
    briefingOrder();

    expect(briefingBundle()->digest())->toBe(briefingBundle()->digest())
        ->and(EvidenceBundle::digestOf(json_decode(json_encode(briefingBundle()->toArray()), true)))->toBe(briefingBundle()->digest());
});

it('keeps a caller limited to one business inside it, and does not read the group-level records for them', function () {
    briefingHealthySources();
    briefingOrder();
    briefingOrder(['store' => 'bionic-garden']);
    Alert::create(['rule' => 'x', 'fingerprint' => 'x:1', 'severity' => 'critical', 'subject' => 'group thing', 'body' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    $laladia = Business::where('slug', 'laladia')->sole();

    $bundle = briefingBundle([], [$laladia->id]);
    $ids = array_map(fn ($i) => $i->id, $bundle->items);

    expect($ids)->toContain('orders.placed.store:laladia-com')->toContain('orders.placed.business:laladia')
        ->and(array_filter($ids, fn ($id) => str_contains($id, 'bionic')))->toBe([])
        ->and($ids)->not->toContain('orders.placed.group')->not->toContain('alerts.open.count')->not->toContain('integrity.discrepancies')
        ->and(collect($bundle->section('anomalies')->notes)->pluck('status')->all())->toContain('not_read');

    // Asking for a business outside the restriction is refused, not quietly emptied.
    expect(fn () => briefingBundle(['business_slug' => 'bionic'], [$laladia->id]))->toThrow(InvalidArgumentException::class);
});

it('withholds the approvals and scheduler sections from a caller without those permissions, and says so', function () {
    briefingHealthySources();
    $bundle = briefingBundle([], null, ['approvals' => false, 'jobs' => false]);

    expect($bundle->section('approvals')->status)->toBe('withheld')
        ->and($bundle->section('scheduled_today')->status)->toBe('withheld')
        ->and($bundle->item('approvals.pending.count'))->toBeNull()
        ->and($bundle->item('jobs.failed.count'))->toBeNull()
        ->and(app(BundleValidator::class)->validate($bundle)->codes())->toContain('section_withheld')->toContain('section_part_withheld');
});

it('refuses a date that has not begun or is not a date, and answers a past date without inventing its schedule', function () {
    expect(fn () => briefingBundle(['date' => '2026-09-21']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => briefingBundle(['date' => '2026-02-30']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => briefingBundle(['date' => 'yesterday']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => briefingBundle(['business_slug' => 'no-such']))->toThrow(InvalidArgumentException::class);

    briefingHealthySources();
    $past = briefingBundle(['date' => '2026-09-18']);

    expect($past->yesterday['date'])->toBe('2026-09-17')
        ->and($past->section('scheduled_today')->status)->toBe('not_applicable');
});
