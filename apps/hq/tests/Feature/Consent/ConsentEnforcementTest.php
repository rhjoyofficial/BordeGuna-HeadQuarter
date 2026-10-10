<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentOperations;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentRefusal;
use App\Consent\ConsentState;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\OutboundPolicy;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ConsentEvent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\PlatformAccount;
use App\Models\ToolCall;
use App\Models\User;
use App\Tools\ToolRegistry;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Tools\MarketingMessageTool;

/*
 | docs/108 (S7): consent is purpose- and scope-specific, and a withdrawal or a do-not-contact is
 | enforced AT RUNTIME, at the Tool Gateway, before any communication. A screen showing "withdrawn"
 | proves nothing; these tests send through `ToolGateway::execute()` and look at what was refused, why,
 | what was audited, and that the tool never ran.
 |
 | Service sends use the real `messaging.send_reply` (inert: with consent passed it stops at "no
 | messaging provider", which is the proof the consent step let it through). Marketing sends use a
 | test tool declaring the Marketing class, because no marketing tool exists in production.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'unknown', 'is_active' => true],
    );
    $this->owner = $this->hqUser('owner');
    $this->artisan('hq:tools:sync')->assertSuccessful();
    MarketingMessageTool::$executions = 0;
    app(ToolRegistry::class)->register(MarketingMessageTool::class);
    app(ToolAvailability::class)->forget();
    $this->lld = Brand::where('code', 'LLD')->sole();
});

/** A Messenger thread on LaLaDia's page: the customer's message grants Service on messenger, brand LLD, for the window. */
function ceThread(string $psid = 'psid-ce'): Conversation
{
    $conversation = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => 'আমার অর্ডার কোথায়?']]]) as $inbound) {
        $conversation = app(ConversationService::class)->ingest($inbound)->conversation;
    }

    return $conversation->fresh();
}

function ceReply(User $user, Conversation $conversation): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator(
        $user, 'messaging.send_reply',
        ['business_slug' => 'laladia', 'conversation_uuid' => $conversation->uuid, 'text' => 'আপনার অর্ডার আগামীকাল পৌঁছাবে। '.Str::random(6)],
        $conversation->business_id,
    ));
}

function ceMarketingRequest(User $user, Customer $customer, ?int $brandId): ToolRequest
{
    return new ToolRequest(
        toolKey: 'test.marketing_message',
        args: ['customer_id' => $customer->id, 'text' => 'অফার '.Str::random(6)],
        caller: CallerKind::HumanUi,
        identity: ActingIdentity::human($user),
        correlationId: (string) Str::uuid(),
        businessId: $customer->business_id,
        brandId: $brandId,
    );
}

function ceMarketing(User $user, Customer $customer, ?int $brandId): ToolOutcome
{
    return app(ToolGateway::class)->execute(ceMarketingRequest($user, $customer, $brandId));
}

/** The service send got past consent: it stopped at the absent provider, the inert posture, not at consent. */
function expectPassedConsent(ToolOutcome $outcome): void
{
    expect($outcome->class)->not->toBe(OutcomeClass::ConsentDenied)
        ->and((string) $outcome->error)->toContain('No messaging provider is registered');
}

function expectConsentRefused(ToolOutcome $outcome, ConsentRefusal $refusal): void
{
    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and($outcome->class)->toBe(OutcomeClass::ConsentDenied)
        ->and((string) $outcome->error)->toContain('consent refused: ['.$refusal->value.']');
}

function ceOperator(User $user, Customer $customer, string $act, array $input = []): void
{
    app(ConsentOperations::class)->record($user, $customer, $act, $input + ['evidence_ref' => 'call 10 Oct', 'reason' => 'the customer said so']);
}

it('refuses a send after a withdrawal, at the gateway, with its class, an audit row naming the deciding event, and the tool never runs', function () {
    $conversation = ceThread();
    expectPassedConsent(ceReply($this->owner, $conversation));

    ceOperator($this->owner, $conversation->customer, ConsentOperations::ACT_WITHDRAW, ['purpose' => 'service', 'channel' => 'messenger']);
    $withdrawal = ConsentEvent::where('customer_id', $conversation->customer_id)->latest('id')->first();

    $outcome = ceReply($this->owner, $conversation);
    expectConsentRefused($outcome, ConsentRefusal::Withdrawn);

    $call = ToolCall::findOrFail($outcome->toolCallId);
    expect($call->outcome_class)->toBe('consent_denied')
        ->and($conversation->messages()->where('direction', 'outbound')->count())->toBe(0);

    $audit = AuditLog::where('action', 'consent.refused')->latest('id')->firstOrFail();
    expect($audit->after['refusal'])->toBe('consent_withdrawn')
        ->and($audit->after['consent_event_id'])->toBe($withdrawal->id)
        ->and($audit->after['tool_call_id'])->toBe($call->id)
        ->and($audit->after['purpose'])->toBe('service')
        ->and((int) $audit->actor_id)->toBe($this->owner->id);
});

