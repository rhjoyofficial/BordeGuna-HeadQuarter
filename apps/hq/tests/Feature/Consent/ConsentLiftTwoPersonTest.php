<?php

use App\Consent\ConsentOperations;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentRefusal;
use App\Consent\ConsentState;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ConsentEvent;
use App\Models\ConsentProjection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\PlatformAccount;
use App\Models\ToolCall;
use App\Models\User;
use App\Tools\Consent\LiftRestrictionTool;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Support\Tools\MarketingMessageTool;

/*
 | docs/114 §3 (S7 follow-up, ruling (k)): lifting a DO-NOT-CONTACT is a two-person act. One person with
 | `hq.consent.lift_restriction` asks (the gateway parks it as an approval), a DIFFERENT person holding the same
 | permission approves it in the Approval Centre, and only then is the `lifted` event written, naming both. Until
 | then, and whenever any part of that is missing, the do-not-contact stays in force: fail closed.
 |
 | Also here, ruling (j): support_agent holds view + restrict, never grant, never lift_restriction.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'unknown', 'is_active' => true],
    );
    $this->requester = $this->hqUser('owner');
    $this->approver = $this->hqUser('operator');
    $this->artisan('hq:tools:sync')->assertSuccessful();
    MarketingMessageTool::$executions = 0;
    app(ToolRegistry::class)->register(MarketingMessageTool::class);
    app(ToolAvailability::class)->forget();
    $this->lld = Brand::where('code', 'LLD')->sole();
});

/** A Messenger thread on LaLaDia's page; the customer's message grants Service on messenger for the window. */
function tpThread(string $psid = 'psid-tp'): Conversation
{
    $conversation = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => 'অর্ডার কোথায়?']]]) as $inbound) {
        $conversation = app(ConversationService::class)->ingest($inbound)->conversation;
    }

    return $conversation->fresh();
}

function tpReply(User $user, Conversation $conversation): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator(
        $user, 'messaging.send_reply',
        ['business_slug' => 'laladia', 'conversation_uuid' => $conversation->uuid, 'text' => 'আগামীকাল পৌঁছাবে। '.Str::random(6)],
        $conversation->business_id,
    ));
}

function tpOperator(User $user, Customer $customer, string $act, array $input = []): ConsentEvent
{
    return app(ConsentOperations::class)->record($user, $customer, $act, $input + ['evidence_ref' => 'call 10 Oct', 'reason' => 'the customer said so']);
}

/** Asks the gateway, as $user, to lift what stands at the scope: the same call the consent screen makes. */
function tpRequestLift(User $user, Customer $customer, array $scope = [], string $evidence = 'call log #91', string $reason = 'the customer called and asked to hear from us again'): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator(
        $user, LiftRestrictionTool::KEY,
        array_filter(['business_slug' => 'laladia', 'customer_id' => $customer->id, 'evidence_ref' => $evidence, 'reason' => $reason] + $scope, fn ($v) => $v !== null),
        $customer->business_id,
    ));
}

function tpExpectDnc(ToolOutcome $outcome): void
{
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->class)->toBe(OutcomeClass::ConsentDenied)
        ->and((string) $outcome->error)->toContain('[consent_do_not_contact]');
}

function tpExpectPassedConsent(ToolOutcome $outcome): void
{
    expect($outcome->class)->not->toBe(OutcomeClass::ConsentDenied)
        ->and((string) $outcome->error)->toContain('No messaging provider is registered');
}

it('refuses to lift a do-not-contact single-person: the operator act throws, writes nothing, and the send stays refused', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    tpOperator($this->requester, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    $events = ConsentEvent::count();

    expect(fn () => tpOperator($this->requester, $customer, ConsentOperations::ACT_LIFT))
        ->toThrow(InvalidArgumentException::class, 'two-person lift');

    expect(ConsentEvent::count())->toBe($events)
        ->and(ConsentEvent::where('state', 'lifted')->exists())->toBeFalse();
    tpExpectDnc(tpReply($this->requester, $conversation));
});

it('parks the lift for a second person, keeps the do-not-contact in force meanwhile, and never lets the requester decide it', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    tpOperator($this->requester, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);

    $outcome = tpRequestLift($this->requester, $customer);

    expect($outcome->status)->toBe(OutcomeStatus::AwaitingApproval)
        ->and($outcome->class)->toBe(OutcomeClass::ApprovalRequired)
        ->and($outcome->policyReason)->toContain('second, independent person');
    $approval = Approval::findOrFail($outcome->approvalId);
    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->tool_key)->toBe('consent.lift_restriction')
        ->and((int) $approval->requested_by_user_id)->toBe($this->requester->id)
        ->and($approval->payload['customer_id'])->toBe($customer->id)
        ->and($approval->preview['result']['would_write'])->toContain('a lifted event')
        ->and($approval->evidence['policy'])->toBe('consent.two_person_lift');

    // Nothing lifted: the ledger holds no `lifted`, the projection still says do-not-contact, a send is refused.
    expect(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->exists())->toBeFalse()
        ->and(ConsentProjection::where('customer_id', $customer->id)->whereNull('channel')->whereNull('purpose')->whereNull('brand_id')->sole()->state)->toBe(ConsentState::DoNotContact);
    tpExpectDnc(tpReply($this->requester, $conversation));

    // The requester (an owner) is barred from deciding, on the one rule every door reads and through the executor.
    expect($approval->decisionRefusalReason($this->requester))->toContain('somebody other than the person who asked');
    $self = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->requester);
    expect($self->status)->toBe(OutcomeStatus::Denied)
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending)
        ->and(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->exists())->toBeFalse();
    tpExpectDnc(tpReply($this->requester, $conversation));
});

