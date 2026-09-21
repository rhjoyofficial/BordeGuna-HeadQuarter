<?php

use App\Agents\AgentToolset;
use App\Commerce\Orders\DraftReconciliation;
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
use App\Identity\PhoneCanonical;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Execution\Contexts;

/*
 | `commerce.take_order`, the Phase 4 draft write, built INERT (docs/83 C.3, docs/79, ADR-046).
 |
 | What must hold: the capability exists and is refused for every caller in production's state; with
 | a store admitted (only in these tests), a person is sent to approval and never allowed, the person
 | who asked never decides, the approval runs exactly its stored payload, and the effect is read back
 | from a simulated ERP before anything is called `succeeded` (ADR-031). No agent holds it.
 */

const TAKE_ORDER = 'commerce.take_order';

const TAKE_ORDER_ERP = 'https://erp.take-order.test';

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

    config(['hq.erp.base_url' => TAKE_ORDER_ERP]);
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

/**
 * A simulated ERP with the real endpoint's behaviour (the ERP's own test proves the endpoint): one
 * draft per key, a replay for the same request, 409 for another, and the read-back by key. Bodies are
 * the ERP's `created` fixture with this request's values put in.
 *
 * @param  array{mismatch?: bool, postTimesOut?: bool, readBackFails?: bool, readBackTampered?: bool}  $opts
 */
function simulatedErp(array $opts = []): ArrayObject
{
    $state = new ArrayObject(['drafts' => [], 'posts' => 0, 'gets' => 0]);
    $template = json_decode((string) file_get_contents(base_path('tests/fixtures/erp/sales-order-drafts/created.json')), true);

    Http::fake(function (Request $r) use ($state, $template, $opts) {
        $base = TAKE_ORDER_ERP.'/api/hq/v1/1/sales-order-drafts';
        if ($r->method() === 'POST' && $r->url() === $base) {
            $state['posts']++;
            $body = $r->data();
            $key = $body['idempotency_key'];
            $digest = hash('sha256', CanonicalPayload::encode($body));
            $drafts = $state['drafts'];
            if (isset($drafts[$key])) {
                return $drafts[$key]['digest'] === $digest
                    ? Http::response(['data' => ['replayed' => true] + $drafts[$key]['data'], 'meta' => ['business_id' => 1, 'contract' => 'hq-sales-order-drafts.v1']], 200)
                    : Http::response(['error' => ['code' => 'idempotency_key_reused', 'message' => 'This idempotency key was already used for a different draft. Nothing was written.']], 409);
            }
            if ($opts['mismatch'] ?? false) {
                return Http::response(['error' => ['code' => 'quote_mismatch', 'message' => 'Figures in the proposal no longer match the ERP\'s own. Nothing was written; quote again.'],
                    'errors' => ['lines.0.unit_price' => ['Proposed 850.00, the ERP\'s price is 900.00.']]], 422);
            }
            $data = $template['data'];
            $data['id'] = count($drafts) + 1;
            $data['idempotency_key'] = $key;
            $data['hq_proposal_fingerprint'] = $body['hq_reference']['proposal_fingerprint'];
            $data['hq_reference'] = ['proposal_fingerprint' => $body['hq_reference']['proposal_fingerprint'], 'approval_uuid' => null, 'tool_call_id' => null, 'conversation_uuid' => $body['hq_reference']['conversation_uuid'] ?? null];
            $data['store'] = ['key' => $body['store_key']];
            $data['recipient']['phone_normalized'] = PhoneCanonical::normalize($body['destination']['phone']);
            $data['destination']['district'] = $body['destination']['district'];
            $data['destination']['thana'] = $body['destination']['thana'];
            $data['lines'] = array_map(fn ($l) => ['sku' => $l['sku'], 'product_id' => 1, 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'].'00',
                'line_total' => number_format($l['quantity'] * (float) $l['unit_price'], 2, '.', ''), 'price_note' => 'Price for group [RETAIL]', 'made_to_order' => false], $body['lines']);
            $data['goods_total'] = $body['proposed']['goods_total'];
            $data['delivery_charge'] = $body['proposed']['delivery_charge'];
            $data['agreed_total'] = $body['proposed']['agreed_total'];
            unset($data['replayed']);
            $drafts[$key] = ['digest' => $digest, 'data' => $data];
            $state['drafts'] = $drafts;

            if (($opts['postTimesOut'] ?? false) && $state['posts'] === 1) {
                throw new ConnectionException('timed out after the ERP wrote it');
            }

            return Http::response(['data' => $data + ['replayed' => false], 'meta' => ['business_id' => 1, 'contract' => 'hq-sales-order-drafts.v1']], 201);
        }

        if ($r->method() === 'GET' && str_starts_with($r->url(), $base.'/')) {
            $state['gets']++;
            if ($opts['readBackFails'] ?? false) {
                throw new ConnectionException('read-back timed out');
            }
            $key = rawurldecode(substr($r->url(), strlen($base) + 1));
            $found = $state['drafts'][$key] ?? null;
            if ($found === null) {
                return Http::response(['error' => ['code' => 'not_found', 'message' => 'No draft with that key in this business.']], 404);
            }
            $data = $found['data'];
            if ($opts['readBackTampered'] ?? false) {
                $data['lines'][0]['quantity'] = 3;
            }

            return Http::response(['data' => $data, 'meta' => ['business_id' => 1, 'contract' => 'hq-sales-order-drafts.v1']]);
        }

        return Http::response(['error' => ['code' => 'unexpected', 'message' => 'not simulated']], 500);
    });

    return $state;
}

