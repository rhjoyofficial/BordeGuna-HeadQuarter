<?php

use App\Consent\ConsentBasis;
use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Consent\ConsentState;
use App\Conversation\Channels\InboundMessage;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\PlatformAccount;
use App\Tools\Conversation\SendWhatsAppReplyTool;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;

/*
 | docs/88 A point 2 (stream Q): `messaging.send_whatsapp_reply`, its own tool (ADR-026). Free text only
 | inside the customer's 24-hour window read from the thread; outside it only an allow-listed,
 | non-marketing template; consent-gated by the gateway; and with no WhatsApp provider registered it
 | fails for everyone, human included. Every call here goes through the real gateway.
 */

const WAT_NUMBER = '106540352242922';

function watThread(string $waId = '8801711223344', ?CarbonImmutable $at = null): Conversation
{
    PlatformAccount::updateOrCreate(['platform' => 'whatsapp_number', 'account_ref' => WAT_NUMBER],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'LaLaDia WhatsApp (test)', 'approval_status' => 'unknown', 'is_active' => true]);

    $inbound = new InboundMessage(
        channel: Channel::Whatsapp, platformAccountRef: WAT_NUMBER, externalThreadRef: WAT_NUMBER.':'.$waId,
        externalMessageRef: 'wamid.'.$waId.'.'.uniqid(), senderExternalId: $waId, body: 'অর্ডার কোথায়?',
        receivedAt: $at ?? CarbonImmutable::now('UTC'), digest: hash('sha256', $waId.uniqid()),
    );

    return app(ConversationService::class)->ingest($inbound)->conversation->fresh();
}

/** A standing opt-in: the customer agreed to hear from the business on WhatsApp beyond the reply window. */
function watOptIn(Conversation $conversation): void
{
    app(ConsentLedger::class)->record(
        customerId: $conversation->customer_id, businessId: $conversation->business_id,
        purpose: ConsentPurpose::Service, state: ConsentState::Granted, basis: ConsentBasis::Explicit, channel: 'whatsapp',
    );
}

function watSend($test, Conversation $conversation, array $args): ToolOutcome
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator(
        $test->owner, 'messaging.send_whatsapp_reply', ['business_slug' => 'laladia', 'conversation_uuid' => $conversation->uuid] + $args, $conversation->business_id,
    ));
}

beforeEach(function () {
    // The outbound policy refuses everything in quiet hours (23:00 to 08:00 Asia/Dhaka) before the window
    // and template rules these cases are about, so a run after 23:00 Dhaka failed two of them on the wall
    // clock (docs/100 Verification). Noon in Dhaka keeps every case on the rule it names.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'Asia/Dhaka'));
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->artisan('hq:tools:sync')->assertSuccessful();
});

it('declares its own WhatsApp tool: customer-facing, Service consent on the thread, R3, one per business, and NOT agent-executable', function () {
    $d = app(SendWhatsAppReplyTool::class)->descriptor();

    expect($d->key)->toBe('messaging.send_whatsapp_reply')
        ->and($d->channel)->toBe(Channel::Whatsapp)
        ->and($d->customerFacing)->toBeTrue()
        ->and($d->consentRequired?->purpose)->toBe(ConsentPurpose::Service)
        ->and($d->consentRequired?->subjectFrom)->toBe('conversation:args.conversation_uuid')
        ->and($d->riskTier)->toBe(RiskTier::R3)
        ->and($d->agentExecutable)->toBeFalse()
        ->and($d->permission)->toBe('hq.inbox.manage');
});

it('cannot send inside the window either, because no WhatsApp provider is registered, and records nothing outbound', function () {
    $conversation = watThread();

    $outcome = watSend($this, $conversation, ['text' => 'আপনার অর্ডার আজ রওনা হয়েছে।']);

    expect($outcome->status)->toBe(OutcomeStatus::Failed)
        ->and($outcome->error)->toContain(SendWhatsAppReplyTool::NO_PROVIDER)
        ->and($conversation->messages()->where('direction', 'outbound')->count())->toBe(0);
});

