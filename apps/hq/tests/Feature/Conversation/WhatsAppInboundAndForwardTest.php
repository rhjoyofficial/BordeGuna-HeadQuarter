<?php

use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Context\Provenance;
use App\Conversation\Channels\DeliveryStatus;
use App\Conversation\Channels\DeliveryWatermark;
use App\Conversation\Channels\MetaMessengerNormaliser;
use App\Conversation\Channels\WhatsAppCloudNormaliser;
use App\Gateway\Descriptor\Channel;
use App\Http\Controllers\Webhooks\MetaForwardController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DomainEvent;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Models\PlatformAccount;
use App\Support\Events\Handlers\MetaInboundHandler;
use App\Support\Features\Features;
use Database\Seeders\DomainSeeder;

/*
 | docs/88 A point 2 (stream Q), inbound: WhatsApp Cloud API webhooks reduced by a pure normaliser from
 | Meta's documented payloads, Messenger's delivery and read signals, the shared Meta webhook accepting
 | a WhatsApp envelope (still 404 while `channels.meta.inbound` is off), and the n8n copy-forward
 | receiver for P3-G3 behind its own OFF flag with HQ's own signature.
 */

const WA_NUMBER = '106540352242922';

function waFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/meta/{$name}.json")), true);
}

function waFlag(string $key, bool $on): void
{
    FeatureFlag::updateOrCreate(['key' => $key, 'environment' => '*'], ['enabled' => $on, 'rollout' => null]);
    app(Features::class)->forget();
}

function waNumberAccount(): PlatformAccount
{
    return PlatformAccount::updateOrCreate(
        ['platform' => 'whatsapp_number', 'account_ref' => WA_NUMBER],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'LaLaDia WhatsApp (test)', 'approval_status' => 'unknown', 'is_active' => true],
    );
}

function waSecret(string $provider, string $ref, string $value): void
{
    IntegrationCredential::create(['provider' => $provider, 'account_ref' => $ref, 'credential_type' => 'token', 'payload' => ['value' => $value], 'status' => 'active']);
}

function forwardPost($test, string $raw, array $headers)
{
    return $test->call('POST', '/api/webhooks/meta/forwarded', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $headers), $raw);
}

function forwardHeaders(string $raw, string $secret, ?int $at = null): array
{
    $at ??= time();

    return ['HTTP_X_HQ_FORWARD_TIMESTAMP' => (string) $at, 'HTTP_X_HQ_FORWARD_SIGNATURE' => 'sha256='.hash_hmac('sha256', $at.'.'.$raw, $secret)];
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
});

it('reduces a WhatsApp text webhook to one InboundMessage: the business number as the account, number:wa_id as the thread', function () {
    $messages = iterator_to_array((new WhatsAppCloudNormaliser)->normalise(waFixture('whatsapp_text')), false);

    expect($messages)->toHaveCount(1);
    $m = $messages[0];
    expect($m->channel)->toBe(Channel::Whatsapp)
        ->and($m->platformAccountRef)->toBe(WA_NUMBER)
        ->and($m->externalThreadRef)->toBe(WA_NUMBER.':8801711223344')
        ->and($m->senderExternalId)->toBe('8801711223344')
        ->and($m->externalMessageRef)->toStartWith('wamid.')
        ->and($m->body)->toBe('ইলিশের আচার ৫০০ গ্রামের দাম কত?')
        ->and($m->receivedAt->getTimestamp())->toBe(1758513600)
        ->and($m->metadata)->toMatchArray(['wa_type' => 'text', 'sender_name' => 'রহিমা খাতুন']);
});

