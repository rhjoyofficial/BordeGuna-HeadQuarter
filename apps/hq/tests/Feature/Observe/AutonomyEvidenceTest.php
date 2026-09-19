<?php

use App\Agents\PromptLibrary;
use App\Evaluation\DeterministicGrader;
use App\Evaluation\EvaluationObservation;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSubject;
use App\Models\Agent;
use App\Models\AutonomyGrant;
use App\Models\EvaluationCase;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\EvaluationSuite;
use App\Models\FeatureFlag;
use App\Observe\Autonomy\AutonomyEvidence;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 | docs/80 C.3: the autonomy evidence view. Read-only, fail closed, and every figure carries the run
 | ids and result ids it was counted from.
 */

const AE_TABLES = ['agents', 'autonomy_grants', 'evaluation_suites', 'evaluation_cases', 'evaluation_runs', 'evaluation_results', 'feature_flags', 'audit_logs'];

function aeAgent(string $key = 'executive', ?string $role = null, string $status = 'paused'): Agent
{
    return Agent::create(['key' => $key, 'name' => ucfirst($key), 'status' => $status, 'role_key' => $role ?? $key, 'max_autonomy_level' => 'a0']);
}

/** @param  array<string, array{0: string, 1?: string|null}>  $cases  case key => [dimension, applies_to_role] */
function aeSuite(string $key, ?string $role, array $cases, bool $active = true): EvaluationSuite
{
    $suite = EvaluationSuite::create(['key' => $key, 'name' => $key, 'kind' => 'safety', 'role_key' => $role, 'is_active' => $active, 'pass_threshold' => 1.0]);

    foreach ($cases as $caseKey => $spec) {
        EvaluationCase::create([
            'suite_id' => $suite->id, 'key' => $caseKey, 'input' => ['prompt' => 'x'], 'expectation' => ['refusal' => false],
            'grader' => 'deterministic', 'severity' => 'blocker', 'dimension' => $spec[0], 'applies_to_role' => $spec[1] ?? null, 'is_active' => true,
        ]);
    }

    return $suite;
}

/**
 * A finished (or unfinished) run written the way the runner writes one, with the outcome of each case
 * scripted: 'pass', 'fail' or 'unmeasured'.
 *
 * @param  array<string, string>  $outcomes  case key => outcome
 */
function aeRun(EvaluationSuite $suite, string $agent, array $outcomes, array $attributes = []): EvaluationRun
{
    $counts = array_count_values($outcomes) + ['pass' => 0, 'fail' => 0, 'unmeasured' => 0];
    $status = $counts['fail'] === 0 && $counts['unmeasured'] === 0 ? 'passed' : 'failed';
    $cases = $suite->cases()->get()->keyBy('key');

    $byDimension = [];
    foreach ($outcomes as $caseKey => $outcome) {
        if ($outcome === 'unmeasured') {
            continue;
        }
        $d = $cases[$caseKey]->dimension;
        $byDimension[$d] ??= ['passed' => 0, 'failed' => 0];
        $byDimension[$d][$outcome === 'pass' ? 'passed' : 'failed']++;
    }
    foreach ($byDimension as $d => $c) {
        $byDimension[$d]['rate'] = round($c['passed'] / max(1, $c['passed'] + $c['failed']), 3);
    }

    $run = EvaluationRun::create($attributes + [
        'uuid' => (string) Str::uuid(), 'suite_id' => $suite->id, 'agent_key' => $agent, 'status' => $status,
        'prompt_version' => app(PromptLibrary::class)->version('executive'),
        'cases_total' => count($outcomes), 'cases_passed' => $counts['pass'], 'cases_failed' => $counts['fail'],
        'cases_unmeasured' => $counts['unmeasured'], 'cases_skipped' => 0,
        'agreement_rate' => round($counts['pass'] / max(1, $counts['pass'] + $counts['fail']), 3),
        'dimension_rates' => $byDimension, 'triggered_by' => 'manual', 'started_at' => now(), 'finished_at' => now(),
    ]);

    foreach ($outcomes as $caseKey => $outcome) {
        EvaluationResult::create([
            'run_id' => $run->id, 'case_id' => $cases[$caseKey]->id, 'passed' => $outcome === 'pass', 'unmeasured' => $outcome === 'unmeasured',
            'grader' => 'deterministic', 'dimension' => $cases[$caseKey]->dimension,
            'checks' => $outcome === 'fail' ? ['must_not_call' => 'pass', 'refusal' => 'fail', 'citations' => 'not_applicable'] : ['must_not_call' => 'pass'],
            'reason' => $outcome === 'fail' ? 'expected no refusal and got one' : null,
        ]);
    }

    return $run;
}

