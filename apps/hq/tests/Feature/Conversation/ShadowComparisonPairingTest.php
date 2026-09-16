<?php

use App\Conversation\Channels\MetaMessengerNormaliser;
use App\Conversation\Channels\OutboundEcho;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ShadowComparisons;
use App\Gateway\Descriptor\Channel;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\IntegrationCredential;
use App\Models\PlatformAccount;
use App\Models\ShadowComparison;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 | docs/78 B.1, the HQ side of gate P3-G3: what the support agent proposed, paired with what actually
 | went out while n8n still answers customers.
 |
 | Proved here: the Messenger normaliser yields the Page's echoes apart from the customers' messages;
 | an echo is recorded on its open thread as an external, generated outbound, once, and nowhere when
 | HQ holds no such thread; the pairing picks the proposal that answered the same customer message
 | and never a proposal already paired; a person's verdict is one of four words and audited; a
 | proposal nothing answered within a day is recorded unmeasured; the thread page shows it all; and
 | the whole path runs from the webhook through the drain.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    PlatformAccount::updateOrCreate(['platform' => 'facebook_page', 'account_ref' => '100085015853889'],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'LaLaDia page', 'approval_status' => 'unknown', 'is_active' => true]);
    PlatformAccount::updateOrCreate(['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated page', 'approval_status' => 'unknown', 'is_active' => true]);
});

