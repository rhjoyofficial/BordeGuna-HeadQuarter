<?php

use App\Consent\ConsentLedger;
use App\Consent\ConsentPurpose;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\HandoffStateMachine;
use App\Conversation\InvalidTransition;
use App\Conversation\Language;
use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\ScenarioLibrary;
use App\Conversation\UnknownPlatformAccount;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\DomainEvent;
use App\Models\PlatformAccount;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Str;

/*
 | Phase 3 stream 3 (docs/72 §B, §E): the conversation core. One inbound message, from any channel,
 | becomes a thread, a person, an untrusted message, a Service consent for the reply window, a state
 | the machine decided, and an event. The simulation world replays the scripted scenarios through
 | exactly this code and leaves nothing behind.
 */

function simPage(string $ref = SimulatedChannelAdapter::DEFAULT_PAGE, string $brand = 'LLD'): PlatformAccount
{
    return PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => $ref],
        ['brand_id' => Brand::where('code', $brand)->sole()->id, 'name' => "Simulated {$ref}", 'approval_status' => 'unknown', 'is_active' => true],
    );
}

function simIngest(array $turns, string $page = SimulatedChannelAdapter::DEFAULT_PAGE): array
{
    $adapter = new SimulatedChannelAdapter;
    $service = app(ConversationService::class);
    $out = [];
    // A provider message id per turn, as a real channel supplies one; the core dedupes on it.
    $turns = array_map(fn (array $t) => $t + ['mid' => 'm-'.Str::uuid()], $turns);
    foreach ($adapter->normalise(['page' => $page, 'messages' => $turns]) as $inbound) {
        $out[] = $service->ingest($inbound);
    }

    return $out;
}

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    simPage();
});

