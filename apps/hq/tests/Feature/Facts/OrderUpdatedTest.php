<?php

use App\Facts\FactsIntegrityChecker;
use App\Facts\FactsOrderUpserter;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\IntegrationCredential;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Str;

/*
 | `order.updated`: the full-state event that lets HQ hold current lines for an order edited on the
 | shop. Reconciliation cannot do it - the admin list endpoint carries no items, and the endpoint
 | that does stamps admin_viewed_at on the shop's own order.
 |
 | The payload shape here is the shop's, not HQ's: `items[].price`, `quantity`, a flat `address`,
 | plus the `source` and `shipping_address` that the new listener adds. Written from the listener,
 | not from this mapper.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_webhook_secret', 'account_ref' => 'laladia-com', 'credential_type' => 'token', 'payload' => ['value' => 'whsec'], 'status' => 'active']);
});

function laladia(): Store
{
    return Store::where('slug', 'laladia-com')->sole();
}

/** The shop's `order.updated` payload: full current state, items priced with `price`. */
function updatedPayload(array $overrides = []): array
{
    return array_merge([
        'order_number' => 'LLD-EDIT-1',
        'order_status' => 'processing',
        'payment_status' => 'unpaid',
        'payment_method' => 'cod',
        'source' => 'checkout',
        'subtotal' => 1239.0,
        'discount_total' => 90.0,
        'shipping_cost' => 90.0,
        'grand_total' => 1239.0,
        'customer_name' => 'Karim Ahmed',
        'customer_phone' => '01711000000',
        'shipping_address' => ['district' => 'Narayanganj', 'thana' => 'Siddhirganj', 'address_line' => 'House 1'],
        'placed_at' => '2026-09-06T14:49:14.000000Z',
        'items' => [
            ['sku' => 'PIC-HIL-V1', 'name' => 'Hilsa Pickle', 'quantity' => 1, 'price' => 999.0],
            ['sku' => 'GHE-ROY-V1', 'name' => 'Ghee', 'quantity' => 1, 'price' => 240.0],
        ],
    ], $overrides);
}

/** The state the fact is in before the edit: one line at 999, which is what `order.created` sent. */
function factBeforeEdit(): FactsOrder
{
    return app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), [
        'order_number' => 'LLD-EDIT-1', 'order_status' => 'pending', 'payment_method' => 'cod',
        'subtotal' => 999.0, 'discount_total' => 0.0, 'shipping_cost' => 90.0, 'grand_total' => 1089.0,
        'customer_name' => 'Karim Ahmed', 'customer_phone' => '01711000000',
        'items' => [['sku' => 'PIC-HIL-V1', 'name' => 'Hilsa Pickle', 'quantity' => 1, 'price' => 999.0]],
    ], 'evt-created', CarbonImmutable::parse('2026-09-06T14:49:14Z'));
}

it('reproduces the production discrepancy, then clears it with order.updated', function () {
    $fact = factBeforeEdit();

    // Exactly what the integrity checker found live on LLD-20260906-JRQXXJLN: the shop's totals moved
    // when the order was edited, and HQ's lines stayed behind.
    $fact->forceFill(['subtotal' => 1239.0, 'discount_total' => 90.0, 'gross' => 1239.0])->save();
    $stale = app(FactsIntegrityChecker::class)->check(laladia(), CarbonImmutable::parse('2026-09-06T00:00:00Z') /* the fixture's own date: a window relative to today expired on 13 September */);
    expect(array_column($stale['findings'], 'code'))->toContain(FactsIntegrityChecker::LINES_NOT_SUBTOTAL);

    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), 'evt-updated', CarbonImmutable::parse('2026-09-07T10:00:00Z'));

    $fact->refresh();
    expect($fact->lines)->toHaveCount(2)
        ->and((float) $fact->lines[0]['unit_price'])->toBe(999.0)
        ->and((float) $fact->lines[1]['unit_price'])->toBe(240.0)
        ->and((float) $fact->lines[1]['total'])->toBe(240.0)
        ->and((float) $fact->subtotal)->toBe(1239.0)
        ->and((float) $fact->discount_total)->toBe(90.0)
        ->and((float) $fact->gross)->toBe(1239.0);

    $after = app(FactsIntegrityChecker::class)->check(laladia(), CarbonImmutable::parse('2026-09-06T00:00:00Z') /* the fixture's own date: a window relative to today expired on 13 September */);
    expect($after['discrepancies'])->toBe(0)->and($after['ok'])->toBe(1);
});

it('carries source and district, which order.created cannot', function () {
    factBeforeEdit();
    expect(FactsOrder::sole()->source)->toBeNull();

    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), 'e2', CarbonImmutable::parse('2026-09-07T10:00:00Z'));

    $fact = FactsOrder::sole();
    expect($fact->source)->toBe('checkout')->and($fact->district)->toBe('Narayanganj')->and($fact->thana)->toBe('Siddhirganj');
});

