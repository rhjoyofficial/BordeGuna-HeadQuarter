<?php

use App\Agents\AgentToolset;
use App\Commerce\Orders\DraftReconciliation;
use App\Commerce\Orders\ErpOperationKey;
use App\Commerce\Orders\OrderReconciliation;
use App\Erp\ErpRegistry;
use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalService;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\Approval\CanonicalPayload;
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
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\Business;
use App\Models\IntegrationCredential;
use App\Models\ToolCall;
use App\Models\User;
use App\Tools\Commerce\TakeOrderPolicy;
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
 | `commerce.take_order`, the Phase 4 order write, built INERT (docs/83 C.3, docs/88 A point 3, docs/79,
 | ADR-046).
 |
 | What must hold: the capability exists and is refused for every caller in production's state; with
 | a store admitted (only in these tests), a person is sent to approval and never allowed, the person
 | who asked never decides, the approval runs exactly its stored payload; the effect is two ERP writes,
 | a DRAFT the ERP prices and checks and then an ORDER made from it that WAITS in the ERP's approval
 | inbox, each under its own key and each read back from a simulated ERP before anything is called
 | `succeeded` (ADR-031); HQ approves nothing in the ERP. No agent holds it.
 */

const TAKE_ORDER = 'commerce.take_order';

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
        'payload' => ['value' => 'draft-token'], 'status' => 'active']);

    $this->order = [
        'business_slug' => 'laladia',
        'store_slug' => 'laladia-com',
        'erp_store_key' => 'laladia',
        'destination' => ['name' => 'Rahim Uddin', 'phone' => '+880 1711-000001', 'district' => 'Bagerhat', 'thana' => 'Fakirhat', 'address_line' => 'House 4, Road 2'],
        'lines' => [['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.00'], ['sku' => 'GHE-250', 'quantity' => 1, 'unit_price' => '650.50']],
        'payment_method' => 'cod',
        'proposed' => ['goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50'],
    ];
});

function takeOrder(User $user, array $args): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, TAKE_ORDER, $args));
}

function admitLaladia(): void
{
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
}

function laladiaId(): int
{
    return (int) Business::where('slug', 'laladia')->value('id');
}

// ── the capability as registered ───────────────────────────────────────────────────────────────

it('is registered as the settled design says: R4, approval-required, the requester never decides, no agent, no store', function () {
    $tool = app(ToolRegistry::class)->get(TAKE_ORDER);
    $d = $tool->descriptor();

    expect($tool)->toBeInstanceOf(TakeOrderTool::class)
        ->and($d->riskTier)->toBe(RiskTier::R4)
        ->and($d->riskTier->baseline())->toBe(ActionClass::ApprovalRequired)
        ->and($d->readWrite)->toBe(ReadWrite::Write)
        ->and($d->permission)->toBe('hq.tools.commerce.write')
        ->and($d->agentExecutable)->toBeFalse()
        ->and($d->permanentlyHumanOnly)->toBeFalse()
        ->and($d->requesterMayDecide)->toBeFalse()
        ->and($d->supportedStores)->toBe([])
        ->and($d->policyClass)->toBe(TakeOrderPolicy::class)
        ->and($d->customerFacing)->toBeTrue()
        ->and($d->channel)->toBe(Channel::Internal)
        ->and($d->consentRequired)->toBeNull()
        ->and($d->monetaryImpact)->toBe(MonetaryImpact::High)
        ->and($d->reversibility)->toBe(Reversibility::Compensable)
        ->and($d->compensatingTool)->toBe('commerce.cancel_sales_order')
        ->and(app(ToolRegistry::class)->has('commerce.cancel_sales_order'))->toBeTrue('the compensating tool is now built and registered')
        ->and($d->dataClass)->toBe(DataClass::Personal)
        ->and($d->idempotent)->toBeTrue()
        ->and($d->replayWithinSeconds)->toBe(0)
        ->and($d->redact)->toBe([])
        ->and($d->credentials[0]->provider)->toBe(ErpRegistry::PROVIDER)
        ->and($d->toArray()['requester_may_decide'])->toBeFalse()
        ->and(config('hq.orders.take_order_stores'))->toBe([]);
});

it('is refused for every caller in production\'s state, before any approval and before the ERP', function () {
    Http::fake();

    $outcome = takeOrder($this->owner, $this->order);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('does not support store [laladia-com]')
        ->and(Approval::count())->toBe(0);
    Http::assertNothingSent();
});