it('lifts once a second holder of the permission approves: the lifted event names both people, is audited, and the send passes consent again', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    $dnc = tpOperator($this->requester, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    $approval = Approval::findOrFail(tpRequestLift($this->requester, $customer)->approvalId);

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver, 'spoke to the customer myself');

    expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Consumed);
    $lifted = ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->sole();
    expect($lifted->basis->value)->toBe('operator')
        ->and($lifted->purpose)->toBeNull()->and($lifted->channel)->toBeNull()->and($lifted->brand_id)->toBeNull()
        ->and((int) $lifted->actor_id)->toBe($this->approver->id)
        ->and($lifted->evidence_ref)->toBe('call log #91')
        ->and($lifted->evidence['act'])->toBe('lift_do_not_contact')
        ->and($lifted->evidence['approval_uuid'])->toBe($approval->uuid)
        ->and($lifted->evidence['lifted_event_id'])->toBe($dnc->id)
        ->and($lifted->evidence['requested_by_user_id'])->toBe($this->requester->id)
        ->and($lifted->evidence['approved_by_user_id'])->toBe($this->approver->id)
        ->and($outcome->output['consent_event_id'])->toBe($lifted->id);

    $audit = AuditLog::where('action', 'consent.restriction_lifted')->latest('id')->firstOrFail();
    expect((int) $audit->actor_id)->toBe($this->approver->id)
        ->and($audit->after['requested_by_user_id'])->toBe($this->requester->id)
        ->and($audit->after['approval_uuid'])->toBe($approval->uuid);

    // The inbound Service grant applies again; a lift grants nothing, so marketing still has no opt-in.
    tpExpectPassedConsent(tpReply($this->requester, $conversation));
    $marketing = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->requester, 'test.marketing_message',
        ['customer_id' => $customer->id, 'text' => 'offer'], $customer->business_id));
    expect((string) $marketing->error)->toContain('[consent_opt_in_missing]');
});

it('fails closed on an approver who may decide approvals but does not hold hq.consent.lift_restriction', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    tpOperator($this->requester, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    $approval = Approval::findOrFail(tpRequestLift($this->requester, $customer)->approvalId);

    $decider = $this->hqUser('brand_manager');
    $decider->givePermissionTo('hq.approvals.decide');
    expect($decider->can('hq.consent.lift_restriction'))->toBeFalse();

    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $decider);

    // The approval is spent (a human said yes) and the gateway's permission step refused the call under the
    // approver's own identity: the tool never ran, the do-not-contact stands.
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and((string) $outcome->error)->toContain('hq.consent.lift_restriction')
        ->and(ToolCall::findOrFail($outcome->toolCallId)->outcome_class)->toBe('permission_denied')
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Consumed)
        ->and(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->exists())->toBeFalse();
    tpExpectDnc(tpReply($this->requester, $conversation));
});

it('lifts nothing when what stands at the scope changed while the approval waited, or the scope names another business', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    tpOperator($this->requester, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    $approval = Approval::findOrFail(tpRequestLift($this->requester, $customer)->approvalId);

    // Between the request and the decision somebody recorded a withdrawal at the same scope: the latest
    // event there is no longer a do-not-contact, and the approved lift must not lift the wrong thing.
    tpOperator($this->requester, $customer, ConsentOperations::ACT_WITHDRAW, ['purpose' => null]);
    $outcome = app(ApprovalExecutor::class)->approveAndExecute($approval, $this->approver);
    expect($outcome->status)->toBe(OutcomeStatus::Failed)
        ->and((string) $outcome->error)->toContain('no do-not-contact standing')
        ->and(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->exists())->toBeFalse();

    // A LaLaDia customer named under Bionic's slug: refused as scope, never a cross-business lift.
    $bionic = app(ToolGateway::class)->execute(ToolRequest::forOperator($this->requester, LiftRestrictionTool::KEY,
        ['business_slug' => 'bionic', 'customer_id' => $customer->id, 'evidence_ref' => 'call log #92', 'reason' => 'asked again']));
    expect($bionic->status)->not->toBe(OutcomeStatus::Succeeded);
    if ($bionic->status === OutcomeStatus::AwaitingApproval) {
        $crossed = app(ApprovalExecutor::class)->approveAndExecute(Approval::findOrFail($bionic->approvalId), $this->approver);
        expect($crossed->status)->toBe(OutcomeStatus::Failed)->and((string) $crossed->error)->toContain('not a customer of the business');
    }
    expect(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->exists())->toBeFalse();
});

