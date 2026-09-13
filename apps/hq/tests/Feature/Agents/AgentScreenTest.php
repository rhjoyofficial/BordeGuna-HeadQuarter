<?php

use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\DecisionRecord;
use App\Models\UserBusinessScope;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
 | The agent screen and the shadow review (docs/48 M5).
 |
 | The most important assertions here are about what the screen CANNOT do: it cannot start a run,
 | cannot activate an agent, and cannot re-tool one. Those are decisions somebody makes deliberately,
 | not buttons.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);

    $this->owner = $this->hqUser('owner');
});

function shadowRun(array $overrides = []): AgentRun
{
    return AgentRun::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'agent_key' => 'executive',
        'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console',
        'question' => 'How did yesterday go?',
        'autonomy_level' => AutonomyLevel::A0->value,
        'autonomy_reason' => 'no autonomy grant matches this combination',
        'shadow' => true,
        'status' => 'answered',
        'answer' => 'Two orders, BDT 2,120.',
        'evidence' => [['kind' => 'claim', 'statement' => 'Two orders', 'source' => 'facts.query_orders', 'as_of' => null]],
        'proposals' => [],
        'context_provenance' => [['id' => 'a', 'provenance' => 'business', 'source' => 'operator:question', 'digest' => 'x', 'bytes' => 10, 'trusted' => true]],
        'turns' => 2,
        'tool_calls_count' => 1,
        'started_at' => now(),
        'finished_at' => now(),
    ], $overrides));
}

it('lists the agents and their runs', function () {
    shadowRun();

    $this->actingAsHq($this->owner)->get('/agents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('agents/index')
            ->has('agents', 3) // executive, analyst, and the support agent seeded paused by Phase 3
            ->has('runs', 1)
            ->where('runs.0.shadow', true));
});

it('shows a run with its evidence, its provenance and what bound its autonomy', function () {
    $run = shadowRun();

    $this->actingAsHq($this->owner)->get("/agents/runs/{$run->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('agents/show')
            ->where('run.shadow', true)
            ->where('run.autonomy_level', 'a0')
            ->has('run.evidence', 1)
            ->has('run.context_provenance', 1));
});

it('records a verdict as a decision record, which is the alignment corpus starting', function () {
    $run = shadowRun();

    expect(DecisionRecord::count())->toBe(0);

    $this->actingAsHq($this->owner)
        ->post("/agents/runs/{$run->id}/review", ['verdict' => 'edited', 'note' => 'It should have separated Bionic out.'])
        ->assertRedirect();

    $run->refresh();
    expect($run->review_verdict)->toBe('edited');
    expect($run->reviewed_by)->toBe($this->owner->id);

    $record = DecisionRecord::sole();
    expect($record->agent_key)->toBe('executive');
    expect($record->decision)->toBe('edited');
    expect($record->context_type)->toBe('agent_run');
    // The same three words the column already used. One idea, one vocabulary.
    expect($record->decision)->toBeIn(['approved', 'edited', 'rejected']);

    expect(AuditLog::where('action', 'agent.run_reviewed')->count())->toBe(1);
});

it('refuses a second verdict, because a review is not revised after the fact', function () {
    $run = shadowRun();

    $this->actingAsHq($this->owner)->post("/agents/runs/{$run->id}/review", ['verdict' => 'approved']);
    $this->actingAsHq($this->owner)
        ->post("/agents/runs/{$run->id}/review", ['verdict' => 'rejected'])
        ->assertSessionHasErrors('verdict');

    expect($run->refresh()->review_verdict)->toBe('approved');
    expect(DecisionRecord::count())->toBe(1);
});

it('refuses a verdict that is not one of the three', function () {
    $run = shadowRun();

    $this->actingAsHq($this->owner)
        ->post("/agents/runs/{$run->id}/review", ['verdict' => 'brilliant'])
        ->assertSessionHasErrors('verdict');
});

it('offers no way to start a run, activate an agent or change its tools', function () {
    // ⛔ The whole point of the shadow period is that a run is a decision somebody makes at a
    // console, not a button. If a route ever appears that starts one from the browser, this fails.
    $routes = collect(app('router')->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'agents'))
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
        ->values()
        ->all();

    expect($routes)->toHaveCount(3);
    expect(implode(' ', $routes))->not->toContain('ask')
        ->not->toContain('run-now')
        ->not->toContain('activate')
        ->not->toContain('pause')
        ->not->toContain('tools');
});

it('keeps the screen behind hq.agents.view, and the review behind hq.agents.manage', function () {
    $viewer = $this->hqUser('brand_manager');

    $run = shadowRun();

    $this->actingAsHq($viewer)->get('/agents')->assertOk();
    $this->actingAsHq($viewer)->post("/agents/runs/{$run->id}/review", ['verdict' => 'approved'])->assertForbidden();

    expect($run->refresh()->review_verdict)->toBeNull();

    $this->actingAsHq($this->hqUser('support_agent'))->get('/agents')->assertForbidden();
});

it('scopes runs to the businesses a restricted operator may see', function () {
    // ⛔ Found reviewing this milestone's own PR. `brand_manager` holds `hq.agents.view`, and the
    // screen applied NO business scope - so a manager restricted to LaLaDia could read a Bionic run,
    // including the model's claims, and a claim quotes figures. Harmless today because HQ has one
    // operator with no scope rows (V.11); this is the gap closed before the first second user, not
    // after.
    $this->seed(DomainSeeder::class);
    $laladia = Business::where('slug', 'laladia')->first()
        ?? Business::orderBy('id')->first();
    $other = Business::where('id', '!=', $laladia->id)->orderBy('id')->firstOrFail();

    $mine = shadowRun(['business_id' => $laladia->id, 'question' => 'mine']);
    $theirs = shadowRun(['business_id' => $other->id, 'question' => 'theirs']);
    $groupWide = shadowRun(['business_id' => null, 'question' => 'group wide']);

    // An OPERATOR with a scope row, not a brand_manager: a brand_manager lacks `hq.agents.manage`,
    // so the permission middleware would answer 403 on the review path before the scope check ever
    // ran, and the test would prove the wrong thing about the write path.
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $laladia->id]);

    $this->actingAsHq($restricted)->get('/agents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('runs', 1)->where('runs.0.question', 'mine'));

    $this->actingAsHq($restricted)->get("/agents/runs/{$mine->id}")->assertOk();

    // 404 rather than 403: that run 412 exists at all is itself a disclosure.
    $this->actingAsHq($restricted)->get("/agents/runs/{$theirs->id}")->assertNotFound();

    // A cross-business run is group-wide by nature, so a restricted user does not see it either.
    $this->actingAsHq($restricted)->get("/agents/runs/{$groupWide->id}")->assertNotFound();

    // And reviewing is reading: the same gate, or the verdict path becomes the way around it.
    $this->actingAsHq($restricted)->post("/agents/runs/{$theirs->id}/review", ['verdict' => 'approved'])->assertNotFound();
    expect($theirs->refresh()->review_verdict)->toBeNull();

    // The owner holds no scope rows, so nothing about today's only operator changes.
    $this->actingAsHq($this->owner)->get('/agents')
        ->assertInertia(fn ($page) => $page->has('runs', 3));
});