it('refuses free text outside the 24-hour window, read from the customer\'s last message on the thread', function () {
    $conversation = watThread('8801811000000', CarbonImmutable::now('UTC')->subHours(25));

    // Without a standing opt-in the gateway refuses first: the consent the customer's message granted ended with the window.
    expect(strtolower((string) watSend($this, $conversation, ['text' => 'hello'])->policyReason))->toContain('expired');
    watOptIn($conversation);

    $outcome = watSend($this, $conversation, ['text' => 'hello']);

    expect($outcome->ok())->toBeFalse()->and($outcome->error)->toContain('Outside the 24-hour customer service window');
    expect(app(SendWhatsAppReplyTool::class)->windowOpen($conversation))->toBeFalse()
        ->and(app(SendWhatsAppReplyTool::class)->windowOpen($conversation, CarbonImmutable::now('UTC')->subHours(2)))->toBeTrue();
});

it('allows outside the window only an allow-listed template in its language with its values, never a marketing one', function () {
    $conversation = watThread('8801811999999', CarbonImmutable::now('UTC')->subDays(3));
    watOptIn($conversation);

    expect(watSend($this, $conversation, ['template_name' => 'order_status', 'template_language' => 'bn', 'template_parameters' => ['LLD-1']])->error)
        ->toContain('not on HQ\'s WhatsApp template list');

    config(['hq.messaging.whatsapp.templates' => [
        'order_status' => ['language' => 'bn', 'category' => 'utility', 'parameters' => 1],
        'eid_offer' => ['language' => 'bn', 'category' => 'marketing', 'parameters' => 0],
    ]]);

    expect(watSend($this, $conversation, ['template_name' => 'order_status', 'template_language' => 'en', 'template_parameters' => ['LLD-1']])->error)->toContain('is listed in [bn]')
        ->and(watSend($this, $conversation, ['template_name' => 'order_status', 'template_language' => 'bn', 'template_parameters' => []])->error)->toContain('takes 1 values')
        ->and(watSend($this, $conversation, ['template_name' => 'eid_offer', 'template_language' => 'bn'])->error)->toContain('marketing consent');

    // The right template reaches the capability check, and stops there: no provider.
    expect(watSend($this, $conversation, ['template_name' => 'order_status', 'template_language' => 'bn', 'template_parameters' => ['LLD-1']])->error)
        ->toContain(SendWhatsAppReplyTool::NO_PROVIDER);
});

it('takes exactly one of a text or a template, and sends a WhatsApp message only to a WhatsApp thread', function () {
    $conversation = watThread();
    expect(watSend($this, $conversation, [])->ok())->toBeFalse()
        ->and(watSend($this, $conversation, ['text' => 'a', 'template_name' => 'x', 'template_language' => 'bn'])->ok())->toBeFalse();

    PlatformAccount::updateOrCreate(['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'unknown', 'is_active' => true]);
    $messenger = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => 'psid-w', 'text' => 'hi']]]) as $inbound) {
        $messenger = app(ConversationService::class)->ingest($inbound)->conversation;
    }
    watOptIn($messenger); // past the gateway's WhatsApp consent check, so the tool's own channel check is what refuses
    $outcome = watSend($this, $messenger, ['text' => 'hi']);
    expect($outcome->ok())->toBeFalse()->and($outcome->error)->toContain('goes only to a WhatsApp thread');
});

it('is refused at the consent gate when the person withdrew on WhatsApp', function () {
    $conversation = watThread('8801911222333');
    app(ConsentLedger::class)->record(
        customerId: $conversation->customer_id, businessId: $conversation->business_id,
        purpose: ConsentPurpose::Service, state: ConsentState::Withdrawn, basis: ConsentBasis::Explicit, channel: 'whatsapp',
    );

    $outcome = watSend($this, $conversation, ['text' => 'hello']);

    expect($outcome->status)->toBe(OutcomeStatus::Denied)
        ->and(strtolower((string) ($outcome->policyReason ?? $outcome->error)))->toContain('consent');
});