function aeGrant(array $attributes): AutonomyGrant
{
    return AutonomyGrant::create($attributes + ['uuid' => (string) Str::uuid(), 'name' => 'test grant', 'max_level' => 'a2', 'status' => 'active', 'agent_key' => 'executive']);
}

function aeRow(array $built, string $dimension, string $agent = 'executive'): array
{
    $found = collect($built['agents'])->firstWhere('key', $agent);

    return collect($found['rows'])->firstWhere('dimension', $dimension);
}

function aeBuild(): array
{
    return app(AutonomyEvidence::class)->build();
}

it('reads every row as not eligible with no requirement configured when nothing exists, and invents no number', function () {
    aeAgent();

    $built = aeBuild();
    $agent = $built['agents'][0];

    expect($built['live_grants_total'])->toBe(0)
        ->and($built['runtime_flag'])->toMatchArray(['key' => 'ai.runtime', 'enabled' => false, 'flag_id' => null])
        ->and($agent['rows'])->toHaveCount(7)
        ->and(array_column($agent['rows'], 'dimension'))->toBe(['overall', 'reasoning', 'tool_selection', 'policy', 'enforcement', 'execution', 'outcome']);

    foreach ($agent['rows'] as $row) {
        expect($row['eligible'])->toBeFalse()
            ->and($row['state'])->toBe('not_eligible')
            ->and($row['required_rate'])->toBeNull()
            ->and($row['agreement_rate'])->toBeNull()
            ->and($row['measurement'])->toBe('never_measured')
            ->and($row['reasons'][0])->toStartWith('no requirement configured');
    }

    expect(aeRow($built, 'tool_selection')['reasons'])->toContain('dimension never measured: no finished run holds a tool selection result for this agent');
});

it('says what the resolver says now and why: paused, runtime off, no grants, A0', function () {
    aeAgent();

    $ceiling = aeBuild()['agents'][0]['ceiling'];

    expect($ceiling['level'])->toBe('a0')
        ->and($ceiling['grant_ids'])->toBe([])
        ->and($ceiling['bound_by'])->toContain('no autonomy grant matches')
        ->and($ceiling['why'])->toContain('The agent is paused, so it runs nothing at all.')
        ->and($ceiling['why'])->toContain('The AI runtime flag (ai.runtime) is off in this environment.')
        ->and($ceiling['why'])->toContain('No live autonomy grant names this agent.');

    // The flag is read by the reader's own rule: this environment's row, else the `*` row.
    $flag = FeatureFlag::create(['key' => 'ai.runtime', 'environment' => '*', 'enabled' => true]);
    expect(aeBuild()['runtime_flag'])->toMatchArray(['enabled' => true, 'flag_id' => $flag->id]);
    FeatureFlag::create(['key' => 'ai.runtime', 'environment' => app()->environment(), 'enabled' => false]);
    expect(aeBuild()['runtime_flag']['enabled'])->toBeFalse();
});

it('does not let an unmeasured dimension inherit the overall rate', function () {
    aeAgent();
    $suite = aeSuite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    $run = aeRun($suite, 'executive', ['a' => 'pass', 'b' => 'pass']);
    // A grant that asks for reasoning evidence, and one that asks for the overall rate.
    $reasoning = aeGrant(['min_agreement_rate' => 0.9, 'min_agreement_dimension' => 'reasoning']);
    aeGrant(['min_agreement_rate' => 0.9]);

    $built = aeBuild();

    expect(aeRow($built, 'overall'))->toMatchArray(['agreement_rate' => 1.0, 'evidence_count' => 2, 'eligible' => true, 'required_rate' => 0.9])
        ->and(aeRow($built, 'tool_selection'))->toMatchArray(['agreement_rate' => 1.0, 'evidence_count' => 2, 'eligible' => false, 'required_rate' => null]);

    $row = aeRow($built, 'reasoning');
    expect($row)->toMatchArray(['agreement_rate' => null, 'evidence_count' => 0, 'measurement' => 'never_measured', 'eligible' => false, 'sources' => []])
        ->and($row['required_rate'])->toBe(0.9)
        ->and($row['requirements'][0])->toMatchArray(['grant_id' => $reasoning->id, 'met' => false, 'measured_rate' => null])
        ->and($row['reasons'])->toContain('dimension never measured: no finished run holds a model reasoning result for this agent')
        ->and($row['reasons'])->toContain("grant {$reasoning->id} requires 0.900 and nothing was measured");

    expect(aeRow($built, 'overall')['sources'][0]['run_id'])->toBe($run->id);
});

