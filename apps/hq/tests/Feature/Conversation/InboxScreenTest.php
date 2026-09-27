<?php

use App\Conversation\Channels\SimulatedChannelAdapter;
use App\Conversation\ConversationService;
use App\Conversation\ConversationState;
use App\Conversation\Simulation\ScenarioLibrary;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\DecisionRecord;
use App\Models\EvaluationSuite;
use App\Models\PlatformAccount;
use App\Models\UserBusinessScope;
use App\Support\Navigation\NavBadges;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\SupportBehaviourSuiteSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 | Phase 3 stream 3 (docs/72 §C): the operator inbox. Scoped reads (404 outside the scope), the
 | handoff actions as state-machine transitions, the composer that only goes through the gateway,
 | the proposal decisions, the nav badge, and the support agent seeded paused with no enabling user.
 */

function inboxThread(string $psid = 'psid-inbox', string $text = 'আমার অর্ডার কোথায়?'): Conversation
{
    PlatformAccount::updateOrCreate(
        ['platform' => 'facebook_page', 'account_ref' => SimulatedChannelAdapter::DEFAULT_PAGE],
        ['brand_id' => Brand::where('code', 'LLD')->sole()->id, 'name' => 'Simulated', 'approval_status' => 'unknown', 'is_active' => true],
    );
    $service = app(ConversationService::class);
    $conversation = null;
    foreach ((new SimulatedChannelAdapter)->normalise(['messages' => [['from' => $psid, 'text' => $text, 'mid' => 'm-'.Str::uuid()]]]) as $inbound) {
        $conversation = $service->ingest($inbound)->conversation;
    }

    return $conversation->fresh();
}

function supportProposal(Conversation $conversation, string $text = 'আপনার অর্ডারটি আগামীকাল পৌঁছাবে।'): AgentRun
{
    return AgentRun::create([
        'uuid' => (string) Str::uuid(),
        'agent_key' => 'support',
        'correlation_id' => $conversation->uuid,
        'trigger' => 'conversation',
        'business_id' => $conversation->business_id,
        'question' => 'Answer the customer on this thread.',
        'autonomy_level' => AutonomyLevel::A0->value,
        'autonomy_reason' => 'no autonomy grant matches this combination',
        'shadow' => true,
        'status' => 'answered',
        'answer' => 'Proposed one reply.',
        'evidence' => [],
        'proposals' => [['kind' => 'proposal', 'tool' => 'messaging.send_reply', 'args' => ['conversation_uuid' => $conversation->uuid, 'text' => $text], 'why' => 'the order facts say it ships today', 'reason' => 'A0']],
        'context_provenance' => [],
        'turns' => 2,
        'tool_calls_count' => 1,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
});

it('shows the inbox to a permitted operator, a support agent, and refuses a finance viewer', function () {
    $thread = inboxThread();

    $this->actingAsHq($this->owner)->get('/inbox')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/index', false)
            ->has('threads', 1)
            ->where('threads.0.uuid', $thread->uuid)
            ->where('threads.0.state', 'human_required')
            ->where('threads.0.stranger', true)
            ->where('threads.0.language', 'bn')
            ->where('counts.needs_person', 1)
            ->where('agent.active', false)
            ->where('can.manage', true));

    $this->actingAsHq($this->hqUser('support_agent'))->get('/inbox')->assertOk();
    $this->actingAsHq($this->hqUser('finance_viewer'))->get('/inbox')->assertForbidden();
    $this->actingAsHq($this->hqUser('finance_viewer'))->get("/inbox/{$thread->uuid}")->assertForbidden();
});

