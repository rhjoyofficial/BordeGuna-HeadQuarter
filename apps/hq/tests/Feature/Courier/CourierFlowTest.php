<?php

use App\Courier\CourierTracker;
use App\Courier\ParcelTimeline;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Business;
use App\Models\CourierParcel;
use App\Models\CourierParcelEvent;
use App\Models\FactsOrder;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Models\UserBusinessScope;
use App\Tools\Connectors\TrackParcelTool;
use App\Tools\ToolContext;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
 | docs/144-courier-connectors, end to end: the webhook route, the ledger, the tools through the gateway, the screens, and
 | the business scope on every one of them. A parcel and its events belong to one business and are never shown beside another's.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->mine = Business::orderBy('id')->firstOrFail();
    $this->theirs = Business::where('id', '!=', $this->mine->id)->orderBy('id')->firstOrFail();
    $this->owner = $this->hqUser('owner');
});

function m3WebhookSecret(Business $business, string $provider, string $value = 'sekret-1'): void
{
    IntegrationCredential::create(['provider' => 'courier_webhook_secret', 'account_ref' => "{$business->slug}.{$provider}", 'credential_type' => 'token', 'payload' => ['value' => $value], 'status' => 'active']);
}

function m3Order(Business $business, string $ref): FactsOrder
{
    return FactsOrder::create(['business_id' => $business->id, 'store_id' => Store::where('business_id', $business->id)->value('id'), 'order_ref' => $ref, 'status' => 'confirmed',
        'subtotal' => '100', 'discount_total' => '0', 'shipping_cost' => '0', 'gross' => '100', 'placed_at' => now()]);
}

function m3Hook(object $test, Business $business, string $provider, array $body, string $token = 'sekret-1')
{
    return $test->postJson("/api/webhooks/courier/{$provider}/{$business->slug}?token={$token}", $body);
}

