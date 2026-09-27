<?php

use App\Commerce\Orders\ErpOperationKey;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\ErpRegistry;
use App\Facts\ErpOrderReadBackFact;
use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\Business;
use App\Models\Customer;
use App\Models\FactsOrder;
use App\Models\IntegrationCredential;
use App\Models\Store;
use App\Models\User;
use App\Tools\Commerce\CancelSalesOrderTool;
use App\Tools\Commerce\GetOrderStatusTool;
use App\Tools\Commerce\TakeOrderTool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Erp\SimulatedErp;

/*
 | docs/90 A.5 (stream Z): what the ERP says about an order HQ placed is a `facts_orders` row the moment it
 | is read back, after `take_order`'s read-back, after a status read, after a cancellation's read-back,
 | with the evidence beside it (source system, provenance, the ERP's ids, the key HQ made it under, the
 | HQ call that read it, a digest of exactly what was read, the ERP's own as_of).
 |
 | What must hold: the row is the ERP's side and never the proposal's; a read-back that says the same
 | thing writes nothing; an older read-back never overwrites a newer one; an answer that names no order
 | writes no row; the ERP stays authoritative (nothing here writes to it) and a disagreement is reported
 | by the tool, never repaired by the row.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole('owner');
    $this->owner = $this->owner->fresh();
    $this->approver = User::factory()->create();
    $this->approver->givePermissionTo(['hq.approvals.view', 'hq.approvals.decide', 'hq.tools.commerce.write']);
    $this->approver = $this->approver->fresh();

    config(['hq.erp.base_url' => SimulatedErp::BASE]);
    IntegrationCredential::create(['provider' => ErpRegistry::PROVIDER, 'account_ref' => 'laladia', 'credential_type' => 'token',
        'payload' => ['value' => 'fact-token'], 'status' => 'active']);

    $this->business = Business::where('slug', 'laladia')->sole();
    $this->store = Store::where('slug', 'laladia-com')->sole();
    $this->order = [
        'business_slug' => 'laladia', 'store_slug' => 'laladia-com', 'erp_store_key' => 'laladia',
        'destination' => ['name' => 'Rahim Uddin', 'phone' => '+880 1711-000001', 'district' => 'Bagerhat', 'thana' => 'Fakirhat', 'address_line' => 'House 4, Road 2'],
        'lines' => [['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.00'], ['sku' => 'GHE-250', 'quantity' => 1, 'unit_price' => '650.50']],
        'payment_method' => 'cod',
        'proposed' => ['goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50'],
    ];

    // An order HQ placed, in the simulation: the tool run directly under a fixed key (the gateway path is
    // proven in TakeOrderTest), returning the tool's result.
    $this->place = function (string $key = 'approval:fact-order', ?array $order = null): ToolResult {
        $context = new ToolContext(idempotencyKey: $key, correlationId: 'corr-'.$key, businessId: $this->business->id);

        return app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'laladia']],
            fn () => app(TakeOrderTool::class)->execute($order ?? $this->order, $context));
    };
});

function readBackOperator(User $user, string $tool, array $args): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, $tool, $args));
}

it('writes the order fact from the ERP read-back, with the evidence beside it, and links the customer like a shop order', function () {
    SimulatedErp::fake();
    $result = ($this->place)();
    expect($result->status)->toBe(ToolResult::STATUS_SUCCEEDED);

    $fact = FactsOrder::sole();
    $orderKey = ErpOperationKey::for('approval:fact-order', $this->business->id, TakeOrderTool::ORDER_STEP);
    expect($fact->store_id)->toBe($this->store->id)
        ->and($fact->business_id)->toBe($this->business->id)
        ->and($fact->order_ref)->toBe($result->output['shop_reference'])
        ->and($fact->source)->toBe('hq_ai')
        ->and($fact->source_system)->toBe('erp')
        ->and($fact->provenance)->toBe('business')
        ->and($fact->erp_order_id)->toBe(1)
        ->and($fact->erp_order_number)->toBe('SO-2026-27-0001')
        ->and($fact->erp_status)->toBe('open')
        ->and($fact->erp_approval_status)->toBe('pending')
        ->and($fact->status)->toBe('pending', 'open and waiting for the ERP\'s approval is pending in the shop-shaped fact')
        ->and($fact->hq_key)->toBe($orderKey)
        ->and($fact->correlation_id)->toBe('corr-approval:fact-order')
        ->and($fact->read_back_digest)->toMatch('/^[0-9a-f]{64}$/')
        ->and($fact->read_back_at?->getTimestamp())->toBe(CarbonImmutable::parse('2026-09-21T10:00:00+06:00')->getTimestamp(), 'the ERP\'s own as_of, never HQ\'s clock')
        ->and((string) $fact->subtotal)->toBe('2350.50')
        ->and((string) $fact->shipping_cost)->toBe('120.00')
        ->and((string) $fact->gross)->toBe('2470.50')
        ->and($fact->payment_method)->toBe('cod')
        ->and($fact->district)->toBe('Bagerhat')
        ->and($fact->thana)->toBe('Fakirhat')
        ->and($fact->customer_phone_canonical)->toBe('01711000001', 'canonical the way every shop fact is')
        ->and(collect($fact->lines)->pluck('sku')->all())->toBe(['HON-500', 'GHE-250'])
        ->and((int) collect($fact->lines)->firstWhere('sku', 'HON-500')['qty'])->toBe(2)
        ->and((float) collect($fact->lines)->firstWhere('sku', 'HON-500')['unit_price'])->toBe(850.0)
        ->and((float) collect($fact->lines)->firstWhere('sku', 'HON-500')['total'])->toBe(1700.0);

    // The customer is resolved the way a shop order resolves one, and counts this order.
    $customer = Customer::findOrFail($fact->customer_id);
    expect((int) $customer->orders_count)->toBe(1)
        ->and((float) $customer->lifetime_value)->toBe(2470.5);
});

it('writes nothing new when the ERP says the same thing again: a replayed order, a status read of an unchanged order', function () {
    $erp = SimulatedErp::fake();
    ($this->place)();
    $before = FactsOrder::sole();
    $digest = $before->read_back_digest;
    $updatedAt = $before->updated_at->toIso8601String();
    $synced = $before->synced_at->toIso8601String();
    $this->travel(2)->minutes();

    // The same key again: the ERP replays the order and HQ reads it back again.
    expect(($this->place)()->status)->toBe(ToolResult::STATUS_SUCCEEDED)
        ->and($erp['orderGets'])->toBe(2);
    // A status read of the same order.
    Cache::flush();
    $status = readBackOperator($this->owner, GetOrderStatusTool::KEY, ['business_slug' => 'laladia', 'reference' => $before->order_ref]);
    expect($status->status)->toBe(OutcomeStatus::Succeeded);

    $after = FactsOrder::sole();
    expect(FactsOrder::count())->toBe(1)
        ->and($after->read_back_digest)->toBe($digest)
        ->and($after->updated_at->toIso8601String())->toBe($updatedAt, 'the same answer writes nothing, not even a timestamp')
        ->and($after->synced_at->toIso8601String())->toBe($synced);
});

it('replaces the fact with what the ERP says now when the order moved, and never regresses to an older answer', function () {
    $erp = SimulatedErp::fake();
    ($this->place)();
    $first = FactsOrder::sole();

    // The ERP delivered part of it: the status read reads that back, and the fact follows the ERP.
    SimulatedErp::deliver($erp, $first->order_ref);
    Cache::flush();
    $status = readBackOperator($this->owner, GetOrderStatusTool::KEY, ['business_slug' => 'laladia', 'reference' => $first->order_ref]);
    expect($status->status)->toBe(OutcomeStatus::Succeeded)
        ->and($status->output['status'])->toBe('partially_delivered');

    $moved = FactsOrder::sole();
    expect($moved->erp_status)->toBe('partially_delivered')
        ->and($moved->status)->toBe('processing')
        ->and($moved->read_back_digest)->not->toBe($first->read_back_digest)
        ->and($moved->correlation_id)->not->toBe($first->correlation_id);

    // An answer older than the one applied is refused, even when it says something different: nothing on
    // the row moves. (The row is made to look like a newer, different reading; the ERP's answer keeps its
    // fixed as_of of 21 September.)
    $moved->forceFill(['read_back_at' => CarbonImmutable::parse('2026-09-22T10:00:00+06:00'), 'read_back_digest' => str_repeat('f', 64)])->save();
    $stale = FactsOrder::sole();
    Cache::flush();
    readBackOperator($this->owner, GetOrderStatusTool::KEY, ['business_slug' => 'laladia', 'reference' => $first->order_ref]);
    $unchanged = FactsOrder::sole();
    expect($unchanged->read_back_digest)->toBe($stale->read_back_digest)
        ->and($unchanged->read_back_at->toIso8601String())->toBe($stale->read_back_at->toIso8601String())
        ->and($unchanged->updated_at->toIso8601String())->toBe($stale->updated_at->toIso8601String());
});

it('records the ERP\'s side when the read-back disagrees with the approved draft, while the tool stays uncertain and repairs nothing', function () {
    $erp = SimulatedErp::fake(['orderReadBackTampered' => true]);
    $result = ($this->place)();

    expect($result->status)->toBe(ToolResult::STATUS_UNCERTAIN)
        ->and($result->error)->toContain('lines.HON-500.quantity');

    // The row is what the ERP said (quantity 3), never what was proposed (quantity 2); the disagreement
    // is the tool's report, and the row did not settle it.
    $fact = FactsOrder::sole();
    expect((int) collect($fact->lines)->firstWhere('sku', 'HON-500')['qty'])->toBe(3)
        ->and($fact->erp_status)->toBe('open')
        ->and($erp['orderPosts'])->toBe(1, 'nothing was sent to the ERP to make it agree');
});

it('follows the cancellation read-back: the fact becomes cancelled with the ERP\'s as_of, under the same reference', function () {
    SimulatedErp::fake();
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
    $placed = ($this->place)()->output;
    $before = FactsOrder::sole();
    Cache::flush();

    $approval = Approval::findOrFail(readBackOperator($this->owner, CancelSalesOrderTool::KEY, [
        'business_slug' => 'laladia', 'store_slug' => 'laladia-com', 'reference' => $placed['shop_reference'], 'reason' => 'The customer changed their mind on the phone.',
    ])->approvalId);
    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);
    expect($outcome->status)->toBe(OutcomeStatus::Succeeded);

    $fact = FactsOrder::sole();
    expect($fact->id)->toBe($before->id)
        ->and($fact->erp_status)->toBe('cancelled')
        ->and($fact->status)->toBe('cancelled')
        ->and($fact->cancelled_at)->not->toBeNull()
        ->and($fact->read_back_digest)->not->toBe($before->read_back_digest);
});

it('writes no row from an answer that names no order, and none from a store that is not the business\'s', function () {
    $writer = app(ErpOrderReadBackFact::class);
    $meta = ['business_id' => 1, 'contract' => 'hq-sales-orders.v1', 'as_of' => '2026-09-21T10:00:00+06:00'];

    expect(fn () => $writer->record($this->store, ErpOrderRead::notFound('not_found', 'none')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $writer->record($this->store, new ErpOrderRead(found: true, data: ['status' => 'open', 'shop_reference' => 'LLD1-X'], meta: $meta)))->toThrow(InvalidArgumentException::class, 'names no order')
        ->and(fn () => $writer->record($this->store, new ErpOrderRead(found: true, data: ['status' => 'open', 'order_number' => 'SO-1', 'shop_reference' => 'LLD1-X'], meta: ['contract' => 'hq-order-reads.v1'])))->toThrow(InvalidArgumentException::class, 'orders contract')
        ->and(FactsOrder::count())->toBe(0);

    // A store of another business named in the request (the gateway refuses this before the ERP; the tool
    // run directly reaches the read-back): no fact can belong to it, and the tool fails closed rather than
    // filing the order under a store it is not for.
    SimulatedErp::fake();
    $other = Store::where('business_id', '!=', $this->business->id)->first();
    expect(fn () => ($this->place)('approval:other-store', ['store_slug' => $other->slug] + $this->order))
        ->toThrow(InvalidArgumentException::class, 'not a store of business laladia')
        ->and(FactsOrder::count())->toBe(0);
});

it('leaves a status read of an order with no fact row alone: the ERP names its store by the ERP\'s key, which HQ cannot place', function () {
    SimulatedErp::fake();
    $placed = ($this->place)()->output;
    FactsOrder::query()->delete();
    Cache::flush();

    $status = readBackOperator($this->owner, GetOrderStatusTool::KEY, ['business_slug' => 'laladia', 'reference' => $placed['shop_reference']]);
    expect($status->status)->toBe(OutcomeStatus::Succeeded)
        ->and(FactsOrder::count())->toBe(0);
});

it('never writes to the ERP: every request the fact writer causes is a read', function () {
    $erp = SimulatedErp::fake();
    ($this->place)();
    Cache::flush();
    readBackOperator($this->owner, GetOrderStatusTool::KEY, ['business_slug' => 'laladia', 'reference' => FactsOrder::sole()->order_ref]);

    expect($erp['posts'])->toBe(1, 'the draft')
        ->and($erp['orderPosts'])->toBe(1, 'the order')
        ->and($erp['cancels'])->toBe(0)
        ->and($erp['orderGets'])->toBe(2, 'the read-back after the write and the status read');
});
