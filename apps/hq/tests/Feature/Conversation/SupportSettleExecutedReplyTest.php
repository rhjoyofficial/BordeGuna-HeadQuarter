<?php

use App\Agents\AgentResponseSchema;
use App\Ai\ModelTier;
use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\HandoffStateMachine;
use App\Conversation\HandoverReason;
use App\Conversation\WakeUp\SupportWakeUp;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\AutonomyGrant;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DomainEvent;
use App\Models\FeatureFlag;
use App\Models\MessageDelivery;
use App\Models\PlatformAccount;
use App\Models\ToolCall;
use App\Models\User;
use App\Support\Events\Handlers\ConversationEventHandler;
use App\Support\Features\Features;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/101 §12 Q4 and docs/103 ruling §3 (S1): `SupportWakeUp::settle()` must settle a Support reply that was
 | ACTUALLY EXECUTED (A2 and above) as answered, not read it as "no proposal" and hand the thread over.
 |
 | The path is unreachable through `run()` today and stays so: ADR-024 caps every run whose context holds a
 | customer's words at A1, and execution starts at A2. So the executed-reply branch is exercised here by calling
 | settle() directly on a run whose record is exactly what the runner writes for an executed call (an `evidence`
 | record carrying the gateway's `tool_call_id`), with the gateway's `tool_calls` row beside it. The second half
 | of the file proves the A1 cap still holds, so this correction does not itself widen anything.
 */

const SE_TEXT = 'Vaiya hilsa pickle ta ki ekhon stock e ase? 500gm er dam koto?';

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

function seIngest(string $text = SE_TEXT, ?string $psid = null): ConversationMessage
{
    $message = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid ?? 'psid-'.Str::random(8), 'text' => $text, 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $message = app(ConversationService::class)->ingest($inbound);
    }

    return $message;
}

