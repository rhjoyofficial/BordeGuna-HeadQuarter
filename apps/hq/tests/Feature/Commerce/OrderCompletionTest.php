<?php

use App\Agents\AgentToolset;
use App\Commerce\Orders\ErpOperationKey;
use App\Erp\ErpRegistry;
use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\CallerKind;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\Descriptor\ActionClass;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\Redaction\Redactor;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\ToolCall;
use App\Models\User;
use App\Tools\Commerce\CancelSalesOrderPolicy;
use App\Tools\Commerce\CancelSalesOrderTool;
use App\Tools\Commerce\GetOrderStatusTool;
use App\Tools\Commerce\QuoteOrderTool;
use App\Tools\Commerce\TakeOrderTool;
use App\Tools\ToolContext;
use App\Tools\ToolRegistry;
use App\Tools\ToolResult;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Erp\SimulatedErp;
use Tests\Support\Execution\Contexts;

/*
 | Phase 4 order completion (docs/88 A point 3, docs/79 D): the quote and the status, two R2 reads of the
 | ERP's own answers; and `commerce.cancel_sales_order`, `take_order`'s compensating tool, R4,
 | approval-required, irreversible, reaching only an order HQ placed while nothing has been delivered,
 | inert under the same empty store list. What must hold: no agent holds any of the three; the reads carry
 | no personal data into a stored row; the cancel is refused for every caller in production's state, sent
 | to approval with a store admitted, never decided by the asker, and called `succeeded` only after the ERP
 | shows the order cancelled under THIS request's key (ADR-031).
 */

const QUOTE = 'commerce.quote_order';
const STATUS = 'commerce.get_order_status';
const CANCEL = 'commerce.cancel_sales_order';

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
        'payload' => ['value' => 'order-token'], 'status' => 'active']);

    $this->business = Business::where('slug', 'laladia')->sole();
    $this->destination = ['name' => 'Rahim Uddin', 'phone' => '+880 1711-000001', 'district' => 'Bagerhat', 'thana' => 'Fakirhat', 'address_line' => 'House 4, Road 2'];
    $this->order = [
        'business_slug' => 'laladia', 'store_slug' => 'laladia-com', 'erp_store_key' => 'laladia',
        'destination' => $this->destination,
        'lines' => [['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.00'], ['sku' => 'GHE-250', 'quantity' => 1, 'unit_price' => '650.50']],
        'payment_method' => 'cod',
        'proposed' => ['goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50'],
    ];
    $this->quote = ['business_slug' => 'laladia', 'erp_store_key' => 'laladia', 'destination' => $this->destination,
        'lines' => [['sku' => 'HON-500', 'quantity' => 2], ['sku' => 'GHE-250', 'quantity' => 1]]];

    // An order HQ placed, in the simulation, for the status and cancel tests: the take_order tool run
    // directly under a fixed key (the gateway path is proven in TakeOrderTest).
    $this->placeOrder = function (string $key = 'approval:placed-order') {
        $context = new ToolContext(idempotencyKey: $key, correlationId: 'corr-'.$key, businessId: $this->business->id);
        $result = app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'laladia']], fn () => app(TakeOrderTool::class)->execute($this->order, $context));
        expect($result->status)->toBe(ToolResult::STATUS_SUCCEEDED);

        return $result->output;
    };
});

function orderOperator(User $user, string $tool, array $args): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, $tool, $args));
}

function cancelArgs(string $reference, string $reason = 'The customer changed their mind on the phone.'): array
{
    return ['business_slug' => 'laladia', 'store_slug' => 'laladia-com', 'reference' => $reference, 'reason' => $reason];
}

// ── as registered ──────────────────────────────────────────────────────────────────────────────

