<?php

use App\Conversation\Channels\DeliveryReceipt;
use App\Conversation\Channels\Meta\MetaInstagramChannel;
use App\Conversation\Channels\Meta\MetaMessengerChannel;
use App\Conversation\Channels\Meta\MetaSendError;
use App\Conversation\Channels\Meta\WhatsAppCloudChannel;
use App\Conversation\Channels\OutboundMessage;
use App\Conversation\Channels\OutboundTemplate;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\Descriptor\Channel;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\IntegrationCredential;
use App\Platform\Contracts\MessagingChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 | docs/88 A point 2 (stream Q): the Messenger, Instagram and WhatsApp send adapters, proven ONLY
 | against recorded answers. Every test here runs with stray requests prevented, so a request that is
 | not faked fails instead of reaching Meta; one test proves exactly that. The request shapes are
 | Meta's as documented on 22 September 2026 (docs/88-q-record.md cites each page).
 */

const Q_PAGE = '100085015853889';
const Q_IG = '17841400000000001';
const Q_WA_NUMBER = '106540352242922';

function qCredential(string $provider, string $ref, string $value): void
{
    IntegrationCredential::create(['provider' => $provider, 'account_ref' => $ref, 'credential_type' => 'token', 'payload' => ['value' => $value], 'status' => 'active']);
}

/** Runs $fn with the named credentials open, as the gateway would for a tool's declared credentials. */
function qOpen(array $pairs, Closure $fn): mixed
{
    return app(CredentialVault::class)->openForAccounts($pairs, $fn);
}

function qMessage(Channel $channel, string $account, string $to, string $body = 'আপনার অর্ডার আজ রওনা হয়েছে।', ?OutboundTemplate $template = null): OutboundMessage
{
    return new OutboundMessage($channel, $account, $to, $body, 'conv-uuid', 'msg-uuid', $template);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
});

it('sends a Messenger reply exactly as Meta documents it: the Page path, RESPONSE, the PSID, the token as a Bearer header and the appsecret_proof', function () {
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 'page-token-123');
    qCredential(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF, 'app-secret-xyz');
    Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => '7412589630', 'message_id' => 'm_sent_1'])]);

    $receipt = qOpen([[MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE], [MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF]],
        fn () => app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, '7412589630')));

    expect($receipt->status)->toBe(DeliveryReceipt::ACCEPTED)
        ->and($receipt->providerRef)->toBe('m_sent_1')
        ->and($receipt->accepted())->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $proof = hash_hmac('sha256', 'page-token-123', 'app-secret-xyz');

        return $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v25.0/'.Q_PAGE.'/messages?appsecret_proof='.$proof
            && $request->header('Authorization') === ['Bearer page-token-123']
            && ! str_contains($request->url(), 'page-token-123')
            && $request->data() === [
                'recipient' => ['id' => '7412589630'],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => 'আপনার অর্ডার আজ রওনা হয়েছে।'],
            ];
    });
});

it('refuses before any request when the gateway opened no credential, and never looks one up itself', function () {
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 'page-token-123'); // stored, but not opened
    Http::fake();

    $receipt = app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, '7412589630'));

    expect($receipt->status)->toBe(DeliveryReceipt::FAILED)
        ->and($receipt->category)->toBe(MetaSendError::CREDENTIAL_NOT_OPEN)
        ->and($receipt->retryable)->toBeFalse();
    Http::assertNothingSent();
});

it('omits the appsecret_proof when no app secret is stored, and never puts a token in the URL', function () {
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 'page-token-123');
    Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'x', 'message_id' => 'm_2'])]);

    qOpen([[MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE]], fn () => app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, 'psid')));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/'.Q_PAGE.'/messages');
});

