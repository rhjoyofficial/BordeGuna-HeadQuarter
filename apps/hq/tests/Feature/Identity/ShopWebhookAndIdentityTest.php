<?php

use App\Facts\FactsOrderUpserter;
use App\Identity\IdentityResolver;
use App\Identity\PhoneCanonical;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerMerge;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\IntegrationCredential;
use App\Models\Store;
use Database\Seeders\DomainSeeder;

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_webhook_secret', 'account_ref' => 'laladia-com', 'credential_type' => 'token', 'payload' => ['value' => 'whsec'], 'status' => 'active']);
});

function orderPayload(string $ref = 'LLD-20260905-AAA111', string $phone = '+880 1711-000000', string $status = 'pending'): array
{
    return ['order_number' => $ref, 'order_status' => $status, 'payment_status' => 'unpaid', 'payment_method' => 'cod',
        'subtotal' => 480, 'discount_total' => 0, 'shipping_cost' => 60, 'grand_total' => 540, 'source' => 'checkout',
        'customer_name' => 'Karim Ahmed', 'customer_phone' => $phone, 'customer_email' => 'Karim@Example.com',
        'placed_at' => '2026-09-05 10:00:00', 'shipping_address' => ['district' => 'Dhaka', 'thana' => 'Gulshan', 'address_line' => 'House 1'],
        'items' => [['product_name' => 'Cane Sugar 350gm', 'sku' => 'SUS-350GM-JAR', 'qty' => 2, 'unit_price' => 240, 'total' => 480, 'variant_id' => 39]],
    ];
}

it('normalises Bangladeshi phones to one canonical form', function () {
    foreach (['01711000000', '+880 1711-000000', '8801711000000', '1711000000', ' 017 1100 0000 '] as $in) {
        expect(PhoneCanonical::normalize($in))->toBe('01711000000');
    }
    expect(PhoneCanonical::normalize('12345'))->toBeNull()->and(PhoneCanonical::normalize('02111111111'))->toBeNull()->and(PhoneCanonical::normalize(null))->toBeNull();
});

it('accepts a signed webhook once, dedupes redelivery, and rejects bad signatures, unknown stores and malformed envelopes', function () {
    $env = ['id' => 'd-1', 'event' => 'order.created', 'occurred_at' => '2026-09-05T10:00:05+00:00', 'schema_version' => 1, 'data' => orderPayload()];
    signedPost($this, $env, headers: ['HTTP_X_SHOP_DELIVERY' => 'd-1'])->assertStatus(202)->assertJsonPath('duplicate', false);
    signedPost($this, $env, headers: ['HTTP_X_SHOP_DELIVERY' => 'd-1'])->assertStatus(202)->assertJsonPath('duplicate', true);
    expect(DomainEvent::count())->toBe(1)->and(DomainEvent::first()->dedupe_key)->toBe('shop:laladia-com:d-1')->and(DomainEvent::first()->business_id)->toBe(Store::where('slug', 'laladia-com')->first()->business_id);

    signedPost($this, $env, secret: 'wrong')->assertStatus(401);
    signedPost($this, $env, store: 'nope')->assertStatus(404);
    signedPost($this, $env, store: 'bionic-garden')->assertStatus(503); // no secret configured for that store yet
    signedPost($this, ['event' => 'order.created'])->assertStatus(422);
    signedPost($this, ['event' => 'something.odd', 'data' => []])->assertStatus(422);
    signedPost($this, $env, headers: ['HTTP_X_SHOP_TIMESTAMP' => (string) (time() - 3600)])->assertStatus(401);
});

it('dedupes the legacy envelope (no id) on its body hash', function () {
    $env = ['event' => 'order.created', 'data' => orderPayload('LLD-LEGACY')];
    signedPost($this, $env)->assertStatus(202)->assertJsonPath('duplicate', false);
    signedPost($this, $env)->assertStatus(202)->assertJsonPath('duplicate', true);
    expect(DomainEvent::count())->toBe(1);
});