it('registers the quote and the status as R2 reads of the orders contract, held by no agent, and the cancel as the irreversible R4 compensating tool', function () {
    $registry = app(ToolRegistry::class);
    $quote = $registry->get(QUOTE)->descriptor();
    $status = $registry->get(STATUS)->descriptor();
    $cancel = $registry->get(CANCEL)->descriptor();

    expect($registry->get(QUOTE))->toBeInstanceOf(QuoteOrderTool::class)
        ->and($quote->riskTier)->toBe(RiskTier::R2)
        ->and($quote->readWrite)->toBe(ReadWrite::Read)
        ->and($quote->agentExecutable)->toBeTrue()
        ->and($quote->customerFacing)->toBeFalse()
        ->and($quote->dataClass)->toBe(DataClass::Personal)
        ->and($quote->redact)->toBe(['destination.name', 'destination.phone', 'destination.address_line', 'destination.landmark'])
        ->and($quote->permission)->toBe('hq.tools.erp.read')
        ->and($quote->replayWithinSeconds)->toBe(60);

    expect($registry->get(STATUS))->toBeInstanceOf(GetOrderStatusTool::class)
        ->and($status->riskTier)->toBe(RiskTier::R2)
        ->and($status->readWrite)->toBe(ReadWrite::Read)
        ->and($status->agentExecutable)->toBeTrue()
        ->and($status->customerFacing)->toBeFalse()
        ->and($status->dataClass)->toBe(DataClass::Internal);

    expect($registry->get(CANCEL))->toBeInstanceOf(CancelSalesOrderTool::class)
        ->and($cancel->riskTier)->toBe(RiskTier::R4)
        ->and($cancel->riskTier->baseline())->toBe(ActionClass::ApprovalRequired)
        ->and($cancel->readWrite)->toBe(ReadWrite::Write)
        ->and($cancel->permission)->toBe('hq.tools.commerce.write')
        ->and($cancel->agentExecutable)->toBeFalse()
        ->and($cancel->permanentlyHumanOnly)->toBeFalse()
        ->and($cancel->requesterMayDecide)->toBeFalse()
        ->and($cancel->supportedStores)->toBe([])
        ->and($cancel->policyClass)->toBe(CancelSalesOrderPolicy::class)
        ->and($cancel->customerFacing)->toBeTrue()
        ->and($cancel->channel)->toBe(Channel::Internal)
        ->and($cancel->consentRequired)->toBeNull()
        ->and($cancel->monetaryImpact)->toBe(MonetaryImpact::High)
        ->and($cancel->reversibility)->toBe(Reversibility::Irreversible)
        ->and($cancel->compensatingTool)->toBeNull()
        ->and($cancel->dataClass)->toBe(DataClass::Personal)
        ->and($cancel->replayWithinSeconds)->toBe(0)
        ->and($cancel->credentials[0]->provider)->toBe(ErpRegistry::PROVIDER)
        ->and($registry->get(TakeOrderTool::KEY)->descriptor()->compensatingTool)->toBe(CANCEL);
});

it('gives none of the three to any agent, and an agent naming the cancel runs nothing', function () {
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
    Http::fake();

    foreach (Agent::all() as $agent) {
        foreach ([QUOTE, STATUS, CANCEL] as $key) {
            expect($agent->allowed_tools ?? [])->not->toContain($key);
            expect(app(AgentToolset::class)->keysFor(Contexts::for($agent)))->not->toContain($key);
        }
    }

    $executive = Agent::where('key', 'executive')->sole();
    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: CANCEL, args: cancelArgs('SO-2026-27-0001'), caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($executive)), correlationId: (string) Str::uuid(),
    ));

    expect($outcome->ok())->toBeFalse()
        ->and(Approval::count())->toBe(0);
    Http::assertNothingSent();
});

// ── the quote ──────────────────────────────────────────────────────────────────────────────────

