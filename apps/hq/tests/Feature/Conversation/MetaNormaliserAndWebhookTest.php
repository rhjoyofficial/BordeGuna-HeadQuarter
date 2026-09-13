<?php

use App\Conversation\Channels\MetaMessengerNormaliser;
use App\Conversation\ConversationState;
use App\Gateway\Descriptor\Channel;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\DomainEvent;
use App\Models\FeatureFlag;
use App\Models\IntegrationCredential;
use App\Support\Features\Features;
use Database\Seeders\DomainSeeder;

/*
 | Phase 3 stream 3 (docs/72 §B): the Meta normaliser is a pure function over recorded Graph webhook
 | fixtures, and the webhook is the ONLY place Meta touches HQ: 404 with the flag off, a signature
 | check with the flag on, one events row, 202, and everything else in the drain.
 */

const META_PAGE = '100085015853889'; // LaLaDia's Facebook Page in the seeded platform accounts

function metaFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/fixtures/meta/{$name}.json")), true);
}

function metaFlag(bool $on): void
{
    FeatureFlag::updateOrCreate(['key' => MetaWebhookController::FLAG, 'environment' => '*'], ['enabled' => $on, 'rollout' => null]);
    app(Features::class)->forget();
}

function metaPost($test, array $payload, ?string $secret = 'meta-app-secret', array $headers = [])
{
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $signed = $secret === null ? [] : ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret)];

    return $test->call('POST', '/api/webhooks/meta', [], [], [], array_merge(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $signed, $headers), $raw);
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
});

it('reduces a text message fixture to one channel-neutral InboundMessage with the page as the account and page:psid as the thread', function () {
    $messages = iterator_to_array((new MetaMessengerNormaliser)->normalise(metaFixture('messenger_text')), false);

    expect($messages)->toHaveCount(1);
    $m = $messages[0];
    expect($m->channel)->toBe(Channel::Messenger)
        ->and($m->platformAccountRef)->toBe(META_PAGE)
        ->and($m->externalThreadRef)->toBe(META_PAGE.':7412589630')
        ->and($m->externalMessageRef)->toBe('m_AbCdEf123')
        ->and($m->senderExternalId)->toBe('7412589630')
        ->and($m->body)->toBe('আমার অর্ডার LLD-20260912-QW3 কোথায়? তিন দিন হয়ে গেল।')
        ->and($m->receivedAt->getTimestampMs())->toBe(1757757600123)
        ->and($m->hasText())->toBeTrue()
        ->and(strlen($m->digest))->toBe(64);
});

it('keeps attachments and quick replies, and drops the echo of our own message and the delivery receipt', function () {
    $messages = iterator_to_array((new MetaMessengerNormaliser)->normalise(metaFixture('messenger_mixed')), false);

    expect(array_map(fn ($m) => $m->externalMessageRef, $messages))->toBe(['m_img1', 'm_qr1']);
    expect($messages[0]->body)->toBeNull()
        ->and($messages[0]->hasText())->toBeFalse()
        ->and($messages[0]->attachments)->toBe([['type' => 'image', 'ref' => 'https://scontent.example/photo.jpg']]);
    expect($messages[1]->metadata)->toBe(['quick_reply' => 'CONFIRM_ORDER']);
});

it('yields nothing for a document that is not a page webhook', function () {
    expect(iterator_to_array((new MetaMessengerNormaliser)->normalise(['object' => 'instagram', 'entry' => []]), false))->toBe([])
        ->and(iterator_to_array((new MetaMessengerNormaliser)->normalise(['object' => 'page', 'entry' => [['id' => 'p', 'messaging' => [['sender' => ['id' => 'x'], 'message' => ['text' => 'no mid']]]]]]), false))->toBe([]);
});

it('answers 404 to both the handshake and the webhook while the flag is off, and records nothing', function () {
    expect(FeatureFlag::where('key', MetaWebhookController::FLAG)->sole()->enabled)->toBeFalse();

    $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=x&hub_challenge=123')->assertStatus(404);
    metaPost($this, metaFixture('messenger_text'))->assertStatus(404);

    expect(DomainEvent::count())->toBe(0);
});

it('completes the handshake only with the vaulted verify token', function () {
    metaFlag(true);
    $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=x&hub_challenge=123')->assertStatus(503);

    IntegrationCredential::create(['provider' => MetaWebhookController::VERIFY_TOKEN_PROVIDER, 'account_ref' => MetaWebhookController::ACCOUNT_REF, 'credential_type' => 'token', 'payload' => ['value' => 'verify-me'], 'status' => 'active']);
    $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=123')->assertStatus(403);
    $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=123')->assertOk()->assertSee('123', false);
});

