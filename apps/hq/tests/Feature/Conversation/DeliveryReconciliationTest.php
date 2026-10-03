<?php

use App\Conversation\Channels\DeliveryReceipt;
use App\Conversation\Channels\DeliveryStatus;
use App\Conversation\Channels\Meta\MetaInstagramChannel;
use App\Conversation\Channels\Meta\MetaMessengerChannel;
use App\Conversation\Channels\Meta\WhatsAppCloudChannel;
use App\Conversation\Channels\OutboundChannelAdapter;
use App\Conversation\Channels\OutboundMessage;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\Deliveries\DeliveryLedger;
use App\Gateway\Credentials\CredentialVault;
use App\Gateway\Descriptor\Channel;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\IntegrationCredential;
use App\Models\MessageDelivery;
use App\Models\PlatformAccount;
use App\Platform\Capabilities;
use App\Platform\Contracts\MessagingChannel;
use App\Platform\PlatformRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 | docs/95 §5 "Delivery", ADR-031, docs/88-q-record open question 2: the attempts the sender could not settle
 | (`sending` left by a crash, `uncertain` after a timeout) are asked of the provider once a day, by the
 | provider's own message id, and settled through the ledger forward-only. Never resent. With no provider
 | registered (today) the pass records one skipped row with the counts and touches nothing. The provider
 | here is a test-only one wrapping the simulated channel; the Meta adapters are proven against faked HTTP.
 */

/** A messaging provider for the simulated page, registered only inside a test. */
final class RcnSimulatedProvider implements MessagingChannel, OutboundChannelAdapter
{
    public function __construct(public readonly SimulatedChannelAdapter $inner) {}

    public function platform(): string
    {
        return 'facebook_page';
    }

    public function ceiling(): Capabilities
    {
        return Capabilities::of([MessagingChannel::SEND_SERVICE, MessagingChannel::RECEIVE]);
    }

    public function requiresApproval(): array
    {
        return [];
    }

    public function channel(): Channel
    {
        return $this->inner->channel();
    }

    public function send(OutboundMessage $message): DeliveryReceipt
    {
        return $this->inner->send($message);
    }

    public function readDelivery(string $providerRef, string $platformAccountRef): ?DeliveryStatus
    {
        return $this->inner->readDelivery($providerRef, $platformAccountRef);
    }

    public function readCredentials(string $platformAccountRef): array
    {
        return $this->inner->readCredentials($platformAccountRef);
    }
}

function rcnThread(string $psid = 'psid-r'): Conversation
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

function rcnOutbound(Conversation $conversation): ConversationMessage
{
    return app(ConversationService::class)->recordOutbound($conversation, '৫০০ গ্রাম ৳৪৫০', ['author_type' => 'user', 'sent_at' => now()]);
}

/** An attempt that never recorded its outcome, sitting unsettled for `$minutesAgo` minutes. */
function rcnAttempt(string $status, ?string $ref, int $minutesAgo = 120, string $psid = 'psid-r'): MessageDelivery
{
    $attempt = MessageDelivery::create(['message_id' => rcnOutbound(rcnThread($psid))->id, 'attempt' => 1, 'status' => $status, 'provider_ref' => $ref]);
    DB::table('message_deliveries')->where('id', $attempt->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

    return $attempt->fresh();
}

function rcnAnswer(string $ref, string $status, ?array $error = null): DeliveryStatus
{
    return new DeliveryStatus(Channel::Messenger, $ref, $status, CarbonImmutable::now('UTC'), null, $error);
}

const RCN_PAGE = '100085015853889';

const RCN_IG = '17841400000000001';

function rcnCredential(string $provider, string $ref, string $value): void
{
    IntegrationCredential::create(['provider' => $provider, 'account_ref' => $ref, 'credential_type' => 'token', 'payload' => ['value' => $value], 'status' => 'active']);
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    Http::preventStrayRequests();
});

it('runs daily at 01:30 Dhaka without overlapping, and contains no send call', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'hq:deliveries:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 1 * * *')
        ->and($event->timezone)->toBe('Asia/Dhaka')
        ->and($event->withoutOverlapping)->toBeTrue();

    $source = (string) file_get_contents(app_path('Conversation/Deliveries/ReconcileDeliveries.php'));
    expect($source)->not->toContain('->send(')->and(config('hq.messaging.reconcile_after_minutes'))->toBe(30);
});