it('never lets an ordinary grant lift a do-not-contact, and requires evidence and a reason to even ask', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    tpOperator($this->requester, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);

    tpOperator($this->requester, $customer, ConsentOperations::ACT_OPERATOR_CONSENT, ['channel' => 'messenger', 'brand_id' => $this->lld->id]);
    tpOperator($this->requester, $customer, ConsentOperations::ACT_MARKETING_OPT_IN, ['channel' => 'messenger', 'brand_id' => $this->lld->id]);
    tpExpectDnc(tpReply($this->requester, $conversation));
    expect(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->exists())->toBeFalse();

    expect(tpRequestLift($this->requester, $customer, evidence: '')->status)->toBe(OutcomeStatus::Invalid)
        ->and(tpRequestLift($this->requester, $customer, reason: '')->status)->toBe(OutcomeStatus::Invalid)
        ->and(Approval::where('tool_key', LiftRestrictionTool::KEY)->count())->toBe(0);
});

it('keeps lifting a plain withdrawal single-person under hq.consent.grant, and never parks it', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    tpOperator($this->requester, $customer, ConsentOperations::ACT_WITHDRAW, ['purpose' => null]);
    expect((string) tpReply($this->requester, $conversation)->error)->toContain('[consent_withdrawn]');

    tpOperator($this->requester, $customer, ConsentOperations::ACT_LIFT);

    tpExpectPassedConsent(tpReply($this->requester, $conversation));
    expect(Approval::where('tool_key', LiftRestrictionTool::KEY)->count())->toBe(0);
});

it('confirms the roles (ruling j): support_agent views and restricts, never grants, never lifts; owner and operator may ask and approve a lift', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $has = fn (string $role, string $permission) => Role::findByName($role, 'web')->hasPermissionTo($permission);

    expect($has('support_agent', 'hq.consent.view'))->toBeTrue()
        ->and($has('support_agent', 'hq.consent.restrict'))->toBeTrue()
        ->and($has('support_agent', 'hq.consent.grant'))->toBeFalse()
        ->and($has('support_agent', 'hq.consent.lift_restriction'))->toBeFalse()
        ->and($has('support_agent', 'hq.approvals.decide'))->toBeFalse()
        ->and($has('owner', 'hq.consent.lift_restriction'))->toBeTrue()
        ->and($has('operator', 'hq.consent.lift_restriction'))->toBeTrue()
        ->and($has('brand_manager', 'hq.consent.lift_restriction'))->toBeFalse()
        ->and($has('finance_viewer', 'hq.consent.lift_restriction'))->toBeFalse();

    // And the permission is checked where it counts: the gateway refuses a support agent asking for the lift.
    $conversation = tpThread();
    $customer = $conversation->customer;
    $agent = $this->hqUser('support_agent');
    tpOperator($agent, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    $outcome = tpRequestLift($agent, $customer);
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->class)->toBe(OutcomeClass::PermissionDenied)
        ->and(Approval::where('tool_key', LiftRestrictionTool::KEY)->count())->toBe(0);
});

it('runs the two-person lift from the consent screen: the lift of a standing do-not-contact parks, is listed as pending, and support staff get 403', function () {
    $conversation = tpThread();
    $customer = $conversation->customer;
    tpOperator($this->requester, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    $url = '/customers/'.$customer->id.'/consent';
    $lift = ['act' => 'lift', 'evidence_ref' => 'WhatsApp message 10 Oct', 'reason' => 'asked to hear from us again'];

    $this->actingAsHq($this->hqUser('support_agent'))->post($url, $lift)->assertForbidden();

    $this->actingAsHq($this->requester)->post($url, $lift)->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_starts_with((string) $s, 'lift-pending:'));
    $approval = Approval::where('tool_key', LiftRestrictionTool::KEY)->sole();
    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and((int) $approval->requested_by_user_id)->toBe($this->requester->id)
        ->and(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->exists())->toBeFalse();

    $this->actingAsHq($this->requester)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/consent')
            ->has('pending_lifts', 1)
            ->where('pending_lifts.0.id', $approval->id)
            ->where('pending_lifts.0.requested_by', $this->requester->email)
            ->where('position.0.state', 'do_not_contact')
            ->where('position.0.two_person', true)
            ->where('can.lift_restriction', true)
            ->where('grid.0.cells.messenger.refusal', ConsentRefusal::DoNotContact->value));

    // The second person approves through the Approval Centre's own route; the screen then shows the lift.
    $this->actingAsHq($this->approver)->post('/approvals/'.$approval->id.'/approve', ['note' => 'confirmed by phone'])->assertSessionHasNoErrors();
    expect(ConsentEvent::where('customer_id', $customer->id)->where('state', 'lifted')->count())->toBe(1);
    $this->actingAsHq($this->requester)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('customers/consent')
            ->has('pending_lifts', 0)
            ->where('history.0.state', 'lifted')
            ->where('history.0.two_person', true)
            ->where('history.0.actor_label', $this->approver->email)
            ->where('grid.0.cells.messenger.permitted', true));
    expect(app(\App\Consent\ConsentLedger::class)->permits($customer->id, $customer->business_id, 'messenger', ConsentPurpose::Service, $this->lld->id)->permitted)->toBeTrue();
});
