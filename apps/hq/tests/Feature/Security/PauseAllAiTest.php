<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Agents\Shadow\ShadowProbes;
use App\Ai\CircuitBreaker;
use App\Ai\Exceptions\AiPaused;
use App\Ai\ModelRuntime;
use App\Ai\ModelTier;
use App\Ai\Pause\AiPause;
use App\Ai\Pause\AiPauseSwitch;
use App\Ai\ProviderCheck;
use App\Ai\RoutingContext;
use App\Ai\TaskType;
use App\Ask\AnswerAskTurn;
use App\Ask\AskHq;
use App\Conversation\WakeUp\SupportWakeUp;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiRun;
use App\Models\Approval;
use App\Models\AskMessage;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\FeatureFlag;
use App\Models\JobRun;
use App\Models\ScheduledJob;
use App\Models\ShadowPeriod;
use App\Models\ShadowProbeRun;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Scheduling\Enums\RunStatus;
use App\Scheduling\Jobs\RunScheduledJob;
use App\Scheduling\Nl\NlScheduling;
use App\Scheduling\ScheduledJobService;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Ai\SecondFixtureProvider;
use Tests\Support\Execution\Contexts;
use Tests\Support\Tools\ModelCallingTool;

/*
 | docs/138 ruling 7 and section 3, P3-W4; docs/141. Pause All AI: a human-only, audited, group-wide switch
 | that can only turn AI execution off. Every test that wants to see a provider NOT called first proves the
 | same path does call it when AI is not paused, so a refusal is never a test that could not have failed.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);

    // An operator holds both permissions through '*', so every refusal of this person is the business restriction.
    $this->restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $this->restricted->id, 'business_id' => Business::orderBy('id')->firstOrFail()->id]);
});

function pauseAi(?User $by = null, string $reason = 'test pause'): void
{
    app(AiPauseSwitch::class)->pause($by ?? test()->owner, $reason);
}

function pausedReply(string $answer = 'ok', array $calls = []): mixed
{
    return FixtureProvider::reply((string) json_encode([
        'status' => $calls === [] ? AgentResponseSchema::STATUS_ANSWER : AgentResponseSchema::STATUS_NEED_EVIDENCE,
        'answer' => $answer, 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
        'tool_calls' => $calls, 'task' => 'the operator question', 'authority_request' => 'none',
    ]));
}

function askAgent(): AgentRun
{
    return app(AgentRunner::class)->run(new AgentRunRequest(
        agentKey: 'executive', question: 'How are we doing?', trigger: 'console', requester: Contexts::asEnabled(),
    ));
}

function pauseModelCall(): mixed
{
    return app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']],
        new RoutingContext(correlationId: (string) Str::uuid(), inputRef: 'test:pause'));
}

// ── The state: durable, read fresh, fail closed ─────────────────────────────────────────────────────

it('ships not paused, and a model call runs until a person pauses', function () {
    expect(app(AiPause::class)->state())->toMatchArray(['paused' => false, 'readable' => true]);

    FixtureProvider::script('fixture', [FixtureProvider::reply('hello')]);
    expect(pauseModelCall()->text)->toBe('hello')->and(FixtureProvider::callsTo('fixture'))->toBe(1);

    pauseAi();

    expect(fn () => pauseModelCall())->toThrow(AiPaused::class, 'Pause All AI is on');
    expect(FixtureProvider::callsTo('fixture'))->toBe(1)
        // Refused before routing: not even an ai_runs row is opened.
        ->and(AiRun::count())->toBe(1);
});

it('treats a missing row as paused (fail closed)', function () {
    DB::table('ai_pause_state')->delete();

    expect(app(AiPause::class)->state())->toMatchArray(['paused' => true, 'readable' => false])
        ->and(fn () => pauseModelCall())->toThrow(AiPaused::class, 'could not be read');
    expect(FixtureProvider::callsTo('fixture'))->toBe(0);

    $this->artisan('hq:ai:pause', ['--show' => true])->expectsOutputToContain('treated as PAUSED')->assertSuccessful();
});

it('treats a state it cannot read at all (a database error) as paused, and the caller can still record the refusal', function () {
    DB::statement('ALTER TABLE ai_pause_state RENAME TO ai_pause_state_gone');

    expect(app(AiPause::class)->isPaused())->toBeTrue();

    // The agent run is still written, with the reason, because the failed read took only its own savepoint.
    $run = askAgent();
    expect($run->status)->toBe('stopped')
        ->and($run->stop_reason)->toBe(AgentRunner::STOP_AI_PAUSED)
        ->and($run->error)->toContain('could not be read')
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0);

    DB::statement('ALTER TABLE ai_pause_state_gone RENAME TO ai_pause_state');
});

it('reads the state fresh on every call, never from a cache', function () {
    expect(app(AiPause::class)->isPaused())->toBeFalse();
    DB::table('ai_pause_state')->where('id', 1)->update(['paused' => true]);
    expect(app(AiPause::class)->isPaused())->toBeTrue();
    DB::table('ai_pause_state')->where('id', 1)->update(['paused' => false]);
    expect(app(AiPause::class)->isPaused())->toBeFalse();
});

// ── Agent runtime ────────────────────────────────────────────────────────────────────────────────────

it('stops an agent run before the first model turn when paused', function () {
    FixtureProvider::script('fixture', [pausedReply('fine')]);
    expect(askAgent()->status)->toBe('answered');
    expect(FixtureProvider::callsTo('fixture'))->toBe(1);

    pauseAi();
    $run = askAgent();

    expect($run->status)->toBe('stopped')
        ->and($run->stop_reason)->toBe(AgentRunner::STOP_AI_PAUSED)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(1);
});

it('runs no tool the model proposed when the pause lands while the model is answering', function () {
    // The provider is answering; a person pauses at that moment; the reply asks for a read tool.
    FixtureProvider::script('fixture', [
        function () {
            pauseAi(reason: 'stop now');

            return pausedReply('', [['tool' => 'facts.query_orders', 'args' => [], 'why' => 'count them']]);
        },
        pausedReply('never asked'),
    ]);

    $run = askAgent();

    expect($run->status)->toBe('stopped')
        ->and($run->stop_reason)->toBe(AgentRunner::STOP_AI_PAUSED)
        ->and($run->error)->toContain('facts.query_orders')
        // One model call, the one under way when the pause landed; no second turn, no tool call.
        ->and(FixtureProvider::callsTo('fixture'))->toBe(1)
        ->and(ToolCall::where('tool_key', 'facts.query_orders')->count())->toBe(0);
});

it('refuses at the gateway any tool call made as an agent while paused, and still runs a person\'s own call', function () {
    $agent = Agent::where('key', 'executive')->sole();
    $asAgent = fn () => new ToolRequest(toolKey: 'facts.query_orders', args: [], caller: CallerKind::Agent,
        identity: ActingIdentity::forExecution(Contexts::for($agent)), correlationId: (string) Str::uuid());
    $asPerson = fn () => new ToolRequest(toolKey: 'facts.query_orders', args: [], caller: CallerKind::HumanUi,
        identity: ActingIdentity::human($this->owner), correlationId: (string) Str::uuid());

    expect(app(ToolGateway::class)->execute($asAgent())->ok())->toBeTrue();

    pauseAi();

    $refused = app(ToolGateway::class)->execute($asAgent());
    expect($refused->ok())->toBeFalse()
        ->and((string) $refused->error)->toContain('Pause All AI is on')
        ->and((string) ToolCall::findOrFail($refused->toolCallId)->policy_reason)->toContain('kill_switch.ai_paused');
    // The read-only A0 proposal path asks the same policy and is refused the same way.
    expect(app(ToolGateway::class)->authorize($asAgent())->permitsProposal())->toBeFalse();

    // A person's own call is a human operation: the pause does not touch it.
    expect(app(ToolGateway::class)->execute($asPerson())->ok())->toBeTrue();
});

// ── Retries and uncertain state ──────────────────────────────────────────────────────────────────────

it('tries no fallback provider after a pause lands during a failing call', function () {
    aiModel('fixture2', 'second-model', ModelTier::Medium, priority: 20);
    FixtureProvider::script('fixture', [function () {
        pauseAi(reason: 'provider misbehaving');

        return FixtureProvider::outage();
    }]);
    FixtureProvider::script('fixture2', [FixtureProvider::reply('from the fallback')]);

    expect(fn () => pauseModelCall())->toThrow(AiPaused::class);
    expect(FixtureProvider::callsTo('fixture'))->toBe(1)
        ->and(SecondFixtureProvider::callsTo('fixture2'))->toBe(0);
});

it('makes no truncation retry and no repair once paused mid-call', function () {
    FixtureProvider::script('fixture', [function () {
        pauseAi();

        return FixtureProvider::truncated('{"cut');
    }]);
    expect(fn () => pauseModelCall())->toThrow(AiPaused::class);
    expect(FixtureProvider::callsTo('fixture'))->toBe(1);

    // The run row says what happened instead of looking like a provider fault.
    $row = AiRun::latest('id')->first();
    expect($row->status)->toBe('failed')->and($row->error)->toContain('AiPaused');
});

it('does not reset the circuit breaker or touch any provider when pausing', function () {
    $breaker = app(CircuitBreaker::class);
    foreach (range(1, CircuitBreaker::THRESHOLD) as $i) {
        $breaker->recordFailure('fixture');
    }
    $before = $breaker->state('fixture');

    pauseAi();

    expect($breaker->state('fixture'))->toBe($before)
        ->and($breaker->failures('fixture'))->toBe(CircuitBreaker::THRESHOLD);
});

it('sends no readiness request to a model provider while paused', function () {
    Http::fake();
    pauseAi();

    $result = app(ProviderCheck::class)->run();

    expect($result['class'])->toBe('capability_unavailable')
        ->and($result['error'])->toContain('Pause All AI');
    Http::assertNothingSent();
});

// ── Queued work ──────────────────────────────────────────────────────────────────────────────────────

it('refuses an Ask HQ answer that was queued before the pause, without asking the agent', function () {
    Queue::fake();
    FeatureFlag::updateOrCreate(['key' => AskHq::FLAG, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
    app(Features::class)->forget();

    $submitted = app(AskHq::class)->submit($this->owner, null, 'How are we doing?', 'executive', null);
    expect($submitted['reply']->status)->toBe(AskMessage::PENDING);
    Queue::assertPushed(AnswerAskTurn::class);

    pauseAi();
    app()->call([new AnswerAskTurn($submitted['reply']->id), 'handle']);

    $reply = $submitted['reply']->fresh();
    expect($reply->status)->toBe(AskMessage::REFUSED)
        ->and($reply->structured['reason'])->toBe('ai_paused')
        ->and(AgentRun::count())->toBe(0)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0);

    // A new question while paused is refused at once and queues nothing.
    $again = app(AskHq::class)->submit($this->owner, null, 'And now?', 'executive', null);
    expect($again['reply']->status)->toBe(AskMessage::REFUSED)
        ->and($again['reply']->structured['reason'])->toBe('ai_paused');
    Queue::assertPushed(AnswerAskTurn::class, 1);
});

it('wakes no support agent while paused', function () {
    metaFlag(true);
    $conversation = new Conversation(['business_id' => Business::orderBy('id')->value('id')]);

    expect(app(SupportWakeUp::class)->refusal($conversation, null))->not->toBe(SupportWakeUp::SKIP_AI_PAUSED);
    pauseAi();
    expect(app(SupportWakeUp::class)->refusal($conversation, null))->toBe(SupportWakeUp::SKIP_AI_PAUSED);
});

it('reads no sentence into a job while paused, and the form still works', function () {
    FeatureFlag::updateOrCreate(['key' => NlScheduling::FLAG, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
    app(Features::class)->forget();
    pauseAi();

    $out = app(NlScheduling::class)->preview($this->owner, 'Every morning at 9, poll the ERP');

    expect($out['token'])->toBeNull()
        ->and($out['questions'][0])->toContain('Pause All AI')
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0)
        ->and(AuditLog::where('action', 'scheduler.nl_refused')->latest('id')->first()->after['reason'])->toBe('ai_paused');
});

// ── Scheduler activity ───────────────────────────────────────────────────────────────────────────────

it('lets a scheduled tool reach no provider after a pause, retries included, and leaves the job as it was', function () {
    Queue::fake();
    app(ToolRegistry::class)->register(ModelCallingTool::class);
    app(ToolAvailability::class)->forget();
    $job = app(ScheduledJobService::class)->create([
        'name' => 'model job', 'kind' => 'cron', 'cron_expression' => '0 4 * * *', 'tool_key' => 'test.model_calling', 'payload' => [],
        'retry_max' => 2,
    ], $this->owner);
    $runOnce = function (ScheduledJob $job, ?JobRun $run = null): JobRun {
        $run ??= JobRun::create(['scheduled_job_id' => $job->id, 'scheduled_for' => CarbonImmutable::now()->addSeconds(random_int(1, 99999)),
            'trigger' => 'run_now', 'attempt' => 0, 'status' => RunStatus::Queued, 'correlation_id' => (string) Str::uuid()]);
        app()->call([new RunScheduledJob($run->id), 'handle']);

        return $run->fresh();
    };

    FixtureProvider::script('fixture', [FixtureProvider::reply('summary')]);
    expect($runOnce($job)->status)->toBe(RunStatus::Succeeded)->and(FixtureProvider::callsTo('fixture'))->toBe(1);

    $stateBefore = [$job->fresh()->state, $job->fresh()->approval_state];
    pauseAi();

    $failed = $runOnce($job);
    expect($failed->status)->toBe(RunStatus::Failed)
        ->and((string) $failed->error)->toContain('Pause All AI');

    // The scheduler's own retry of the same run, after the pause, is refused the same way.
    $retried = $runOnce($job, $failed);
    expect($retried->attempt)->toBe(2)->and((string) $retried->error)->toContain('Pause All AI');

    expect(FixtureProvider::callsTo('fixture'))->toBe(1)
        // A pause cancels and pauses nothing: the job is exactly as it was.
        ->and([$job->fresh()->state, $job->fresh()->approval_state])->toBe($stateBefore);
});

it('asks no shadow probe while paused and records the slot as skipped with the reason', function () {
    $executive = Agent::where('key', 'executive')->sole();
    $period = ShadowPeriod::create([
        'uuid' => (string) Str::uuid(), 'agent_id' => $executive->id, 'agent_key' => 'executive',
        'autonomy_level' => 'a0', 'status' => ShadowPeriod::STATUS_OPEN, 'opened_at' => now(), 'planned_end_at' => now()->addDays(7),
        'opened_by_user_id' => $this->owner->id, 'opened_by_label' => 'test', 'reason' => 'test', 'evidence' => [], 'allowed_tools' => [],
        'questions' => [['key' => 'attention_today', 'question' => 'What needs my attention today?']], 'probes_required' => true,
    ]);
    pauseAi();

    $rows = app(ShadowProbes::class)->ask($period, 'morning', CarbonImmutable::now()->startOfDay(), true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe(ShadowProbeRun::SKIPPED)
        ->and($rows[0]->note)->toContain('Pause All AI')
        ->and(AgentRun::count())->toBe(0)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(0)
        // The period is left as it was: a pause closes nothing.
        ->and($period->fresh()->status)->toBe(ShadowPeriod::STATUS_OPEN);
});

// ── What a pause does not do ─────────────────────────────────────────────────────────────────────────

it('grants nothing, changes no consent, approval, flag, agent or job, and leaves human screens working', function () {
    $approval = Approval::create(['uuid' => (string) Str::uuid(), 'tool_key' => 'demo.request_approval', 'tool_version' => '1.0.0',
        'payload' => [], 'payload_hash' => str_repeat('a', 64), 'risk_tier' => 'r4', 'action_class' => 'approval_required',
        'reason' => 'test', 'status' => 'pending', 'sla_due_at' => now()->addHour(), 'expires_at' => now()->addDay()]);
    $snapshot = fn () => [
        'approvals' => DB::table('approvals')->orderBy('id')->get(['id', 'status', 'decided_by_user_id'])->toArray(),
        'flags' => DB::table('feature_flags')->orderBy('id')->get(['key', 'enabled'])->toArray(),
        'agents' => DB::table('agents')->orderBy('id')->get(['key', 'status', 'enabling_user_id'])->toArray(),
        'grants' => DB::table('autonomy_grants')->count(),
        'consent' => DB::table('consent_events')->count() + DB::table('consent_state')->count(),
        'permissions' => DB::table('model_has_permissions')->count() + DB::table('role_has_permissions')->count() + DB::table('model_has_roles')->count(),
        'jobs' => DB::table('scheduled_jobs')->orderBy('id')->get(['id', 'state', 'approval_state'])->toArray(),
    ];
    $before = $snapshot();

    $this->actingAsHq($this->owner)->post('/ai/pause', ['reason' => 'incident'])->assertRedirect();

    expect(app(AiPause::class)->isPaused())->toBeTrue()
        ->and($snapshot())->toEqual($before)
        ->and($approval->fresh()->status->value)->toBe('pending');

    // Human operations and auditability stay available.
    $this->actingAsHq($this->owner)->get('/approvals')->assertOk();
    $this->actingAsHq($this->owner)->get("/approvals/{$approval->id}")->assertOk();
    $this->actingAsHq($this->owner)->get('/audit')->assertOk();
    $this->actingAsHq($this->owner)->get('/observe/posture')->assertOk();
});

// ── Who may pause, and re-enabling ───────────────────────────────────────────────────────────────────

it('lets the owner pause with a reason, audited with actor, scope, before, after and reason', function () {
    $this->actingAsHq($this->owner)->post('/ai/pause', [])->assertSessionHasErrors('reason');
    $this->actingAsHq($this->owner)->post('/ai/pause', ['reason' => '   '])->assertSessionHasErrors('reason');
    expect(app(AiPause::class)->isPaused())->toBeFalse();

    $this->actingAsHq($this->owner)->post('/ai/pause', ['reason' => 'odd replies from the model'])->assertRedirect();

    $row = AuditLog::where('action', 'ai.paused')->sole();
    expect($row->actor_type)->toBe('human')
        ->and($row->actor_id)->toBe($this->owner->id)
        ->and($row->context)->toMatchArray(['scope' => 'group', 'permission' => 'hq.ai.pause', 'authority' => 'group-wide person'])
        ->and($row->before)->toBe(['paused' => false, 'readable' => true])
        ->and($row->after)->toBe(['paused' => true, 'readable' => true])
        ->and($row->reason)->toBe('odd replies from the model');

    expect(app(AiPause::class)->state())->toMatchArray(['paused' => true, 'paused_by_user_id' => $this->owner->id, 'pause_reason' => 'odd replies from the model']);
});

it('cannot re-enable through the pause route or the pause command, whatever is sent', function () {
    pauseAi();

    $this->actingAsHq($this->owner)->post('/ai/pause', ['reason' => 'again', 'paused' => false, 'enabled' => true, 'resume' => true])->assertRedirect();
    $this->artisan('hq:ai:pause', ['--by' => $this->owner->email, '--reason' => 'again'])->assertSuccessful();
    expect(app(AiPause::class)->isPaused())->toBeTrue();

    // Structurally: the only write the pause method makes is paused = true, and it takes no state argument.
    $params = array_map(fn (ReflectionParameter $p) => $p->getName(), (new ReflectionMethod(AiPauseSwitch::class, 'pause'))->getParameters());
    expect($params)->toBe(['by', 'reason']);
    $source = (string) file_get_contents(app_path('Ai/Pause/AiPauseSwitch.php'));
    $pauseBody = Str::between($source, 'public function pause(', 'public function resume(');
    expect($pauseBody)->toContain("'paused' => true")->not->toContain("'paused' => false");
    expect((string) file_get_contents(app_path('Http/Controllers/Admin/AiPauseController.php')))
        ->toMatch('/public function pause\(Request \$request\): RedirectResponse\s*\{[^}]*->pause\(/s');
});

it('needs the separate hq.ai.resume permission and group-wide authority to turn AI back on', function () {
    pauseAi();

    // Holds hq.ai.pause only: group-wide, enabled, and still refused the re-enable.
    $pauser = $this->hqUser('finance_viewer');
    $pauser->givePermissionTo('hq.ai.pause');
    $pauser = $pauser->fresh();

    $this->actingAsHq($pauser)->post('/ai/pause', ['reason' => 'still bad'])->assertRedirect();
    $this->actingAsHq($pauser)->post('/ai/resume', ['reason' => 'try'])->assertForbidden();
    $this->artisan('hq:ai:resume', ['--by' => $pauser->email, '--reason' => 'try'])->assertFailed();
    expect(fn () => app(AiPauseSwitch::class)->resume($pauser, 'try'))->toThrow(AuthorizationException::class, 'hq.ai.resume');
    expect(app(AiPause::class)->isPaused())->toBeTrue()
        ->and(AuditLog::where('action', 'ai.resumed')->count())->toBe(0);

    $this->actingAsHq($this->owner)->post('/ai/resume', [])->assertSessionHasErrors('reason');
    $this->actingAsHq($this->owner)->post('/ai/resume', ['reason' => 'fixed, checked the replies'])->assertRedirect();
    expect(app(AiPause::class)->isPaused())->toBeFalse();

    $row = AuditLog::where('action', 'ai.resumed')->sole();
    expect($row->actor_id)->toBe($this->owner->id)
        ->and($row->context)->toMatchArray(['scope' => 'group', 'permission' => 'hq.ai.resume'])
        ->and($row->before)->toBe(['paused' => true, 'readable' => true])
        ->and($row->after)->toBe(['paused' => false, 'readable' => true])
        ->and($row->reason)->toBe('fixed, checked the replies');

    // Re-enabling lifts the pause only: ai.runtime and the agents are where they were.
    expect(app(Features::class)->enabled('ai.runtime'))->toBeTrue();

    // Nothing to re-enable now.
    $this->actingAsHq($this->owner)->post('/ai/resume', ['reason' => 'again'])->assertSessionHasErrors('reason');
});

it('re-enables an unreadable state only by the distinct operation', function () {
    DB::table('ai_pause_state')->delete();
    expect(app(AiPause::class)->isPaused())->toBeTrue();

    $this->artisan('hq:ai:resume', ['--by' => $this->owner->email, '--reason' => 'row restored after a restore from backup'])->assertSuccessful();

    expect(app(AiPause::class)->state())->toMatchArray(['paused' => false, 'readable' => true]);
});

it('refuses a restricted operator who holds both permissions, on the web and the terminal, with no change and no audit row', function () {
    expect($this->restricted->hasPermissionTo('hq.ai.pause'))->toBeTrue()
        ->and($this->restricted->hasPermissionTo('hq.ai.resume'))->toBeTrue();

    $this->actingAsHq($this->restricted)->post('/ai/pause', ['reason' => 'try'])->assertForbidden();
    $this->artisan('hq:ai:pause', ['--by' => $this->restricted->email, '--reason' => 'try'])->assertFailed();
    expect(app(AiPause::class)->isPaused())->toBeFalse();

    pauseAi();
    $this->actingAsHq($this->restricted)->post('/ai/resume', ['reason' => 'try'])->assertForbidden();
    $this->artisan('hq:ai:resume', ['--by' => $this->restricted->email, '--reason' => 'try'])->assertFailed();
    expect(fn () => app(AiPauseSwitch::class)->pause($this->restricted, 'x'))->toThrow(AuthorizationException::class, 'limited to some businesses');

    expect(app(AiPause::class)->isPaused())->toBeTrue()
        ->and(AuditLog::where('action', 'ai.paused')->count())->toBe(1)
        ->and(AuditLog::where('action', 'ai.resumed')->count())->toBe(0);
});

it('refuses nobody named on the terminal, and people without the permission on the web', function () {
    $this->artisan('hq:ai:pause', ['--reason' => 'no name'])->assertFailed();
    $this->artisan('hq:ai:resume', ['--reason' => 'no name'])->assertFailed();

    $this->actingAsHq($this->hqUser('brand_manager'))->post('/ai/pause', ['reason' => 'try'])->assertForbidden();
    $this->actingAsHq($this->hqUser('support_agent'))->post('/ai/pause', ['reason' => 'try'])->assertForbidden();

    expect(app(AiPause::class)->isPaused())->toBeFalse()
        ->and(AuditLog::whereIn('action', ['ai.paused', 'ai.resumed'])->count())->toBe(0);
});

// ── Human-only: no agent, tool or automated path can reach the switch ───────────────────────────────

it('is not a tool and no agent, tool, gateway, workflow or scheduler class can reach the switch', function () {
    // No registered tool is gated by either permission, so no tool can be the switch.
    $registry = app(ToolRegistry::class);
    foreach (array_map(fn (string $key) => $registry->get($key), $registry->keys()) as $tool) {
        expect($tool->descriptor()->permission)->not->toBeIn([AiPauseSwitch::PAUSE_PERMISSION, AiPauseSwitch::RESUME_PERMISSION]);
        expect((string) file_get_contents((new ReflectionClass($tool))->getFileName()))->not->toContain('AiPauseSwitch');
    }

    // The writer is referenced only by the two human doors (the controller and the two commands) and the
    // read-only screen props that name its permission constants.
    $allowed = [
        'app/Ai/Pause/AiPauseSwitch.php',
        'app/Ai/Pause/AiPauseScreen.php',
        'app/Http/Controllers/Admin/AiPauseController.php',
        'app/Console/Commands/AiPause.php',
        'app/Console/Commands/AiResume.php',
    ];
    $writers = [];
    $tableWriters = [];
    foreach (File::allFiles(app_path()) as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        $source = $file->getContents();
        if (str_contains($source, 'AiPauseSwitch')) {
            $writers[] = $relative;
        }
        if (str_contains($source, 'ai_pause_state') || str_contains($source, 'AiPause::TABLE')) {
            $tableWriters[] = $relative;
        }
    }
    sort($writers);
    sort($allowed);
    expect($writers)->toBe($allowed);
    // Only the reader names the table (and the writer through the reader's constant).
    sort($tableWriters);
    expect($tableWriters)->toBe(['app/Ai/Pause/AiPause.php', 'app/Ai/Pause/AiPauseSwitch.php']);

    // Both methods take a person, never an agent or "the system".
    foreach (['pause', 'resume'] as $method) {
        $type = (new ReflectionMethod(AiPauseSwitch::class, $method))->getParameters()[0]->getType();
        expect((string) $type)->toBe(User::class);
    }
});

// ── Every entry point reads the state ────────────────────────────────────────────────────────────────

it('reads the pause at every AI execution entry point', function () {
    $entryPoints = [
        'app/Ai/ModelRuntime.php' => 2, // before routing, and before every provider request
        'app/Ai/ProviderCheck.php' => 1,
        'app/Agents/AgentRunner.php' => 2, // before the first turn, and before every proposed tool call
        'app/Gateway/Policy/PolicyEngine.php' => 1,
        'app/Ask/AskHq.php' => 2, // on submit, and again when the queued answer runs
        'app/Conversation/WakeUp/SupportWakeUp.php' => 1,
        'app/Agents/Shadow/ShadowProbes.php' => 1,
        'app/Scheduling/Nl/NlScheduling.php' => 1,
        'app/Briefing/BriefingComposer.php' => 1,
    ];
    foreach ($entryPoints as $file => $reads) {
        $source = (string) file_get_contents(base_path($file));
        expect(preg_match_all('/\$this->pause->(isPaused|assertRunning)\(/', $source))->toBe($reads, "{$file} reads Pause All AI {$reads} time(s)");
    }
});

// ── The screens ──────────────────────────────────────────────────────────────────────────────────────

it('tells every page the state, and gives a control only to a group-wide holder of the permission', function () {
    pauseAi(reason: 'checking a bad reply');

    $this->actingAsHq($this->owner)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('aiPause.paused', true)
            ->where('aiPause.reason', 'checking a bad reply')
            ->where('aiPause.paused_by', $this->owner->name)
            ->where('aiPause.can_pause', true)
            ->where('aiPause.can_resume', true));

    $this->actingAsHq($this->restricted)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('aiPause.paused', true)
            ->where('aiPause.can_pause', false)
            ->where('aiPause.can_resume', false)
            ->where('aiPause.refusal', fn ($r) => str_contains((string) $r, 'limited to some businesses')));

    // A person with neither permission sees the state and no refusal text either.
    $this->actingAsHq($this->hqUser('support_agent'))->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('aiPause.paused', true)->where('aiPause.can_pause', false)->where('aiPause.refusal', null));

    // The banner sits in the shell (every page), and the controls show from the server's answer.
    expect((string) file_get_contents(resource_path('js/layouts/app/app-sidebar-layout.tsx')))->toContain('<AiPauseBanner');
    $component = (string) file_get_contents(resource_path('js/components/ai-pause.tsx'));
    expect($component)->toContain('aiPause.can_pause')->toContain('aiPause.can_resume')->toContain('/ai/pause')->toContain('/ai/resume');
    expect((string) file_get_contents(resource_path('js/components/console/live-panel.tsx')))->toContain('<AiPauseControl');
    expect((string) file_get_contents(resource_path('js/pages/observe/posture.tsx')))->toContain('<AiPauseControl');
});