it('is idempotent: the same update twice changes nothing and duplicates nothing', function () {
    factBeforeEdit();
    $at = CarbonImmutable::parse('2026-09-07T10:00:00Z');
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), 'e2', $at);
    $customers = Customer::count();
    $identities = CustomerIdentity::count();

    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), 'e2', $at);

    expect(FactsOrder::count())->toBe(1)
        ->and(FactsOrder::sole()->lines)->toHaveCount(2)
        ->and(Customer::count())->toBe($customers)
        ->and(CustomerIdentity::count())->toBe($identities);
});

it('refuses an older full-state event so a delayed delivery cannot undo a newer one', function () {
    factBeforeEdit();
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), 'newer', CarbonImmutable::parse('2026-09-07T10:00:00Z'));

    // The same order as it looked BEFORE the edit, arriving late. Delivery order is not event order.
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload([
        'subtotal' => 999.0, 'discount_total' => 0.0, 'grand_total' => 1089.0,
        'items' => [['sku' => 'PIC-HIL-V1', 'name' => 'Hilsa Pickle', 'quantity' => 1, 'price' => 999.0]],
    ]), 'older', CarbonImmutable::parse('2026-09-07T09:00:00Z'));

    $fact = FactsOrder::sole();
    expect($fact->lines)->toHaveCount(2)->and((float) $fact->subtotal)->toBe(1239.0)
        ->and($fact->last_event_id)->toBe('newer');
});

it('accepts reconciliation with no timestamp even after a newer event, because it reads current truth', function () {
    factBeforeEdit();
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), 'newer', CarbonImmutable::parse('2026-09-07T10:00:00Z'));

    // Reconciliation passes no occurredAt: it just read the shop, so it cannot be stale.
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(['order_status' => 'delivered']));

    expect(FactsOrder::sole()->status)->toBe('delivered');
});

it('distinguishes an omitted key from an explicit null and from a zero', function () {
    factBeforeEdit();
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), 'e2', CarbonImmutable::parse('2026-09-07T10:00:00Z'));
    expect(FactsOrder::sole()->source)->toBe('checkout');

    // OMITTED: the shop is not talking about source, so what HQ holds must survive.
    $noSource = updatedPayload();
    unset($noSource['source']);
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), $noSource, 'e3', CarbonImmutable::parse('2026-09-07T11:00:00Z'));
    expect(FactsOrder::sole()->source)->toBe('checkout');

    // EXPLICIT NULL: the shop is asserting emptiness, and that is data.
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(['source' => null]), 'e4', CarbonImmutable::parse('2026-09-07T12:00:00Z'));
    expect(FactsOrder::sole()->source)->toBeNull();

    // EXPLICIT ZERO: a real value, never treated as absent.
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload([
        'discount_total' => 0.0, 'subtotal' => 1239.0, 'grand_total' => 1329.0,
    ]), 'e5', CarbonImmutable::parse('2026-09-07T13:00:00Z'));
    expect((float) FactsOrder::sole()->discount_total)->toBe(0.0);
});

it('accepts order.updated through the signed webhook endpoint and dedupes a redelivery', function () {
    factBeforeEdit();
    $env = ['id' => 'd-upd-1', 'event' => 'order.updated', 'occurred_at' => '2026-09-07T10:00:00+00:00', 'schema_version' => 1, 'data' => updatedPayload() + ['_store' => 'laladia-com']];

    signedPost($this, $env, headers: ['HTTP_X_SHOP_DELIVERY' => 'd-upd-1'])->assertStatus(202)->assertJsonPath('duplicate', false);
    signedPost($this, $env, headers: ['HTTP_X_SHOP_DELIVERY' => 'd-upd-1'])->assertStatus(202)->assertJsonPath('duplicate', true);
    expect(DomainEvent::where('type', 'order.updated')->count())->toBe(1);

    $this->artisan('hq:events:process')->assertSuccessful();
    expect(FactsOrder::sole()->lines)->toHaveCount(2)
        ->and(FactsOrder::sole()->source)->toBe('checkout');
});

it('creates the fact when order.updated arrives for an order HQ never saw created', function () {
    // A lost order.created must not strand the update; full state is enough to build the fact.
    app(FactsOrderUpserter::class)->upsertFromShopPayload(laladia(), updatedPayload(), Str::uuid()->toString(), CarbonImmutable::parse('2026-09-07T10:00:00Z'));

    $fact = FactsOrder::sole();
    expect($fact->order_ref)->toBe('LLD-EDIT-1')->and($fact->lines)->toHaveCount(2)
        ->and((float) $fact->gross)->toBe(1239.0)->and($fact->customer_id)->not->toBeNull();
});