function takeOrder(User $user, array $args): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, TAKE_ORDER, $args));
}

function admitLaladia(): void
{
    config(['hq.orders.take_order_stores' => ['laladia-com']]);
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
        ->and($approval->preview['result']['after_the_draft'])->toContain('nothing');
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
    $erp = simulatedErp();
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->owner);
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->error)->toContain('somebody other than the person who asked')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and($erp['posts'])->toBe(0)
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

it('writes the draft when another person approves, and calls it succeeded only after reading it back', function () {
    admitLaladia();
    $erp = simulatedErp();
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);

    expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
        ->and($erp['posts'])->toBe(1)
        ->and($erp['gets'])->toBe(1)
        ->and($outcome->output)->toMatchArray([
            'business' => 'laladia', 'source' => 'erp', 'contract' => 'hq-sales-order-drafts.v1', 'status' => 'draft',
            'store_key' => 'laladia', 'goods_total' => '2350.50', 'agreed_total' => '2470.50', 'verified' => true, 'replayed' => false,
            'proposal_fingerprint' => $approval->payload_hash,
        ])
        ->and($outcome->output['effects']['sales_order_created'])->toBeFalse()
        ->and($outcome->providerRef)->toBe('erp-draft:1');

    // The ERP was asked under a key derived from the approval, with the approval's own fingerprint.
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r['idempotency_key'] === TakeOrderTool::erpKey("approval:{$approval->uuid}", Business::where('slug', 'laladia')->value('id'))
        && $r['hq_reference']['proposal_fingerprint'] === $approval->payload_hash);

    // ⛔ No name, phone or address in what HQ stores about the result.
    $stored = json_encode(ToolCall::findOrFail($outcome->toolCallId)->output);
    expect($stored)->not->toContain('Rahim')->not->toContain('1711')->not->toContain('House 4');
});

it('replays the same key as the same draft, and treats the same key for another order as a refusal', function () {
    $erp = simulatedErp();
    $tool = app(TakeOrderTool::class);
    $business = Business::where('slug', 'laladia')->sole();
    $context = new ToolContext(idempotencyKey: 'approval:fixed-uuid', correlationId: 'corr-take-order-1', businessId: $business->id);
    $run = fn (array $order) => app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'laladia']], fn () => $tool->execute($order, $context));

    $one = $run($this->order);
    $two = $run($this->order);

    expect($one->status)->toBe(ToolResult::STATUS_SUCCEEDED)
        ->and($two->status)->toBe(ToolResult::STATUS_SUCCEEDED)
        ->and($two->output['erp_draft_id'])->toBe($one->output['erp_draft_id'])
        ->and($one->output['replayed'])->toBeFalse()
        ->and($two->output['replayed'])->toBeTrue()
        ->and(count($erp['drafts']))->toBe(1);

    // The same key, another order: the ERP refuses it and HQ reports it, never a second draft.
    $other = $this->order;
    $other['lines'][0]['quantity'] = 3;
    $other['proposed'] = ['goods_total' => '3200.50', 'delivery_charge' => '120.00', 'agreed_total' => '3320.50'];
    $refused = $run($other);
    expect($refused->status)->toBe(ToolResult::STATUS_FAILED)
        ->and($refused->error)->toContain('already used for a different draft')
        ->and(count($erp['drafts']))->toBe(1);
});

