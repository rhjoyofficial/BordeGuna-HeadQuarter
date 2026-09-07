<?php

use App\Facts\FactsOrderUpserter;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\DomainEvent;
use App\Models\FactsOrder;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Scheduling\Tools\ToolContext;
use App\Scheduling\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 | The reconciliation safety net and the event replay that repairs facts a mapping bug wrote wrong.
 | Both are exercised against the shapes the real systems use: the admin *list* endpoint (which
 | carries `source` and the address but NOT order items), and the `order.created` webhook payload
 | (which carries items priced with `price`, and neither source nor address).
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    IntegrationCredential::create(['provider' => 'shop_assistant_key', 'account_ref' => 'laladia-com', 'credential_type' => 'api_key', 'payload' => ['value' => 'k'], 'status' => 'active']);
    IntegrationCredential::create(['provider' => 'shop_admin_token', 'account_ref' => 'laladia-com', 'credential_type' => 'token', 'payload' => ['value' => 'admin-token'], 'status' => 'active']);
});

/** The admin list shape: real fields, and deliberately no `items` - OrderListQuery only counts them. */
function adminListOrder(string $ref = 'LLD-RECON-1', string $status = 'pending'): array
{
    return [
        'order_number' => $ref, 'order_status' => $status, 'payment_status' => 'unpaid', 'payment_method' => 'cod',
        'source' => 'checkout', 'subtotal' => 999.0, 'discount_total' => 0.0, 'shipping_cost' => 90.0, 'grand_total' => 1089.0,
        'customer_name' => 'Karim Ahmed', 'customer_phone' => '01711000000', 'items_count' => 1,
        'placed_at' => now()->subHour()->toDateTimeString(),
        'shipping_address' => ['address_line' => 'House 1', 'area' => 'Gulshan', 'city' => 'Dhaka', 'customer_phone' => '01711000000'],
    ];
}

function fakeAdminList(array $orders): void
{
    Http::fake(['laladia.com/api/v1/admin/orders*' => Http::response(['success' => true, 'data' => $orders])]);
}

it('inserts an order the webhook never delivered, and a second run inserts nothing', function () {
    fakeAdminList([adminListOrder()]);

    $this->artisan('hq:facts:reconcile --store=laladia-com --hours=48')
        ->expectsOutputToContain('checked 1 orders since')->assertSuccessful();

    expect(FactsOrder::count())->toBe(1);
    $identities = CustomerIdentity::count();
    $customers = Customer::count();

    $this->artisan('hq:facts:reconcile --store=laladia-com --hours=48')
        ->expectsOutputToContain('inserted 0 missing')->assertSuccessful();

    expect(FactsOrder::count())->toBe(1)
        ->and(Customer::count())->toBe($customers)
        ->and(CustomerIdentity::count())->toBe($identities);
});

it('reconciliation fills the channel and district a webhook cannot carry, and leaves the lines it never sees', function () {
    // First the webhook arrives: lines, but no source and no address.
    $facts = app(FactsOrderUpserter::class);
    $store = Store::where('slug', 'laladia-com')->sole();
    $facts->upsertFromShopPayload($store, liveWebhookOrderPayload('LLD-RECON-1'));
    $fact = FactsOrder::where('order_ref', 'LLD-RECON-1')->sole();
    expect($fact->source)->toBeNull()->and($fact->district)->toBeNull()->and((float) $fact->lines[0]['unit_price'])->toBe(333.0);

    // Then reconciliation replays the admin shape over it.
    fakeAdminList([adminListOrder('LLD-RECON-1')]);
    $this->artisan('hq:facts:reconcile --store=laladia-com --hours=48')->assertSuccessful();

    $fact->refresh();
    expect($fact->source)->toBe('checkout')->and($fact->district)->toBe('Dhaka')->and($fact->thana)->toBe('Gulshan')
        // The admin list carries no items, so the lines the webhook delivered must survive untouched.
        ->and((float) $fact->lines[0]['unit_price'])->toBe(333.0)
        ->and((float) $fact->lines[0]['total'])->toBe(999.0);
});