it('with no provider registered, which is today, changes nothing and records one skipped row with the counts', function () {
    $sending = rcnAttempt('sending', null);
    $uncertain = rcnAttempt(DeliveryReceipt::UNCERTAIN, 'm_u', psid: 'psid-2');
    rcnAttempt(DeliveryReceipt::UNCERTAIN, 'm_young', minutesAgo: 5, psid: 'psid-3');

    $this->artisan('hq:deliveries:reconcile')->assertSuccessful();

    expect($sending->fresh()->status)->toBe('sending')
        ->and($uncertain->fresh()->status)->toBe(DeliveryReceipt::UNCERTAIN)
        ->and(AuditLog::where('action', 'deliveries.reconciled')->count())->toBe(0);
    $skipped = AuditLog::where('action', 'deliveries.reconcile_skipped')->sole();
    expect($skipped->after)->toMatchArray(['considered' => 2, 'no_provider' => 2, 'settled_accepted' => 0, 'settled_failed' => 0]);
    Http::assertNothingSent();
});

it('asks the registered provider by message id and settles each attempt forward-only, leaving what it cannot read', function () {
    $sim = new SimulatedChannelAdapter;
    app(PlatformRegistry::class)->register(new RcnSimulatedProvider($sim));

    $accepted = rcnAttempt('sending', 'm_ok');
    $failed = rcnAttempt(DeliveryReceipt::UNCERTAIN, 'm_gone', psid: 'psid-2');
    $unknown = rcnAttempt(DeliveryReceipt::UNCERTAIN, 'm_silent', psid: 'psid-3');
    $noRef = rcnAttempt('sending', null, psid: 'psid-4');
    $young = rcnAttempt('sending', 'm_young', minutesAgo: 5, psid: 'psid-5');
    $settledAlready = MessageDelivery::create(['message_id' => rcnOutbound(rcnThread('psid-6'))->id, 'attempt' => 1, 'status' => 'delivered', 'provider_ref' => 'm_done']);

    $sim->answerDelivery('m_ok', rcnAnswer('m_ok', DeliveryStatus::ACCEPTED));
    $sim->answerDelivery('m_gone', rcnAnswer('m_gone', DeliveryStatus::FAILED, ['code' => 100, 'title' => 'Meta holds no message with this id']));
    $sim->answerDelivery('m_young', rcnAnswer('m_young', DeliveryStatus::ACCEPTED));

    $this->artisan('hq:deliveries:reconcile')->assertSuccessful();

    expect($accepted->fresh()->status)->toBe('accepted')
        ->and($failed->fresh()->status)->toBe('failed')
        ->and($failed->fresh()->error)->toContain('100')
        ->and($unknown->fresh()->status)->toBe(DeliveryReceipt::UNCERTAIN)
        ->and($noRef->fresh()->status)->toBe('sending')
        ->and($young->fresh()->status)->toBe('sending')
        ->and($settledAlready->fresh()->status)->toBe('delivered')
        ->and($sim->deliveryReads())->toBe(['m_ok', 'm_gone', 'm_silent'])
        ->and($sim->sent())->toBe([]);

    $summary = AuditLog::where('action', 'deliveries.reconciled')->sole();
    expect($summary->after)->toMatchArray(['considered' => 4, 'settled_accepted' => 1, 'settled_failed' => 1, 'unreadable' => 1, 'no_provider_ref' => 1])
        ->and(AuditLog::where('action', 'message.delivery_reconciled')->count())->toBe(2)
        ->and(AuditLog::where('action', 'deliveries.reconcile_skipped')->count())->toBe(0);

    // The accepted attempt now blocks a resend as an accepted one, not an uncertain one.
    expect(app(DeliveryLedger::class)->receiptFor($accepted->fresh())->status)->toBe(DeliveryReceipt::ACCEPTED);

    // Forward-only: a status webhook that moved the attempt first is never pulled back by a later read.
    $sim->answerDelivery('m_done', rcnAnswer('m_done', DeliveryStatus::FAILED, ['code' => 100, 'title' => 'late']));
    DB::table('message_deliveries')->where('id', $settledAlready->id)->update(['status' => 'uncertain', 'updated_at' => now()->subHour()]);
    app(DeliveryLedger::class)->apply(rcnAnswer('m_done', DeliveryStatus::DELIVERED));
    $this->artisan('hq:deliveries:reconcile')->assertSuccessful();
    expect($settledAlready->fresh()->status)->toBe('delivered');
});