it('reads media, voice notes, buttons, lists and template quick replies; drops reactions and system notices; keeps an unknown type visible', function () {
    $messages = iterator_to_array((new WhatsAppCloudNormaliser)->normalise(waFixture('whatsapp_media_mixed')), false);
    $byId = collect($messages)->keyBy('externalMessageRef');

    expect($byId->keys()->all())->toBe(['wamid.img1', 'wamid.voice1', 'wamid.btn1', 'wamid.list1', 'wamid.tplbtn1', 'wamid.unsup1']);

    expect($byId['wamid.img1']->body)->toBe('এই জারটা কি আছে?')
        ->and($byId['wamid.img1']->attachments)->toBe([['type' => 'image', 'ref' => 'wa-media:1003383421387256']])
        ->and($byId['wamid.img1']->metadata['mime_type'])->toBe('image/jpeg');

    expect($byId['wamid.voice1']->body)->toBeNull()
        ->and($byId['wamid.voice1']->hasText())->toBeFalse()
        ->and($byId['wamid.voice1']->attachments)->toBe([['type' => 'audio', 'ref' => 'wa-media:1908647269898587']])
        ->and($byId['wamid.voice1']->metadata['voice'])->toBeTrue();

    expect($byId['wamid.btn1']->body)->toBe('হ্যাঁ, নিশ্চিত')
        ->and($byId['wamid.btn1']->metadata)->toMatchArray(['interactive_type' => 'button_reply', 'interactive_id' => 'confirm-order', 'reply_to' => 'wamid.ours1']);
    expect($byId['wamid.list1']->body)->toBe('৫০০ গ্রাম')->and($byId['wamid.list1']->metadata['interactive_id'])->toBe('size_500g');
    expect($byId['wamid.tplbtn1']->body)->toBe('অর্ডার কোথায়?')->and($byId['wamid.tplbtn1']->metadata['button_payload'])->toBe('TRACK_ORDER');
    expect($byId['wamid.unsup1']->body)->toBeNull()->and($byId['wamid.unsup1']->metadata['wa_type'])->toBe('unsupported');

    // No media URL is kept: Meta's download link is short-lived and needs a token.
    expect(json_encode($messages))->not->toContain('lookaside.fbsbx.com');
});

it('reads WhatsApp statuses as delivery signals, with Meta\'s error on a failure', function () {
    $statuses = iterator_to_array((new WhatsAppCloudNormaliser)->statuses(waFixture('whatsapp_statuses')), false);

    expect(array_map(fn (DeliveryStatus $s) => [$s->providerRef, $s->status], $statuses))->toBe([
        ['wamid.sent.by.hq.1', 'sent'], ['wamid.sent.by.hq.1', 'delivered'], ['wamid.sent.by.hq.2', 'failed'], ['wamid.sent.by.someone.else', 'read'],
    ]);
    expect($statuses[2]->error)->toMatchArray(['code' => 131049])
        ->and($statuses[2]->error['details'])->toContain('healthy ecosystem')
        ->and($statuses[1]->at->getTimestamp())->toBe(1758513710);
    // A status document carries no customer message.
    expect(iterator_to_array((new WhatsAppCloudNormaliser)->normalise(waFixture('whatsapp_statuses')), false))->toBe([]);
});

it('reads Messenger delivery ids and watermarks, and neither the Messenger nor the WhatsApp normaliser reads the other\'s document', function () {
    $signals = iterator_to_array((new MetaMessengerNormaliser)->deliveries(waFixture('messenger_delivery_read')), false);

    expect($signals)->toHaveCount(3)
        ->and($signals[0])->toBeInstanceOf(DeliveryStatus::class)
        ->and($signals[0]->providerRef)->toBe('m_hq_sent_1')
        ->and($signals[1])->toBeInstanceOf(DeliveryWatermark::class)
        ->and($signals[1]->threadRef())->toBe('100085015853889:7412589630')
        ->and($signals[2]->status)->toBe('read');

    expect(iterator_to_array((new MetaMessengerNormaliser)->normalise(waFixture('messenger_delivery_read')), false))->toBe([])
        ->and(iterator_to_array((new WhatsAppCloudNormaliser)->normalise(waFixture('messenger_delivery_read')), false))->toBe([])
        ->and(iterator_to_array((new MetaMessengerNormaliser)->normalise(waFixture('whatsapp_text')), false))->toBe([]);
});

it('answers 404 to a WhatsApp webhook while channels.meta.inbound is off, and with it on records one event for the business number', function () {
    waSecret(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF, 'meta-app-secret');
    waNumberAccount();
    $raw = json_encode(waFixture('whatsapp_text'), JSON_UNESCAPED_UNICODE);
    $signed = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-app-secret')];

    $this->call('POST', '/api/webhooks/meta', [], [], [], $signed, $raw)->assertStatus(404);
    expect(DomainEvent::count())->toBe(0);

    waFlag(MetaWebhookController::FLAG, true);
    $this->call('POST', '/api/webhooks/meta', [], [], [], $signed, $raw)->assertStatus(202);

    $event = DomainEvent::sole();
    expect($event->aggregate_id)->toBe(WA_NUMBER)
        ->and($event->business_id)->toBe(Brand::where('code', 'LLD')->sole()->business_id)
        ->and($event->source)->toBe('meta:webhook');
});