it('quotes an order from the ERP, stores no name, phone or address, and writes nothing', function () {
    $erp = SimulatedErp::fake();

    $outcome = orderOperator($this->owner, QUOTE, $this->quote);

    expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
        ->and($outcome->output)->toMatchArray(['business' => 'laladia', 'source' => 'erp', 'contract' => 'hq-sales-orders.v1', 'store_key' => 'laladia',
            'goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50', 'payment_methods' => ['cod'], 'written' => false])
        ->and($outcome->output['lines'][0])->toMatchArray(['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.0000', 'line_total' => '1700.00', 'sellable' => true, 'made_to_order' => false])
        ->and($outcome->output['lines'][1]['made_to_order'])->toBeTrue()
        ->and($outcome->output['delivery_promise'])->toContain('24 to 72 hours')
        ->and($erp['quotes'])->toBe(1)
        ->and($erp['posts'])->toBe(0)
        ->and($erp['orderPosts'])->toBe(0);

    // ⛔ The recorded arguments mask the person; the output never carried them.
    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->input['destination']['name'])->toBe(Redactor::MASK)
        ->and($call->input['destination']['phone'])->toBe(Redactor::MASK)
        ->and($call->input['destination']['district'])->toBe('Bagerhat');
    $stored = json_encode($call->output);
    expect($stored)->not->toContain('Rahim')->not->toContain('1711')->not->toContain('House 4');

    // And the ERP was asked without any price: the quote is what asks for one.
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sales-orders/quote')
        && $r['lines'] === [['sku' => 'HON-500', 'quantity' => 2], ['sku' => 'GHE-250', 'quantity' => 1]]);
});

it('refuses a malformed quote before the ERP, and reports the ERP refusing one with its own sentence', function () {
    $erp = SimulatedErp::fake();

    $bad = $this->quote;
    $bad['destination']['phone'] = '12345';
    $bad['lines'] = [['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.00']];
    $outcome = orderOperator($this->owner, QUOTE, $bad);
    expect($outcome->status)->toBe(OutcomeStatus::Failed)
        ->and($outcome->error)->toContain('[destination.phone]')
        ->and($outcome->error)->toContain('[lines.0.unit_price] is not taken')
        ->and($erp['quotes'])->toBe(0);

    $unknown = $this->quote;
    $unknown['lines'] = [['sku' => 'NOPE-1', 'quantity' => 1]];
    $outcome = orderOperator($this->owner, QUOTE, $unknown);
    expect($outcome->status)->toBe(OutcomeStatus::Failed)
        ->and($outcome->error)->toContain('would not take this order')
        ->and($erp['quotes'])->toBe(1);
});

// ── the status ─────────────────────────────────────────────────────────────────────────────────

it('reads an order HQ placed by its reference, its number or HQ\'s key, names no person, and says "none" for any other', function () {
    SimulatedErp::fake();
    $placed = ($this->placeOrder)();
    Cache::flush();

    foreach ([$placed['shop_reference'], $placed['order_number']] as $reference) {
        $outcome = orderOperator($this->owner, STATUS, ['business_slug' => 'laladia', 'reference' => $reference]);
        expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
            ->and($outcome->output)->toMatchArray(['found' => true, 'contract' => 'hq-sales-orders.v1', 'status' => 'open', 'approval_status' => 'pending',
                'deliverable' => false, 'order_number' => $placed['order_number'], 'agreed_total' => '2470.50', 'cancellation_requested' => false])
            ->and($outcome->output['hq']['idempotency_key'])->toBe($placed['erp_key'])
            ->and(json_encode($outcome->output))->not->toContain('Rahim')->not->toContain('1711')->not->toContain('House 4');
    }

    $byKey = orderOperator($this->owner, STATUS, ['business_slug' => 'laladia', 'hq_key' => $placed['erp_key']]);
    expect($byKey->status)->toBe(OutcomeStatus::Succeeded)
        ->and($byKey->output['order_number'])->toBe($placed['order_number']);

    $none = orderOperator($this->owner, STATUS, ['business_slug' => 'laladia', 'reference' => 'SO-NOT-HQ-0001']);
    expect($none->status)->toBe(OutcomeStatus::Succeeded)
        ->and($none->output['found'])->toBeFalse()
        ->and($none->output['reason'])->toContain('No order HQ placed');

    expect(orderOperator($this->owner, STATUS, ['business_slug' => 'laladia'])->status)->toBe(OutcomeStatus::Failed)
        ->and(orderOperator($this->owner, STATUS, ['business_slug' => 'laladia', 'reference' => 'x', 'hq_key' => 'y'])->status)->toBe(OutcomeStatus::Failed);
});