it('ingests a first message into a thread for the page\'s business and brand, a stranger, an untrusted message and a Service consent', function () {
    [$message] = simIngest([['from' => 'psid-1', 'text' => 'আমার অর্ডার কোথায়?', 'mid' => 'm-1', 'at' => '2026-09-13T10:00:00+06:00']]);

    $conversation = $message->conversation;
    $brand = Brand::where('code', 'LLD')->sole();
    expect($conversation->business_id)->toBe($brand->business_id)
        ->and($conversation->brand_id)->toBe($brand->id)
        ->and($conversation->platform_account_id)->toBe(simPage()->id)
        ->and($conversation->external_thread_ref)->toBe('sim-page:psid-1')
        ->and($conversation->state)->toBe(ConversationState::HumanRequired)
        ->and($conversation->owner)->toBe('human')
        ->and($conversation->language)->toBe('bn')
        ->and($conversation->customer_id)->not->toBeNull();

    expect($message->provenance->isTrusted())->toBeFalse()
        ->and($message->direction)->toBe('inbound')
        ->and($message->external_message_ref)->toBe('m-1')
        ->and($message->received_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-09-13T10:00:00+06:00')->getTimestamp())
        ->and($message->metadata['language'])->toBe('bn');

    $customer = $conversation->customer;
    expect($customer->primary_phone_canonical)->toBeNull()
        ->and($customer->attributes['stranger'])->toBeTrue()
        ->and($customer->attributes['arrived_via'])->toBe('messenger')
        ->and($conversation->participants()->where('participant_type', 'customer')->where('customer_id', $customer->id)->exists())->toBeTrue();

    // Writing to us is consent to be answered here, for the window, and to nothing else. Asked at an
    // instant inside the fixture's window, not at now(): the window is 24 hours from the message,
    // and the message is dated, so "now" walked out of it the day after this was written.
    $ledger = app(ConsentLedger::class);
    $inWindow = CarbonImmutable::parse('2026-09-13T10:05:00+06:00');
    expect($ledger->permittedAt($customer->id, $conversation->business_id, 'messenger', ConsentPurpose::Service, $inWindow)->permitted)->toBeTrue()
        ->and($ledger->permittedAt($customer->id, $conversation->business_id, 'messenger', ConsentPurpose::Marketing, $inWindow)->permitted)->toBeFalse()
        ->and($ledger->permittedAt($customer->id, $conversation->business_id, 'whatsapp', ConsentPurpose::Service, $inWindow)->permitted)->toBeFalse()
        ->and($ledger->permittedAt($customer->id, $conversation->business_id, 'messenger', ConsentPurpose::Service, $inWindow->addHours(25))->permitted)->toBeFalse();

    $event = DomainEvent::where('type', 'conversation.message_received')->sole();
    expect($event->aggregate_id)->toBe($conversation->uuid)
        ->and($event->payload['state'])->toBe('human_required')
        ->and($event->payload)->not->toHaveKey('body');
});

it('is idempotent on the provider message id and finds the same thread for the same page and sender', function () {
    simIngest([['from' => 'psid-2', 'text' => 'hi', 'mid' => 'm-2']]);
    simIngest([['from' => 'psid-2', 'text' => 'hi', 'mid' => 'm-2']]);
    simIngest([['from' => 'psid-2', 'text' => 'again', 'mid' => 'm-3']]);

    expect(ConversationMessage::count())->toBe(2)
        ->and(Conversation::count())->toBe(1)
        ->and(Customer::count())->toBe(1);
});

it('refuses a page HQ does not hold rather than guessing a business', function () {
    expect(fn () => simIngest([['from' => 'psid-3', 'text' => 'hello']], 'not-our-page'))->toThrow(UnknownPlatformAccount::class);
    expect(Conversation::count())->toBe(0)->and(Customer::count())->toBe(0);
});

it('recognises a person already linked by channel identity and does not make a second one', function () {
    $brand = Brand::where('code', 'LLD')->sole();
    $rina = Customer::create(['business_id' => $brand->business_id, 'name' => 'Rina', 'primary_phone_canonical' => '01711000009']);
    $rina->identities()->create(['channel' => 'messenger_psid', 'external_id' => 'psid-rina', 'confidence' => 1.0]);

    [$message] = simIngest([['from' => 'psid-rina', 'text' => 'same as last time please']]);

    expect($message->conversation->customer_id)->toBe($rina->id)->and(Customer::count())->toBe(1);
});

it('opens with the agent only when the support agent is active, and escalates on the deterministic triggers', function () {
    $this->seed(AgentSeeder::class);
    expect(Agent::where('key', 'support')->sole()->status)->toBe('paused');
    [$paused] = simIngest([['from' => 'psid-p', 'text' => 'Do you deliver to Sylhet?']]);
    expect($paused->conversation->state)->toBe(ConversationState::HumanRequired);

    Agent::where('key', 'support')->update(['status' => 'active']);
    [$plain] = simIngest([['from' => 'psid-a', 'text' => 'Do you deliver to Sylhet?']]);
    expect($plain->conversation->state)->toBe(ConversationState::AiActive)->and($plain->conversation->owner)->toBe('ai');

    foreach ([
        'psid-b' => ['I want to talk to a real person', 'the customer asked for a person'],
        'psid-c' => ['টাকা ফেরত চাই', 'the message is about money, a complaint or a damaged item'],
        'psid-d' => ['manager er sathe kotha bolte chai', 'the customer asked for a person'],
    ] as $psid => [$text, $reason]) {
        [$m] = simIngest([['from' => $psid, 'text' => $text]]);
        $c = $m->conversation->fresh();
        expect($c->state)->toBe(ConversationState::HumanRequired, $text);
        $event = DomainEvent::where('type', 'conversation.state_changed')->where('aggregate_id', $c->uuid)->latest('id')->first();
        expect($event->payload['reason'])->toBe($reason);
    }

    // A second inbound while the agent waits is a person's.
    [$first] = simIngest([['from' => 'psid-w', 'text' => 'Is the mango pickle in stock?']]);
    app(HandoffStateMachine::class)->transition($first->conversation, ConversationState::AiWaiting, null, 'agent answered');
    [$second] = simIngest([['from' => 'psid-w', 'text' => 'hello??']]);
    expect($second->conversation->fresh()->state)->toBe(ConversationState::HumanRequired);

    // The per-thread switch wins over an active agent.
    [$off] = simIngest([['from' => 'psid-off', 'text' => 'hi']]);
    $off->conversation->forceFill(['ai_disabled' => true])->save();
    app(HandoffStateMachine::class)->transition($off->conversation->fresh(), ConversationState::HumanRequired, null, 'switched off');
    [$offAgain] = simIngest([['from' => 'psid-off', 'text' => 'still here']]);
    expect($offAgain->conversation->fresh()->state)->toBe(ConversationState::HumanRequired);
});

it('moves only along the table, keeps owner and handoff_state in step, audits and emits every transition', function () {
    [$message] = simIngest([['from' => 'psid-sm', 'text' => 'hi']]);
    $conversation = $message->conversation;
    $machine = app(HandoffStateMachine::class);
    $person = $this->hqUser('owner')->id;

    expect(fn () => $machine->transition($conversation, ConversationState::AiActive))->toThrow(InvalidTransition::class);

    $machine->transition($conversation, ConversationState::HumanActive, $person, 'taken over');
    expect($conversation->fresh()->owner)->toBe('human')
        ->and($conversation->fresh()->handoff_state)->toBe('accepted')
        ->and($conversation->fresh()->assigned_user_id)->toBe($person);

    $machine->transition($conversation->fresh(), ConversationState::HumanResolved, $person, 'done');
    $machine->transition($conversation->fresh(), ConversationState::Closed, $person, 'bye');
    $closed = $conversation->fresh();
    expect($closed->status)->toBe('closed')->and($closed->closed_at)->not->toBeNull()->and($closed->owner)->toBe('none');
    expect(fn () => $machine->transition($closed, ConversationState::HumanRequired))->toThrow(InvalidTransition::class);

    expect(AuditLog::where('action', 'conversation.state_changed')->count())->toBe(3)
        ->and(DomainEvent::where('type', 'conversation.state_changed')->where('aggregate_id', $closed->uuid)->count())->toBe(3);

    // A person may take a thread from the agent at any time; the reverse never happens by itself.
    expect(ConversationState::AiActive->canMoveTo(ConversationState::HumanActive))->toBeTrue()
        ->and(ConversationState::HumanActive->canMoveTo(ConversationState::AiActive))->toBeFalse()
        ->and(ConversationState::HumanResolved->canMoveTo(ConversationState::HumanRequired))->toBeTrue();
});

it('tags the script the customer wrote in, ignoring order numbers and codes', function () {
    expect(Language::detect('আমার অর্ডার LLD-20260912-QW3 কোথায়?'))->toBe('bn')
        ->and(Language::detect('Order LLD-20260912-QW3 এখনো আসেনি, please check'))->toBe('banglish')
        ->and(Language::detect('vaiya amr order ta kothay?'))->toBe('en')
        ->and(Language::detect('01711-000000'))->toBeNull()
        ->and(Language::detect(''))->toBeNull()
        ->and(Language::normalise("\u{09DC}"))->toBe("\u{09A1}\u{09BC}"); // NFC: RRA is a composition exclusion, so it is DDA + nukta
});

it('replays the whole simulation world through the core and rolls it back', function () {
    $this->seed(AgentSeeder::class);
    $before = [Conversation::count(), Customer::count(), ConversationMessage::count(), DomainEvent::count(), AuditLog::count()];

    $results = app(ConversationSimulator::class)->replay(ScenarioLibrary::all(), 'LLD');

    $failed = array_filter($results, fn ($r) => ! $r->passed);
    expect(array_map(fn ($r) => $r->key.': '.implode('; ', $r->mismatches), $failed))->toBe([]);
    expect(count($results))->toBeGreaterThanOrEqual(21);

    // Traceless: nothing the replay wrote survives, the agent row is as it was.
    expect([Conversation::count(), Customer::count(), ConversationMessage::count(), DomainEvent::count(), AuditLog::count()])->toBe($before)
        ->and(Agent::where('key', 'support')->sole()->status)->toBe('paused')
        ->and(PlatformAccount::where('account_ref', 'sim-page-2')->exists())->toBeFalse();
});

it('exposes the simulation as a command that fails on any mismatch and refuses to keep on production', function () {
    $this->seed(AgentSeeder::class);
    $this->artisan('hq:conversation:simulate', ['--brand' => 'LLD'])->assertSuccessful();
    $this->artisan('hq:conversation:simulate', ['--brand' => 'LLD', '--only' => 'nope'])->assertFailed();
    $this->artisan('hq:conversation:simulate', ['--brand' => 'LLD', '--only' => 'injection.system_notice', '--json' => true])
        ->expectsOutputToContain('"passed": 1')
        ->assertSuccessful();
    expect(Conversation::count())->toBe(0);
});