it('refuses marketing without an opt-in even while a live service grant exists, and lets it through once the person opts in', function () {
    $conversation = ceThread();
    $customer = $conversation->customer;
    // The customer wrote on WhatsApp too: Service, inbound basis, brand LLD.
    app(ConsentLedger::class)->record($customer->id, $customer->business_id, ConsentPurpose::Service, ConsentState::Granted,
        ConsentBasis::Inbound, 'whatsapp', attributes: ['brand_id' => $this->lld->id, 'expires_at' => now()->addHours(24)]);

    expectConsentRefused(ceMarketing($this->owner, $customer, $this->lld->id), ConsentRefusal::OptInMissing);
    expect(MarketingMessageTool::$executions)->toBe(0);

    ceOperator($this->owner, $customer, ConsentOperations::ACT_MARKETING_OPT_IN, ['channel' => 'whatsapp', 'brand_id' => $this->lld->id]);

    $outcome = ceMarketing($this->owner, $customer, $this->lld->id);
    expect($outcome->status)->toBe(OutcomeStatus::Succeeded)
        ->and(MarketingMessageTool::$executions)->toBe(1);
});

it('never lets a wildcard grant or a customer message stand in for a marketing opt-in', function () {
    $conversation = ceThread();
    $customer = $conversation->customer;
    $ledger = app(ConsentLedger::class);

    // Every purpose on every channel, explicitly: still not an opt-in for marketing.
    $ledger->record($customer->id, $customer->business_id, null, ConsentState::Granted, ConsentBasis::Explicit, null);
    expectConsentRefused(ceMarketing($this->owner, $customer, $this->lld->id), ConsentRefusal::OptInMissing);

    // And the ledger refuses to write a marketing grant on a basis that cannot evidence an opt-in.
    expect(fn () => $ledger->record($customer->id, $customer->business_id, ConsentPurpose::Marketing, ConsentState::Granted, ConsentBasis::Inbound, 'whatsapp'))
        ->toThrow(InvalidArgumentException::class, 'needs an opt-in');
    expect(MarketingMessageTool::$executions)->toBe(0);
});

it('keeps service replies allowed after a marketing withdrawal, and stops them after a withdrawal of every purpose', function () {
    $conversation = ceThread();
    $customer = $conversation->customer;

    ceOperator($this->owner, $customer, ConsentOperations::ACT_MARKETING_OPT_IN, ['channel' => 'messenger', 'brand_id' => $this->lld->id]);
    ceOperator($this->owner, $customer, ConsentOperations::ACT_WITHDRAW, ['purpose' => 'marketing']);

    // Purpose-specific: stopping marketing is not stopping the reply to "where is my order".
    expectPassedConsent(ceReply($this->owner, $conversation));

    ceOperator($this->owner, $customer, ConsentOperations::ACT_WITHDRAW, ['purpose' => null]);
    expectConsentRefused(ceReply($this->owner, $conversation), ConsentRefusal::Withdrawn);
});

