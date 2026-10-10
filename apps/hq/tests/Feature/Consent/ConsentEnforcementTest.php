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