it('reads a Messenger message back exactly as Graph serves it: GET by id, the Page token as a Bearer header, the appsecret_proof, and never a POST', function () {
    rcnCredential(MetaMessengerChannel::TOKEN_PROVIDER, RCN_PAGE, 'page-token-123');
    rcnCredential(MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF, 'app-secret-xyz');
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'm_sent_1', 'created_time' => '2026-10-03T01:00:00+0000'])]);
    $adapter = app(MetaMessengerChannel::class);

    $status = app(CredentialVault::class)->openForAccounts($adapter->readCredentials(RCN_PAGE), fn () => $adapter->readDelivery('m_sent_1', RCN_PAGE));

    expect($status)->not->toBeNull()
        ->and($status->status)->toBe(DeliveryStatus::ACCEPTED)
        ->and($status->providerRef)->toBe('m_sent_1')
        ->and($status->channel)->toBe(Channel::Messenger)
        ->and($adapter->readCredentials(RCN_PAGE))->toBe([[MetaMessengerChannel::TOKEN_PROVIDER, RCN_PAGE], [MetaWebhookController::SECRET_PROVIDER, MetaWebhookController::ACCOUNT_REF]]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $proof = hash_hmac('sha256', 'page-token-123', 'app-secret-xyz');

        return $request->method() === 'GET'
            && $request->url() === 'https://graph.facebook.com/v25.0/m_sent_1?fields=id%2Ccreated_time&appsecret_proof='.$proof
            && $request->header('Authorization') === ['Bearer page-token-123']
            && ! str_contains($request->url(), 'page-token-123');
    });
});

it('reads failed when Meta says the id does not exist, unknown on any other answer, and nothing without an open credential', function () {
    rcnCredential(MetaInstagramChannel::TOKEN_PROVIDER, RCN_IG, 'ig-token');
    $adapter = app(MetaInstagramChannel::class);
    $open = fn (string $ref) => app(CredentialVault::class)->openForAccounts($adapter->readCredentials(RCN_IG), fn () => $adapter->readDelivery($ref, RCN_IG));

    Http::fake([
        'graph.facebook.com/v25.0/m_gone*' => Http::response(['error' => ['message' => 'Unsupported get request. Object with ID \'m_gone\' does not exist', 'type' => 'GraphMethodException', 'code' => 100, 'error_subcode' => 33]], 400),
        'graph.facebook.com/v25.0/m_down*' => Http::response(['error' => ['message' => 'An unknown error occurred', 'code' => 1]], 500),
        'graph.facebook.com/v25.0/m_other*' => Http::response(['id' => 'm_different']),
    ]);

    $gone = $open('m_gone');
    expect($gone->status)->toBe(DeliveryStatus::FAILED)
        ->and($gone->error['code'])->toBe(100)
        ->and($gone->error['title'])->toContain('does not exist')
        ->and($open('m_down'))->toBeNull()
        ->and($open('m_other'))->toBeNull();
    Http::assertSentCount(3);

    // Stored but not opened: the connector never looks a credential up, and no request leaves.
    Http::fake();
    expect($adapter->readDelivery('m_x', RCN_IG))->toBeNull();
    Http::assertNothingSent();
});

it('cannot read a WhatsApp message back, so it answers unknown without a request', function () {
    rcnCredential(WhatsAppCloudChannel::TOKEN_PROVIDER, '106540352242922', 'wa-token');
    Http::fake();
    $adapter = app(WhatsAppCloudChannel::class);

    $status = app(CredentialVault::class)->openForAccounts($adapter->readCredentials('106540352242922'), fn () => $adapter->readDelivery('wamid.HBgL', '106540352242922'));

    expect($status)->toBeNull();
    Http::assertNothingSent();
});
