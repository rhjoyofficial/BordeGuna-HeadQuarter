<?php

use App\Conversation\Channels\DeliveryReceipt;
use App\Conversation\Channels\DeliveryStatus;
use App\Conversation\Channels\DeliveryWatermark;
use App\Conversation\Channels\OutboundChannelAdapter;
use App\Conversation\Channels\OutboundMessage;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\Deliveries\ChannelSender;
use App\Conversation\Deliveries\DeliveryLedger;
use App\Gateway\Descriptor\Channel;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DomainEvent;
use App\Models\MessageDelivery;
use App\Models\PlatformAccount;
use App\Support\Events\Handlers\MetaInboundHandler;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Str;

/*
 | docs/88 A point 2 (stream Q), ADR-031: exactly-once sending is HQ's to keep (Meta takes no
 | idempotency key), and a message is real when the provider's own delivery signal says so. The
 | adapter here is a counting fake: nothing in this file can reach a provider.
 */

final class QCountingAdapter implements OutboundChannelAdapter
{
    public int $calls = 0;

    /** @param  list<DeliveryReceipt|Throwable>  $answers */
    public function __construct(private array $answers, private Channel $channel = Channel::Messenger) {}

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function send(OutboundMessage $message): DeliveryReceipt
    {
        $this->calls++;
        $answer = array_shift($this->answers) ?? throw new RuntimeException('no more answers');
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    public function readDelivery(string $providerRef, string $platformAccountRef): ?DeliveryStatus
    {
        return null;
    }

    public function readCredentials(string $platformAccountRef): array
    {
        return [];
    }
}

function ledgerThread(string $psid = 'psid-l'): Conversation
{
    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'unknown', 'is_active' => true],
    );
    $conversation = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => 'দাম কত?']]]) as $inbound) {
        $conversation = app(ConversationService::class)->ingest($inbound)->conversation;
    }

    return $conversation->fresh();
}

function ledgerOutbound(Conversation $conversation, ?CarbonImmutable $sentAt = null): ConversationMessage
{
    return app(ConversationService::class)->recordOutbound($conversation, '৫০০ গ্রাম ৳৪৫০', ['author_type' => 'user', 'sent_at' => $sentAt ?? now()]);
}

function outboundFor(ConversationMessage $row): OutboundMessage
{
    return new OutboundMessage(Channel::Messenger, SimulatedChannelAdapter::DEFAULT_PAGE, 'psid-l', $row->body, $row->conversation->uuid, $row->uuid);
}

function qAccepted(string $ref): DeliveryReceipt
{
    return new DeliveryReceipt(DeliveryReceipt::ACCEPTED, $ref, CarbonImmutable::now('UTC'));
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
});