it('replays a stored order.created event through the corrected handler without asking the shop', function () {
    $store = Store::where('slug', 'laladia-com')->sole();
    $event = DomainEvent::create([
        'event_id' => (string) Str::uuid(), 'type' => 'order.created', 'direction' => 'inbound',
        'source' => 'shop:laladia-com', 'aggregate_type' => 'order', 'aggregate_id' => 'LLD-REPLAY-1',
        'business_id' => $store->business_id, 'payload' => liveWebhookOrderPayload('LLD-REPLAY-1') + ['_store' => 'laladia-com'],
        'dedupe_key' => 'shop:laladia-com:replay-1', 'occurred_at' => now()->subHours(2),
        'status' => DomainEvent::STATUS_PROCESSED, 'processed_at' => now()->subHours(2),
    ]);

    // A dry run reports and changes nothing.
    $this->artisan('hq:events:replay --type=order.created --store=laladia-com')->assertSuccessful();
    expect(FactsOrder::count())->toBe(0);

    $this->artisan('hq:events:replay --type=order.created --store=laladia-com --apply --reason="line price repair"')
        ->expectsOutputToContain('replayed 1')->assertSuccessful();

    $fact = FactsOrder::where('order_ref', 'LLD-REPLAY-1')->sole();
    expect((float) $fact->lines[0]['unit_price'])->toBe(333.0)->and((float) $fact->lines[0]['total'])->toBe(999.0)
        ->and($fact->last_event_id)->toBe($event->event_id);
    // The shop was never contacted, and the replay is on the record.
    Http::assertNothingSent();
    expect(AuditLog::where('action', 'event.replayed')->count())->toBe(1);
});

it('replaying twice changes nothing, and never regresses a status that has moved on', function () {
    $store = Store::where('slug', 'laladia-com')->sole();
    DomainEvent::create([
        'event_id' => (string) Str::uuid(), 'type' => 'order.created', 'direction' => 'inbound',
        'source' => 'shop:laladia-com', 'aggregate_type' => 'order', 'aggregate_id' => 'LLD-REPLAY-2',
        'business_id' => $store->business_id, 'payload' => liveWebhookOrderPayload('LLD-REPLAY-2') + ['_store' => 'laladia-com'],
        'dedupe_key' => 'shop:laladia-com:replay-2', 'occurred_at' => now()->subHours(2),
        'status' => DomainEvent::STATUS_PROCESSED, 'processed_at' => now()->subHours(2),
    ]);
    $this->artisan('hq:events:replay --type=order.created --apply')->assertSuccessful();

    // The order has since been cancelled; the stored event still says pending.
    FactsOrder::where('order_ref', 'LLD-REPLAY-2')->sole()->forceFill(['status' => 'cancelled'])->save();
    $customers = Customer::count();
    $identities = CustomerIdentity::count();

    $this->artisan('hq:events:replay --type=order.created --apply')->assertSuccessful();

    expect(FactsOrder::count())->toBe(1)
        ->and(FactsOrder::sole()->status)->toBe('cancelled')
        ->and(Customer::count())->toBe($customers)
        ->and(CustomerIdentity::count())->toBe($identities);
});

it('refuses to replay every event when no filter is given', function () {
    $this->artisan('hq:events:replay --apply')->assertFailed();
});

it('runs reconciliation as a registered tool, scoped to one store, and repeats safely', function () {
    fakeAdminList([adminListOrder('LLD-TOOL-1')]);
    $tool = app(ToolRegistry::class)->get('commerce.reconcile_facts');
    $context = new ToolContext(idempotencyKey: 'idem-1', correlationId: 'corr-1');

    $first = $tool->execute(['store_slug' => 'laladia-com', 'hours' => 48], $context);
    expect($first->ok())->toBeTrue()
        ->and($first->output['store'])->toBe('laladia-com')
        ->and($first->output['checked'])->toBe(1)
        ->and($first->output['inserted'])->toBe(1)
        ->and($first->output['hours'])->toBe(48);

    $second = $tool->execute(['store_slug' => 'laladia-com', 'hours' => 48], $context);
    expect($second->output['inserted'])->toBe(0)->and(FactsOrder::count())->toBe(1);

    // Naming a store HQ does not have is a configuration mistake, so it fails rather than retrying.
    expect($tool->execute(['store_slug' => 'no-such-shop'], $context)->status)->toBe('failed');
    expect($tool->reconcile($context))->toBeNull();
});