it('answers 404 to a courier webhook while the switch is off, and for a courier or business HQ does not know', function () {
    m3SandboxConnectors([]);
    m3WebhookSecret($this->mine, 'steadfast');

    m3Hook($this, $this->mine, 'steadfast', ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered'])->assertNotFound();

    m3SandboxConnectors(['connectors.courier']);
    m3Hook($this, $this->mine, 'nobody', ['consignment_id' => 'x'])->assertNotFound();
    $this->postJson('/api/webhooks/courier/steadfast/no-such-business?token=sekret-1', [])->assertNotFound();
    expect(CourierParcel::count())->toBe(0);
});

it('answers 503 when no secret is stored and 401 for a wrong one, and stores nothing in either case', function () {
    m3SandboxConnectors(['connectors.courier']);

    m3Hook($this, $this->mine, 'steadfast', ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered'])->assertStatus(503);

    m3WebhookSecret($this->mine, 'steadfast');
    m3Hook($this, $this->mine, 'steadfast', ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered'], 'wrong')->assertStatus(401);
    $this->postJson("/api/webhooks/courier/steadfast/{$this->mine->slug}", ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered'])->assertStatus(401);

    expect(CourierParcel::count())->toBe(0)->and(CourierParcelEvent::count())->toBe(0);
});

it('records an authentic webhook once, however many times the courier sends it', function () {
    m3SandboxConnectors(['connectors.courier']);
    m3WebhookSecret($this->mine, 'steadfast');
    $body = ['consignment_id' => 'FAKE-DELIVERED-1', 'status' => 'Delivered', 'updated_at' => '2026-10-02T10:00:00Z'];

    m3Hook($this, $this->mine, 'steadfast', $body)->assertOk()->assertJson(['status' => 'recorded']);
    m3Hook($this, $this->mine, 'steadfast', $body)->assertOk();

    $parcel = CourierParcel::sole();
    expect($parcel->business_id)->toBe($this->mine->id)->and($parcel->status)->toBe('delivered')->and($parcel->terminal)->toBeTrue()
        ->and($parcel->events()->count())->toBe(1);
});

it('does not move a parcel backwards when an older event arrives late', function () {
    m3SandboxConnectors(['connectors.courier']);
    m3WebhookSecret($this->mine, 'redx');

    m3Hook($this, $this->mine, 'redx', ['tracking_id' => 'P1', 'message_en' => 'Delivered', 'timestamp' => '2026-10-03T10:00:00Z'], 'sekret-1')->assertOk();
    m3Hook($this, $this->mine, 'redx', ['tracking_id' => 'P1', 'message_en' => 'Picked up', 'timestamp' => '2026-10-01T10:00:00Z'], 'sekret-1')->assertOk();

    $parcel = CourierParcel::sole();
    expect($parcel->status)->toBe('delivered')->and($parcel->events()->count())->toBe(2)->and($parcel->events->first()->status)->toBe('picked_up');
});

it('links a parcel only to an order of the same business, matched by the reference the courier echoes', function () {
    m3SandboxConnectors(['connectors.courier']);
    m3WebhookSecret($this->mine, 'steadfast');
    $own = m3Order($this->mine, 'ORD-1');
    $foreign = m3Order($this->theirs, 'ORD-2');

    m3Hook($this, $this->mine, 'steadfast', ['consignment_id' => 'FAKE-A', 'status' => 'In Transit', 'invoice' => 'ORD-1'])->assertOk();
    m3Hook($this, $this->mine, 'steadfast', ['consignment_id' => 'FAKE-B', 'status' => 'In Transit', 'invoice' => 'ORD-2'])->assertOk();

    expect(CourierParcel::where('consignment_ref', 'FAKE-A')->value('facts_order_id'))->toBe($own->id)
        ->and(CourierParcel::where('consignment_ref', 'FAKE-B')->value('facts_order_id'))->toBeNull()
        ->and($foreign->id)->not->toBe($own->id);
});

it('never shows a parcel beside an order of another business, even if the row says so', function () {
    $mineOrder = m3Order($this->mine, 'ORD-1');
    CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->theirs->id, 'provider' => 'pathao', 'consignment_ref' => 'X1', 'facts_order_id' => $mineOrder->id, 'status' => 'delivered']);

    expect(ParcelTimeline::forOrders([$mineOrder->id], $this->mine->id))->toBe([])
        ->and(ParcelTimeline::forOrders([$mineOrder->id], $this->theirs->id))->toHaveKey($mineOrder->id);
});

it('echoes the Pathao pairing secret in the reply of an authentic call only', function () {
    FeatureFlag::query()->updateOrCreate(['key' => 'connectors.courier', 'environment' => '*'], ['description' => 't', 'enabled' => true]);
    m3WebhookSecret($this->mine, 'pathao');

    $ok = $this->postJson("/api/webhooks/courier/pathao/{$this->mine->slug}", ['event' => 'webhook_integration'], ['X-PATHAO-Signature' => 'sekret-1']);
    $ok->assertStatus(202)->assertHeader('X-Pathao-Merchant-Webhook-Integration-Secret', 'sekret-1');

    $bad = $this->postJson("/api/webhooks/courier/pathao/{$this->mine->slug}", ['event' => 'webhook_integration'], ['X-PATHAO-Signature' => 'nope']);
    $bad->assertStatus(401);
    expect($bad->headers->has('X-Pathao-Merchant-Webhook-Integration-Secret'))->toBeFalse();
});

it('tracks a parcel through the gateway, refuses while the switch is off, and says what is missing when no connector is ready', function () {
    $args = ['business_slug' => $this->mine->slug, 'courier' => 'pathao', 'consignment_ref' => 'FAKE-DELIVERED-9'];

    // Switch off: the tool is not in the registry at all, so the gateway refuses it as unknown, for everyone; and the tool
    // itself refuses too, if it is ever called directly.
    m3SandboxConnectors([]);
    $off = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'courier.track_parcel', $args, $this->mine->id));
    expect($off->ok())->toBeFalse()->and((string) $off->error)->toContain('Unknown tool');
    $direct = app(TrackParcelTool::class)->execute($args, new ToolContext('k', 'c', $this->mine->id));
    expect($direct->ok())->toBeFalse()->and((string) $direct->error)->toContain('Not connected yet');

    // Switch on with the sandbox: served by the fake, recorded for this business.
    m3SandboxConnectors(['connectors.courier']);
    $on = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'courier.track_parcel', $args, $this->mine->id));
    expect($on->ok())->toBeTrue(json_encode($on->toArray()))->and($on->output['status'])->toBe('delivered')->and($on->output['events'])->toBe(5);
    expect(CourierParcel::sole()->business_id)->toBe($this->mine->id);

    // Real rows only, switch on, no courier credential stored: a typed "no connector is ready", and no request is made.
    config(['hq-connectors.connectors' => array_values(array_filter(config('hq-connectors.connectors'), fn ($r) => $r['provider'] !== 'sandbox'))]);
    m3RebuildRegistries();
    $missing = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->owner, 'courier.track_parcel', ['consignment_ref' => 'ABC123'] + $args, $this->mine->id));
    expect($missing->ok())->toBeFalse()->and((string) $missing->error)->toContain('No pathao connector is ready');
});