// ── the cancel: inert, approval-required, never decided by the asker ───────────────────────────

it('refuses the cancel for every caller in production\'s state, before any approval and before the ERP', function () {
    Http::fake();

    $outcome = orderOperator($this->owner, CANCEL, cancelArgs('SO-2026-27-0001'));

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('does not support store [laladia-com]')
        ->and(Approval::count())->toBe(0);
    Http::assertNothingSent();
});

it('sends even an owner to approval with a store admitted, says it is irreversible, and never lets the asker decide', function () {
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
    $erp = SimulatedErp::fake();

    $outcome = orderOperator($this->owner, CANCEL, cancelArgs('SO-2026-27-0001'));

    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval);
    $approval = Approval::findOrFail($outcome->approvalId);
    expect($approval->tool_key)->toBe(CANCEL)
        ->and($approval->risk_tier)->toBe(RiskTier::R4)
        ->and($approval->preview['result']['irreversible'])->toBeTrue()
        ->and($approval->preview['result']['refused_when'])->toContain('delivered');

    $denied = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->owner);
    expect($denied->status)->toBe(OutcomeStatus::Denied)
        ->and($denied->error)->toContain('somebody other than the person who asked')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($erp['cancels'])->toBe(0);

    // A store of another business, or a reason that is not a sentence: refused before an approval.
    $foreign = cancelArgs('SO-2026-27-0001');
    $foreign['business_slug'] = 'bor-de-guna';
    expect(orderOperator($this->owner, CANCEL, $foreign)->error)->toContain('belongs to another business');
    $blank = orderOperator($this->owner, CANCEL, cancelArgs('SO-2026-27-0001', '  a  '));
    expect($blank->status)->toBe(OutcomeStatus::Denied)
        ->and($blank->error)->toContain('[reason]')
        ->and(Approval::count())->toBe(1);
});

it('cancels an order HQ placed when another person approves, and calls it succeeded only after the ERP shows it cancelled under this key', function () {
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
    $erp = SimulatedErp::fake();
    $placed = ($this->placeOrder)();
    $approval = Approval::findOrFail(orderOperator($this->owner, CANCEL, cancelArgs($placed['shop_reference']))->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);

    $key = ErpOperationKey::for("approval:{$approval->uuid}", $this->business->id, 'cancel');
    expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
        ->and($outcome->output)->toMatchArray(['business' => 'laladia', 'source' => 'erp', 'contract' => 'hq-sales-orders.v1', 'status' => 'cancelled',
            'order_number' => $placed['order_number'], 'shop_reference' => $placed['shop_reference'], 'cancellation_key' => $key, 'verified' => true, 'replayed' => false,
            'next_step' => 'None. The order is cancelled.'])
        ->and($outcome->output['effects'])->toMatchArray(['stock_moved' => false, 'journal_posted' => false, 'payment_taken' => false, 'courier_booked' => false])
        ->and($outcome->providerRef)->toBe('erp-order:1')
        ->and($erp['cancels'])->toBe(1);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/cancel')
        && $r['idempotency_key'] === $key && $r['hq_reference']['approval_uuid'] === $approval->uuid
        && $r['reason'] === 'The customer changed their mind on the phone.');

    // The status read now says so, and the ERP refuses a second cancellation of it.
    Cache::flush();
    $status = orderOperator($this->owner, STATUS, ['business_slug' => 'laladia', 'reference' => $placed['order_number']]);
    expect($status->output)->toMatchArray(['status' => 'cancelled', 'cancellation_requested' => true]);
});