it('shows a thread with every message labelled by provenance, and 404s a thread outside the user\'s business scope', function () {
    $thread = inboxThread();
    app(ConversationService::class)->recordInternal($thread, 'called them', ['user_id' => $this->owner->id]);

    $this->actingAsHq($this->owner)->get("/inbox/{$thread->uuid}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/show', false)
            ->where('thread.uuid', $thread->uuid)
            ->where('thread.allowed_next', ['human_active', 'closed'])
            ->has('messages', 2)
            ->where('messages.0.provenance', 'customer')
            ->where('messages.0.trusted', false)
            ->where('messages.1.direction', 'internal')
            ->where('messages.1.author', $this->owner->email)
            ->where('proposals', []));

    $elsewhere = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => Business::where('slug', 'bionic')->sole()->id, 'access' => 'full']);
    $this->actingAsHq($elsewhere)->get("/inbox/{$thread->uuid}")->assertNotFound();
    $this->actingAsHq($elsewhere)->post("/inbox/{$thread->uuid}/take-over")->assertNotFound();
    $this->actingAsHq($elsewhere)->get('/inbox')->assertOk()->assertInertia(fn ($page) => $page->has('threads', 0));
});

it('moves a thread through a person\'s hands with audited transitions, and refuses moves the machine does not allow', function () {
    $thread = inboxThread();

    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/resolve")->assertSessionHasErrors('state'); // not taken over yet
    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/take-over")->assertRedirect();
    expect($thread->fresh()->state)->toBe(ConversationState::HumanActive)->and($thread->fresh()->assigned_user_id)->toBe($this->owner->id);

    // The agent is paused, so there is nobody to hand back to.
    $this->seed(AgentSeeder::class);
    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/return-to-agent")->assertSessionHasErrors('state');

    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/resolve", ['note' => 'told them it ships today'])->assertRedirect();
    expect($thread->fresh()->state)->toBe(ConversationState::HumanResolved);

    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/close", ['reason' => 'done'])->assertRedirect();
    expect($thread->fresh()->status)->toBe('closed');

    expect(AuditLog::where('action', 'conversation.state_changed')->where('actor_id', $this->owner->id)->count())->toBe(3);
});

it('assigns only a colleague who may work the thread, switches the agent off per thread, and records a note', function () {
    $thread = inboxThread();
    $colleague = $this->hqUser('support_agent');
    $viewer = $this->hqUser('finance_viewer');

    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/assign", ['user_id' => $viewer->id])->assertSessionHasErrors('user_id');
    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/assign", ['user_id' => $colleague->id])->assertRedirect();
    expect($thread->fresh()->assigned_user_id)->toBe($colleague->id);

    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/toggle-ai")->assertRedirect();
    expect($thread->fresh()->ai_disabled)->toBeTrue()
        ->and(AuditLog::where('action', 'conversation.ai_toggled')->count())->toBe(1);

    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/note", ['body' => 'customer called back'])->assertRedirect();
    $note = ConversationMessage::where('direction', 'internal')->sole();
    expect($note->body)->toBe('customer called back')->and($note->user_id)->toBe($this->owner->id);
});

it('sends the composer only through the gateway, which refuses it for lack of a provider, and keeps the draft as a note when asked', function () {
    $thread = inboxThread();
    $this->artisan('hq:tools:sync')->assertSuccessful();

    $this->actingAsHq($this->owner)
        ->post("/inbox/{$thread->uuid}/reply", ['text' => 'আপনার অর্ডার আজ পাঠানো হচ্ছে।', 'keep_as_note' => true])
        ->assertRedirect()
        ->assertSessionHas('status', 'reply-not-sent');

    expect(ConversationMessage::where('direction', 'outbound')->count())->toBe(0);
    $draft = ConversationMessage::where('direction', 'internal')->sole();
    expect($draft->body)->toStartWith('[draft reply, not sent]');
    expect(session('outcome.error'))->toContain('No messaging provider is registered');
});

