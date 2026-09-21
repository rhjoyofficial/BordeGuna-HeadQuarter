<?php

use App\Models\AutonomyEvidencePolicy;
use App\Observe\Autonomy\AutonomyEvidence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Autonomy\EvidenceFixtures as F;

/*
 | ADR-048 on the autonomy evidence view: the active policy or the plain statement that none is,
 | every proposed version shown as not in force with what blocks it, the policy's judgement of each
 | agent's evidence level by level, and the resolver asked exactly the way the runner asks it.
 | Still read-only. Every policy number below is a TEST VALUE.
 */

function aepvBuild(): array
{
    return app(AutonomyEvidence::class)->build();
}

beforeEach(function () {
    $this->owner = $this->hqUser('owner');
    F::agent();
    $this->suite = F::suite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    $this->run = F::run($this->suite, 'executive', ['a' => 'pass', 'b' => 'pass']);
});

it('says no policy is active, lists a proposed version as not in force with what blocks it, and judges nobody', function () {
    F::propose([F::requirement('a1', 'tool_selection', null, 2, 7)], by: $this->owner, name: 'first draft');

    $built = aepvBuild();

    expect($built['policy']['active'])->toBeNull()
        ->and($built['policy']['statement'])->toBe('No autonomy evidence policy is active, so evidence cannot widen autonomy.')
        ->and($built['policy']['proposed'])->toHaveCount(1)
        ->and($built['policy']['proposed'][0])->toMatchArray(['version' => 1, 'name' => 'first draft', 'status' => 'proposed', 'in_force' => false])
        ->and($built['policy']['proposed'][0]['blocking'])->toBe(['a1 tool_selection: min_agreement_rate is not decided (NULL)'])
        ->and($built['agents'][0]['policy_judgement'])->toBeNull();
});

it('shows the active policy, its judgement of the evidence level by level, and asks the resolver the way the runner does', function () {
    $grant = F::grant(['max_level' => 'a2', 'min_agreement_rate' => 0.9, 'min_agreement_dimension' => 'tool_selection']);
    $policy = F::activate($this->owner, [F::requirement('a1', 'tool_selection', 0.9, 2, 7)]);
    F::propose([F::requirement('a1', 'tool_selection', 0.95, 2, 7)], by: $this->owner, name: 'stricter draft');

    $built = aepvBuild();
    $agent = $built['agents'][0];

    expect($built['policy']['active'])->toMatchArray(['version' => $policy->version, 'status' => 'active', 'in_force' => true,
        'content_digest' => $policy->content_digest, 'activated_by_user_id' => $this->owner->id])
        ->and($built['policy']['statement'])->toStartWith("Autonomy evidence policy version {$policy->version} is active.")
        ->and(array_column($built['policy']['proposed'], 'name'))->toBe(['stricter draft'])
        ->and($agent['policy_judgement']['ceiling'])->toBe('a1')
        ->and($agent['policy_judgement']['run_ids'])->toBe([$this->run->id])
        ->and($agent['policy_judgement']['levels'][0]['requirements'][0])->toMatchArray(['dimension' => 'tool_selection', 'met' => true, 'passed' => 2])
        ->and($agent['policy_judgement']['ceiling_reasons'])->toBe(['the policy names no requirement for A2, so evidence cannot reach it']);

    // The grant requiring evidence now matches, because the page asks with the producer's rates as the
    // runner does, and the policy bounds it at A1. The agent's own ceiling is A0, which binds first.
    expect($agent['ceiling']['level'])->toBe('a0')
        ->and($agent['ceiling']['grant_ids'])->toBe([$grant->id])
        ->and($agent['ceiling']['why'])->toContain("The autonomy evidence policy (version {$policy->version}) allows at most A1 on the measured evidence.")
        ->and($agent['ceiling']['evidence_policy']['ceiling'])->toBe('a1');
});

it('still writes nothing when a policy is active, and renders the policy on the page', function () {
    F::grant();
    F::activate($this->owner, [F::requirement('a1', 'tool_selection')]);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop|create)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $before = [AutonomyEvidencePolicy::count(), DB::table('autonomy_evidence_policy_events')->count(), DB::table('audit_logs')->count()];

    $this->actingAsHq($this->owner)->get('/observe/autonomy-evidence')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('observe/autonomy-evidence')
            ->where('policy.active.version', 1)
            ->where('policy.active.in_force', true)
            ->where('agents.0.policy_judgement.ceiling', 'a1')
            ->where('agents.0.policy_judgement.levels.0.level', 'a1')
            ->where('agents.0.policy_judgement.levels.0.eligible', true));

    expect($writes)->toBe([])
        ->and([AutonomyEvidencePolicy::count(), DB::table('autonomy_evidence_policy_events')->count(), DB::table('audit_logs')->count()])->toBe($before);
});