it('gives it to no agent, and an agent naming it runs nothing', function () {
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    admitLaladia();
    Http::fake();

    foreach (Agent::all() as $agent) {
        expect($agent->allowed_tools ?? [])->not->toContain(TAKE_ORDER);
        $toolset = app(AgentToolset::class);
        expect($toolset->keysFor(Contexts::for($agent)))->not->toContain(TAKE_ORDER);
    }

    $executive = Agent::where('key', 'executive')->sole();
    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: TAKE_ORDER, args: $this->order, caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($executive)), correlationId: (string) Str::uuid(),
    ));

    expect($outcome->ok())->toBeFalse()
        ->and(Approval::count())->toBe(0);
    Http::assertNothingSent();
});

// ── approval: always, never by the one who asked ───────────────────────────────────────────────

it('sends even an owner to approval with a store admitted, and parks exactly the proposal', function () {
    admitLaladia();
    Http::fake();

    $outcome = takeOrder($this->owner, $this->order);

    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval);
    $approval = Approval::findOrFail($outcome->approvalId);
    expect($approval->tool_key)->toBe(TAKE_ORDER)
        ->and($approval->risk_tier)->toBe(RiskTier::R4)
        ->and($approval->payload_hash)->toBe(CanonicalPayload::hash($this->order))
        ->and((int) $approval->requested_by_user_id)->toBe($this->owner->id)
        ->and($approval->preview['result']['after_the_order'])->toContain('approval inbox')
        ->and($approval->preview['result']['after_the_order'])->toContain('HQ approves nothing');
    Http::assertNothingSent();
});

it('refuses a malformed order, or a store of another business, before an approval is ever parked', function () {
    admitLaladia();
    Http::fake();

    $bad = $this->order;
    $bad['destination']['phone'] = '12345';
    $bad['lines'][] = ['sku' => 'HON-500', 'quantity' => 1, 'unit_price' => '850.00'];
    $bad['proposed']['agreed_total'] = 'about 2500';
    $outcome = takeOrder($this->owner, $bad);
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('[destination.phone]')
        ->and($outcome->error)->toContain('appears twice')
        ->and($outcome->error)->toContain('[proposed.agreed_total]');

    $foreign = $this->order;
    $foreign['business_slug'] = 'bor-de-guna';
    $outcome = takeOrder($this->owner, $foreign);
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('belongs to another business');

    $quantity = $this->order;
    $quantity['lines'][0]['quantity'] = 100;
    expect(takeOrder($this->owner, $quantity)->status)->toBe(OutcomeStatus::Denied);

    expect(Approval::count())->toBe(0);
    Http::assertNothingSent();
});

it('never lets the person who asked decide, at the executor or by rejecting, owner included', function () {
    admitLaladia();
    $erp = SimulatedErp::fake();
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->owner);
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('somebody other than the person who asked')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($erp['posts'])->toBe(0)
        ->and($erp['orderPosts'])->toBe(0)
        ->and($approval->fresh()->decisionRefusalReason($this->owner))->toContain('somebody other than the person who asked')
        ->and($approval->fresh()->decisionRefusalReason($this->approver))->toBeNull();

    expect(fn () => app(ApprovalService::class)->reject($approval->fresh(), $this->owner, 'no'))->toThrow(Exception::class);
    expect($approval->fresh()->status)->toBe(ApprovalStatus::Pending);
});

it('changes nothing for a tool that lets its requester decide', function () {
    $approval = new Approval(['tool_key' => 'demo.request_approval', 'requested_by_user_id' => $this->owner->id]);
    expect($approval->separationOfDutiesReason($this->owner))->toBeNull();

    $take = new Approval(['tool_key' => TAKE_ORDER, 'requested_by_user_id' => $this->owner->id]);
    expect($take->separationOfDutiesReason($this->owner))->not->toBeNull()
        ->and($take->separationOfDutiesReason($this->approver))->toBeNull();
});

// ── ADR-031 against a simulated ERP ────────────────────────────────────────────────────────────