it('classifies Meta\'s refusals by code and subcode, and says which ones may be retried', function (int $status, array $error, string $category, bool $retryable) {
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 't');
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => $error + ['type' => 'OAuthException', 'fbtrace_id' => 'Ab12']], $status)]);

    $receipt = qOpen([[MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE]], fn () => app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, 'psid')));

    expect($receipt->status)->toBe(DeliveryReceipt::FAILED)
        ->and($receipt->category)->toBe($category)
        ->and($receipt->retryable)->toBe($retryable)
        ->and($receipt->error)->toContain((string) $error['code']);
})->with([
    'outside the 24-hour window' => [400, ['code' => 10, 'error_subcode' => 2018278, 'message' => '(#10) This message is sent outside of allowed window.'], MetaSendError::OUTSIDE_WINDOW, false],
    'a plain permission refusal' => [403, ['code' => 10, 'message' => '(#10) Permission denied'], MetaSendError::PERMISSION, false],
    'rate limit 613' => [400, ['code' => 613, 'message' => 'Calls to this api have exceeded the rate limit.'], MetaSendError::RATE_LIMITED, true],
    'expired token 190' => [400, ['code' => 190, 'message' => 'Error validating access token'], MetaSendError::AUTH, false],
    'person unavailable 551' => [400, ['code' => 551, 'message' => 'This person isn\'t available right now.'], MetaSendError::RECIPIENT_UNAVAILABLE, false],
    'invalid parameter 100' => [400, ['code' => 100, 'message' => 'Invalid parameter'], MetaSendError::INVALID_REQUEST, false],
    'server error 2' => [500, ['code' => 2, 'message' => 'An unexpected error has occurred.'], MetaSendError::PROVIDER_ERROR, true],
    'a code HQ does not know' => [400, ['code' => 987654, 'message' => 'Something new'], MetaSendError::UNKNOWN, false],
]);

it('calls a send UNCERTAIN, never failed, when HQ cannot know whether Meta received it', function (Closure $fake, string $contains) {
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 't');
    Http::fake(['graph.facebook.com/*' => $fake]);

    $receipt = qOpen([[MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE]], fn () => app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, 'psid')));

    expect($receipt->status)->toBe(DeliveryReceipt::UNCERTAIN)
        ->and($receipt->uncertain())->toBeTrue()
        ->and($receipt->accepted())->toBeFalse()
        ->and($receipt->error)->toContain($contains);
})->with([
    'a timeout after connecting' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds'), 'no answer'],
    'a 504 from Meta\'s edge' => [fn () => Http::response('Gateway Timeout', 504), '504'],
    'a 200 that names no message' => [fn () => Http::response(['recipient_id' => 'psid']), 'no message id'],
]);

it('calls a connection that never opened a plain retryable failure, because nothing was sent', function () {
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 't');
    Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to graph.facebook.com port 443')]);

    $receipt = qOpen([[MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE]], fn () => app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, 'psid')));

    expect($receipt->status)->toBe(DeliveryReceipt::FAILED)
        ->and($receipt->category)->toBe(MetaSendError::PROVIDER_ERROR)
        ->and($receipt->retryable)->toBeTrue();
});

it('holds HQ\'s own send rate per account and refuses above it before any request', function () {
    config(['hq.messaging.meta.send_per_minute.facebook_page' => 2]);
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 't');
    Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'p', 'message_id' => 'm'])]);

    $receipts = qOpen([[MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE]], fn () => array_map(
        fn () => app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, 'psid')), [1, 2, 3]));

    expect(array_map(fn ($r) => $r->status, $receipts))->toBe([DeliveryReceipt::ACCEPTED, DeliveryReceipt::ACCEPTED, DeliveryReceipt::FAILED])
        ->and($receipts[2]->category)->toBe(MetaSendError::THROTTLED_LOCALLY)
        ->and($receipts[2]->retryable)->toBeTrue();
    Http::assertSentCount(2);
});