it('processes order.created into a fact and a customer with phone, email and order identities, idempotently', function () {
    signedPost($this, ['id' => 'd-2', 'event' => 'order.created', 'data' => orderPayload()], headers: ['HTTP_X_SHOP_DELIVERY' => 'd-2'])->assertStatus(202);
    $this->artisan('hq:events:process')->assertSuccessful();

    $fact = FactsOrder::sole();
    $store = Store::where('slug', 'laladia-com')->sole();
    expect($fact->order_ref)->toBe('LLD-20260905-AAA111')->and($fact->store_id)->toBe($store->id)->and($fact->business_id)->toBe($store->business_id)
        ->and((float) $fact->gross)->toBe(540.0)->and($fact->customer_phone_canonical)->toBe('01711000000')->and($fact->district)->toBe('Dhaka')->and($fact->lines[0]['sku'])->toBe('SUS-350GM-JAR');

    $customer = Customer::sole();
    expect($customer->primary_phone_canonical)->toBe('01711000000')->and($customer->email)->toBe('karim@example.com')->and($customer->orders_count)->toBe(1)->and((float) $customer->lifetime_value)->toBe(540.0);
    expect(CustomerIdentity::where('customer_id', $customer->id)->pluck('channel')->sort()->values()->all())->toBe(['email', 'phone', 'shop_order']);
    expect(DomainEvent::first()->status)->toBe('processed');

    // Redelivery with a new delivery id but the same order: no second fact, no second customer.
    signedPost($this, ['id' => 'd-3', 'event' => 'order.created', 'data' => orderPayload()], headers: ['HTTP_X_SHOP_DELIVERY' => 'd-3'])->assertStatus(202);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(FactsOrder::count())->toBe(1)->and(Customer::count())->toBe(1)->and(CustomerIdentity::count())->toBe(3);
});

it('links a second order from the same phone to the same customer even when the name differs, and never regresses status', function () {
    $resolver = app(IdentityResolver::class);
    $facts = app(FactsOrderUpserter::class);
    $store = Store::where('slug', 'laladia-com')->sole();
    $facts->upsertFromShopPayload($store, orderPayload('LLD-1', '01711000000', 'delivered'));
    $facts->upsertFromShopPayload($store, array_merge(orderPayload('LLD-2', '8801711000000'), ['customer_name' => 'K. Ahmed']));
    // An older event for LLD-1 arriving late must not pull it back to pending.
    $facts->upsertFromShopPayload($store, orderPayload('LLD-1', '01711000000', 'pending'));

    expect(Customer::count())->toBe(1);
    $c = Customer::sole();
    expect($c->orders_count)->toBe(2)->and($c->attributes['aliases'])->toBe(['K. Ahmed'])
        ->and(FactsOrder::where('order_ref', 'LLD-1')->sole()->status)->toBe('delivered');
    expect($resolver->resolve($store, ['phone' => '+8801711000000'])->id)->toBe($c->id);
});

it('parks a status event for an unknown order and applies it once the order exists', function () {
    $env = fn ($type, $data, $id) => ['id' => $id, 'event' => $type, 'data' => $data];
    signedPost($this, $env('order.status_changed', ['order_number' => 'LLD-9', 'from' => 'pending', 'to' => 'confirmed', 'at' => '2026-09-05T11:00:00+00:00'], 's-1'), headers: ['HTTP_X_SHOP_DELIVERY' => 's-1'])->assertStatus(202);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(DomainEvent::first()->status)->toBe('failed')->and(DomainEvent::first()->last_error)->toContain('not yet known');

    signedPost($this, $env('order.created', orderPayload('LLD-9'), 'o-9'), headers: ['HTTP_X_SHOP_DELIVERY' => 'o-9'])->assertStatus(202);
    // Drain 1: the order.created lands (the parked status event is retried first and fails once more).
    // Drain 2: the status event applies. That is the real cadence: one tick per minute.
    DomainEvent::query()->update(['next_attempt_at' => now()->subMinute()]);
    $this->artisan('hq:events:process')->assertSuccessful();
    DomainEvent::query()->update(['next_attempt_at' => now()->subMinute()]);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(FactsOrder::where('order_ref', 'LLD-9')->sole()->status)->toBe('confirmed')->and(DomainEvent::where('status', 'processed')->count())->toBe(2);

    // The shop's real names and fields (old_status/new_status/changed_at), as its listeners emit them today.
    signedPost($this, $env('order.payment_updated', ['order_number' => 'LLD-9', 'old_status' => 'unpaid', 'new_status' => 'paid', 'payment_method' => 'sslcommerz', 'order_status' => 'confirmed', 'changed_at' => '2026-09-05T11:05:00+00:00'], 'p-1'), headers: ['HTTP_X_SHOP_DELIVERY' => 'p-1'])->assertStatus(202);
    signedPost($this, $env('shipment.status_updated', ['order_number' => 'LLD-9', 'courier' => 'pathao', 'old_status' => 'in_transit', 'new_status' => 'delivered', 'synced_at' => '2026-09-06T09:00:00+00:00'], 'sh-1'), headers: ['HTTP_X_SHOP_DELIVERY' => 'sh-1'])->assertStatus(202);
    $this->artisan('hq:events:process')->assertSuccessful();
    $f = FactsOrder::where('order_ref', 'LLD-9')->sole();
    expect($f->payment_status)->toBe('paid')->and($f->payment_method)->toBe('sslcommerz')->and($f->status)->toBe('delivered')->and($f->delivered_at)->not->toBeNull();
});