it('replays the same key, and reports already cancelled, deliveries against it, and an order HQ did not place, without retrying', function () {
    $erp = SimulatedErp::fake();
    $placed = ($this->placeOrder)();
    $second = ($this->placeOrder)('approval:placed-order-2');
    $tool = app(CancelSalesOrderTool::class);
    $run = fn (string $key, array $args) => app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'laladia']],
        fn () => $tool->execute($args, new ToolContext(idempotencyKey: $key, correlationId: 'corr-'.$key, businessId: $this->business->id)));

    $one = $run('approval:cancel-1', cancelArgs($placed['shop_reference']));
    $two = $run('approval:cancel-1', cancelArgs($placed['shop_reference']));
    expect($one->status)->toBe(ToolResult::STATUS_SUCCEEDED)
        ->and($two->status)->toBe(ToolResult::STATUS_SUCCEEDED)
        ->and($one->output['replayed'])->toBeFalse()
        ->and($two->output['replayed'])->toBeTrue()
        ->and($erp['cancels'])->toBe(2);

    $again = $run('approval:cancel-2', cancelArgs($placed['order_number']));
    expect($again->status)->toBe(ToolResult::STATUS_FAILED)->and($again->error)->toContain('already cancelled');

    SimulatedErp::deliver($erp, $second['shop_reference']);
    $late = $run('approval:cancel-3', cancelArgs($second['shop_reference']));
    expect($late->status)->toBe(ToolResult::STATUS_FAILED)->and($late->error)->toContain('has deliveries against it');

    $nobody = $run('approval:cancel-4', cancelArgs('SO-NOT-HQ-0001'));
    expect($nobody->status)->toBe(ToolResult::STATUS_FAILED)->and($nobody->error)->toContain('No order HQ placed');
});

it('calls a cancellation whose POST never answered uncertain, and settles it by reading the order back', function () {
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
    $erp = SimulatedErp::fake(['cancelPostTimesOut' => true]);
    $placed = ($this->placeOrder)();
    $approval = Approval::findOrFail(orderOperator($this->owner, CANCEL, cancelArgs($placed['shop_reference']))->approvalId);

    $first = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);
    expect($first->status)->toBe(OutcomeStatus::Uncertain)
        ->and($erp['cancels'])->toBe(1);

    $again = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: CANCEL, args: $approval->payload, caller: CallerKind::HumanUi, identity: ActingIdentity::human($this->approver),
        correlationId: (string) $approval->correlation_id, businessId: $approval->business_id, storeId: $approval->store_id,
        idempotencyKey: "approval:{$approval->uuid}",
    ), $approval->fresh());

    expect($again->status)->toBe(OutcomeStatus::Succeeded)
        ->and($again->output['verified'])->toBeTrue()
        ->and($again->output['status'])->toBe('cancelled')
        ->and($erp['cancels'])->toBe(1, 'nothing was POSTed again');
});

it('never calls a cancellation the ERP does not show when read back succeeded', function () {
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
    SimulatedErp::fake(['cancelReadBackStale' => true]);
    $placed = ($this->placeOrder)();
    $approval = Approval::findOrFail(orderOperator($this->owner, CANCEL, cancelArgs($placed['shop_reference']))->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);

    expect($outcome->status)->toBe(OutcomeStatus::Uncertain)
        ->and($outcome->error)->toContain('reads [open] when read back');
});

// ── no other door ──────────────────────────────────────────────────────────────────────────────

it('reaches each of the three only through the gateway: no controller, command, job or other class names them', function () {
    foreach (['QuoteOrderTool', 'GetOrderStatusTool', 'CancelSalesOrderTool'] as $class) {
        $mentions = collect(File::allFiles(app_path()))
            ->filter(fn ($f) => $f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), $class))
            ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))->values()->all();

        expect($mentions)->toBe($class === 'CancelSalesOrderTool' ? ['Tools/Commerce/CancelSalesOrderTool.php', 'Tools/Commerce/TakeOrderTool.php'] : ["Tools/Commerce/{$class}.php"])
            ->and((string) file_get_contents(config_path('hq.php')))->toContain("{$class}::class");
    }
});