it('refuses to track for a business a restricted person may not use', function () {
    m3SandboxConnectors(['connectors.courier']);
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->mine->id]);
    $args = ['courier' => 'pathao', 'consignment_ref' => 'FAKE-DELIVERED-9'];

    $other = app(ToolGateway::class)->execute(ToolRequest::forOperator($restricted, 'courier.track_parcel', ['business_slug' => $this->theirs->slug] + $args, $this->theirs->id));
    expect($other->ok())->toBeFalse();
    expect(CourierParcel::count())->toBe(0);

    $own = app(ToolGateway::class)->execute(ToolRequest::forOperator($restricted, 'courier.track_parcel', ['business_slug' => $this->mine->slug] + $args, $this->mine->id));
    expect($own->ok())->toBeTrue();
});

it('polls only open parcels that are due, and counts a parcel the courier keeps refusing', function () {
    m3SandboxConnectors(['connectors.courier']);
    $open = CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->mine->id, 'provider' => 'pathao', 'consignment_ref' => 'FAKE-TRANSIT-1', 'status' => 'created']);
    CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->mine->id, 'provider' => 'pathao', 'consignment_ref' => 'FAKE-DELIVERED-2', 'status' => 'delivered', 'terminal' => true]);
    CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->mine->id, 'provider' => 'pathao', 'consignment_ref' => 'FAKE-UNKNOWN-3', 'status' => 'created']);
    CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->theirs->id, 'provider' => 'pathao', 'consignment_ref' => 'FAKE-TRANSIT-OTHER', 'status' => 'created']);

    $result = app(CourierTracker::class)->pollDue($this->mine);

    expect($result)->toBe(['polled' => 1, 'failed' => 1, 'skipped' => 0])
        ->and($open->fresh()->status)->toBe('in_transit')
        ->and(CourierParcel::where('consignment_ref', 'FAKE-UNKNOWN-3')->value('poll_failures'))->toBe(1)
        ->and(CourierParcel::where('consignment_ref', 'FAKE-TRANSIT-OTHER')->value('last_polled_at'))->toBeNull();
    // Not due again within the interval.
    expect(app(CourierTracker::class)->pollDue($this->mine)['polled'])->toBe(0);
});

it('shows the parcels screen and the order view only for the businesses a person may see', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->mine->id]);
    $mineOrder = m3Order($this->mine, 'ORD-1');
    $theirOrder = m3Order($this->theirs, 'ORD-9');
    CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->mine->id, 'provider' => 'pathao', 'consignment_ref' => 'M1', 'facts_order_id' => $mineOrder->id, 'status' => 'delivered']);
    CourierParcel::create(['uuid' => (string) Str::uuid(), 'business_id' => $this->theirs->id, 'provider' => 'pathao', 'consignment_ref' => 'T1', 'facts_order_id' => $theirOrder->id, 'status' => 'delivered']);

    $this->actingAsHq($restricted)->get('/parcels')->assertOk()->assertInertia(fn ($page) => $page->component('parcels/index')->has('parcels', 1)->where('parcels.0.consignment_ref', 'M1'));
    $this->actingAsHq($restricted)->get("/orders/{$mineOrder->id}")->assertOk()->assertInertia(fn ($page) => $page->component('orders/show')->has('parcels', 1));
    $this->actingAsHq($restricted)->get("/orders/{$theirOrder->id}")->assertNotFound();

    $this->actingAsHq($this->owner)->get('/parcels')->assertOk()->assertInertia(fn ($page) => $page->has('parcels', 2));
});