it('lets a person decide an agent proposal: discard records the decision and closes the run; send is refused and the proposal stays open', function () {
    $thread = inboxThread();
    $this->artisan('hq:tools:sync')->assertSuccessful();
    $run = supportProposal($thread);

    $this->actingAsHq($this->owner)->get("/inbox/{$thread->uuid}")
        ->assertInertia(fn ($page) => $page->has('proposals', 1)->where('proposals.0.run_id', $run->id)->where('proposals.0.text', 'আপনার অর্ডারটি আগামীকাল পৌঁছাবে।'));

    $this->actingAsHq($this->owner)
        ->post("/inbox/{$thread->uuid}/proposals/{$run->id}/decide", ['decision' => 'send', 'text' => 'আপনার অর্ডারটি কাল পৌঁছাবে।'])
        ->assertRedirect()
        ->assertSessionHas('status', 'reply-not-sent');
    expect($run->fresh()->reviewed_at)->toBeNull()
        ->and(DecisionRecord::where('context_type', 'conversation')->where('decision', 'send')->sole()->final['edited'])->toBeTrue();

    $this->actingAsHq($this->owner)
        ->post("/inbox/{$thread->uuid}/proposals/{$run->id}/decide", ['decision' => 'discard', 'reason' => 'too vague'])
        ->assertRedirect()
        ->assertSessionHas('status', 'proposal-discarded');
    expect($run->fresh()->reviewed_at)->not->toBeNull()
        ->and($run->fresh()->review_verdict)->toBe('discarded')
        ->and(DecisionRecord::where('context_type', 'conversation')->count())->toBe(2);

    $this->actingAsHq($this->owner)->get("/inbox/{$thread->uuid}")->assertInertia(fn ($page) => $page->where('proposals', []));
    $this->actingAsHq($this->owner)->post("/inbox/{$thread->uuid}/proposals/{$run->id}/decide", ['decision' => 'discard'])->assertSessionHasErrors('decision');

    // A run from another thread or another agent is not this thread's proposal.
    $other = inboxThread('psid-other');
    $this->actingAsHq($this->owner)->post("/inbox/{$other->uuid}/proposals/{$run->id}/decide", ['decision' => 'discard'])->assertNotFound();
});

it('counts the threads that need a person in the nav badge, scoped to the user', function () {
    inboxThread('psid-b1');
    inboxThread('psid-b2');

    expect(app(NavBadges::class)->for($this->owner)['inbox'])->toBe(2);

    $elsewhere = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => Business::where('slug', 'bionic')->sole()->id, 'access' => 'full']);
    expect(app(NavBadges::class)->for($elsewhere)['inbox'])->toBe(0);
    expect(app(NavBadges::class)->for($this->hqUser('finance_viewer')))->not->toHaveKey('inbox');
});

it('seeds the support agent paused at A0 with no enabling user, and its role suite apart from the executive\'s', function () {
    $this->seed(AgentSeeder::class);
    $this->seed(SupportBehaviourSuiteSeeder::class);

    $support = Agent::where('key', 'support')->sole();
    expect($support->status)->toBe('paused')
        ->and($support->enabling_user_id)->toBeNull()
        ->and($support->role_key)->toBe('support')
        ->and($support->allowed_tools)->toContain('messaging.send_reply', 'conversation.read_thread')
        ->and($support->allowed_tools)->not->toContain('erp.poll_overview', 'commerce.reconcile_facts');

    $suite = EvaluationSuite::where('key', SupportBehaviourSuiteSeeder::SUITE_KEY)->sole();
    expect($suite->role_key)->toBe('support')
        // The ten written cases (v2 since docs/90: each on a real thread), plus one per simulation scenario
        // from gate P3-G2 (docs/77 C.4); attempt 1's rows are retired beside them, never evaluated again.
        // docs/91 D: the written cases are versioned (support.v2.*, and support.v3.* where a later ruling re-versioned one).
        ->and($suite->cases()->where('key', 'like', 'support.v%')->where('is_active', true)->whereNull('retired_at')->count())->toBe(10)
        ->and($suite->cases()->where('is_active', true)->whereNull('retired_at')->count())->toBe(10 + count(ScenarioLibrary::all()))
        ->and($suite->cases()->where('key', 'support.customer_message_instructs_tool_call')->sole()->replaced_by_key)->toBe('support.v2.customer_message_instructs_tool_call')
        ->and($suite->cases()->where('key', 'support.v2.customer_message_instructs_tool_call')->sole()->expectation)
        ->toMatchArray(['no_tool_calls' => true, 'support_workflow' => 'silent_handover']);
});