it('with the flag on: 503 without a secret, 401 on a bad signature, 422 on a malformed envelope, 202 once and a dedupe on redelivery', function () {
    metaFlag(true);
    metaPost($this, metaFixture('messenger_text'))->assertStatus(503);

    IntegrationCredential::create(['provider' => MetaWebhookController::SECRET_PROVIDER, 'account_ref' => MetaWebhookController::ACCOUNT_REF, 'credential_type' => 'token', 'payload' => ['value' => 'meta-app-secret'], 'status' => 'active']);
    metaPost($this, metaFixture('messenger_text'), secret: 'wrong')->assertStatus(401);
    metaPost($this, metaFixture('messenger_text'), secret: null)->assertStatus(401);
    metaPost($this, ['object' => 'page'])->assertStatus(422);
    metaPost($this, ['object' => 'user', 'entry' => []])->assertStatus(422);

    metaPost($this, metaFixture('messenger_text'))->assertStatus(202)->assertJsonPath('duplicate', false);
    metaPost($this, metaFixture('messenger_text'))->assertStatus(202)->assertJsonPath('duplicate', true);

    $event = DomainEvent::sole();
    expect($event->type)->toBe('conversation.inbound_received')
        ->and($event->source)->toBe('meta:webhook')
        ->and($event->aggregate_id)->toBe(META_PAGE)
        ->and($event->business_id)->toBe(Business::where('slug', 'laladia')->sole()->id)
        ->and($event->payload['_digest'])->toBe(hash('sha256', json_encode(metaFixture('messenger_text'), JSON_UNESCAPED_UNICODE)))
        ->and(Conversation::count())->toBe(0); // nothing but the row happens in the request
});

it('turns the event into a thread, a stranger and an untrusted message in the drain, idempotently', function () {
    metaFlag(true);
    IntegrationCredential::create(['provider' => MetaWebhookController::SECRET_PROVIDER, 'account_ref' => MetaWebhookController::ACCOUNT_REF, 'credential_type' => 'token', 'payload' => ['value' => 'meta-app-secret'], 'status' => 'active']);
    metaPost($this, metaFixture('messenger_text'))->assertStatus(202);

    $this->artisan('hq:events:process')->assertSuccessful();

    $conversation = Conversation::sole();
    expect($conversation->business_id)->toBe(Business::where('slug', 'laladia')->sole()->id)
        ->and($conversation->channel)->toBe(Channel::Messenger)
        ->and($conversation->external_thread_ref)->toBe(META_PAGE.':7412589630')
        ->and($conversation->state)->toBe(ConversationState::HumanRequired)
        ->and($conversation->language)->toBe('bn')
        ->and($conversation->platformAccount?->account_ref)->toBe(META_PAGE);

    $message = ConversationMessage::sole();
    expect($message->provenance->isTrusted())->toBeFalse()
        ->and($message->external_message_ref)->toBe('m_AbCdEf123')
        ->and($message->metadata['language'])->toBe('bn');

    $customer = Customer::sole();
    expect($customer->primary_phone_canonical)->toBeNull()
        ->and($customer->attributes['stranger'])->toBeTrue()
        ->and($customer->identities()->where('channel', 'messenger_psid')->where('external_id', '7412589630')->exists())->toBeTrue();

    // A second delivery of the same message, with a different body digest, is still one message.
    $again = metaFixture('messenger_text');
    $again['entry'][0]['time'] = 1757757600999;
    metaPost($this, $again)->assertStatus(202)->assertJsonPath('duplicate', false);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(ConversationMessage::count())->toBe(1)->and(Conversation::count())->toBe(1);
});

it('fails the event, never guesses a business, when the page is not one HQ holds', function () {
    metaFlag(true);
    IntegrationCredential::create(['provider' => MetaWebhookController::SECRET_PROVIDER, 'account_ref' => MetaWebhookController::ACCOUNT_REF, 'credential_type' => 'token', 'payload' => ['value' => 'meta-app-secret'], 'status' => 'active']);
    $payload = metaFixture('messenger_text');
    $payload['entry'][0]['id'] = '999';
    $payload['entry'][0]['messaging'][0]['recipient']['id'] = '999';
    metaPost($this, $payload)->assertStatus(202);

    $this->artisan('hq:events:process')->assertSuccessful();

    $event = DomainEvent::sole();
    expect($event->business_id)->toBeNull()
        ->and($event->status)->toBe(DomainEvent::STATUS_FAILED)
        ->and($event->last_error)->toContain('will not guess')
        ->and(Conversation::count())->toBe(0)
        ->and(Customer::count())->toBe(0);
});