/** The gateway's row for a reply call, as the gateway writes it after execution. */
function seReplyCall(Conversation $conversation, string $status = 'succeeded', string $tool = 'messaging.send_reply', ?string $threadUuid = null): ToolCall
{
    return ToolCall::create([
        'tool_key' => $tool,
        'status' => $status,
        'input' => ['business_slug' => 'laladia', 'conversation_uuid' => $threadUuid ?? (string) $conversation->uuid, 'text' => 'Ji vaiya, stock e ase.'],
        'output' => [],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

/** A Support run on the thread, at the given level, carrying exactly the records given. */
function seRun(Conversation $conversation, ConversationMessage $message, array $records, string $level = 'a2', string $status = 'answered'): AgentRun
{
    return AgentRun::create([
        'uuid' => (string) Str::uuid(), 'agent_key' => 'support', 'correlation_id' => (string) Str::uuid(),
        'trigger' => SupportWakeUp::TRIGGER, 'question' => SupportWakeUp::QUESTION, 'autonomy_level' => $level,
        'status' => $status, 'shadow' => false, 'proposals' => $records,
        'business_id' => $conversation->business_id, 'conversation_message_uuid' => (string) $message->uuid,
    ]);
}

/** The record the runner writes for a call the gateway EXECUTED (AgentRunner::invoke, the evidence branch). */
function seExecutedRecord(ToolCall $call, string $tool = 'messaging.send_reply'): array
{
    return ['kind' => 'evidence', 'tool' => $tool, 'tool_call_id' => $call->id, 'args' => $call->input];
}

/** settle() is private: the A2+ branch is unreachable through run() while the A1 cap holds, so it is called directly. */
function seSettle(Conversation $conversation, ConversationMessage $message, AgentRun $run): void
{
    $method = new ReflectionMethod(SupportWakeUp::class, 'settle');
    $method->invoke(app(SupportWakeUp::class), $conversation->fresh(), $message, $run);
}

/** @return list<array{0: string, 1: string}> */
function seTransitions(Conversation $conversation): array
{
    return DomainEvent::where('type', 'conversation.state_changed')->where('aggregate_id', $conversation->uuid)->orderBy('id')->get()
        ->map(fn (DomainEvent $e) => [$e->payload['from'], $e->payload['to']])->all();
}

function seHandoverReason(): ?HandoverReason
{
    $row = AuditLog::where('action', 'conversation.state_changed')->latest('id')->first();

    return $row === null ? null : HandoverReason::fromText((string) $row->reason);
}

// ── An executed reply is an answered thread ─────────────────────────────────────────────────────────

it('settles an EXECUTED reply as answered: ai_active -> ai_waiting, reply_sent recorded, no hand-over and no ping', function () {
    $message = seIngest();
    $conversation = $message->conversation()->sole();
    expect($conversation->state)->toBe(ConversationState::AiActive);

    $call = seReplyCall($conversation);
    $run = seRun($conversation, $message, [seExecutedRecord($call)]);

    seSettle($conversation, $message, $run);

    $sent = DomainEvent::where('type', 'conversation.reply_sent')->sole();
    expect($conversation->fresh()->state)->toBe(ConversationState::AiWaiting)
        ->and(seTransitions($conversation))->toBe([['ai_active', 'ai_waiting']])
        ->and($sent->dedupe_key)->toBe("conversation:reply_sent:{$message->uuid}")
        ->and($sent->payload)->toMatchArray(['conversation_uuid' => $conversation->uuid, 'run_id' => $run->id, 'tool_call_id' => $call->id, 'autonomy_level' => 'a2'])
        // Not a proposal (nothing waits for a person) and never a hand-over.
        ->and(DomainEvent::where('type', 'conversation.reply_proposed')->count())->toBe(0)
        ->and(AuditLog::where('action', 'notification.raised')->count())->toBe(0);
});

it('takes a handed-back thread (ai_resumed) through ai_active to ai_waiting on an executed reply', function () {
    $person = $this->hqUser('owner')->id;
    $first = seIngest(SE_TEXT, 'psid-se-resumed');
    $conversation = $first->conversation()->sole();
    DomainEvent::query()->update(['status' => DomainEvent::STATUS_PROCESSED]);
    $machine = app(HandoffStateMachine::class);
    $machine->transition($conversation, ConversationState::HumanActive, $person, 'taken over');
    $machine->transition($conversation->fresh(), ConversationState::HumanResolved, $person, 'resolved');
    $second = seIngest('ar ekta jinish jante chai, ghee ase?', 'psid-se-resumed');
    expect($conversation->fresh()->state)->toBe(ConversationState::AiResumed);

    $run = seRun($conversation, $second, [seExecutedRecord(seReplyCall($conversation))]);
    seSettle($conversation, $second, $run);

    expect($conversation->fresh()->state)->toBe(ConversationState::AiWaiting)
        ->and(array_slice(seTransitions($conversation), -2))->toBe([['ai_resumed', 'ai_active'], ['ai_active', 'ai_waiting']]);
});

it('counts a reply the gateway REPLAYED (the same call, already done) as executed', function () {
    $message = seIngest();
    $conversation = $message->conversation()->sole();
    $run = seRun($conversation, $message, [seExecutedRecord(seReplyCall($conversation, 'replayed'))]);

    seSettle($conversation, $message, $run);

    expect($conversation->fresh()->state)->toBe(ConversationState::AiWaiting)
        ->and(DomainEvent::where('type', 'conversation.reply_sent')->count())->toBe(1);
});

it('lets an executed reply win over a proposal on the same run, and records it once whatever settles it again', function () {
    $message = seIngest();
    $conversation = $message->conversation()->sole();
    $call = seReplyCall($conversation);
    $run = seRun($conversation, $message, [
        ['kind' => 'proposal', 'tool' => 'messaging.send_reply', 'args' => ['text' => 'a proposal'], 'authorization' => 'authorized'],
        seExecutedRecord($call),
    ]);

    seSettle($conversation, $message, $run);
    seSettle($conversation, $message, $run);

    expect($conversation->fresh()->state)->toBe(ConversationState::AiWaiting)
        ->and(DomainEvent::where('type', 'conversation.reply_sent')->count())->toBe(1)
        ->and(DomainEvent::where('type', 'conversation.reply_proposed')->count())->toBe(0);
});

// ── Verification: the record alone is never trusted ────────────────────────────────────────────────

it('hands the thread to a person when a reply was attempted and its delivery is UNCERTAIN, saying so', function () {
    $message = seIngest();
    $conversation = $message->conversation()->sole();
    $call = seReplyCall($conversation, 'uncertain');
    $run = seRun($conversation, $message, [
        ['kind' => 'failed', 'tool' => 'messaging.send_reply', 'reason' => 'timed out', 'tool_call_id' => $call->id, 'outcome_class' => 'uncertain', 'side' => 'external'],
    ]);

    seSettle($conversation, $message, $run);

    $reason = (string) AuditLog::where('action', 'conversation.state_changed')->latest('id')->first()->reason;
    expect($conversation->fresh()->state)->toBe(ConversationState::HumanRequired)
        ->and(seHandoverReason())->toBe(HandoverReason::AgentCannotServe)
        ->and($reason)->toContain('uncertain')->toContain('tool call #'.$call->id)
        ->and(DomainEvent::where('type', 'conversation.reply_sent')->count())->toBe(0);
});

it('does not trust an executed-looking record the gateway\'s row does not confirm, and hands the thread to a person', function (Closure $records) {
    $message = seIngest();
    $conversation = $message->conversation()->sole();
    $run = seRun($conversation, $message, $records($conversation));

    seSettle($conversation, $message, $run);

    expect($conversation->fresh()->state)->toBe(ConversationState::HumanRequired)
        ->and(seHandoverReason())->toBe(HandoverReason::AgentCannotServe)
        ->and(DomainEvent::where('type', 'conversation.reply_sent')->count())->toBe(0)
        ->and(DomainEvent::where('type', 'conversation.reply_proposed')->count())->toBe(0);
})->with([
    'the row failed' => [fn (Conversation $c) => [seExecutedRecord(seReplyCall($c, 'failed'))]],
    'the row was refused' => [fn (Conversation $c) => [seExecutedRecord(seReplyCall($c, 'denied'))]],
    'no row at all' => [fn (Conversation $c) => [['kind' => 'evidence', 'tool' => 'messaging.send_reply', 'tool_call_id' => 999999, 'args' => []]]],
    'no tool_call_id' => [fn (Conversation $c) => [['kind' => 'evidence', 'tool' => 'messaging.send_reply', 'args' => []]]],
    'the row is another tool' => [fn (Conversation $c) => [seExecutedRecord(seReplyCall($c, 'succeeded', 'conversation.read_thread'))]],
    'the row names another thread' => [fn (Conversation $c) => [seExecutedRecord(seReplyCall($c, 'succeeded', 'messaging.send_reply', (string) Str::uuid()))]],
    'evidence from a read, not the reply' => [fn (Conversation $c) => [seExecutedRecord(seReplyCall($c, 'succeeded', 'conversation.read_thread'), 'conversation.read_thread')]],
]);

it('keeps the proposal path and the no-proposal path exactly as before', function () {
    $message = seIngest();
    $conversation = $message->conversation()->sole();
    seSettle($conversation, $message, seRun($conversation, $message, [
        ['kind' => 'proposal', 'tool' => 'messaging.send_reply', 'args' => ['text' => 'x'], 'authorization' => 'authorized'],
    ], 'a0'));
    expect($conversation->fresh()->state)->toBe(ConversationState::AiWaiting)
        ->and(DomainEvent::where('type', 'conversation.reply_proposed')->count())->toBe(1);

    $other = seIngest();
    $thread = $other->conversation()->sole();
    seSettle($thread, $other, seRun($thread, $other, [], 'a0', 'answered'));
    expect($thread->fresh()->state)->toBe(ConversationState::HumanRequired)
        ->and(seHandoverReason())->toBe(HandoverReason::AgentCannotServe);
});

it('registers conversation.reply_sent on the conversation handler, so the event is never an unhandled row', function () {
    expect(config('hq.events.handlers')['conversation.reply_sent'] ?? null)->toBe([ConversationEventHandler::class]);
});

// ── The A1 cap still holds: this correction widens nothing ─────────────────────────────────────────

it('still caps every Support wake-up run at A1 under a grant and an agent ceiling of A3, and executes nothing', function () {
    AutonomyGrant::create(['uuid' => (string) Str::uuid(), 'name' => 's1 cap test', 'agent_key' => 'support', 'max_level' => 'a3', 'status' => 'active']);
    Agent::where('key', 'support')->update(['max_autonomy_level' => 'a3']);

    FixtureProvider::script('fixture', [
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_NEED_EVIDENCE, 'answer' => '', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [['tool' => 'messaging.send_reply', 'args' => ['business_slug' => 'laladia', 'conversation_uuid' => 'from-the-header', 'text' => 'Ji vaiya, stock e ase.'], 'why' => 'the reply']],
            'task' => 'answer the customer on this thread', 'authority_request' => 'none',
        ])),
        FixtureProvider::reply((string) json_encode([
            'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Proposed a reply.', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
            'tool_calls' => [], 'task' => 'answer the customer on this thread', 'authority_request' => 'none',
        ])),
    ]);
    Agent::where('key', 'support')->update(['allowed_tools' => ['conversation.read_thread', 'messaging.send_reply']]);

    $message = seIngest();
    $conversation = $message->conversation()->sole();
    $this->artisan('hq:events:process')->assertSuccessful();

    $run = AgentRun::sole();
    expect($run->autonomy_level)->toBe(AutonomyLevel::A1)
        ->and($run->autonomy_level->allowsExecution())->toBeFalse()
        ->and(collect((array) $run->autonomy_constraints)->pluck('source')->all())->toContain('context')
        // The reply is a proposal; nothing reached the gateway's execution and nothing left HQ.
        ->and(collect((array) $run->proposals)->where('kind', 'proposal')->pluck('tool')->all())->toBe(['messaging.send_reply'])
        ->and(collect((array) $run->proposals)->where('kind', 'evidence')->where('tool', 'messaging.send_reply')->count())->toBe(0)
        ->and(ToolCall::where('tool_key', 'messaging.send_reply')->count())->toBe(0)
        ->and(MessageDelivery::count())->toBe(0)
        ->and(ConversationMessage::where('direction', 'outbound')->count())->toBe(0)
        // So the thread settles through the proposal path, never the executed one.
        ->and($conversation->fresh()->state)->toBe(ConversationState::AiWaiting)
        ->and(DomainEvent::where('type', 'conversation.reply_proposed')->count())->toBe(1)
        ->and(DomainEvent::where('type', 'conversation.reply_sent')->count())->toBe(0);
});