it('counts a provider-refused result as neither a pass nor a failure', function () {
    aeAgent();
    $suite = aeSuite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['tool_selection'], 'c' => ['tool_selection'], 'r' => ['reasoning']]);
    $run = aeRun($suite, 'executive', ['a' => 'pass', 'b' => 'fail', 'c' => 'unmeasured', 'r' => 'unmeasured']);

    $built = aeBuild();
    $row = aeRow($built, 'tool_selection');
    $ids = fn (string $key) => [EvaluationResult::where('run_id', $run->id)->where('case_id', EvaluationCase::where('key', $key)->value('id'))->value('id')];

    expect($row)->toMatchArray(['passed' => 1, 'failed' => 1, 'unmeasured' => 1, 'evidence_count' => 2, 'agreement_rate' => 0.5, 'measurement' => 'measured'])
        ->and($row['sources'][0]['result_ids'])->toBe(['passed' => $ids('a'), 'failed' => $ids('b'), 'unmeasured' => $ids('c')])
        ->and($row['reasons'])->toContain("latest gate failed 1 of 4 (run {$run->id} of safety.core)")
        ->and($row['reasons'])->toContain("latest run had 2 unmeasured case(s), refused by the model provider (run {$run->id} of safety.core)")
        // An unmeasured result is never listed as a failure.
        ->and(array_column($row['recent_failures'], 'case_key'))->toBe(['b']);

    // A dimension holding only refused results was not measured, and is not a failure either.
    expect(aeRow($built, 'reasoning'))->toMatchArray(['passed' => 0, 'failed' => 0, 'unmeasured' => 1, 'agreement_rate' => null, 'measurement' => 'unmeasured_only', 'recent_failures' => []])
        ->and(aeRow($built, 'reasoning')['reasons'])->toContain('nothing was measured: all 1 result(s) were refused by the model provider, which is not evidence about the agent');
});

it('excludes other roles, other agents and inactive suites from an agent\'s evidence', function () {
    aeAgent();
    aeAgent('support');
    $core = aeSuite('safety.core', null, ['every' => ['tool_selection'], 'support.only' => ['tool_selection', 'support']]);
    $supportSuite = aeSuite('behaviour.support', 'support', ['s' => ['tool_selection']]);
    $retired = aeSuite('retired', null, ['old' => ['tool_selection']], active: false);

    // A result row for another role's case must not count even if one exists under the agent's run.
    $run = aeRun($core, 'executive', ['every' => 'pass', 'support.only' => 'fail']);
    aeRun($core, 'support', ['every' => 'fail', 'support.only' => 'fail']);
    aeRun($supportSuite, 'executive', ['s' => 'fail']);
    aeRun($retired, 'executive', ['old' => 'fail']);

    $built = aeBuild();
    $row = aeRow($built, 'tool_selection');

    expect($row)->toMatchArray(['passed' => 1, 'failed' => 0, 'evidence_count' => 1, 'agreement_rate' => 1.0, 'recent_failures' => []])
        ->and(array_column($row['sources'], 'run_id'))->toBe([$run->id])
        ->and(array_column(collect($built['agents'])->firstWhere('key', 'executive')['suites'], 'suite_key'))->toBe(['safety.core'])
        // The recount differs from what that run stored, and the page says so instead of hiding it.
        ->and($row['sources'][0]['stored_matches'])->toBeFalse()
        ->and($row['reasons'])->toContain("the rate stored on run {$run->id} of safety.core does not match a recount of its results");

    // The support agent sees its own two suites and its own runs only.
    expect(aeRow($built, 'tool_selection', 'support'))->toMatchArray(['passed' => 0, 'failed' => 2, 'evidence_count' => 2]);
});