it('lets do-not-contact beat everything: a later opt-in, a later inbound message, a human operator, the outbound policy', function () {
    $conversation = ceThread();
    $customer = $conversation->customer;

    ceOperator($this->owner, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    // Recorded AFTER the do-not-contact, at narrower scopes. None of them reopens contact.
    ceOperator($this->owner, $customer, ConsentOperations::ACT_MARKETING_OPT_IN, ['channel' => 'whatsapp', 'brand_id' => $this->lld->id]);
    ceOperator($this->owner, $customer, ConsentOperations::ACT_OPERATOR_CONSENT, ['channel' => 'messenger', 'brand_id' => $this->lld->id]);
    ceThread(); // the customer writes again: a fresh inbound Service grant

    expectConsentRefused(ceReply($this->owner, $conversation->fresh()), ConsentRefusal::DoNotContact);
    expectConsentRefused(ceMarketing($this->owner, $customer, $this->lld->id), ConsentRefusal::DoNotContact);
    expect(MarketingMessageTool::$executions)->toBe(0)
        ->and(app(OutboundPolicy::class)->refusal($conversation->fresh(), humanAuthor: true))->toContain('do-not-contact');
});

it('keeps consent inside its brand: one brand\'s opt-in never permits another brand, nor a send whose brand is unknown', function () {
    $conversation = ceThread();
    $customer = $conversation->customer;
    $sister = Brand::create(['business_id' => $customer->business_id, 'code' => 'LLG', 'slug' => 'laladia-gold', 'name' => 'LaLaDia Gold', 'is_active' => true]);

    ceOperator($this->owner, $customer, ConsentOperations::ACT_MARKETING_OPT_IN, ['channel' => 'whatsapp', 'brand_id' => $this->lld->id]);

    expectConsentRefused(ceMarketing($this->owner, $customer, $sister->id), ConsentRefusal::OptInMissing);
    expectConsentRefused(ceMarketing($this->owner, $customer, null), ConsentRefusal::OptInMissing);
    expect(ceMarketing($this->owner, $customer, $this->lld->id)->status)->toBe(OutcomeStatus::Succeeded);

    // A brand's do-not-contact stops that brand, and also a send whose brand is unknown (fail closed),
    // but not the sister brand.
    ceOperator($this->owner, $customer, ConsentOperations::ACT_MARKETING_OPT_IN, ['channel' => 'whatsapp', 'brand_id' => $sister->id]);
    ceOperator($this->owner, $customer, ConsentOperations::ACT_DO_NOT_CONTACT, ['brand_id' => $this->lld->id]);
    expectConsentRefused(ceMarketing($this->owner, $customer, $this->lld->id), ConsentRefusal::DoNotContact);
    expectConsentRefused(ceMarketing($this->owner, $customer, null), ConsentRefusal::DoNotContact);
    expect(ceMarketing($this->owner, $customer, $sister->id)->status)->toBe(OutcomeStatus::Succeeded);
});

it('lifts a withdrawal only where one stands, single-person, and a lift grants nothing by itself', function () {
    $conversation = ceThread();
    $customer = $conversation->customer;

    expect(fn () => ceOperator($this->owner, $customer, ConsentOperations::ACT_LIFT))
        ->toThrow(InvalidArgumentException::class, 'no withdrawal or do-not-contact standing');

    // A withdrawal of every purpose (not a do-not-contact: that lift is two-person, ConsentLiftTwoPersonTest).
    ceOperator($this->owner, $customer, ConsentOperations::ACT_WITHDRAW, ['purpose' => null]);
    expectConsentRefused(ceReply($this->owner, $conversation), ConsentRefusal::Withdrawn);

    ceOperator($this->owner, $customer, ConsentOperations::ACT_LIFT);
    // The inbound Service grant applies again; marketing still has no opt-in.
    expectPassedConsent(ceReply($this->owner, $conversation));
    expectConsentRefused(ceMarketing($this->owner, $customer, $this->lld->id), ConsentRefusal::OptInMissing);
});

it('refuses identically on the read-only authorize path, which records and audits nothing', function () {
    $conversation = ceThread();
    $customer = $conversation->customer;
    ceOperator($this->owner, $customer, ConsentOperations::ACT_DO_NOT_CONTACT);
    $calls = ToolCall::count();
    $audits = AuditLog::where('action', 'consent.refused')->count();

    $auth = app(ToolGateway::class)->authorize(ceMarketingRequest($this->owner, $customer, $this->lld->id));

    expect($auth->permitsProposal())->toBeFalse()
        ->and($auth->class)->toBe(OutcomeClass::ConsentDenied)
        ->and((string) $auth->reason)->toContain('[consent_do_not_contact]')
        ->and(ToolCall::count())->toBe($calls)
        ->and(AuditLog::where('action', 'consent.refused')->count())->toBe($audits);
});

it('refuses with consent_subject_unresolved when the call names nobody, and consent_not_recorded for service never granted', function () {
    $this->seed(DomainSeeder::class);
    $customer = Customer::create(['business_id' => $this->lld->business_id, 'name' => 'Never wrote']);

    $decision = app(ConsentLedger::class)->permits($customer->id, $customer->business_id, 'messenger', ConsentPurpose::Service, $this->lld->id);
    expect($decision->permitted)->toBeFalse()->and($decision->refusal)->toBe(ConsentRefusal::NotRecorded);

    $outcome = app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: 'test.marketing_message', args: ['customer_id' => 0, 'text' => 'x'], caller: CallerKind::HumanUi,
        identity: ActingIdentity::human($this->owner), correlationId: (string) Str::uuid(),
    ));
    expectConsentRefused($outcome, ConsentRefusal::SubjectUnresolved);
});

