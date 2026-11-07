<?php

use App\Agents\AgentResponseSchema;
use App\Ai\ModelTier;
use App\Conversation\Channels\InboundMessage;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Gateway\Descriptor\Channel;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\FeatureFlag;
use App\Models\MessageDelivery;
use App\Models\PlatformAccount;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Features\Features;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/138 section 5 P3-W3 (docs/142): the inert customer AI path, walked as ONE path with synthetic fixtures:
 | inbound -> scoped identity -> conversation -> Ask/proposal -> consent and policy review -> human handover.
 |
 | The pieces are proved one by one in their own files (SupportWakeUpTest, SupportLanguageContractTest,
 | SupportSettleExecutedReplyTest, ConversationThreadScopeTest, IdentityConflictResolutionTest, ConsentEnforcementTest).
 | This file proves they hold TOGETHER on the real route and the real drain: the replay, the second inbound while a
 | person has the thread, the cross-business reference, the business-scoped inbox, and that nothing left HQ at any step.
 | No live customer-channel traffic: the channel is the simulated adapter and every HTTP call is faked and asserted unsent.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => '', 'hq.alerts.telegram_chat_id' => '']);
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    aiProviders();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = User::factory()->create();
    $this->owner->givePermissionTo(['hq.tools.facts.read', 'hq.tools.commerce.read', 'hq.inbox.view', 'hq.inbox.manage']);

    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'simulated', 'is_active' => true],
    );

    enableAiRuntime();
    FeatureFlag::updateOrCreate(['key' => MetaWebhookController::FLAG, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
    app(Features::class)->forget();
    Agent::where('key', 'support')->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
});

function inertIngest(string $text, string $psid = 'psid-inert', ?string $mid = null): ConversationMessage
{
    $message = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => $text, 'mid' => $mid ?? 'm-'.Str::uuid()]]]) as $inbound) {
        $message = app(ConversationService::class)->ingest($inbound);
    }

    return $message;
}

function inertDrain(): void
{
    test()->artisan('hq:events:process')->assertSuccessful();
}

/** The agent proposes one reply, then ends its turn. */
function inertScript(string $reply): void
{
    $envelope = fn (string $status, array $o = []) => FixtureProvider::reply((string) json_encode($o + [
        'status' => $status, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [], 'tool_calls' => [],
        'task' => 'answer the customer on this thread', 'authority_request' => 'none',
    ]));
    FixtureProvider::script('fixture', [
        $envelope(AgentResponseSchema::STATUS_NEED_EVIDENCE, ['tool_calls' => [
            ['tool' => 'messaging.send_reply', 'args' => ['business_slug' => 'laladia', 'conversation_uuid' => 'x', 'text' => $reply], 'why' => 'the reply'],
        ]]),
        $envelope(AgentResponseSchema::STATUS_ANSWER, ['answer' => 'Proposed a reply for a person to send.']),
    ]);
}

function assertNothingLeftHq(): void
{
    Http::assertNothingSent();
    expect(MessageDelivery::count())->toBe(0)
        ->and(ConversationMessage::where('direction', 'outbound')->count())->toBe(0)
        ->and(ToolCall::where('tool_key', 'messaging.send_reply')->count())->toBe(0);
}

it('walks inbound to proposal to a person, and nothing leaves HQ at any step', function () {
    inertScript('জি ভাইয়া, ইলিশ আচার স্টকে আছে। ৫০০ গ্রাম ৬৫০ টাকা।');

    $message = inertIngest('ভাইয়া hilsa pickle ta ki ekhon stock e ase? 500gm er dam koto? ধন্যবাদ');
    $conversation = $message->conversation()->sole();

    // Scoped identity and thread: the page's business, the agent holds it.
    $laladia = Business::where('slug', 'laladia')->sole();
    expect($conversation->business_id)->toBe($laladia->id)
        ->and($conversation->state)->toBe(ConversationState::AiActive);

    inertDrain();

    // Ask/proposal: a Bengali-script reply is a PROPOSAL on the thread, executed by nobody.
    $run = AgentRun::sole();
    expect($run->status)->toBe('answered')
        ->and(data_get($run->proposals, '0.args.text'))->toContain('জি ভাইয়া')
        ->and($conversation->fresh()->state)->toBe(ConversationState::AiWaiting);
    assertNothingLeftHq();

    // A person takes the thread over from the inbox, on the real route.
    $this->actingAsHq($this->hqUser('owner'))->post("/inbox/{$conversation->uuid}/take-over")->assertRedirect();
    expect($conversation->fresh()->state)->not->toBe(ConversationState::AiWaiting);
    assertNothingLeftHq();
});