it('takes the latest finished run per suite, and treats a newer unfinished run as a caveat only', function () {
    aeAgent();
    $suite = aeSuite('behaviour.executive', 'executive', ['a' => ['tool_selection'], 'b' => ['tool_selection']]);
    $first = aeRun($suite, 'executive', ['a' => 'fail', 'b' => 'fail']);
    $second = aeRun($suite, 'executive', ['a' => 'pass', 'b' => 'pass']);
    $third = aeRun($suite, 'executive', ['a' => 'pass', 'b' => 'fail']);
    $running = EvaluationRun::create(['uuid' => (string) Str::uuid(), 'suite_id' => $suite->id, 'agent_key' => 'executive', 'status' => 'running', 'cases_total' => 2]);

    $built = aeBuild();
    $row = aeRow($built, 'tool_selection');
    $suiteRow = $built['agents'][0]['suites'][0];

    expect($row)->toMatchArray(['passed' => 1, 'failed' => 1, 'agreement_rate' => 0.5])
        ->and(array_column($row['sources'], 'run_id'))->toBe([$third->id])
        ->and($row['reasons'])->toContain("latest gate failed 1 of 2 (run {$third->id} of behaviour.executive)")
        ->and($suiteRow)->toMatchArray(['finished_runs' => 3, 'unfinished_newer_run_ids' => [$running->id]])
        ->and($suiteRow['latest_run']['run_id'])->toBe($third->id);

    // History stays readable under recent failures, newest first, and says which run is the latest.
    expect(array_map(fn ($f) => [$f['run_id'], $f['case_key'], $f['in_latest_run'], $f['failed_checks']], $row['recent_failures']))->toBe([
        [$third->id, 'b', true, ['refusal']],
        [$first->id, 'b', false, ['refusal']],
        [$first->id, 'a', false, ['refusal']],
    ]);
    expect($second->fresh()->status)->toBe('passed');
});

it('names a suite that holds cases for the agent and has never been run against it', function () {
    aeAgent();
    aeSuite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['reasoning']]);

    $built = aeBuild();

    expect(aeRow($built, 'overall')['reasons'])->toContain('suite safety.core holds 2 case(s) for this agent and has never been run against it')
        ->and(aeRow($built, 'reasoning')['reasons'])->toContain('suite safety.core holds 1 model reasoning case(s) for this agent and has never been run against it')
        ->and(aeRow($built, 'policy')['reasons'])->toBe([
            'no requirement configured: no live autonomy grant names a required policy decision rate for this agent',
            'dimension never measured: no finished run holds a policy decision result for this agent',
        ]);
});

it('refuses evidence measured on a different prompt stamp, or on none', function () {
    aeAgent();
    $today = app(PromptLibrary::class)->version('executive');
    $suite = aeSuite('safety.core', null, ['a' => ['tool_selection']]);
    aeGrant(['min_agreement_rate' => 1.0, 'min_agreement_dimension' => 'tool_selection']);

    $old = aeRun($suite, 'executive', ['a' => 'pass'], ['prompt_version' => 'executive.v1+00000000']);
    expect(aeRow(aeBuild(), 'tool_selection'))->toMatchArray(['eligible' => false, 'agreement_rate' => 1.0])
        ->and(aeRow(aeBuild(), 'tool_selection')['reasons'])->toBe([
            "measured on a different prompt stamp (executive.v1+00000000, run {$old->id} of safety.core) than the one the agent runs with today ({$today})",
        ]);

    $none = aeRun($suite, 'executive', ['a' => 'pass'], ['prompt_version' => null]);
    expect(aeRow(aeBuild(), 'tool_selection')['reasons'])->toBe([
        "run {$none->id} of safety.core carries no prompt stamp, so it cannot be tied to the prompt the agent runs with today ({$today})",
    ]);

    aeRun($suite, 'executive', ['a' => 'pass']);
    expect(aeRow(aeBuild(), 'tool_selection'))->toMatchArray(['eligible' => true, 'state' => 'satisfied', 'reasons' => []]);

    // An agent whose role has no prompt file cannot have its evidence tied to anything.
    aeAgent('nobody');
    aeRun($suite, 'nobody', ['a' => 'pass']);
    expect(aeRow(aeBuild(), 'tool_selection', 'nobody')['reasons'])
        ->toContain('role nobody has no prompt file today, so no run can be tied to what the agent runs with');
});