it('writes the draft and then the order when another person approves, and calls it succeeded only after reading both back', function () {
    admitLaladia();
    $erp = SimulatedErp::fake();
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);

    $draftKey = TakeOrderTool::erpKey("approval:{$approval->uuid}", laladiaId());
    $orderKey = ErpOperationKey::for("approval:{$approval->uuid}", laladiaId(), TakeOrderTool::ORDER_STEP);
    expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
        ->and($erp['posts'])->toBe(1)
        ->and($erp['orderPosts'])->toBe(1)
        ->and($erp['gets'])->toBe(2, 'the draft is read back after its write and again beside the order')
        ->and($erp['orderGets'])->toBe(1, 'the order is read back by HQ\'s key')
        ->and($outcome->output)->toMatchArray([
            'business' => 'laladia', 'source' => 'erp', 'contract' => 'hq-sales-orders.v1', 'status' => 'open', 'approval_status' => 'pending',
            'deliverable' => false, 'store_key' => 'laladia', 'goods_total' => '2350.50', 'agreed_total' => '2470.50', 'verified' => true, 'replayed' => false,
            'proposal_fingerprint' => $approval->payload_hash, 'erp_draft_key' => $draftKey, 'erp_key' => $orderKey, 'order_number' => 'SO-2026-27-0001',
        ])
        ->and($outcome->output['effects'])->toMatchArray(['sales_order_created' => true, 'approval_requested' => true, 'approved_by_hq' => false,
            'stock_moved' => false, 'journal_posted' => false, 'payment_taken' => false, 'courier_booked' => false])
        ->and($outcome->output['next_step'])->toContain('approval inbox')
        ->and($outcome->providerRef)->toBe('erp-order:1')
        ->and(count($erp['drafts']))->toBe(1)
        ->and(count($erp['orders']))->toBe(1);

    // The ERP was asked for the draft under a key derived from the approval, with the approval's own
    // fingerprint; and for the order under its own key, naming that draft and that approval.
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sales-order-drafts')
        && $r['idempotency_key'] === $draftKey
        && $r['hq_reference']['proposal_fingerprint'] === $approval->payload_hash);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sales-orders')
        && $r['idempotency_key'] === $orderKey
        && $r['draft_key'] === $draftKey
        && $r['proposed'] == $this->order['proposed']
        && $r['hq_reference']['proposal_fingerprint'] === $approval->payload_hash
        && $r['hq_reference']['approval_uuid'] === $approval->uuid);

    // ⛔ No name, phone or address in what HQ stores about the result.
    $stored = json_encode(ToolCall::findOrFail($outcome->toolCallId)->output);
    expect($stored)->not->toContain('Rahim')->not->toContain('1711')->not->toContain('House 4');
});

it('replays the same key as the same order, and treats the same key for another order as a refusal', function () {
    $erp = SimulatedErp::fake();
    $tool = app(TakeOrderTool::class);
    $context = new ToolContext(idempotencyKey: 'approval:fixed-uuid', correlationId: 'corr-take-order-1', businessId: laladiaId());
    $run = fn (array $order) => app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'laladia']], fn () => $tool->execute($order, $context));

    $one = $run($this->order);
    $two = $run($this->order);

    expect($one->status)->toBe(ToolResult::STATUS_SUCCEEDED)
        ->and($two->status)->toBe(ToolResult::STATUS_SUCCEEDED)
        ->and($two->output['erp_order_id'])->toBe($one->output['erp_order_id'])
        ->and($two->output['erp_draft_id'])->toBe($one->output['erp_draft_id'])
        ->and($one->output['replayed'])->toBeFalse()
        ->and($two->output['replayed'])->toBeTrue()
        ->and(count($erp['drafts']))->toBe(1)
        ->and(count($erp['orders']))->toBe(1);

    // The same key, another order: the ERP refuses the draft and HQ reports it; no second draft, no second order.
    $other = $this->order;
    $other['lines'][0]['quantity'] = 3;
    $other['proposed'] = ['goods_total' => '3200.50', 'delivery_charge' => '120.00', 'agreed_total' => '3320.50'];
    $refused = $run($other);
    expect($refused->status)->toBe(ToolResult::STATUS_FAILED)
        ->and($refused->error)->toContain('already used for a different draft')
        ->and(count($erp['drafts']))->toBe(1)
        ->and(count($erp['orders']))->toBe(1);
});

it('treats a token without a write ability as an answer about readiness, not a refusal by HQ', function () {
    Http::fake([SimulatedErp::BASE.'/*' => Http::response(['error' => ['code' => 'forbidden', 'message' => 'Token lacks ability sales.orders.draft.']], 403)]);
    $tool = app(TakeOrderTool::class);
    $context = new ToolContext(idempotencyKey: 'approval:no-ability', correlationId: 'corr-take-order-2', businessId: laladiaId());

    $result = app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'laladia']], fn () => $tool->execute($this->order, $context));

    expect($result->status)->toBe(ToolResult::STATUS_FAILED)
        ->and($result->error)->toContain('sales.orders.draft');
});

it('reports a moved price with the ERP\'s sentence at the draft, and never sends it again', function () {
    admitLaladia();
    $erp = SimulatedErp::fake(['mismatch' => true]);
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);

    expect($outcome->status)->toBe(OutcomeStatus::Failed)
        ->and($outcome->error)->toContain('no longer match')
        ->and($erp['posts'])->toBe(1)
        ->and($erp['gets'])->toBe(0)
        ->and($erp['orderPosts'])->toBe(0);
});

