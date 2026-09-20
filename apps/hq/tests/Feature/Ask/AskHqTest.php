<?php

use App\Agents\AgentResponseSchema;
use App\Ai\ModelTier;
use App\Ask\AnswerAskTurn;
use App\Ask\AskHq;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AskConversation;
use App\Models\AskMessage;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Features\Features;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/78 B.3, docs/48 K: Ask HQ, built inert behind the flag `ask_hq`. It asks nothing while the AI
 | runtime is off, lends no agent's reach to someone who lacks it (ADR-047: the run is narrowed to the
 | asker, not refused to them), and takes no tool from a browser.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    aiProviders();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
});

function askHqFlag(bool $on = true): void
{
    FeatureFlag::updateOrCreate(['key' => AskHq::FLAG, 'environment' => '*'], ['enabled' => $on, 'rollout' => null]);
    app(Features::class)->forget();
}

function askHqReply(string $answer): mixed
{
    return FixtureProvider::reply((string) json_encode([
        'status' => AgentResponseSchema::STATUS_ANSWER,
        'answer' => $answer,
        'refusal_reason' => '',
        'facts' => [],
        'inferences' => [],
        'tool_calls' => [],
        'task' => 'the operator question',
        'authority_request' => 'none',
    ]));
}

it('does not exist while its flag is off', function () {
    $this->actingAsHq($this->owner)->get('/ask')->assertNotFound();
    $this->actingAsHq($this->owner)->post('/ask', ['question' => 'How are we doing?', 'agent_key' => 'executive'])->assertNotFound();

    expect(AskConversation::count())->toBe(0)->and(AskMessage::count())->toBe(0);
});

it('is behind hq.ask.use, and only the owner and operator roles hold it', function () {
    askHqFlag();

    $route = Route::getRoutes()->getByName('ask.store');
    expect($route->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.ask.use', 'throttle:20,1');

    $this->actingAsHq($this->owner)->get('/ask')->assertOk()
        ->assertInertia(fn ($page) => $page->component('ask/index')
            ->where('runtime_on', false)
            ->where('nav.features.ask_hq', true)
            ->where('nav.features.scheduler_nl', false)
            ->where('agents', [['key' => 'executive', 'refusal' => null], ['key' => 'analytics', 'refusal' => null]]));

    foreach (['brand_manager', 'support_agent', 'finance_viewer'] as $role) {
        $this->actingAsHq($this->hqUser($role))->get('/ask')->assertForbidden();
    }
});

it('records the question and asks nothing while the AI runtime is off', function () {
    askHqFlag();

    $this->actingAsHq($this->owner)->post('/ask', ['question' => 'What needs my attention today?', 'agent_key' => 'executive'])
        ->assertRedirect()->assertSessionHas('status', 'ask-refused');

    $conversation = AskConversation::sole();
    $messages = $conversation->messages()->get();

    expect($conversation->user_id)->toBe($this->owner->id)
        ->and($conversation->title)->toBe('What needs my attention today?')
        ->and($messages->pluck('role')->all())->toBe(['user', 'system'])
        ->and($messages[1]->status)->toBe(AskMessage::REFUSED)
        ->and($messages[1]->structured['reason'])->toBe('ai_runtime_off')
        ->and(AgentRun::count())->toBe(0)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0)
        ->and(AuditLog::where('action', 'ask.refused')->count())->toBe(1);
});

it('puts the question to the agent through the runner and records the answer and its run', function () {
    askHqFlag();
    enableAiRuntime();
    FixtureProvider::script('fixture', [askHqReply('Two orders arrived yesterday.')]);

    $this->actingAsHq($this->owner)->post('/ask', ['question' => 'How did the group do yesterday?', 'agent_key' => 'executive'])
        ->assertRedirect()->assertSessionHas('status', 'ask-submitted');

    $reply = AskMessage::where('role', 'assistant')->sole();
    $run = AgentRun::sole();

    expect($reply->status)->toBe(AskMessage::DONE)
        ->and($reply->content)->toBe('Two orders arrived yesterday.')
        ->and($reply->agent_run_id)->toBe($run->id)
        ->and($reply->correlation_id)->toBe($run->correlation_id)
        ->and($reply->structured['shadow'])->toBeTrue()
        ->and($reply->structured['autonomy_level'])->toBe('a0')
        ->and($run->trigger)->toBe('operator')
        ->and($run->triggered_by_user_id)->toBe($this->owner->id)
        ->and($run->question)->toBe('How did the group do yesterday?')
        ->and(AuditLog::where('action', 'ask.submitted')->count())->toBe(1);

    $this->actingAsHq($this->owner)->get('/ask/'.AskConversation::sole()->uuid)->assertOk()
        ->assertInertia(fn ($page) => $page->component('ask/index')
            ->where('messages.1.run.id', $run->id)
            ->where('messages.1.run.shadow', true)
            ->where('messages.1.run.tool_calls', []));
});

