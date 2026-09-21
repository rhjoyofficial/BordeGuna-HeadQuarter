<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Ai\ModelTier;
use App\Execution\Requester;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Models\UserBusinessScope;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Ai\FixtureProvider;

/*
 | docs/81 point 3 (C.2): the recorded `eec.v1` context on the agent run page, read-only.
 |
 | The runs here are REAL runs through the runner and the one resolver, so the page is tested against
 | what the resolver actually records and not against a hand-written array.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    $this->seed(DomainSeeder::class);
    aiProviders();
    enableAiRuntime();
    aiModel('fixture', 'fixture-model', ModelTier::Medium);

    $this->owner = $this->hqUser('owner', ['name' => 'Owner Person']);
    $this->seed(AgentSeeder::class);
    Agent::query()->where('key', 'executive')->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);

    $this->laladia = Business::where('slug', 'laladia')->sole();
});

function panelRun(Requester $requester, ?int $businessId = null): AgentRun
{
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode([
        'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'Done.', 'refusal_reason' => '', 'facts' => [],
        'inferences' => [], 'tool_calls' => [], 'task' => 'a question', 'authority_request' => 'none',
    ]))]);

    return app(AgentRunner::class)->run(new AgentRunRequest(
        agentKey: 'executive', question: 'How did yesterday go?', trigger: 'operator', requester: $requester, businessId: $businessId,
    ));
}

it('shows the recorded context of a run: who asked, who enabled, what acted, where, the scope, the tools, the digest and when', function () {
    $run = panelRun(Requester::human($this->owner, 'ask_conversations#7.user_id'));

    $this->actingAsHq($this->owner)->get("/agents/runs/{$run->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('agents/show')
            ->where('execution_context.state', 'recorded')
            ->where('execution_context.version', 'eec.v1')
            ->where('execution_context.requester.user_id', $this->owner->id)
            ->where('execution_context.requester.name', 'Owner Person')
            ->where('execution_context.requester.kind', 'human')
            ->where('execution_context.requester.basis', 'ask_conversations#7.user_id')
            ->where('execution_context.requester.is_enabler', true)
            ->where('execution_context.enabler.user_id', $this->owner->id)
            ->where('execution_context.enabler.name', 'Owner Person')
            ->where('execution_context.acting_identity', 'agent:executive')
            ->where('execution_context.agent.key', 'executive')
            ->where('execution_context.entry_point', 'operator')
            ->where('execution_context.environment', 'testing')
            ->where('execution_context.business_scope.group_wide', true)
            ->where('execution_context.brand_scope.every_brand', true)
            ->where('execution_context.narrowed', [])
            ->where('execution_context.digest', $run->execution_context_digest)
            ->where('execution_context.resolved_at', AuditLog::where('action', 'agent_run.context_resolved')->sole()->occurred_at->toIso8601String())
            ->has('execution_context.tools')
            ->has('execution_context.permissions')
            ->has('execution_context.decided_per_call'));
});

it('shows a narrowed run as narrowed: the businesses left, and what narrowed it and why', function () {
    $asker = User::factory()->create(['name' => 'Asker Person']);
    $asker->givePermissionTo('hq.tools.facts.read');
    UserBusinessScope::create(['user_id' => $asker->id, 'business_id' => $this->laladia->id]);

    $run = panelRun(Requester::human($asker->fresh(), 'ask_conversations#8.user_id'), $this->laladia->id);

    $this->actingAsHq($this->owner)->get("/agents/runs/{$run->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('execution_context.state', 'recorded')
            ->where('execution_context.requester.name', 'Asker Person')
            ->where('execution_context.requester.is_enabler', false)
            ->where('execution_context.enabler.name', 'Owner Person')
            ->where('execution_context.business_scope.group_wide', false)
            ->where('execution_context.business_scope.businesses', [['id' => $this->laladia->id, 'name' => $this->laladia->name]])
            ->where('execution_context.business_scope.requested', ['id' => $this->laladia->id, 'name' => $this->laladia->name])
            ->where('execution_context.narrowed.0.term', 'business')
            ->where('execution_context.narrowed.0.by', 'requester')
            ->where('execution_context.tools', fn ($tools) => collect($tools)->every(fn ($t) => is_string($t)) && count($tools) > 0)
            ->where('execution_context.permissions', ['hq.tools.facts.read']));
});

it('says a run from before ADR-047 was recorded before execution contexts existed, and never sends an empty context', function () {
    $run = AgentRun::create([
        'uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(), 'trigger' => 'console',
        'question' => 'An old one', 'autonomy_level' => AutonomyLevel::A0->value, 'shadow' => true, 'status' => 'answered',
        'answer' => 'Old answer.', 'evidence' => [], 'proposals' => [], 'context_provenance' => [], 'turns' => 1, 'tool_calls_count' => 0,
        'started_at' => now(), 'finished_at' => now(),
    ]);

    $this->actingAsHq($this->owner)->get("/agents/runs/{$run->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('execution_context', ['state' => 'before_adr_047']));
});

it('names no email and no secret anywhere in the context it sends', function () {
    $run = panelRun(Requester::human($this->owner, 'ask_conversations#7.user_id'));

    $this->actingAsHq($this->owner)->get("/agents/runs/{$run->id}")
        ->assertInertia(function ($page) {
            $flat = mb_strtolower((string) json_encode($page->toArray()['props']['execution_context']));

            foreach ([mb_strtolower($this->owner->email), '@', 'password', 'secret', 'token', 'two_factor'] as $never) {
                expect($flat)->not->toContain($never);
            }
        });
});

it('names only the businesses the viewer may see, and counts the rest', function () {
    $run = panelRun(Requester::human($this->owner, 'ask_conversations#7.user_id'), $this->laladia->id);
    // A recorded scope wider than the viewer's: two businesses, of which the viewer may see one.
    $other = Business::where('slug', 'bionic')->sole();
    $context = $run->execution_context;
    $context['scope']['business_ids'] = [$this->laladia->id, $other->id];
    $run->forceFill(['execution_context' => $context])->save();

    $viewer = $this->hqUser('brand_manager', ['name' => 'Viewer Person']);
    UserBusinessScope::create(['user_id' => $viewer->id, 'business_id' => $this->laladia->id]);
    Cache::flush();

    $this->actingAsHq($viewer->fresh())->get("/agents/runs/{$run->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('execution_context.business_scope.businesses', [['id' => $this->laladia->id, 'name' => $this->laladia->name]])
            ->where('execution_context.business_scope.outside_your_view', 1));
});

it('refuses the page, context and all, to a viewer without the permission, and hides another business\'s run', function () {
    $run = panelRun(Requester::human($this->owner, 'ask_conversations#7.user_id'), $this->laladia->id);

    $plain = User::factory()->create();
    $plain->forceFill(['two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()])->save();
    $this->actingAsHq($plain->fresh())->get("/agents/runs/{$run->id}")->assertForbidden();

    $elsewhere = $this->hqUser('brand_manager');
    UserBusinessScope::create(['user_id' => $elsewhere->id, 'business_id' => Business::where('slug', 'bionic')->sole()->id]);
    Cache::flush();
    $this->actingAsHq($elsewhere->fresh())->get("/agents/runs/{$run->id}")->assertNotFound();
});