it('ingests a WhatsApp message in the drain as a customer\'s untrusted words on a WhatsApp thread, with Service consent on WhatsApp only', function () {
    waNumberAccount();
    // The consent a message grants lasts the channel's window from the message's own time, so the message is from now.
    $payload = waFixture('whatsapp_text');
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = (string) time();
    $event = DomainEvent::create([
        'event_id' => (string) Str::uuid(), 'type' => 'conversation.inbound_received', 'source' => 'meta:webhook', 'direction' => 'inbound',
        'payload' => $payload, 'dedupe_key' => 'test:wa-1', 'status' => 'pending', 'occurred_at' => now(),
    ]);

    app(MetaInboundHandler::class)->handle($event);

    $conversation = Conversation::sole();
    $message = ConversationMessage::sole();
    expect($conversation->channel)->toBe(Channel::Whatsapp)
        ->and($conversation->external_thread_ref)->toBe(WA_NUMBER.':8801711223344')
        ->and($message->provenance)->toBe(Provenance::Customer)
        ->and($message->provenance->isTrusted())->toBeFalse()
        ->and($message->body)->toBe('ইলিশের আচার ৫০০ গ্রামের দাম কত?');

    $ledger = app(ConsentLedger::class);
    expect($ledger->permits($conversation->customer_id, $conversation->business_id, 'whatsapp', ConsentPurpose::Service, $conversation->brand_id)->permitted)->toBeTrue()
        ->and($ledger->permits($conversation->customer_id, $conversation->business_id, 'whatsapp', ConsentPurpose::Marketing, $conversation->brand_id)->permitted)->toBeFalse();
});

it('keeps the n8n copy-forward at 404 while its own flag is off, even with channels.meta.inbound on', function () {
    waFlag(MetaWebhookController::FLAG, true);
    expect(FeatureFlag::where('key', MetaForwardController::FLAG)->sole()->enabled)->toBeFalse();

    $raw = json_encode(waFixture('whatsapp_text'));
    forwardPost($this, $raw, forwardHeaders($raw, 'fwd'))->assertStatus(404);
    expect(DomainEvent::count())->toBe(0);
});

it('with the forward flag on: 503 without its secret, 401 on a bad or stale signature, 202 once, and the same body from Meta itself is the same event', function () {
    waFlag(MetaForwardController::FLAG, true);
    waFlag(MetaWebhookController::FLAG, true);
    $raw = json_encode(waFixture('messenger_text'), JSON_UNESCAPED_UNICODE);

    forwardPost($this, $raw, forwardHeaders($raw, 'fwd-secret'))->assertStatus(503);

    waSecret(MetaForwardController::SECRET_PROVIDER, MetaForwardController::ACCOUNT_REF, 'fwd-secret');
    forwardPost($this, $raw, forwardHeaders($raw, 'wrong'))->assertStatus(401);
    forwardPost($this, $raw, forwardHeaders($raw, 'fwd-secret', time() - 600))->assertStatus(401);
    forwardPost($this, $raw, ['HTTP_X_HQ_FORWARD_SIGNATURE' => 'sha256='.hash_hmac('sha256', $raw, 'fwd-secret')])->assertStatus(401);
    expect(DomainEvent::count())->toBe(0);

    forwardPost($this, $raw, forwardHeaders($raw, 'fwd-secret'))->assertStatus(202)->assertJson(['duplicate' => false]);
    $event = DomainEvent::sole();
    expect($event->source)->toBe('meta:n8n-forward')
        ->and($event->payload['_provenance'])->toBe(['forwarded_by' => 'n8n', 'meta_signature' => 'absent']);

    // Meta delivering the same body directly is recognised as the same event, not a second one.
    waSecret(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF, 'meta-app-secret');
    $this->call('POST', '/api/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-app-secret')], $raw)->assertStatus(202)->assertJson(['duplicate' => true]);
    expect(DomainEvent::count())->toBe(1);
});

it('refuses a forwarded copy whose Meta signature does not verify, and marks one whose signature does', function () {
    waFlag(MetaForwardController::FLAG, true);
    waSecret(MetaForwardController::SECRET_PROVIDER, MetaForwardController::ACCOUNT_REF, 'fwd-secret');
    waSecret(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF, 'meta-app-secret');
    $raw = json_encode(waFixture('whatsapp_text'), JSON_UNESCAPED_UNICODE);

    forwardPost($this, $raw, forwardHeaders($raw, 'fwd-secret') + ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw.'altered', 'meta-app-secret')])
        ->assertStatus(401);
    expect(DomainEvent::count())->toBe(0);

    forwardPost($this, $raw, forwardHeaders($raw, 'fwd-secret') + ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'meta-app-secret')])
        ->assertStatus(202);
    expect(DomainEvent::sole()->payload['_provenance']['meta_signature'])->toBe('verified');
});