it('enforces identity scope at the gateway and in the outbound policy: a do-not-contact on one PSID stops that thread, not the same person\'s other thread (docs/114 §2)', function () {
    $threadA = ceThread('psid-ida');
    $customer = $threadA->customer;
    $identityA = \App\Models\CustomerIdentity::where('channel', 'messenger_psid')->where('external_id', 'psid-ida')->sole();
    // The same person's second Messenger identity, linked before they write from it: the thread resolves to them.
    $identityB = \App\Models\CustomerIdentity::create(['customer_id' => $customer->id, 'channel' => 'messenger_psid', 'external_id' => 'psid-idb']);
    $threadB = ceThread('psid-idb');
    expect($threadB->customer_id)->toBe($customer->id)->and($threadB->id)->not->toBe($threadA->id);
    expectPassedConsent(ceReply($this->owner, $threadA));
    expectPassedConsent(ceReply($this->owner, $threadB));

    ceOperator($this->owner, $customer, ConsentOperations::ACT_DO_NOT_CONTACT, ['identity_id' => $identityB->id]);

    // Thread B is refused at the gateway and by the outbound policy, from the thread row's identity; thread A is not.
    expectConsentRefused(ceReply($this->owner, $threadB), ConsentRefusal::DoNotContact);
    expect(app(OutboundPolicy::class)->refusal($threadB->fresh(), humanAuthor: true))->toContain('do-not-contact');
    expectPassedConsent(ceReply($this->owner, $threadA));
    expect(app(OutboundPolicy::class)->refusal($threadA->fresh(), humanAuthor: true))->toBeNull();

    // A grant on identity A, recorded after, never defeats B's restriction; nor does a customer-wide opt-in.
    ceOperator($this->owner, $customer, ConsentOperations::ACT_OPERATOR_CONSENT, ['channel' => 'messenger', 'brand_id' => $this->lld->id, 'identity_id' => $identityA->id]);
    ceOperator($this->owner, $customer, ConsentOperations::ACT_MARKETING_OPT_IN, ['channel' => 'whatsapp', 'brand_id' => $this->lld->id]);
    expectConsentRefused(ceReply($this->owner, $threadB), ConsentRefusal::DoNotContact);
    expectPassedConsent(ceReply($this->owner, $threadA));

    // A send that names the person but no thread resolves no identity: B's denial still applies (fail closed).
    expectConsentRefused(ceMarketing($this->owner, $customer, $this->lld->id), ConsentRefusal::DoNotContact);
    expect(MarketingMessageTool::$executions)->toBe(0);

    // The refusal audit names the deciding event, B's do-not-contact.
    $dnc = ConsentEvent::where('customer_id', $customer->id)->where('state', 'do_not_contact')->sole();
    expect(AuditLog::where('action', 'consent.refused')->latest('id')->firstOrFail()->after['consent_event_id'])->toBe($dnc->id)
        ->and((int) $dnc->identity_id)->toBe($identityB->id);
});

it('never reopens service on a new inbound after a withdrawal of every purpose: an explicit, audited lift is required (ruling h)', function () {
    $conversation = ceThread('psid-h');
    $customer = $conversation->customer;

    ceOperator($this->owner, $customer, ConsentOperations::ACT_WITHDRAW, ['purpose' => null]);
    expectConsentRefused(ceReply($this->owner, $conversation), ConsentRefusal::Withdrawn);

    // The customer writes again: a fresh inbound Service grant is recorded, and it changes nothing.
    ceThread('psid-h');
    expectConsentRefused(ceReply($this->owner, $conversation->fresh()), ConsentRefusal::Withdrawn);
    expect(app(OutboundPolicy::class)->refusal($conversation->fresh(), humanAuthor: true))->toContain('withdrawn');

    // Only the explicit lift, by a person with evidence and a reason, reopens it.
    ceOperator($this->owner, $customer, ConsentOperations::ACT_LIFT);
    expectPassedConsent(ceReply($this->owner, $conversation->fresh()));
    expect(AuditLog::where('action', 'consent.operator_recorded')->latest('id')->firstOrFail()->after['act'])->toBe('lift');
});
