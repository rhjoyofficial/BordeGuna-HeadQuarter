<?php

use App\Ai\Budget\AiBudget;
use App\Models\AgentRun;
use App\Models\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | The whole-second bound audit (docs/119, the docs/118 section 8 follow-up of fix 6726f51).
 |
 | Every timestamp column in HQ is precision 0. A value reaches such a column in one of two ways:
 |  - written by the application (Eloquent or a bound Carbon): Laravel formats it as 'Y-m-d H:i:s', so the
 |    fraction is DROPPED before Postgres sees it: 10:00:05.6 is stored as 10:00:05;
 |  - written by a database default (`useCurrent()` with the column left out of the insert): Postgres
 |    ROUNDS the fraction: 10:00:05.6 is stored as 10:00:06, later than "now" by PHP's clock.
 | A window bound sent as a Carbon is cut the same way as an application write. So an application-written
 | row is always inside a window that ends at "now" (`<=`), and the readers audited in docs/119 rely on
 | that. These tests pin the assumptions, so a model that one day keeps microseconds, or a grammar that
 | starts sending them, fails here and not as a one-second miss in a budget or a safety probe.
 */

it('writes every application timestamp without its fraction, in every model', function () {
    $keeping = [];
    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        if ((new $class)->getDateFormat() !== 'Y-m-d H:i:s') {
            $keeping[] = $class;
        }
    }

    expect($keeping)->toBe([]);
});

it('stores an application-written stamp cut down to its second, never rounded up past now', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:05.600000', 'UTC'));

    $run = AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'answered']);
    $ai = AiRun::create(['task' => 't', 'status' => 'succeeded', 'estimated_cost_usd' => '1.000000']);

    expect(DB::table('agent_runs')->where('id', $run->id)->value('created_at'))->toStartWith('2026-10-15 10:00:05')->not->toContain('10:00:06')
        ->and(DB::table('ai_runs')->where('id', $ai->id)->value('created_at'))->toStartWith('2026-10-15 10:00:05')->not->toContain('10:00:06');
});

it('sends a Carbon bound cut down to its second, while the column itself rounds a fraction it is given', function () {
    $bound = CarbonImmutable::parse('2026-10-15 10:00:05.600000', 'UTC');

    expect(DB::selectOne('select ?::timestamptz(0) as v', [$bound])->v)->toBe('2026-10-15 10:00:05+00')
        // What a database default does (decision_records, docs/111): the fraction reaches the column and is rounded.
        ->and(DB::selectOne("select '2026-10-15 10:00:05.600000+00'::timestamptz(0) as v")->v)->toBe('2026-10-15 10:00:06+00');
});

it('counts a model run written in the current second against the budget at once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:05.600000', 'UTC'));
    AiRun::create(['task' => 't', 'status' => 'succeeded', 'estimated_cost_usd' => '2.500000']);

    expect(app(AiBudget::class)->spend('global', null, 'daily'))->toBe(2.5)
        ->and(app(AiBudget::class)->spend('global', null, 'monthly'))->toBe(2.5);
});