it('reports the ERP pricing the draft differently at the order, leaves the draft, and never rewrites it', function () {
    admitLaladia();
    $erp = SimulatedErp::fake(['draftRepriced' => true]);
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);

    expect($outcome->status)->toBe(OutcomeStatus::Failed)
        ->and($outcome->error)->toContain('no longer match')
        ->and($erp['posts'])->toBe(1)
        ->and($erp['orderPosts'])->toBe(1)
        ->and(count($erp['drafts']))->toBe(1)
        ->and(count($erp['orders']))->toBe(0);
});

it('calls a draft write whose POST never answered uncertain, and settles it by reading back and finishing the order', function () {
    admitLaladia();
    $erp = SimulatedErp::fake(['postTimesOut' => true]);
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $first = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);
    expect($first->status)->toBe(OutcomeStatus::Uncertain)
        ->and(count($erp['drafts']))->toBe(1)
        ->and(count($erp['orders']))->toBe(0);

    // The same operation again: the gateway reconciles before anything is sent. The tool reads the
    // draft back by key, finds it agrees and finds no order under the order key, so the gateway sends
    // the operation again: the draft REPLAYS under its key (no second draft), and the order is made.
    $again = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: TAKE_ORDER, args: $approval->payload, caller: CallerKind::HumanUi, identity: ActingIdentity::human($this->approver),
        correlationId: (string) $approval->correlation_id, businessId: $approval->business_id, storeId: $approval->store_id,
        idempotencyKey: "approval:{$approval->uuid}",
    ), $approval->fresh());

    expect($again->status)->toBe(OutcomeStatus::Succeeded)
        ->and($again->output['verified'])->toBeTrue()
        ->and($again->output['status'])->toBe('open')
        ->and(count($erp['drafts']))->toBe(1)
        ->and(count($erp['orders']))->toBe(1);
});

it('calls an order write whose POST never answered uncertain, and settles it by reading the order back under its key', function () {
    admitLaladia();
    $erp = SimulatedErp::fake(['orderPostTimesOut' => true]);
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $first = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);
    expect($first->status)->toBe(OutcomeStatus::Uncertain)
        ->and(count($erp['drafts']))->toBe(1)
        ->and(count($erp['orders']))->toBe(1, 'the ERP wrote the order before the answer was lost');

    $again = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: TAKE_ORDER, args: $approval->payload, caller: CallerKind::HumanUi, identity: ActingIdentity::human($this->approver),
        correlationId: (string) $approval->correlation_id, businessId: $approval->business_id, storeId: $approval->store_id,
        idempotencyKey: "approval:{$approval->uuid}",
    ), $approval->fresh());

    // Read back: the draft agrees, the order under HQ's key agrees. Nothing was POSTed again.
    expect($again->status)->toBe(OutcomeStatus::Succeeded)
        ->and($again->output['verified'])->toBeTrue()
        ->and($again->output['order_number'])->toBe('SO-2026-27-0001')
        ->and($erp['posts'])->toBe(1)
        ->and($erp['orderPosts'])->toBe(1);
});

it('never calls a draft it could not read back succeeded, and writes no order from it', function () {
    admitLaladia();
    $erp = SimulatedErp::fake(['readBackFails' => true]);
    $failed = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId), $this->approver);
    expect($failed->status)->toBe(OutcomeStatus::Uncertain)
        ->and($failed->error)->toContain('not verified')
        ->and($erp['orderPosts'])->toBe(0);
});

it('never calls a draft that disagrees with its proposal succeeded, names what disagrees, and writes no order from it', function () {
    admitLaladia();
    $erp = SimulatedErp::fake(['readBackTampered' => true]);
    $tampered = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId), $this->approver);
    expect($tampered->status)->toBe(OutcomeStatus::Uncertain)
        ->and($tampered->error)->toContain('lines.HON-500.quantity')
        ->and($tampered->error)->toContain('nothing was changed')
        ->and($erp['orderPosts'])->toBe(0);
});

it('never calls an order that disagrees with the approved draft succeeded, and names what disagrees', function () {
    admitLaladia();
    SimulatedErp::fake(['orderReadBackTampered' => true]);
    $tampered = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId), $this->approver);
    expect($tampered->status)->toBe(OutcomeStatus::Uncertain)
        ->and($tampered->error)->toContain('does not agree with the approved draft')
        ->and($tampered->error)->toContain('lines.HON-500.quantity')
        ->and($tampered->providerRef)->toBe('erp-order:1');
});