it('takes no tool, arguments or model from the browser', function () {
    askHqFlag();
    enableAiRuntime();
    FixtureProvider::script('fixture', [askHqReply('Nothing to do.')]);

    $this->actingAsHq($this->owner)->post('/ask', [
        'question' => 'Hello',
        'agent_key' => 'executive',
        'tool_key' => 'commerce.reconcile_facts',
        'arguments' => ['store_slug' => 'laladia-com'],
        'tool_calls' => [['tool' => 'erp.poll_overview', 'args' => []]],
        'model' => 'some-expensive-model',
        'autonomy_level' => 'a3',
    ])->assertRedirect();

    expect(ToolCall::count())->toBe(0)
        ->and(AgentRun::sole()->autonomy_level->value)->toBe('a0')
        ->and(FixtureProvider::requestsTo('fixture')[0]->model->model_id)->toBe('fixture-model');

    // The question post validates exactly four fields, and nothing else in the controller reaches a tool or a model.
    $source = file_get_contents(app_path('Http/Controllers/Ask/AskController.php'));
    preg_match('/function store\(.*?->validate\(\[(.*?)\]\);/s', $source, $rules);
    preg_match_all("/^\s*'([a-z_]+)' =>/m", $rules[1] ?? '', $fields);
    expect($fields[1])->toBe(['question', 'agent_key', 'business_id', 'conversation'])
        ->and($source)->not->toContain('ToolGateway')->not->toContain('ModelRuntime')->not->toContain('AgentRunner');
});

it('refuses the support agent, a stranger\'s conversation, a business outside the asker\'s rows, and an asker who shares nothing with the agent', function () {
    askHqFlag();

    $this->actingAsHq($this->owner)->post('/ask', ['question' => 'Hi', 'agent_key' => 'support'])
        ->assertSessionHasErrors('agent_key');

    $mine = app(AskHq::class)->submit($this->owner, null, 'First', 'executive', null)['conversation'];
    $other = $this->hqUser('operator');
    $this->actingAsHq($other)->get("/ask/{$mine->uuid}")->assertNotFound();
    $this->actingAsHq($other)->post('/ask', ['question' => 'Mine now', 'agent_key' => 'executive', 'conversation' => $mine->uuid])
        ->assertNotFound();

    // ADR-047 replaced the old rule ("open only to operators who see every business"): the run itself
    // is narrowed to the asker now, so a business-restricted operator may ask. What is still refused
    // at the door is a business outside the asker's own rows.
    $business = Business::firstOrFail();
    $elsewhere = Business::where('id', '!=', $business->id)->firstOrFail();
    UserBusinessScope::create(['user_id' => $other->id, 'business_id' => $business->id]);
    Cache::flush();
    expect(app(AskHq::class)->refusalFor($other, 'executive'))->toBeNull();
    $this->actingAsHq($other)->post('/ask', ['question' => 'Hi', 'agent_key' => 'executive', 'business_id' => $elsewhere->id])
        ->assertSessionHasErrors(['question' => 'You cannot ask about that business.']);

    // Someone holding hq.ask.use and none of what the agent's tools need: the intersection leaves the
    // agent nothing to work with, and the resolver's own sentence says so.
    $narrow = User::factory()->create();
    $narrow->givePermissionTo('hq.ask.use');
    expect(app(AskHq::class)->refusalFor($narrow, 'executive'))->toContain('hold in common none of the permissions');

    expect(AskMessage::where('role', 'user')->count())->toBe(1);
});

it('records a failure the runner refuses, and a runtime switched off before the answer', function () {
    askHqFlag();
    enableAiRuntime();
    Agent::where('key', 'executive')->update(['status' => 'paused']);

    app(AskHq::class)->submit($this->owner, null, 'Anything new?', 'executive', null);
    $reply = AskMessage::where('role', 'assistant')->sole();
    expect($reply->status)->toBe(AskMessage::FAILED)
        ->and($reply->content)->toBe('Agent [executive] is [paused], so it will not run.');

    Agent::where('key', 'executive')->update(['status' => 'active']);
    Queue::fake();
    $pending = app(AskHq::class)->submit($this->owner, null, 'And now?', 'executive', null)['reply'];
    Queue::assertPushed(AnswerAskTurn::class, fn ($job) => $job->replyId === $pending->id);
    expect($pending->fresh()->status)->toBe(AskMessage::PENDING);

    FeatureFlag::where('key', 'ai.runtime')->update(['enabled' => false]);
    app(Features::class)->forget();
    (new AnswerAskTurn($pending->id))->handle(app(AskHq::class));

    expect($pending->fresh()->status)->toBe(AskMessage::REFUSED)
        ->and($pending->fresh()->role)->toBe('system')
        ->and(AgentRun::count())->toBe(0);

    // Answering is once: a reply that is no longer pending is left as it is.
    expect(app(AskHq::class)->answer($pending->fresh())->status)->toBe(AskMessage::REFUSED);
});

it('keeps each operator\'s conversations to themselves', function () {
    askHqFlag();
    $other = $this->hqUser('operator');
    app(AskHq::class)->submit($this->owner, null, 'Owner question', 'executive', null);
    app(AskHq::class)->submit($other, null, 'Operator question', 'analytics', null);

    $this->actingAsHq($this->owner)->get('/ask')
        ->assertInertia(fn ($page) => $page->where('conversations', fn ($rows) => collect($rows)->pluck('title')->all() === ['Owner question']));
});