it('treats a token without the draft ability as an answer about readiness, not a refusal by HQ', function () {
    Http::fake([TAKE_ORDER_ERP.'/*' => Http::response(['error' => ['code' => 'forbidden', 'message' => 'Token lacks ability sales.orders.draft.']], 403)]);
    $tool = app(TakeOrderTool::class);
    $business = Business::where('slug', 'laladia')->sole();
    $context = new ToolContext(idempotencyKey: 'approval:no-ability', correlationId: 'corr-take-order-2', businessId: $business->id);

    $result = app(CredentialVault::class)->openForAccounts([[ErpRegistry::PROVIDER, 'laladia']], fn () => $tool->execute($this->order, $context));

    expect($result->status)->toBe(ToolResult::STATUS_FAILED)
        ->and($result->error)->toContain('sales.orders.draft');
});

it('reports a moved price with the ERP\'s sentence, and never sends it again', function () {
    admitLaladia();
    $erp = simulatedErp(['mismatch' => true]);
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);

    expect($outcome->status)->toBe(OutcomeStatus::Failed)
        ->and($outcome->error)->toContain('no longer match')
        ->and($erp['posts'])->toBe(1)
        ->and($erp['gets'])->toBe(0);
});

it('calls a write whose POST never answered uncertain, and settles it by reading the draft back', function () {
    admitLaladia();
    $erp = simulatedErp(['postTimesOut' => true]);
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);

    $first = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);
    expect($first->status)->toBe(OutcomeStatus::Uncertain)
        ->and(count($erp['drafts']))->toBe(1);

    // The same operation again: the gateway reconciles before anything is sent. The tool reads the
    // draft back by key, finds it agrees with what the uncertain call executed, and nothing is POSTed.
    $again = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: TAKE_ORDER, args: $approval->payload, caller: CallerKind::HumanUi, identity: ActingIdentity::human($this->approver),
        correlationId: (string) $approval->correlation_id, businessId: $approval->business_id, storeId: $approval->store_id,
        idempotencyKey: "approval:{$approval->uuid}",
    ), $approval->fresh());

    expect($again->status)->toBe(OutcomeStatus::Succeeded)
        ->and($again->output['verified'])->toBeTrue()
        ->and($erp['posts'])->toBe(1);
});

it('never calls a draft it could not read back succeeded', function () {
    admitLaladia();
    simulatedErp(['readBackFails' => true]);
    $failed = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId), $this->approver);
    expect($failed->status)->toBe(OutcomeStatus::Uncertain)
        ->and($failed->error)->toContain('not verified');
});

it('never calls a draft that disagrees with its proposal succeeded, and names what disagrees', function () {
    admitLaladia();
    simulatedErp(['readBackTampered' => true]);
    $tampered = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId), $this->approver);
    expect($tampered->status)->toBe(OutcomeStatus::Uncertain)
        ->and($tampered->error)->toContain('lines.HON-500.quantity')
        ->and($tampered->error)->toContain('nothing was changed');
});

it('refuses an approval whose stored payload was altered after it was given', function () {
    admitLaladia();
    $erp = simulatedErp();
    $approval = Approval::findOrFail(takeOrder($this->owner, $this->order)->approvalId);
    $altered = $approval->payload;
    $altered['lines'][0]['quantity'] = 9;
    Approval::whereKey($approval->id)->update(['payload' => json_encode($altered)]);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval->fresh(), $this->approver);

    expect($outcome->ok())->toBeFalse()
        ->and($erp['posts'])->toBe(0);
});

// ── the reconciliation check ───────────────────────────────────────────────────────────────────

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

// ── no other door ──────────────────────────────────────────────────────────────────────────────

it('is reached only through the gateway: no controller, command, job or other class names the tool', function () {
    $mentions = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), 'TakeOrderTool'))
        ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))->values()->all();

    expect($mentions)->toBe(['Tools/Commerce/TakeOrderTool.php'])
        ->and((string) file_get_contents(config_path('hq.php')))->toContain('TakeOrderTool::class');
});
