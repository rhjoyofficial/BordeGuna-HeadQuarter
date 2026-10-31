<?php

use App\Execution\ExecutionContextRefused;
use App\Execution\ExecutionContextResolver;
use App\Models\Agent;
use App\Models\AgentDelegation;
use App\Models\Business;
use App\Models\Skill;
use App\Models\User;
use App\Skills\Delegations;
use App\Skills\SkillCatalogue;
use App\Support\Authorization\BusinessScope;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Completion\M4;
use Tests\Support\Execution\Contexts;

/*
 | docs/145-c (Phase 9, ADR-047): the skill registry moves only by people, and a delegation can only NARROW:
 | businesses = target AND parent, tools = target AND skill AND held by the parent's enabling user, for the
 | same accountable person. Proved over every combination of two people's scopes and permissions below.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
    $this->seed(AgentSeeder::class);
    Agent::query()->whereIn('key', ['executive', 'analytics'])->update(['status' => 'active', 'enabling_user_id' => $this->owner->id]);
    $this->executive = Agent::where('key', 'executive')->sole();
    $this->analytics = Agent::where('key', 'analytics')->sole();
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->bionic = Business::where('slug', 'bionic')->sole();
});

function sdSkill(array $tools, ?Business $business = null, string $key = 'sales_snapshot'): Skill
{
    $registry = app(SkillCatalogue::class);
    $skill = $registry->propose(test()->owner, $business, ['key' => $key, 'name' => 'Sales snapshot', 'purpose' => 'Read orders and report.', 'required_tools' => $tools]);
    $registry->approve(test()->owner, $skill, 'read it');

    return $registry->activate(test()->owner, $skill->fresh(), 'go');
}

it('registers the skill routes behind auth, 2FA and permissions, and shows the screen in Bangla-ready form', function () {
    foreach (['skills.index' => 'permission:hq.skills.view', 'skills.store' => 'permission:hq.skills.propose'] as $name => $permission) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', $permission);
    }
    $this->actingAsHq($this->owner)->get('/skills')->assertOk()->assertInertia(fn (Assert $p) => $p->component('skills/index')->where('delegationOn', false));
    $this->actingAsHq($this->hqUser('support_agent'))->get('/skills')->assertForbidden();
});

it('moves a skill only by people, refuses unknown and human-only tools, and keeps one active version per key', function () {
    $this->actingAsHq($this->owner)->post('/skills', ['key' => 'x_skill', 'name' => 'X', 'purpose' => 'p', 'required_tools' => ['no.such_tool']])->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post('/skills', ['key' => 'x_skill', 'name' => 'X', 'purpose' => 'p', 'required_tools' => ['customers.identity_conflicts.resolve']])->assertSessionHasErrors('form');

    $this->actingAsHq($this->owner)->post('/skills', ['key' => 'x_skill', 'name' => 'X', 'purpose' => 'p', 'required_tools' => ['facts.query_orders']])->assertSessionHasNoErrors();
    $v1 = Skill::query()->sole();
    expect($v1->status)->toBe('proposed');
    $this->actingAsHq($this->owner)->post("/skills/{$v1->uuid}/activate", ['reason' => 'skip the review'])->assertSessionHasErrors('form');
    $this->actingAsHq($this->owner)->post("/skills/{$v1->uuid}/approve", ['reason' => 'ok'])->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post("/skills/{$v1->uuid}/activate", ['reason' => 'ok'])->assertSessionHasNoErrors();

    $this->actingAsHq($this->owner)->post('/skills', ['key' => 'x_skill', 'name' => 'X2', 'purpose' => 'p', 'required_tools' => ['facts.query_orders']])->assertSessionHasNoErrors();
    $v2 = Skill::query()->where('version', 2)->sole();
    $this->actingAsHq($this->owner)->post("/skills/{$v2->uuid}/approve", ['reason' => 'ok'])->assertSessionHasNoErrors();
    $this->actingAsHq($this->owner)->post("/skills/{$v2->uuid}/activate", ['reason' => 'ok'])->assertSessionHasNoErrors();
    expect($v1->fresh()->status)->toBe('retired')->and($v2->fresh()->status)->toBe('active');

    // The brand manager may not move skills at all; nothing is deleted.
    $this->actingAsHq($this->hqUser('brand_manager'))->post("/skills/{$v2->uuid}/suspend", ['reason' => 'x'])->assertForbidden();
    expect(fn () => DB::transaction(fn () => Skill::query()->whereKey($v1->id)->delete()))->toThrow(QueryException::class);
});

it('refuses a delegation while the flag is off or AI is paused, and records the refusal', function () {
    $skill = sdSkill(['facts.query_orders']);
    $parent = Contexts::for($this->executive);

    $row = app(Delegations::class)->delegate($parent, $this->analytics, $skill);
    expect($row->status)->toBe('refused')->and($row->refusal_reason)->toContain('agents.delegation');

    M4::on('agents.delegation');
    M4::pause();
    expect(app(Delegations::class)->delegate($parent, $this->analytics, $skill)->refusal_reason)->toContain('Pause All AI');
    M4::pause(false);

    $ok = app(Delegations::class)->delegate($parent, $this->analytics, $skill);
    expect($ok->status)->toBe('resolved')->and($ok->effective['tools'])->toBe(['facts.query_orders']);
    expect(AgentDelegation::query()->count())->toBe(3);
    expect(fn () => DB::transaction(fn () => AgentDelegation::query()->whereKey($ok->id)->update(['status' => 'refused'])))->toThrow(QueryException::class);
});

it('never widens: over every mix of scopes and permissions, the delegated authority is inside the parent\'s, the skill\'s and every person\'s', function () {
    M4::on('agents.delegation');
    $skill = sdSkill(['facts.query_orders', 'erp.read_snapshot', 'commerce.list_orders', 'facts.snapshot_freshness']);

    $people = [
        'group-wide owner' => fn () => $this->owner,
        'laladia operator' => fn () => M4::limit($this->hqUser('operator'), 'laladia'),
        'bionic operator' => fn () => M4::limit($this->hqUser('operator'), 'bionic'),
        'facts-only reader' => function () {
            $u = User::factory()->create();
            $u->givePermissionTo(['hq.tools.facts.read']);

            return $u;
        },
    ];

    $checked = 0;
    foreach ($people as $askerName => $makeAsker) {
        foreach ($people as $enablerName => $makeEnabler) {
            $asker = $makeAsker();
            $targetEnabler = $makeEnabler();
            $this->analytics->forceFill(['enabling_user_id' => $targetEnabler->id])->save();

            try {
                $parent = Contexts::for($this->executive->fresh(), requester: $asker);
            } catch (ExecutionContextRefused) {
                continue; // nothing to delegate from
            }
            foreach ([null, $this->laladia->id, $this->bionic->id] as $business) {
                $row = app(Delegations::class)->delegate($parent, $this->analytics->fresh(), $skill, $business);
                if ($row->status !== 'resolved') {
                    continue;
                }
                $e = $row->effective;
                $checked++;

                // Tools: inside the skill, inside the target agent, and every permission held by all three people.
                expect(array_diff($e['tools'], (array) $skill->required_tools))->toBe([], "{$askerName}/{$enablerName}")
                    ->and(array_diff($e['tools'], (array) $this->analytics->allowed_tools))->toBe([]);
                foreach ($e['permissions'] as $permission) {
                    expect($asker->fresh()->can($permission) && $targetEnabler->fresh()->can($permission) && $this->owner->can($permission))->toBeTrue("{$askerName}/{$enablerName}: {$permission}");
                }
                // Businesses: never outside the parent run's, never outside either person's.
                foreach ([$parent->businessIds, app(BusinessScope::class)->all($asker), app(BusinessScope::class)->all($targetEnabler)] as $limit) {
                    if ($limit !== null) {
                        expect($e['business_ids'])->not->toBeNull()->and(array_diff($e['business_ids'], $limit))->toBe([]);
                    }
                }
                // The accountable person never changes hands.
                expect($e['requester_user_id'])->toBe($asker->id);
            }
        }
    }
    expect($checked)->toBeGreaterThan(5);
});

it('refuses a delegation about a business the parent run cannot touch, an inactive skill, and a skill of another business', function () {
    M4::on('agents.delegation');
    $asker = M4::limit($this->hqUser('operator'), 'laladia');
    $parent = Contexts::for($this->executive, requester: $asker);
    $skill = sdSkill(['facts.query_orders']);

    expect(app(Delegations::class)->delegate($parent, $this->analytics, $skill, $this->bionic->id)->refusal_reason)->toContain('outside what the delegating run');

    $bionicSkill = sdSkill(['facts.query_orders'], $this->bionic, 'bionic_only');
    expect(app(Delegations::class)->delegate($parent, $this->analytics, $bionicSkill, $this->laladia->id)->refusal_reason)->toContain('another business');

    app(SkillCatalogue::class)->suspend($this->owner, $skill->fresh(), 'stop');
    expect(app(Delegations::class)->delegate($parent, $this->analytics, $skill->fresh())->refusal_reason)->toContain('suspended');
});

it('builds a delegated context only through the resolver, and never swaps in the target\'s enabling user as the requester', function () {
    $other = $this->hqUser('operator');
    $this->analytics->forceFill(['enabling_user_id' => $other->id])->save();
    $parent = Contexts::for($this->executive); // as enabled: the requester is the owner
    $child = app(ExecutionContextResolver::class)->resolveDelegated($parent, $this->analytics->fresh(), ['facts.query_orders']);

    expect($child->requesterUser->id)->toBe($this->owner->id)->and($child->enabler->id)->toBe($other->id)
        ->and($child->toolKeys)->toBe(['facts.query_orders']);
});