it('writes the attempt as sending BEFORE the call, records the receipt on it, and never sends the same message twice', function () {
    $row = ledgerOutbound(ledgerThread());
    $adapter = new QCountingAdapter([qAccepted('m_1'), qAccepted('m_2')]);
    $sender = app(ChannelSender::class);

    $first = $sender->send($adapter, outboundFor($row));
    $again = $sender->send($adapter, outboundFor($row));

    expect($first->providerRef)->toBe('m_1')
        ->and($again->status)->toBe(DeliveryReceipt::ACCEPTED)
        ->and($again->providerRef)->toBe('m_1')
        ->and($again->category)->toBe('replayed')
        ->and($adapter->calls)->toBe(1);
    expect(MessageDelivery::where('message_id', $row->id)->get(['attempt', 'status', 'provider_ref'])->toArray())
        ->toBe([['attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'm_1']]);
});

it('allows a second attempt only after a failure', function () {
    $row = ledgerOutbound(ledgerThread());
    $adapter = new QCountingAdapter([
        new DeliveryReceipt(DeliveryReceipt::FAILED, null, CarbonImmutable::now('UTC'), 'rate_limited (code 613): slow down', 'rate_limited', true),
        qAccepted('m_after_retry'),
    ]);

    app(ChannelSender::class)->send($adapter, outboundFor($row));
    $retry = app(ChannelSender::class)->send($adapter, outboundFor($row));

    expect($retry->providerRef)->toBe('m_after_retry')->and($adapter->calls)->toBe(2)
        ->and(MessageDelivery::where('message_id', $row->id)->orderBy('attempt')->pluck('status')->all())->toBe(['failed', 'accepted']);
});

it('never retries an uncertain attempt, nor one a crash left as sending, and records an adapter that threw as uncertain', function () {
    $sender = app(ChannelSender::class);

    $uncertain = ledgerOutbound(ledgerThread('psid-u'));
    $adapter = new QCountingAdapter([new DeliveryReceipt(DeliveryReceipt::UNCERTAIN, null, CarbonImmutable::now('UTC'), 'no answer'), qAccepted('never')]);
    $sender->send($adapter, outboundFor($uncertain));
    expect($sender->send($adapter, outboundFor($uncertain))->status)->toBe(DeliveryReceipt::UNCERTAIN)
        ->and($adapter->calls)->toBe(1);

    $crashed = ledgerOutbound(ledgerThread('psid-c'));
    MessageDelivery::create(['message_id' => $crashed->id, 'attempt' => 1, 'status' => 'sending']);
    $untouched = new QCountingAdapter([qAccepted('never')]);
    expect($sender->send($untouched, outboundFor($crashed))->status)->toBe(DeliveryReceipt::UNCERTAIN)
        ->and($untouched->calls)->toBe(0);

    $threw = ledgerOutbound(ledgerThread('psid-t'));
    $receipt = $sender->send(new QCountingAdapter([new RuntimeException('socket closed')]), outboundFor($threw));
    expect($receipt->status)->toBe(DeliveryReceipt::UNCERTAIN)
        ->and(MessageDelivery::where('message_id', $threw->id)->sole()->status)->toBe('uncertain');
});

it('sends only a message HQ recorded as outbound, never an inbound one or one it does not hold', function () {
    $conversation = ledgerThread('psid-x');
    $inbound = $conversation->messages()->where('direction', 'inbound')->sole();
    $adapter = new QCountingAdapter([qAccepted('never'), qAccepted('never')]);

    $a = app(ChannelSender::class)->send($adapter, outboundFor($inbound));
    $b = app(ChannelSender::class)->send($adapter, new OutboundMessage(Channel::Messenger, 'p', 'psid', 'x', 'c', (string) Str::uuid()));

    expect([$a->status, $b->status])->toBe([DeliveryReceipt::FAILED, DeliveryReceipt::FAILED])
        ->and($adapter->calls)->toBe(0)
        ->and(MessageDelivery::count())->toBe(0);
});

it('moves a delivery only forward, verifies it at delivered, and audits the verification once', function () {
    $row = ledgerOutbound(ledgerThread());
    $delivery = MessageDelivery::create(['message_id' => $row->id, 'attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'wamid.x']);
    $ledger = app(DeliveryLedger::class);
    $at = CarbonImmutable::parse('2026-09-22 05:00:00', 'UTC');

    $ledger->apply(new DeliveryStatus(Channel::Whatsapp, 'wamid.x', 'sent', $at));
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->verified_at)->toBeNull();

    $ledger->apply(new DeliveryStatus(Channel::Whatsapp, 'wamid.x', 'delivered', $at->addMinute()));
    $ledger->apply(new DeliveryStatus(Channel::Whatsapp, 'wamid.x', 'read', $at->addMinutes(2)));
    $ledger->apply(new DeliveryStatus(Channel::Whatsapp, 'wamid.x', 'sent', $at->addMinutes(3)));   // late, out of order
    $ledger->apply(new DeliveryStatus(Channel::Whatsapp, 'wamid.x', 'failed', $at->addMinutes(4), error: ['code' => 131000, 'title' => 'late']));

    $fresh = $delivery->fresh();
    expect($fresh->status)->toBe('read')
        ->and($fresh->verified_at->toIso8601String())->toBe($at->addMinute()->toIso8601String())
        ->and(AuditLog::where('action', 'message.delivery_verified')->count())->toBe(1)
        ->and(AuditLog::where('action', 'message.delivery_failed')->count())->toBe(0);
});

it('records a failure before delivery with the provider\'s code, and ignores a signal for a message HQ never sent', function () {
    $row = ledgerOutbound(ledgerThread());
    $delivery = MessageDelivery::create(['message_id' => $row->id, 'attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'wamid.f']);
    $ledger = app(DeliveryLedger::class);

    $ledger->apply(new DeliveryStatus(Channel::Whatsapp, 'wamid.f', 'failed', CarbonImmutable::now('UTC'), error: ['code' => 131049, 'title' => 'healthy ecosystem']));
    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->error)->toContain('131049')
        ->and(AuditLog::where('action', 'message.delivery_failed')->count())->toBe(1);

    expect($ledger->apply(new DeliveryStatus(Channel::Whatsapp, 'wamid.not.ours', 'read', CarbonImmutable::now('UTC'))))->toBeNull()
        ->and(MessageDelivery::count())->toBe(1);
});

it('applies a Messenger watermark to the deliveries on that Page-and-person thread sent at or before it, and to no other', function () {
    $conversation = ledgerThread();
    $watermark = CarbonImmutable::parse('2026-09-22 05:00:00', 'UTC');
    $before = MessageDelivery::create(['message_id' => ledgerOutbound($conversation, $watermark->subMinute())->id, 'attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'm_b']);
    $after = MessageDelivery::create(['message_id' => ledgerOutbound($conversation, $watermark->addMinute())->id, 'attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'm_a']);
    $otherThread = MessageDelivery::create(['message_id' => ledgerOutbound(ledgerThread('psid-other'), $watermark->subMinute())->id, 'attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'm_o']);

    $moved = app(DeliveryLedger::class)->applyWatermark(new DeliveryWatermark(SimulatedChannelAdapter::DEFAULT_PAGE, 'psid-l', 'delivered', $watermark));

    expect($moved)->toBe(1)
        ->and($before->fresh()->status)->toBe('delivered')
        ->and($before->fresh()->verified_at)->not->toBeNull()
        ->and($after->fresh()->status)->toBe('accepted')
        ->and($otherThread->fresh()->status)->toBe('accepted');
});

it('applies WhatsApp statuses arriving through the webhook drain, and touches nothing HQ did not send', function () {
    PlatformAccount::updateOrCreate(['platform' => 'whatsapp_number', 'account_ref' => '106540352242922'],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'WA', 'approval_status' => 'unknown', 'is_active' => true]);
    $row = ledgerOutbound(ledgerThread());
    $one = MessageDelivery::create(['message_id' => $row->id, 'attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'wamid.sent.by.hq.1']);
    $two = MessageDelivery::create(['message_id' => ledgerOutbound(ledgerThread('psid-2'))->id, 'attempt' => 1, 'status' => 'accepted', 'provider_ref' => 'wamid.sent.by.hq.2']);

    $event = DomainEvent::create(['event_id' => (string) Str::uuid(), 'type' => 'conversation.inbound_received', 'source' => 'meta:webhook', 'direction' => 'inbound',
        'payload' => json_decode((string) file_get_contents(base_path('tests/fixtures/meta/whatsapp_statuses.json')), true), 'dedupe_key' => 'test:st', 'status' => 'pending', 'occurred_at' => now()]);
    app(MetaInboundHandler::class)->handle($event);

    expect($one->fresh()->status)->toBe('delivered')
        ->and($two->fresh()->status)->toBe('failed')
        ->and(MessageDelivery::count())->toBe(2)
        ->and(ConversationMessage::where('channel', 'whatsapp')->count())->toBe(0);
});