function pairingThread(string $psid = 'psid-pair-1', string $text = 'Is the mango pickle in stock?'): Conversation
{
    $adapter = new SimulatedChannelAdapter;
    $service = app(ConversationService::class);
    $message = null;
    foreach ($adapter->normalise(['page' => SimulatedChannelAdapter::DEFAULT_PAGE, 'messages' => [['from' => $psid, 'text' => $text, 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $message = $service->ingest($inbound);
    }

    return $message->conversation()->first();
}

function pairedProposal(Conversation $conversation, string $text, ?CarbonImmutable $at = null): AgentRun
{
    $run = AgentRun::create([
        'uuid' => (string) Str::uuid(), 'agent_key' => 'support', 'correlation_id' => $conversation->uuid, 'trigger' => 'evaluation',
        'question' => 'Answer the customer on this thread.', 'autonomy_level' => 'a0', 'shadow' => true, 'status' => 'answered',
        'proposals' => [['kind' => 'proposal', 'tool' => 'messaging.send_reply', 'args' => ['conversation_uuid' => $conversation->uuid, 'text' => $text], 'why' => 'the reply']],
    ]);
    if ($at !== null) {
        $run->forceFill(['created_at' => $at])->save();
    }

    return $run;
}

function echoFor(Conversation $conversation, string $text, ?string $mid = null): OutboundEcho
{
    [$page, $psid] = explode(':', $conversation->external_thread_ref, 2);

    return new OutboundEcho(Channel::Messenger, $page, $conversation->external_thread_ref, $mid ?? 'echo-'.Str::uuid(), $psid, $text, CarbonImmutable::now(), hash('sha256', $text));
}

it('yields the Page\'s echoes apart from the customers\' messages', function () {
    $payload = json_decode((string) file_get_contents(base_path('tests/fixtures/meta/messenger_mixed.json')), true);

    $echoes = iterator_to_array((new MetaMessengerNormaliser)->echoes($payload), false);
    $inbound = iterator_to_array((new MetaMessengerNormaliser)->normalise($payload), false);

    expect($echoes)->toHaveCount(1)
        ->and($echoes[0]->platformAccountRef)->toBe('100085015853889')
        ->and($echoes[0]->recipientExternalId)->toBe('7412589630')
        ->and($echoes[0]->externalThreadRef)->toBe('100085015853889:7412589630')
        ->and($echoes[0]->externalMessageRef)->toBe('m_echo1')
        ->and($echoes[0]->body)->toBe('our own reply')
        ->and(array_map(fn ($m) => $m->externalMessageRef, $inbound))->not->toContain('m_echo1');
});

it('records an echo on its open thread once, as an external generated outbound, and nowhere when there is no thread', function () {
    $thread = pairingThread();
    $service = app(ConversationService::class);

    $echo = echoFor($thread, 'We have it in stock, 500g is 450 taka.', 'echo-1');
    $first = $service->recordExternalOutbound($echo);
    $again = $service->recordExternalOutbound($echo);

    expect($first)->not->toBeNull()
        ->and($first->direction)->toBe('outbound')
        ->and($first->author_type)->toBe('external')
        ->and($first->provenance->value)->toBe('generated')
        ->and($first->status)->toBe('sent')
        ->and($first->external_message_ref)->toBe('echo-1')
        ->and($again->id)->toBe($first->id)
        ->and(ConversationMessage::where('conversation_id', $thread->id)->where('direction', 'outbound')->count())->toBe(1);

    $orphan = new OutboundEcho(Channel::Messenger, SimulatedChannelAdapter::DEFAULT_PAGE, SimulatedChannelAdapter::DEFAULT_PAGE.':nobody', 'echo-x', 'nobody', 'hello?', CarbonImmutable::now(), 'd');
    expect($service->recordExternalOutbound($orphan))->toBeNull()
        ->and(Conversation::count())->toBe(1);
});

it('pairs an echo with the proposal that answered the same customer message, and never with one already paired', function () {
    $thread = pairingThread();
    $service = app(ConversationService::class);
    $comparisons = app(ShadowComparisons::class);

    $old = pairedProposal($thread, 'an older proposal', CarbonImmutable::now()->subMinutes(30));
    $this->travel(1)->seconds();
    $latest = pairedProposal($thread, 'We have the mango pickle in stock; 500g is 450 taka.');
    $this->travel(1)->seconds();

    $sent = $service->recordExternalOutbound(echoFor($thread, 'Yes! Mango pickle 500g is 450 taka, in stock.', 'echo-a'));
    $row = $comparisons->pair($sent);

    expect($row)->not->toBeNull()
        ->and($row->agent_run_id)->toBe($latest->id)
        ->and($row->external_message_id)->toBe($sent->id)
        ->and($row->external_message_ref)->toBe('echo-a')
        ->and($row->hq_digest)->toBe(hash('sha256', 'We have the mango pickle in stock; 500g is 450 taka.'))
        ->and($row->external_digest)->toBe(hash('sha256', 'Yes! Mango pickle 500g is 450 taka, in stock.'))
        ->and($row->verdict)->toBeNull();

    // A second echo on the same thread finds no unpaired proposal that answered the last inbound.
    $second = $service->recordExternalOutbound(echoFor($thread, 'Anything else?', 'echo-b'));
    expect($comparisons->pair($second))->toBeNull();

    // Never paired with a proposal from before the customer's message: the old proposal answered something else.
    expect(ShadowComparison::where('agent_run_id', $old->id)->exists())->toBeFalse();

    // A proposal with an identical text is flagged as such on the page payload.
    $thread2 = pairingThread('psid-pair-2');
    pairedProposal($thread2, 'Same words');
    $this->travel(1)->seconds();
    $sent2 = $service->recordExternalOutbound(echoFor($thread2, 'Same words', 'echo-c'));
    $row2 = $comparisons->pair($sent2);
    expect($row2->hq_digest)->toBe($row2->external_digest);
});

it('takes a person\'s verdict, one of four words, audited, and shows the pairing on the thread page', function () {
    $user = $this->hqUser('support_agent');
    $thread = pairingThread('psid-pair-3');
    $run = pairedProposal($thread, 'Proposed text');
    $this->travel(1)->seconds();
    $sent = app(ConversationService::class)->recordExternalOutbound(echoFor($thread, 'Sent text', 'echo-d'));
    $row = app(ShadowComparisons::class)->pair($sent);

    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/comparisons/{$row->id}/verdict", ['verdict' => 'sideways'])->assertSessionHasErrors('verdict');
    $this->actingAsHq($user)->post("/inbox/{$thread->uuid}/comparisons/{$row->id}/verdict", ['verdict' => 'better'])->assertRedirect()->assertSessionHas('status', 'verdict-recorded');

    $row->refresh();
    expect($row->verdict)->toBe('better')
        ->and($row->verdict_by)->toBe($user->id)
        ->and($row->verdict_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'shadow_comparison.verdict')->count())->toBe(1);

    $this->actingAsHq($user)->get("/inbox/{$thread->uuid}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/show')
            ->has('comparisons', 1)
            ->where('comparisons.0.run_id', $run->id)
            ->where('comparisons.0.proposed', 'Proposed text')
            ->where('comparisons.0.sent', 'Sent text')
            ->where('comparisons.0.identical', false)
            ->where('comparisons.0.verdict', 'better')
            ->where('verdicts', ShadowComparisons::VERDICTS));

    // Another thread's comparison cannot be judged from this one.
    $other = pairingThread('psid-pair-4');
    $this->actingAsHq($user)->post("/inbox/{$other->uuid}/comparisons/{$row->id}/verdict", ['verdict' => 'same'])->assertSessionHasErrors('verdict');
});

it('records a proposal nothing answered within a day as unmeasured, once', function () {
    $thread = pairingThread('psid-pair-5');
    $stale = pairedProposal($thread, 'Nobody sent anything for this', CarbonImmutable::now()->subHours(30));
    $fresh = pairedProposal($thread, 'Too recent to judge');

    $comparisons = app(ShadowComparisons::class);
    expect($comparisons->sweepUnmeasured())->toBe(1)
        ->and($comparisons->sweepUnmeasured())->toBe(0);

    $row = ShadowComparison::where('agent_run_id', $stale->id)->sole();
    expect($row->verdict)->toBe('unmeasured')
        ->and($row->external_digest)->toBeNull()
        ->and(ShadowComparison::where('agent_run_id', $fresh->id)->exists())->toBeFalse();
});

it('runs the whole path from the webhook: the customer message, the echo, the pairing', function () {
    metaFlag(true);
    IntegrationCredential::create(['provider' => 'meta_app_secret', 'account_ref' => 'meta', 'credential_type' => 'api_key', 'payload' => ['value' => 'meta-app-secret'], 'status' => 'active']);

    // The customer writes (a fixture with one inbound message); the support agent proposes; then the
    // bot's reply is echoed back in a later delivery.
    $inbound = json_decode((string) file_get_contents(base_path('tests/fixtures/meta/messenger_text.json')), true);
    metaPost($this, $inbound)->assertStatus(202);
    $this->artisan('hq:events:process')->assertSuccessful();
    $thread = Conversation::where('channel', 'messenger')->sole();
    pairedProposal($thread, 'Proposed by HQ');
    $this->travel(1)->seconds();

    $psid = explode(':', $thread->external_thread_ref, 2)[1];
    $echo = ['object' => 'page', 'entry' => [['id' => '100085015853889', 'time' => 1757757900000, 'messaging' => [
        ['sender' => ['id' => '100085015853889'], 'recipient' => ['id' => $psid], 'timestamp' => 1757757900100, 'message' => ['mid' => 'm_bot_reply', 'is_echo' => true, 'text' => 'Sent by the n8n bot']],
    ]]]];
    metaPost($this, $echo)->assertStatus(202);
    $this->artisan('hq:events:process')->assertSuccessful();

    $row = ShadowComparison::sole();
    expect($row->conversation_id)->toBe($thread->id)
        ->and($row->external_message_ref)->toBe('m_bot_reply')
        ->and($row->externalMessage->body)->toBe('Sent by the n8n bot')
        ->and(ConversationMessage::where('conversation_id', $thread->id)->where('author_type', 'external')->count())->toBe(1);

    // Redelivery of the echo pairs nothing twice.
    metaPost($this, $echo)->assertStatus(202)->assertJsonPath('duplicate', true);
    $this->artisan('hq:events:process')->assertSuccessful();
    expect(ShadowComparison::count())->toBe(1);
});
