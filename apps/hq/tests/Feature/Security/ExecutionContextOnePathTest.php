<?php

use App\Agents\AgentEvaluationSubject;
use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\Shadow\ShadowProbes;
use App\Ai\ModelTier;
use App\Execution\ExecutionContextRefused;
use App\Execution\ExecutionContextResolver;
use App\Execution\Requester;
use App\Gateway\CallerKind;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Models\Business;
use App\Models\EvaluationCase;
use App\Models\ScheduledJob;
use App\Models\ShadowPeriod;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserBusinessScope;
use App\Support\Authorization\BusinessScope;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Execution\Contexts;

/*
 | One path, and a guard that is reached (docs/80 point 3, ADR-047).
 |
 | The first half puts calls to the gateway DIRECTLY, with no runner in front, because "a guard
 | nothing can reach is not a guard": the catalogue hiding a tool proves nothing about the gateway.
 | The second half reads the sources, as the repository already does for the model-to-tool path
 | (docs/78 C.3), and holds that no entry point builds an agent's authority any other way.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->whereIn('key', ['executive', 'analytics'])->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    $this->executive = Agent::where('key', 'executive')->sole();
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

function onePathPerson(array $businessSlugs = [], ?array $permissions = null): User
{
    if ($permissions === null) {
        $user = test()->hqUser('operator');
    } else {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
    }
    foreach ($businessSlugs as $slug) {
        UserBusinessScope::create(['user_id' => $user->id, 'business_id' => Business::where('slug', $slug)->value('id'), 'access' => 'read']);
    }
    app(BusinessScope::class)->forget($user);

    return $user;
}

function onePathCall(ActingIdentity $identity, string $tool, array $args = [], ?int $brandId = null)
{
    return app(ToolGateway::class)->execute(new ToolRequest(
        toolKey: $tool, args: $args, caller: CallerKind::Agent, identity: $identity,
        correlationId: (string) Str::uuid(), brandId: $brandId,
    ));
}

function onePathAnswer(): mixed
{
    return FixtureProvider::reply((string) json_encode([
        'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Nothing needs you.', 'refusal_reason' => '', 'facts' => [],
        'inferences' => [], 'tool_calls' => [], 'task' => 'the operator question', 'authority_request' => 'none',
    ]));
}

// ── The gateway's own check, with no runner in front of it ──────────────────────────────────────

it('refuses at the gateway a business, a permission and a brand outside the context, whatever the agent was shown', function () {
    $asker = onePathPerson(['laladia'], ['hq.tools.erp.read', 'hq.tools.facts.read']);
    $identity = ActingIdentity::forExecution(Contexts::for($this->executive, requester: $asker));

    // Inside the intersection: it runs.
    expect(onePathCall($identity, 'erp.read_snapshot', ['business_slug' => 'laladia'])->ok())->toBeTrue();

    // The enabling owner may read Bionic. The asker may not. The gateway says no, and records it.
    $scope = onePathCall($identity, 'erp.read_snapshot', ['business_slug' => 'bionic']);
    expect($scope->status)->toBe(OutcomeStatus::Denied)
        ->and($scope->class)->toBe(OutcomeClass::ScopeDenied)
        ->and(ToolCall::findOrFail($scope->toolCallId)->status)->toBe('denied');

    // The enabling owner holds hq.jobs.view. The asker does not.
    $permission = onePathCall($identity, 'hq.list_jobs');
    expect($permission->status)->toBe(OutcomeStatus::Denied)
        ->and($permission->class)->toBe(OutcomeClass::PermissionDenied)
        ->and(ToolCall::findOrFail($permission->toolCallId)->policy_reason)->toStartWith('permission.denied');

    // A brand outside the agent's brand scope, on a business everybody may read.
    $own = Brand::where('slug', 'laladia')->sole();
    $other = Brand::create(['code' => 'LL2', 'slug' => 'laladia-second', 'name' => 'LaLaDia Second', 'business_id' => $this->laladia->id, 'is_active' => true]);
    $this->executive->update(['brand_scope' => [$own->id]]);
    $branded = ActingIdentity::forExecution(Contexts::for($this->executive->fresh(), requester: $asker));

    expect(onePathCall($branded, 'erp.read_snapshot', ['business_slug' => 'laladia'], $own->id)->ok())->toBeTrue();
    $brand = onePathCall($branded, 'erp.read_snapshot', ['business_slug' => 'laladia'], $other->id);
    expect($brand->status)->toBe(OutcomeStatus::Denied)
        ->and($brand->class)->toBe(OutcomeClass::ScopeDenied)
        ->and($brand->error)->toContain("not scoped to brand {$other->id}");
});

it('stops a run\'s calls the moment a person it rests on is disabled or removed, the agent is paused, or the agent changes hands', function (Closure $change, string $says) {
    $asker = onePathPerson(['laladia']);
    $identity = ActingIdentity::forExecution(Contexts::for($this->executive, requester: $asker));
    expect(onePathCall($identity, 'erp.read_snapshot', ['business_slug' => 'laladia'])->ok())->toBeTrue();

    $change($asker, $this->owner, $this->executive);

    $after = onePathCall($identity, 'erp.read_snapshot', ['business_slug' => 'laladia']);
    expect($after->status)->toBe(OutcomeStatus::Denied)->and($after->error)->toContain($says);
})->with([
    'the requester disabled mid-run' => [fn ($asker) => User::whereKey($asker->id)->update(['is_active' => false]), 'disabled or removed'],
    'the requester deleted mid-run' => [fn ($asker) => User::whereKey($asker->id)->delete(), 'disabled or removed'],
    'the enabling user disabled mid-run' => [fn ($asker, $owner) => User::whereKey($owner->id)->update(['is_active' => false]), 'disabled or removed'],
    'the agent paused mid-run' => [fn ($asker, $owner, $agent) => Agent::whereKey($agent->id)->update(['status' => 'paused']), 'no longer active'],
    // The mismatch: a context resolved under one enabling user is worth nothing under another.
    'the agent handed to another enabling user mid-run' => [fn ($asker, $owner, $agent) => Agent::whereKey($agent->id)->update(['enabling_user_id' => $asker->id]), 'no longer borrows the authority'],
]);

it('resolves a requester who is not the enabling user as two people, and says so on the record', function () {
    $asker = onePathPerson(['laladia']);
    $context = Contexts::for($this->executive, requester: $asker);

    expect($context->requesterIsEnabler())->toBeFalse()
        ->and($context->requesterUser->id)->toBe($asker->id)
        ->and($context->enabler->id)->toBe($this->owner->id)
        ->and($context->toAuditArray()['requester_is_enabler'])->toBeFalse();

    // The same person on both sides is one term, not two, and narrows nothing.
    $same = Contexts::for($this->executive, requester: $this->owner);
    expect($same->requesterIsEnabler())->toBeTrue()->and($same->narrowed)->toBe([]);
});

it('refuses a requested scope that does not hold together: a brand of another business, an unknown business', function () {
    $laladiaBrand = Brand::where('slug', 'laladia')->sole();

    // The support agent's path hands over the thread's business and brand; they must belong together.
    expect(fn () => Contexts::for($this->executive, $this->bionic->id, $laladiaBrand->id))
        ->toThrow(ExecutionContextRefused::class, 'does not belong to business')
        ->and(fn () => Contexts::for($this->executive, 999999))->toThrow(ExecutionContextRefused::class, 'does not exist');

    // An agent row scoped to one business holds the run there even when both people are group-wide.
    $this->executive->update(['business_scope' => ['bionic']]);
    $context = Contexts::for($this->executive->fresh());
    expect($context->businessIds)->toBe([$this->bionic->id])
        ->and(fn () => Contexts::for($this->executive->fresh(), $this->laladia->id))->toThrow(ExecutionContextRefused::class, 'outside');

    // An empty list is nothing, as it is for allowed_tools; it is not "everything".
    $this->executive->update(['business_scope' => []]);
    expect(fn () => Contexts::for($this->executive->fresh()))->toThrow(ExecutionContextRefused::class, 'share no business');
});

// ── Scheduled and system invocations take the same path ─────────────────────────────────────────

it('asks for a scheduled job on behalf of its owner, and refuses a job that names nobody', function () {
    $owner = onePathPerson(['bionic']);
    $job = (new ScheduledJob)->forceFill(['id' => 41, 'owner_user_id' => $owner->id]);

    $context = app(ExecutionContextResolver::class)->resolve($this->executive, Requester::forScheduledJob($job), 'scheduler');
    expect($context->toAuditArray()['requester'])->toBe(['kind' => 'on_behalf_of', 'user_id' => $owner->id, 'as_enabled' => false, 'basis' => 'scheduled_jobs#41.owner_user_id'])
        ->and($context->businessIds)->toBe([$this->bionic->id]);

    // No owner, no accountable person, no run. The `system` identity's narrow reads are tool calls,
    // never an agent run, and the scheduler has no authority of its own to lend.
    $ownerless = (new ScheduledJob)->forceFill(['id' => 42, 'owner_user_id' => null]);
    expect(fn () => app(ExecutionContextResolver::class)->resolve($this->executive, Requester::forScheduledJob($ownerless), 'scheduler'))
        ->toThrow(ExecutionContextRefused::class, 'Nobody accountable');
});

it('runs a Shadow Probe of a period with no recorded opener as enabled, and the record says that is what happened', function () {
    $period = ShadowPeriod::create([
        'uuid' => (string) Str::uuid(), 'agent_id' => $this->executive->id, 'agent_key' => 'executive',
        'autonomy_level' => 'a0', 'status' => ShadowPeriod::STATUS_OPEN, 'opened_at' => now(), 'planned_end_at' => now()->addDays(7),
        'opened_by_user_id' => null, 'opened_by_label' => 'hq:shadow:open', 'reason' => 'test', 'evidence' => [], 'allowed_tools' => [],
        'questions' => [['key' => 'attention_today', 'question' => 'What needs my attention today?']], 'probes_required' => true,
    ]);
    FixtureProvider::script('fixture', [onePathAnswer()]);

    app(ShadowProbes::class)->ask($period, 'morning', CarbonImmutable::now()->startOfDay(), true);

    $requester = AgentRun::sole()->execution_context['requester'];
    expect($requester['kind'])->toBe('on_behalf_of')
        ->and($requester['as_enabled'])->toBeTrue()
        ->and($requester['user_id'])->toBe($this->owner->id)
        ->and($requester['basis'])->toContain('recorded no opener');
});

it('measures an agent through the gate runner as enabled, and cannot measure one that names no enabling user', function () {
    FixtureProvider::script('fixture', [onePathAnswer()]);
    $case = (new EvaluationCase)->forceFill(['key' => 'eec.case', 'purpose' => 'What needs my attention today?', 'input' => ['prompt' => 'What needs my attention today?']]);

    $observation = (new AgentEvaluationSubject(app(AgentRunner::class), 'executive'))->run($case);
    $run = AgentRun::sole();
    expect($observation->outcome)->toBe('answered')
        ->and($run->execution_context['requester'])->toMatchArray(['kind' => 'gate_runner', 'as_enabled' => true, 'user_id' => $this->owner->id])
        ->and($run->execution_context['entry_point'])->toBe('evaluation');

    // The support agent's enabling-user relationship: until a person is named for it, nothing runs.
    Agent::where('key', 'support')->update(['status' => 'active']);
    $refused = (new AgentEvaluationSubject(app(AgentRunner::class), 'support'))->run($case);
    expect($refused->outcome)->toBe('failed')
        ->and($refused->refusalReason)->toContain('names no enabling user')
        ->and(AgentRun::count())->toBe(1)
        ->and(FixtureProvider::callsTo('fixture'))->toBe(1);
});

it('lets the console name who is asking, and runs as enabled when it names nobody', function () {
    $asker = onePathPerson(['laladia']);
    FixtureProvider::script('fixture', [onePathAnswer()]);

    $this->artisan('hq:agent:ask', ['agent' => 'executive', 'question' => ['What', 'needs', 'me?'], '--as' => $asker->email])->assertSuccessful();
    $this->artisan('hq:agent:ask', ['agent' => 'executive', 'question' => ['What', 'needs', 'me?']])->assertSuccessful();
    $this->artisan('hq:agent:ask', ['agent' => 'executive', 'question' => ['What', 'needs', 'me?'], '--as' => 'nobody@example.test'])->assertFailed();

    [$named, $unnamed] = AgentRun::orderBy('id')->get()->all();
    expect(AgentRun::count())->toBe(2)
        ->and($named->execution_context['requester'])->toMatchArray(['kind' => 'human', 'user_id' => $asker->id])
        ->and($named->execution_context['scope']['business_ids'])->toBe([$this->laladia->id])
        ->and($named->triggered_by_user_id)->toBe($asker->id)
        ->and($unnamed->execution_context['requester'])->toMatchArray(['kind' => 'console', 'as_enabled' => true])
        ->and($unnamed->execution_context['scope']['business_ids'])->toBeNull();
});

// ── The sources: nobody builds an agent's authority any other way ───────────────────────────────

it('has one resolver, one place that builds a context, and one place that turns it into an identity', function () {
    $sources = collect(File::allFiles(app_path()))->filter(fn ($f) => $f->getExtension() === 'php')
        ->mapWithKeys(fn ($f) => [str_replace('\\', '/', $f->getRelativePathname()) => $f->getContents()]);
    $filesWith = fn (string $needle) => $sources->filter(fn (string $code) => str_contains($code, $needle))->keys()->sort()->values()->all();

    // The old two-term factory is gone, and nothing can name an agent identity without a context.
    expect($filesWith('ActingIdentity::agent('))->toBe([])
        ->and(method_exists(ActingIdentity::class, 'agent'))->toBeFalse()
        ->and($filesWith('ActingIdentity::forExecution('))->toBe(['Agents/AgentRunner.php'])
        ->and($filesWith('IdentityKind::Agent,'))->toBe(['Gateway/Identity/ActingIdentity.php']);

    // A context is built by the resolver and by nothing else; a run is resolved by the runner and by nothing else.
    expect($filesWith('new EffectiveExecutionContext('))->toBe(['Execution/ExecutionContextResolver.php'])
        ->and($filesWith('->contexts->resolve('))->toBe(['Agents/AgentRunner.php'])
        ->and($filesWith('ExecutionContextResolver $'))->toBe(['Agents/AgentRunner.php', 'Ask/AskHq.php', 'Gateway/ToolGateway.php']);

    // Ask HQ previews and never resolves; the gateway only asks whether a context still stands.
    expect($sources['Ask/AskHq.php'])->toContain('->contexts->preview(')->not->toContain('->contexts->resolve(')
        ->and($sources['Gateway/ToolGateway.php'])->toContain('->contexts->stillStands(')->not->toContain('->contexts->resolve(')->not->toContain('->contexts->preview(');

    // Every entry point that starts a run says who is asking, and the runner is the only thing that runs one.
    $starters = $filesWith('new AgentRunRequest(');
    expect($starters)->toBe(['Agents/AgentEvaluationSubject.php', 'Agents/Shadow/ShadowProbes.php', 'Ask/AskHq.php', 'Console/Commands/AgentAsk.php']);
    foreach ($starters as $file) {
        expect(substr_count($sources[$file], 'requester:'))->toBe(substr_count($sources[$file], 'new AgentRunRequest('), $file);
    }
    expect($sources['Agents/Shadow/ShadowProbes.php'])->toContain('requester: Requester::forShadowPeriod($period)');

    // Nothing that starts or carries a run reads the agent's enabling user for itself any more.
    foreach ($sources->filter(fn ($code, $file) => preg_match('#^(Agents|Ask|Scheduling|Evaluation|Conversation|Console)/#', $file) === 1) as $file => $code) {
        expect($code)->not->toContain('->enablingUser', $file);
    }

    // And the runner still has exactly one door to a tool.
    expect(substr_count($sources['Agents/AgentRunner.php'], '->execute('))->toBe(1);
});