it('refuses, never shortens or rewrites, a message the channel cannot carry, before any request', function (Closure $adapter, OutboundMessage $message, string $category) {
    Http::fake();

    $receipt = $adapter()->send($message);

    expect($receipt->status)->toBe(DeliveryReceipt::FAILED)->and($receipt->category)->toBe($category);
    Http::assertNothingSent();
})->with([
    'an empty reply' => [fn () => app(MetaMessengerChannel::class), new OutboundMessage(Channel::Messenger, Q_PAGE, 'psid', '   ', 'c', 'm'), MetaSendError::INVALID_REQUEST],
    'Messenger over 2,000 characters' => [fn () => app(MetaMessengerChannel::class), new OutboundMessage(Channel::Messenger, Q_PAGE, 'psid', str_repeat('a', 2001), 'c', 'm'), MetaSendError::INVALID_REQUEST],
    'Instagram over 1,000 bytes of Bangla (334 letters)' => [fn () => app(MetaInstagramChannel::class), new OutboundMessage(Channel::Instagram, Q_IG, 'igsid', str_repeat('আ', 334), 'c', 'm'), MetaSendError::INVALID_REQUEST],
    'a template on Messenger' => [fn () => app(MetaMessengerChannel::class), new OutboundMessage(Channel::Messenger, Q_PAGE, 'psid', '', 'c', 'm', new OutboundTemplate('order_update', 'bn', 'utility')), MetaSendError::UNSUPPORTED],
    'a WhatsApp message handed to the Messenger adapter' => [fn () => app(MetaMessengerChannel::class), new OutboundMessage(Channel::Whatsapp, Q_WA_NUMBER, '8801711223344', 'hi', 'c', 'm'), MetaSendError::UNSUPPORTED],
    'no recipient' => [fn () => app(WhatsAppCloudChannel::class), new OutboundMessage(Channel::Whatsapp, Q_WA_NUMBER, '', 'hi', 'c', 'm'), MetaSendError::INVALID_REQUEST],
]);

it('sends an Instagram DM through the linked Page\'s token, with no messaging_type, and reads the message id', function () {
    qCredential(MetaInstagramChannel::TOKEN_PROVIDER, Q_IG, 'ig-page-token');
    Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'igsid-1', 'message_id' => 'aWdfZAG1_ig'])]);

    $receipt = qOpen([[MetaInstagramChannel::TOKEN_PROVIDER, Q_IG]], fn () => app(MetaInstagramChannel::class)->send(qMessage(Channel::Instagram, Q_IG, 'igsid-1', 'ধন্যবাদ!')));

    expect($receipt->providerRef)->toBe('aWdfZAG1_ig');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v25.0/me/messages'
        && $r->header('Authorization') === ['Bearer ig-page-token']
        && $r->data() === ['recipient' => ['id' => 'igsid-1'], 'message' => ['text' => 'ধন্যবাদ!']]);
});

it('sends WhatsApp text and templates in the Cloud API shape and reads messages[0].id', function () {
    qCredential(WhatsAppCloudChannel::TOKEN_PROVIDER, Q_WA_NUMBER, 'wa-system-user-token');
    Http::fake(['graph.facebook.com/*' => Http::response([
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => '8801711223344', 'wa_id' => '8801711223344']],
        'messages' => [['id' => 'wamid.sent.1']],
    ])]);

    [$text, $template] = qOpen([[WhatsAppCloudChannel::TOKEN_PROVIDER, Q_WA_NUMBER]], fn () => [
        app(WhatsAppCloudChannel::class)->send(qMessage(Channel::Whatsapp, Q_WA_NUMBER, '8801711223344', '৫০০ গ্রাম ৳৪৫০')),
        app(WhatsAppCloudChannel::class)->send(qMessage(Channel::Whatsapp, Q_WA_NUMBER, '8801711223344', '', new OutboundTemplate('order_status', 'bn', 'utility', ['LLD-1001', 'রওনা হয়েছে']))),
    ]);

    expect($text->providerRef)->toBe('wamid.sent.1')->and($template->providerRef)->toBe('wamid.sent.1');

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->url())->toBe('https://graph.facebook.com/v25.0/'.Q_WA_NUMBER.'/messages')
        ->and($sent[0]->data())->toBe([
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '8801711223344',
            'type' => 'text', 'text' => ['preview_url' => false, 'body' => '৫০০ গ্রাম ৳৪৫০'],
        ])
        ->and($sent[1]->data())->toBe([
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '8801711223344',
            'type' => 'template', 'template' => [
                'name' => 'order_status', 'language' => ['code' => 'bn'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'LLD-1001'], ['type' => 'text', 'text' => 'রওনা হয়েছে']]]],
            ],
        ]);
});

