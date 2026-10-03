<?php

use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\EscalationTriggers;
use App\Conversation\HandoffStateMachine;
use App\Conversation\HandoverNotifications;
use App\Conversation\HandoverReason;
use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\ScenarioLibrary;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\ConversationMessage;
use App\Models\PlatformAccount;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 | docs/95 §5 "Hand-over" and §16 item 4 (stream W2a): the hand-over ping.
 |
 | A thread entering `human_required` is announced ONCE per entry on every notification channel HQ has
 | (Telegram when configured, the in-app audit row always), with the business, the channel, the reason
 | code and the thread's link, and nothing the customer wrote. A second message on a thread that already
 | needs a person is not a second entry. `hq.inbox.handover_notify` off sends nothing. The simulator's
 | rolled-back replays send nothing, because the ping waits for the commit that never comes.
 */

beforeEach(function () {
    Cache::flush();
    Http::fake();
    config(['hq.alerts.telegram_bot_token' => 'bot-token-under-test', 'hq.alerts.telegram_chat_id' => '-100123']);
    $this->seed(DomainSeeder::class);
    $this->seed(AgentSeeder::class);

    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'simulated', 'is_active' => true],
    );
});

function hnIngest(string $text, string $psid = 'psid-handover'): ConversationMessage
{
    $message = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => $text, 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $message = app(ConversationService::class)->ingest($inbound);
    }

    return $message;
}

function hnAgentActive(): void
{
    Agent::where('key', 'support')->update(['status' => 'active', 'enabling_user_id' => User::factory()->create()->id]);
}

/** @return list<string> the Telegram texts HQ sent, oldest first */
function hnTelegram(): array
{
    $texts = [];
    Http::assertSent(function (Request $request) use (&$texts) {
        if (str_contains($request->url(), 'api.telegram.org')) {
            $texts[] = (string) $request['text'];
        }

        return true;
    });

    return $texts;
}

it('notifies exactly once, with the reason code and the thread link and no customer text, when the core hands a thread to a person', function () {
    hnAgentActive();
    $text = 'আমি টাকা ফেরত চাই, প্রোডাক্ট নষ্ট ছিল। আমার নাম্বার 01711000000';

    $message = hnIngest($text);
    $conversation = $message->conversation()->sole();
    expect($conversation->state)->toBe(ConversationState::HumanRequired);

    $sent = hnTelegram();
    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toContain('needs a person')
        ->and($sent[0])->toContain('Reason: '.HandoverReason::MoneyOrComplaint->value)
        ->and($sent[0])->toContain('/inbox/'.$conversation->uuid)
        ->and($sent[0])->toContain('Channel: messenger')
        ->and($sent[0])->toContain('LaLaDia')
        ->and($sent[0])->not->toContain('টাকা')
        ->and($sent[0])->not->toContain('01711000000')
        ->and($sent[0])->not->toContain('psid-handover');

    $inApp = AuditLog::where('action', 'notification.raised')->get();
    expect($inApp)->toHaveCount(1)
        ->and($inApp[0]->after['reason'])->toBe('money_or_complaint')
        ->and($inApp[0]->after['conversation_uuid'])->toBe($conversation->uuid)
        ->and($inApp[0]->after['handover_key'])->toStartWith("conversation:{$conversation->uuid}:transition:")
        ->and(json_encode($inApp[0]->after))->not->toContain('01711000000');

    // A second message while the thread already needs a person is not a second entry.
    hnIngest('hello? ekhono answer pai nai', 'psid-handover');
    expect(AuditLog::where('action', 'notification.raised')->count())->toBe(1)
        ->and(hnTelegram())->toHaveCount(1);
});

it('announces a thread that opens needing a person while the agent is paused, once, as agent_paused', function () {
    expect(Agent::where('key', 'support')->sole()->status)->toBe('paused');

    $message = hnIngest('Vaiya, hilsa ache?');
    $conversation = $message->conversation()->sole();
    expect($conversation->state)->toBe(ConversationState::HumanRequired);

    $sent = hnTelegram();
    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toContain('Reason: '.HandoverReason::AgentPaused->value)
        ->and($sent[0])->not->toContain('hilsa');

    $row = AuditLog::where('action', 'notification.raised')->sole();
    expect($row->after['handover_key'])->toBe("conversation:{$conversation->uuid}:opened");

    hnIngest('abar likhlam', 'psid-handover');
    expect(AuditLog::where('action', 'notification.raised')->count())->toBe(1);
});