it('refuses an approval whose stored payload was altered after it was given', function () {
    admitLaladia();
    $erp = SimulatedErp::fake();
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);
    $altered = $approval->payload;
    $altered['lines'][0]['quantity'] = 9;
    Approval::whereKey($approval->id)->update(['payload' => json_encode($altered)]);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $this->approver);

    expect($outcome->ok())->toBeFalse()
        ->and($erp['posts'])->toBe(0)
        ->and($erp['orderPosts'])->toBe(0);
});

// ── the reconciliation checks ──────────────────────────────────────────────────────────────────

it('reports every disagreement between proposal, approval and ERP draft, and repairs nothing', function () {
    $check = app(DraftReconciliation::class);
    $fingerprint = CanonicalPayload::hash($this->order);
    $draft = [
        'status' => 'draft', 'hq_proposal_fingerprint' => $fingerprint, 'store' => ['key' => 'laladia'], 'payment_method' => 'cod',
        'recipient' => ['phone_normalized' => '01711000001'], 'destination' => ['district' => 'Bagerhat', 'thana' => 'Fakirhat'],
        'lines' => [['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.0000'], ['sku' => 'GHE-250', 'quantity' => 1, 'unit_price' => '650.5000']],
        'goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50',
    ];
    $before = $draft;

    expect($check->compare($this->order, $draft, $fingerprint))->toBe([]);

    $bad = $draft;
    $bad['status'] = 'open';
    $bad['hq_proposal_fingerprint'] = str_repeat('0', 64);
    $bad['store']['key'] = 'bionic';
    $bad['recipient']['phone_normalized'] = '01811000000';
    $bad['destination']['thana'] = 'Mongla';
    $bad['lines'][0]['unit_price'] = '800.0000';
    $bad['lines'][1] = ['sku' => 'OIL-1L', 'quantity' => 1, 'unit_price' => '300.0000'];
    $bad['agreed_total'] = '2400.00';

    $fields = array_column($check->compare($this->order, $bad, str_repeat('a', 64)), 'field');
    expect($fields)->toBe(['approval', 'hq_proposal_fingerprint', 'status', 'store', 'destination.phone', 'destination.thana',
        'lines.HON-500.unit_price', 'lines.GHE-250', 'lines.OIL-1L', 'agreed_total'])
        ->and($draft)->toBe($before);
});

it('reports every disagreement between the approved draft and the ERP order, including an order that does not wait, and repairs nothing', function () {
    $check = app(OrderReconciliation::class);
    $fingerprint = CanonicalPayload::hash($this->order);
    $order = json_decode((string) file_get_contents(base_path('tests/fixtures/erp/sales-orders/created.json')), true)['data'];
    $order['hq'] = ['draft_key' => 'hq:draft-1', 'idempotency_key' => 'hq:order-1', 'proposal_fingerprint' => $fingerprint, 'approval_uuid' => null];
    $before = $order;

    expect($check->compare($this->order, $order, 'hq:draft-1', $fingerprint))->toBe([]);

    $bad = $order;
    $bad['status'] = 'delivered';
    $bad['approval_status'] = 'approved';
    $bad['deliverable'] = true;
    $bad['hq']['draft_key'] = 'hq:draft-9';
    $bad['hq']['proposal_fingerprint'] = str_repeat('0', 64);
    $bad['store']['key'] = 'bionic';
    $bad['lines'][0]['quantity'] = 3;
    $bad['lines'][1] = ['sku' => 'OIL-1L', 'quantity' => 1, 'unit_price' => '300.0000'];
    $bad['goods_total'] = '3200.50';
    $bad['effects']['approved_by_hq'] = true;
    $bad['effects']['stock_moved'] = true;

    $fields = array_column($check->compare($this->order, $bad, 'hq:draft-1', $fingerprint), 'field');
    expect($fields)->toBe(['status', 'approval_status', 'deliverable', 'hq.draft_key', 'hq.proposal_fingerprint', 'store',
        'lines.HON-500.quantity', 'lines.GHE-250', 'lines.OIL-1L', 'goods_total', 'effects.approved_by_hq', 'effects.stock_moved'])
        ->and($order)->toBe($before);
});

// ── no other door ──────────────────────────────────────────────────────────────────────────────

it('is reached only through the gateway: no controller, command, job or other class names the tool', function () {
    $mentions = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), 'TakeOrderTool'))
        ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))->values()->all();

    expect($mentions)->toBe(['Tools/Commerce/TakeOrderTool.php'])
        ->and((string) file_get_contents(config_path('hq.php')))->toContain('TakeOrderTool::class');
});
