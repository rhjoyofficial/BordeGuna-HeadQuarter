<?php

use App\Agents\AgentResponseSchema;
use App\Agents\AgentRunner;
use App\Agents\AgentRunRequest;
use App\Ai\ModelTier;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\Agent;
use App\Models\AgentRun;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Autonomy\EvidenceFixtures as F;
use Tests\Support\Execution\Contexts;

/*
 | ADR-048, docs/81 C.4: the runner receives the evidence. Until 21 September `resolveAutonomy()`
 | asked with no rate at all, so a grant requiring evidence could never match; and nothing bounded a
 | grant by what had been measured. With no active policy nothing here may change by a byte.
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
    Agent::where('key', 'executive')->update(['max_autonomy_level' => 'a2']);

    // Evidence for the executive: one suite, three tool-selection cases, all passed on today's stamp.
    $this->suite = F::suite('test.policy', null, ['t1' => ['tool_selection'], 't2' => ['tool_selection'], 'r1' => ['reasoning']]);
    $this->run = F::run($this->suite, 'executive', ['t1' => 'pass', 't2' => 'pass', 'r1' => 'pass']);
});

function aerAnswer(): void
{
    FixtureProvider::script('fixture', [FixtureProvider::reply((string) json_encode([
        'status' => AgentResponseSchema::STATUS_ANSWER, 'answer' => 'ok', 'refusal_reason' => '', 'facts' => [], 'inferences' => [],
        'tool_calls' => [], 'task' => 'the operator question', 'authority_request' => 'none',
    ]))]);
}

function aerRun(): AgentRun
{
    aerAnswer();

    return app(AgentRunner::class)->run(new AgentRunRequest(
        agentKey: 'executive', question: 'How are we doing?', trigger: 'console', requester: Contexts::asEnabled(),
    ));
}

it('changes nothing when no policy is active: no evidence recorded, no new constraint, the grant decides as before', function () {
    F::grant(['max_level' => 'a2']);

    $run = aerRun();

    expect($run->autonomy_level)->toBe(AutonomyLevel::A2)
        ->and($run->autonomy_evidence)->toBeNull()
        ->and(collect($run->autonomy_constraints)->pluck('source')->all())->not->toContain('evidence_policy');
});

it('bounds a grant by what the active policy allows on the measured evidence, and records what it judged', function () {
    F::grant(['max_level' => 'a2']);
    // TEST VALUES: A1 needs tool selection; the policy says nothing about A2, so A2 is unreachable.
    $policy = F::activate($this->owner, [F::requirement('a1', 'tool_selection', 0.9, 2, 7)]);

    $run = aerRun()->fresh();

    expect($run->autonomy_level)->toBe(AutonomyLevel::A1)
        ->and($run->autonomy_reason)->toBe("the autonomy evidence policy (version {$policy->version}) allows at most A1 on the measured evidence")
        ->and($run->autonomy_evidence['policy'])->toMatchArray(['policy_id' => $policy->id, 'version' => $policy->version, 'digest' => $policy->content_digest])
        ->and($run->autonomy_evidence['evidence_counted'])->toBeTrue()
        ->and($run->autonomy_evidence['run_ids'])->toBe([$this->run->id])
        ->and($run->autonomy_evidence['ceiling'])->toBe('a1')
        ->and($run->autonomy_evidence['ceiling_reasons'])->toBe(['the policy names no requirement for A2, so evidence cannot reach it'])
        ->and($run->autonomy_evidence['evidence_digest'])->toHaveLength(64)
        ->and($run->autonomy_evidence['rates']['dimensions'])->toEqual(['reasoning' => 1.0, 'tool_selection' => 1.0]);
});

it('makes a grant that requires evidence reachable, compared with the dimension it names', function () {
    $grant = F::grant(['max_level' => 'a2', 'min_agreement_rate' => 0.9, 'min_agreement_dimension' => 'tool_selection']);
    F::activate($this->owner, [F::requirement('a1', 'tool_selection'), F::requirement('a2', 'tool_selection'), F::requirement('a2', 'reasoning', 1.0, 1, 7)]);

    $run = aerRun()->fresh();

    // Before ADR-048 the runner passed no rate, the grant never matched, and the answer was A0.
    expect($run->autonomy_level)->toBe(AutonomyLevel::A2)
        ->and(collect($run->autonomy_constraints)->pluck('source')->all())->toContain("grant:{$grant->id}", 'evidence_policy');
});

it('records the policy and counts no evidence when no grant names the agent, and the answer stays A0', function () {
    $policy = F::activate($this->owner, [F::requirement('a1', 'tool_selection')]);

    $run = aerRun()->fresh();

    expect($run->autonomy_level)->toBe(AutonomyLevel::A0)
        ->and($run->shadow)->toBeTrue()
        ->and($run->autonomy_reason)->toContain('no autonomy grant matches')
        ->and($run->autonomy_evidence)->toMatchArray(['evidence_counted' => false, 'ceiling' => 'a0'])
        ->and($run->autonomy_evidence['policy']['version'])->toBe($policy->version)
        ->and($run->autonomy_evidence)->not->toHaveKey('run_ids');
});

it('drops to the level below when the evidence is measured on an old prompt stamp', function () {
    F::grant(['max_level' => 'a2']);
    F::activate($this->owner, [F::requirement('a1', 'tool_selection')]);
    $this->run->update(['prompt_version' => 'executive.v1+00000000']);

    $run = aerRun()->fresh();

    expect($run->autonomy_level)->toBe(AutonomyLevel::A0)
        ->and($run->autonomy_evidence['ceiling'])->toBe('a0')
        ->and(implode(' | ', $run->autonomy_evidence['ceiling_reasons']))->toContain('was measured on prompt stamp executive.v1+00000000')
        ->and($run->autonomy_evidence['rates']['dimensions'])->toBe([]);
});