it('announces again when a thread leaves the state and re-enters it: once per entry, with the new reason', function () {
    hnAgentActive();
    $owner = $this->hqUser('owner');

    $conversation = hnIngest('I want a refund')->conversation()->sole();
    expect(AuditLog::where('action', 'notification.raised')->count())->toBe(1);

    $states = app(HandoffStateMachine::class);
    $states->transition($conversation, ConversationState::HumanActive, $owner->id, 'taking it');
    $states->transition($conversation->fresh(), ConversationState::HumanResolved, $owner->id, 'done');
    // The customer comes back and asks for a person: the thread re-enters human_required from human_resolved.
    hnIngest('I want to talk to a real person now', 'psid-handover');

    $rows = AuditLog::where('action', 'notification.raised')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->after['reason'])->toBe('asks_for_person')
        ->and($rows[0]->after['handover_key'])->not->toBe($rows[1]->after['handover_key']);
});

it('records the reason as ai_disabled when a person switches the agent off a thread it held', function () {
    hnAgentActive();
    $owner = $this->hqUser('owner');
    $conversation = hnIngest('Vaiya, hilsa ache?')->conversation()->sole();
    expect($conversation->state)->toBe(ConversationState::AiActive)
        ->and(AuditLog::where('action', 'notification.raised')->count())->toBe(0);

    $this->actingAsHq($owner)->post('/inbox/'.$conversation->uuid.'/toggle-ai')->assertRedirect();

    $row = AuditLog::where('action', 'notification.raised')->sole();
    expect($row->after['reason'])->toBe(HandoverReason::AiDisabled->value)
        ->and($conversation->fresh()->state)->toBe(ConversationState::HumanRequired);
});

it('sends nothing when hq.inbox.handover_notify is off, and the thread still needs a person', function () {
    config([HandoverNotifications::CONFIG => false]);
    hnAgentActive();

    $conversation = hnIngest('I want a refund')->conversation()->sole();

    expect($conversation->state)->toBe(ConversationState::HumanRequired)
        ->and(AuditLog::where('action', 'notification.raised')->count())->toBe(0);
    Http::assertNothingSent();
});

it('sends nothing for the simulator\'s rolled-back replays, because the ping waits for a commit', function () {
    $results = app(ConversationSimulator::class)->replay(ScenarioLibrary::all(), 'LLD');

    expect(array_filter($results, fn ($r) => ! $r->passed))->toBe([])
        ->and(AuditLog::where('action', 'notification.raised')->count())->toBe(0);
    Http::assertNothingSent();
});

it('maps every reason the core and the inbox write to a code, and leaves an unknown sentence unmapped', function () {
    expect(HandoverReason::fromText(EscalationTriggers::REASON_ASKS_FOR_PERSON))->toBe(HandoverReason::AsksForPerson)
        ->and(HandoverReason::fromText(EscalationTriggers::REASON_MONEY_OR_COMPLAINT))->toBe(HandoverReason::MoneyOrComplaint)
        ->and(HandoverReason::fromText(EscalationTriggers::REASON_SECOND_INBOUND_WHILE_WAITING))->toBe(HandoverReason::SecondInboundWhileWaiting)
        ->and(HandoverReason::fromText(EscalationTriggers::REASON_AGENT_PAUSED))->toBe(HandoverReason::AgentPaused)
        ->and(HandoverReason::fromText(EscalationTriggers::REASON_AI_DISABLED))->toBe(HandoverReason::AiDisabled)
        ->and(HandoverReason::fromText('the agent was switched off for this thread'))->toBe(HandoverReason::AiDisabled)
        ->and(HandoverReason::fromText(HandoverReason::CANNOT_SERVE_PREFIX.': the run ended refused'))->toBe(HandoverReason::AgentCannotServe)
        ->and(HandoverReason::fromText('simulation: a person took over'))->toBeNull()
        ->and(HandoverReason::fromText(null))->toBeNull();
});