it('does not duplicate work or change identity ownership when the same inbound is replayed', function () {
    inertScript('জি, স্টকে আছে।');

    $first = inertIngest('Vaiya hilsa pickle ase?', 'psid-replay', 'mid-fixed-1');
    inertDrain();
    $customerId = $first->conversation()->value('customer_id');
    $runs = AgentRun::count();
    $messages = ConversationMessage::count();

    $again = inertIngest('Vaiya hilsa pickle ase?', 'psid-replay', 'mid-fixed-1');
    inertDrain();

    expect($again->id)->toBe($first->id)
        ->and(ConversationMessage::count())->toBe($messages)
        ->and(AgentRun::count())->toBe($runs)
        ->and(Conversation::count())->toBe(1)
        ->and($first->conversation()->value('customer_id'))->toBe($customerId)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(2);
    assertNothingLeftHq();
});

it('keeps a thread with a person when a second inbound arrives while they hold it: no run, no model call, the message is recorded', function () {
    inertScript('জি, স্টকে আছে।');
    $first = inertIngest('Vaiya hilsa pickle ase?', 'psid-wait');
    $conversation = $first->conversation()->sole();
    inertDrain();
    $this->actingAsHq($this->hqUser('owner'))->post("/inbox/{$conversation->uuid}/take-over")->assertRedirect();
    $stateWithPerson = $conversation->fresh()->state;
    $runs = AgentRun::count();
    $calls = FixtureProvider::callsTo('fixture');

    $second = inertIngest('Ar ekta proshno ache, delivery koto din?', 'psid-wait');
    inertDrain();

    expect($second->conversation_id)->toBe($conversation->id)
        ->and($conversation->fresh()->state)->toBe($stateWithPerson)
        ->and(AgentRun::count())->toBe($runs)
        ->and(FixtureProvider::callsTo('fixture'))->toBe($calls)
        ->and(ConversationMessage::where('conversation_id', $conversation->id)->where('direction', 'inbound')->count())->toBe(2);
    assertNothingLeftHq();
});

it('keeps one thread reference in two businesses as two threads with their own customers', function () {
    $bionic = Business::where('slug', 'bionic')->sole();
    PlatformAccount::updateOrCreate(['platform' => 'facebook_page', 'account_ref' => 'inert-page-bnc'],
        ['brand_id' => Brand::where('code', 'BNC')->sole()->id, 'name' => 'Bionic test page', 'approval_status' => 'simulated', 'is_active' => true]);
    $service = app(ConversationService::class);

    $a = $service->ingest(new InboundMessage(Channel::Messenger, SimulatedChannelAdapter::DEFAULT_PAGE, 'shared-ref', 'm-'.Str::uuid(), 'psid-shared',
        'Hilsa pickle ase?', CarbonImmutable::now(), hash('sha256', 'Hilsa pickle ase?')));
    $b = $service->ingest(new InboundMessage(Channel::Messenger, 'inert-page-bnc', 'shared-ref', 'm-'.Str::uuid(), 'psid-shared',
        'Honey ase?', CarbonImmutable::now(), hash('sha256', 'Honey ase?')));

    $threadA = $a->conversation()->first();
    $threadB = $b->conversation()->first();
    expect($threadB->id)->not->toBe($threadA->id)
        ->and($threadB->business_id)->toBe($bionic->id)
        ->and($threadB->customer_id)->not->toBe($threadA->customer_id)
        ->and(ConversationMessage::where('conversation_id', $threadA->id)->pluck('body')->all())->toBe(['Hilsa pickle ase?']);
    assertNothingLeftHq();
});

it('shows a thread only to a person whose scope includes its business, on read and on every action', function () {
    $message = inertIngest('Vaiya hilsa pickle ase?', 'psid-scope');
    $conversation = $message->conversation()->sole();

    $elsewhere = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => Business::where('slug', 'bionic')->sole()->id, 'access' => 'full']);

    $this->actingAsHq($elsewhere)->get("/inbox/{$conversation->uuid}")->assertNotFound();
    foreach (['take-over', 'return-to-agent', 'toggle-ai', 'close', 'resolve', 'assign'] as $action) {
        $this->actingAsHq($elsewhere)->post("/inbox/{$conversation->uuid}/{$action}")->assertNotFound();
    }
    $this->actingAsHq($elsewhere)->post("/inbox/{$conversation->uuid}/reply", ['text' => 'hello'])->assertNotFound();
    $this->actingAsHq($elsewhere)->get('/inbox')->assertOk()->assertInertia(fn ($page) => $page->has('threads', 0));
    expect($conversation->fresh()->state)->toBe(ConversationState::AiActive);
    assertNothingLeftHq();
});