it('checks a requirement in whole thousandths, per suite as well as pooled, and only against live grants', function () {
    aeAgent();
    $strong = aeSuite('safety.core', null, array_fill_keys(array_map(fn ($i) => "s{$i}", range(1, 9)), ['tool_selection']));
    $weak = aeSuite('behaviour.executive', 'executive', ['w1' => ['tool_selection'], 'w2' => ['tool_selection']]);
    aeRun($strong, 'executive', array_fill_keys(array_map(fn ($i) => "s{$i}", range(1, 9)), 'pass'), ['status' => 'passed']);
    $weakRun = aeRun($weak, 'executive', ['w1' => 'pass', 'w2' => 'fail'], ['status' => 'passed']);

    // Pooled 10 of 11 = 0.909: meets 0.9 pooled, but the weak suite alone is 0.5, so it is not met.
    $grant = aeGrant(['min_agreement_rate' => 0.9, 'min_agreement_dimension' => 'tool_selection']);
    $row = aeRow(aeBuild(), 'tool_selection');
    expect($row)->toMatchArray(['agreement_rate' => 0.909, 'evidence_count' => 11, 'required_rate' => 0.9, 'eligible' => false])
        ->and($row['reasons'])->toBe(["run {$weakRun->id} of behaviour.executive measured tool selection at 0.500 (1 of 2), below the 0.900 grant {$grant->id} requires"]);

    // A grant naming a suite is checked against that suite alone.
    $grant->update(['evaluation_suite_key' => 'safety.core']);
    $row = aeRow(aeBuild(), 'tool_selection');
    expect($row['eligible'])->toBeTrue()
        ->and($row['requirements'][0])->toMatchArray(['met' => true, 'measured_passed' => 9, 'measured_failed' => 0, 'evaluation_suite_key' => 'safety.core']);

    // A second, stricter grant: every requirement must hold, and the pooled shortfall is named.
    $strict = aeGrant(['min_agreement_rate' => 0.95, 'min_agreement_dimension' => 'tool_selection', 'agent_key' => null]);
    $row = aeRow(aeBuild(), 'tool_selection');
    expect($row['required_rate'])->toBe(0.95)
        ->and($row['eligible'])->toBeFalse()
        ->and($row['reasons'])->toContain("tool selection measured 0.909 (10 of 11), below the 0.950 grant {$strict->id} requires");

    // Revoked, expired and other agents' grants are not requirements.
    $strict->update(['status' => 'revoked']);
    aeGrant(['min_agreement_rate' => 1.0, 'min_agreement_dimension' => 'tool_selection', 'expires_at' => now()->subMinute()]);
    aeGrant(['min_agreement_rate' => 1.0, 'min_agreement_dimension' => 'tool_selection', 'agent_key' => 'support']);
    expect(aeRow(aeBuild(), 'tool_selection'))->toMatchArray(['eligible' => true, 'required_rate' => 0.9]);

    // Satisfied evidence changes nothing: the resolver is asked without measured rates, as the runtime asks.
    $ceiling = aeBuild()['agents'][0]['ceiling'];
    expect($ceiling['level'])->toBe('a0')
        ->and($ceiling['why'])->toContain("Grant {$grant->id} requires measured evidence. The resolver was asked the way the runtime asks it, without measured rates, so that grant does not apply.");
});

it('recounts a real runner run to exactly what the runner stored, with every result id', function () {
    aeAgent();
    $suite = aeSuite('safety.core', null, ['ok' => ['tool_selection'], 'bad' => ['reasoning'], 'quota' => ['reasoning'], 'support.only' => ['reasoning', 'support']]);
    $suite->cases()->where('key', 'bad')->update(['expectation' => ['refusal' => true]]);

    $subject = new class implements EvaluationSubject
    {
        public function subjectKey(): string
        {
            return 'executive';
        }

        public function subjectRole(): ?string
        {
            return 'executive';
        }

        public function run(EvaluationCase $case): EvaluationObservation
        {
            return $case->key === 'quota' ? new EvaluationObservation(unmeasured: true, stopReason: 'provider_refused') : new EvaluationObservation;
        }
    };

    $run = app(EvaluationRunner::class)->withGrader(new DeterministicGrader)
        ->run($suite, $subject, ['agent_key' => 'executive', 'prompt_version' => app(PromptLibrary::class)->version('executive')]);

    $built = aeBuild();
    $overall = aeRow($built, 'overall');

    expect($run->cases_skipped)->toBe(1)
        ->and($overall)->toMatchArray(['passed' => 1, 'failed' => 1, 'unmeasured' => 1, 'evidence_count' => 2, 'agreement_rate' => 0.5])
        ->and($overall['sources'][0])->toMatchArray(['stored_matches' => true, 'run_cases_skipped' => 1, 'run_cases_unmeasured' => 1, 'run_uuid' => $run->uuid])
        ->and(aeRow($built, 'reasoning'))->toMatchArray(['passed' => 0, 'failed' => 1, 'unmeasured' => 1, 'agreement_rate' => 0.0])
        ->and(aeRow($built, 'reasoning')['sources'][0]['stored_matches'])->toBeTrue()
        ->and(aeRow($built, 'tool_selection')['sources'][0]['stored'])->toBe(['passed' => 1, 'failed' => 0, 'rate' => 1.0]);

    // Every result of the run is accounted for exactly once in the overall row.
    $ids = $overall['sources'][0]['result_ids'];
    expect(collect($ids)->flatten()->sort()->values()->all())->toBe(EvaluationResult::where('run_id', $run->id)->orderBy('id')->pluck('id')->all());
});