it('classifies WhatsApp\'s closed window, throughput limit and template refusals', function (array $error, string $category) {
    qCredential(WhatsAppCloudChannel::TOKEN_PROVIDER, Q_WA_NUMBER, 't');
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => $error], 400)]);

    $receipt = qOpen([[WhatsAppCloudChannel::TOKEN_PROVIDER, Q_WA_NUMBER]], fn () => app(WhatsAppCloudChannel::class)->send(qMessage(Channel::Whatsapp, Q_WA_NUMBER, '8801711223344')));

    expect($receipt->category)->toBe($category);
})->with([
    '131047 re-engagement' => [['code' => 131047, 'message' => 'More than 24 hours have passed since the recipient last replied'], MetaSendError::OUTSIDE_WINDOW],
    '130429 throughput' => [['code' => 130429, 'message' => 'Cloud API message throughput has been reached.'], MetaSendError::RATE_LIMITED],
    '132001 template missing' => [['code' => 132001, 'message' => 'Template name does not exist in the translation'], MetaSendError::TEMPLATE],
    '131026 undeliverable' => [['code' => 131026, 'message' => 'Message undeliverable'], MetaSendError::RECIPIENT_UNAVAILABLE],
    '131049 ecosystem' => [['code' => 131049, 'message' => 'not delivered to maintain healthy ecosystem engagement'], MetaSendError::DECLINED],
]);

it('declares each adapter\'s platform, channel, ceiling and the operations Meta must approve', function () {
    $messenger = app(MetaMessengerChannel::class);
    $instagram = app(MetaInstagramChannel::class);
    $whatsapp = app(WhatsAppCloudChannel::class);

    expect([$messenger->platform(), $instagram->platform(), $whatsapp->platform()])->toBe(['facebook_page', 'instagram_account', 'whatsapp_number'])
        ->and([$messenger->channel(), $instagram->channel(), $whatsapp->channel()])->toBe([Channel::Messenger, Channel::Instagram, Channel::Whatsapp])
        ->and($messenger->ceiling()->supports(MessagingChannel::SEND_SERVICE))->toBeTrue()
        ->and($messenger->ceiling()->supports(MessagingChannel::SEND_MARKETING))->toBeFalse()
        ->and($messenger->ceiling()->supports(MessagingChannel::SEND_TEMPLATE))->toBeFalse()
        ->and($whatsapp->ceiling()->supports(MessagingChannel::SEND_TEMPLATE))->toBeTrue()
        ->and($whatsapp->ceiling()->supports(MessagingChannel::SEND_MARKETING))->toBeFalse()
        ->and($messenger->requiresApproval())->toContain(MessagingChannel::SEND_SERVICE)
        ->and($whatsapp->requiresApproval())->toContain(MessagingChannel::SEND_TEMPLATE);
});

it('cannot reach the network in these tests: with nothing faked, the request is stopped and the attempt is uncertain, not sent', function () {
    qCredential(MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE, 't');

    $receipt = qOpen([[MetaMessengerChannel::TOKEN_PROVIDER, Q_PAGE]], fn () => app(MetaMessengerChannel::class)->send(qMessage(Channel::Messenger, Q_PAGE, 'psid')));

    expect($receipt->status)->toBe(DeliveryReceipt::UNCERTAIN)
        ->and($receipt->error)->toContain('without a matching fake');
});