it('merges two customers reversibly, moving identities and facts, and rebuild recomputes counts', function () {
    $facts = app(FactsOrderUpserter::class);
    $resolver = app(IdentityResolver::class);
    $store = Store::where('slug', 'laladia-com')->sole();
    $facts->upsertFromShopPayload($store, orderPayload('LLD-A', '01711000000'));
    $facts->upsertFromShopPayload($store, array_merge(orderPayload('LLD-B', '01822000000'), ['customer_email' => 'other@example.com']));
    [$a, $b] = Customer::orderBy('id')->get();

    $resolver->merge($a, $b, null, 'same person, two SIMs');
    expect($b->fresh()->merged_into_id)->toBe($a->id)->and($a->fresh()->orders_count)->toBe(2)
        ->and(CustomerIdentity::where('customer_id', $a->id)->count())->toBe(6)
        ->and(FactsOrder::where('customer_id', $a->id)->count())->toBe(2)
        ->and(CustomerMerge::count())->toBe(1);
    // Resolving the absorbed phone lands on the kept customer.
    expect($resolver->resolve($store, ['phone' => '01822000000'])->id)->toBe($a->id);

    $this->artisan('hq:customers:rebuild')->assertSuccessful();
    expect($a->fresh()->orders_count)->toBe(2)->and((float) $a->fresh()->lifetime_value)->toBe(1080.0);
});

it('keeps the line price the live webhook sends, and derives the line total it omits', function () {
    $facts = app(FactsOrderUpserter::class);
    $store = Store::where('slug', 'laladia-com')->sole();

    // The listener's contract is the thing under test, so pin it first. Without this, the obvious
    // way to "fix" a future failure here is to add `unit_price` to the fixture, which would make
    // the test agree with HQ and disagree with production all over again.
    assertLiveWebhookContractUnchanged(liveWebhookOrderPayload());

    $fact = $facts->upsertFromShopPayload($store, liveWebhookOrderPayload());

    expect($fact->lines[0])->toMatchArray(['sku' => 'PIC-HIL-V1', 'qty' => 3, 'unit_price' => 333.0, 'total' => 999.0]);
    // What this payload genuinely cannot carry, recorded so the gap stays visible: the shop
    // listener sends neither of these, and only hq:facts:reconcile can fill them in later.
    expect($fact->source)->toBeNull()->and($fact->district)->toBeNull();
});

it('lets a later reconcile fill in the source and district the webhook could not carry', function () {
    $facts = app(FactsOrderUpserter::class);
    $store = Store::where('slug', 'laladia-com')->sole();
    $facts->upsertFromShopPayload($store, liveWebhookOrderPayload('LLD-RECON'));

    // The admin API shape hq:facts:reconcile replays, which does carry both.
    $facts->upsertFromShopPayload($store, array_merge(orderPayload('LLD-RECON'), ['source' => 'checkout']));

    $fact = FactsOrder::where('order_ref', 'LLD-RECON')->sole();
    expect($fact->source)->toBe('checkout')->and($fact->district)->toBe('Dhaka');
});