it('never modifies anything by being viewed: no write query, same row counts, same updated_at', function () {
    aeAgent();
    $suite = aeSuite('safety.core', null, ['a' => ['tool_selection'], 'b' => ['reasoning']]);
    aeRun($suite, 'executive', ['a' => 'pass', 'b' => 'fail']);
    aeRun($suite, 'executive', ['a' => 'pass', 'b' => 'unmeasured']);
    aeGrant(['min_agreement_rate' => 0.9, 'min_agreement_dimension' => 'tool_selection']);
    $user = $this->hqUser('owner');

    $snapshot = fn () => collect(AE_TABLES)->mapWithKeys(fn ($t) => [$t => [
        DB::table($t)->count(),
        DB::getSchemaBuilder()->hasColumn($t, 'updated_at') ? DB::table($t)->orderBy('id')->pluck('updated_at', 'id')->all() : null,
    ]])->all();
    $before = $snapshot();

    $this->travel(5)->minutes();

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop|create)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    app(AutonomyEvidence::class)->build();
    $this->actingAsHq($user)->get('/observe/autonomy-evidence')->assertOk();

    expect($writes)->toBe([])
        ->and($snapshot())->toBe($before);
});

it('is one GET route behind auth, both 2FA gates and a permission that already exists, with no other verb anywhere', function () {
    $route = Route::getRoutes()->getByName('observe.autonomy-evidence');

    expect($route)->not->toBeNull()
        ->and('/'.$route->uri())->toBe('/observe/autonomy-evidence')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.agents.view')
        ->and(RolesAndPermissionsSeeder::PERMISSIONS)->toContain('hq.agents.view');

    // No route of any verb other than this one mentions the view.
    $others = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'autonomy-evidence') || str_contains((string) $r->getActionName(), 'autonomyEvidence'))
        ->reject(fn ($r) => $r->getName() === 'observe.autonomy-evidence');
    expect($others->all())->toBe([]);

    $user = $this->hqUser('owner');
    foreach (['post', 'put', 'patch', 'delete'] as $verb) {
        $this->actingAsHq($user)->{$verb}('/observe/autonomy-evidence')->assertStatus(405);
    }
});

it('opens for the roles that hold hq.agents.view and no other, and renders the evidence', function () {
    aeAgent();
    $suite = aeSuite('safety.core', null, ['a' => ['tool_selection']]);
    $run = aeRun($suite, 'executive', ['a' => 'fail']);

    $this->get('/observe/autonomy-evidence')->assertRedirect();

    foreach (['owner' => true, 'brand_manager' => true, 'finance_viewer' => false, 'support_agent' => false] as $role => $allowed) {
        $response = $this->actingAsHq($this->hqUser($role))->get('/observe/autonomy-evidence');
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    $this->actingAsHq($this->hqUser('owner'))->get('/observe/autonomy-evidence')
        ->assertInertia(fn ($page) => $page
            ->component('observe/autonomy-evidence')
            ->where('live_grants_total', 0)
            ->where('runtime_flag.enabled', false)
            ->where('agents.0.key', 'executive')
            ->where('agents.0.ceiling.level', 'a0')
            ->where('agents.0.rows.2.dimension', 'tool_selection')
            ->where('agents.0.rows.2.state', 'not_eligible')
            ->where('agents.0.rows.2.failed', 1)
            ->where('agents.0.rows.2.sources.0.run_id', $run->id)
            ->where('agents.0.rows.2.recent_failures.0.case_key', 'a')
            ->where('agents.0.rows.2.recent_failures.0.failed_checks', ['refusal']));
});
