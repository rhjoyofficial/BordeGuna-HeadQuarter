<?php

use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\DailyAggregate;
use App\Models\RetentionRun;
use App\Models\RetentionWatermark;
use App\Observe\Retention\RetentionPolicy;
use App\Observe\Retention\RetentionRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | docs/78 B.2: retention of the three detail tables and of event payloads. The clock is fixed at
 | 1 June 2027, 12:00 Dhaka, so with the governed 180 days the first kept day is 3 December 2026 and
 | 2 December 2026 is the last eligible one.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-06-01 12:00:00', 'Asia/Dhaka'));
});

function retAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Dhaka')->utc();
}

function retToolCall(array $attributes = []): int
{
    return DB::table('tool_calls')->insertGetId($attributes + [
        'tool_key' => 'erp.poll_overview',
        'risk_tier' => 'r3',
        'caller_kind' => 'scheduler',
        'actor_type' => 'system',
        'status' => 'succeeded',
        'policy_result' => 'allow',
        'duration_ms' => 100,
        'created_at' => retAt('2026-11-01 09:00'),
    ]);
}

function retAiRun(array $attributes = []): int
{
    return DB::table('ai_runs')->insertGetId($attributes + [
        'task' => 'probe',
        'provider' => 'anthropic',
        'model' => 'fixture-model',
        'model_tier' => 'low',
        'status' => 'succeeded',
        'input_tokens' => 10,
        'output_tokens' => 5,
        'latency_ms' => 900,
        'estimated_cost_usd' => '0.010000',
        'created_at' => retAt('2026-11-01 09:00'),
        'updated_at' => retAt('2026-11-01 09:00'),
    ]);
}

function retEvent(string $status, string $createdLocal, array $payload): int
{
    return DB::table('events')->insertGetId([
        'event_id' => (string) Str::uuid(),
        'type' => 'order.created',
        'direction' => 'inbound',
        'source' => 'laladia',
        'payload' => json_encode($payload),
        'dedupe_key' => (string) Str::uuid(),
        'occurred_at' => retAt($createdLocal),
        'created_at' => retAt($createdLocal),
        'status' => $status,
    ]);
}

it('pins the governed policy and keeps every permanent class out of reach', function () {
    $policy = app(RetentionPolicy::class);

    expect(config('hq.retention.detail_keep_days'))->toBe(['tool_calls' => 180, 'ai_runs' => 180, 'connector_calls' => 180])
        ->and(config('hq.retention.event_payload_keep_days'))->toBe(365)
        ->and(array_intersect(RetentionPolicy::DETAIL_TABLES, RetentionPolicy::neverTargeted()))->toBe([])
        ->and(RetentionPolicy::PERMANENT)->toContain('audit_logs')->toContain('decision_records');

    foreach (RetentionPolicy::neverTargeted() as $table) {
        expect(fn () => $policy->keepDays($table))->toThrow(InvalidArgumentException::class);
        expect(fn () => $policy->detail($table))->toThrow(InvalidArgumentException::class);
    }

    // Naming a permanent table in configuration does nothing: the policy knows three tables.
    config(['hq.retention.detail_keep_days.audit_logs' => 1]);
    expect(array_map(fn ($d) => $d->table, $policy->detailTables()))->toBe(RetentionPolicy::DETAIL_TABLES);
});

it('knows every column that points at a detail row, so a new reference cannot be pruned out from under it', function () {
    $columns = collect(DB::select("select table_name || '.' || column_name as c from information_schema.columns
        where table_schema = 'public' and (column_name like '%tool_call_id' or column_name like '%ai_run_id' or column_name like '%connector_call_id')"))
        ->pluck('c')->sort()->values()->all();

    // Each of these is named in a keep condition in RetentionPolicy. A new one fails here first.
    expect($columns)->toBe([
        'approvals.ai_run_id',
        'approvals.requesting_tool_call_id',
        'approvals.resulting_tool_call_id',
        'consent_events.tool_call_id',
        'conversation_messages.ai_run_id',
        'conversation_messages.tool_call_id',
        'evaluation_results.ai_run_id',
        'tool_calls.ai_run_id',
    ]);

    $source = file_get_contents(app_path('Observe/Retention/RetentionPolicy.php'));
    foreach (['a.requesting_tool_call_id', 'a.resulting_tool_call_id', 'c.tool_call_id', 'm.tool_call_id', 'a.ai_run_id', 'e.ai_run_id', 'm.ai_run_id', 'c.ai_run_id'] as $reference) {
        expect($source)->toContain($reference);
    }

    // The runner deletes from one place only: the detail table the policy named, by id.
    $runner = file_get_contents(app_path('Observe/Retention/RetentionRunner.php'));
    expect(substr_count($runner, '->delete()'))->toBe(1)
        ->and($runner)->toContain('DB::table($detail->table)->whereIn(\'id\', $ids)->delete()')
        ->and(preg_match_all('/DELETE\s+FROM/i', $runner))->toBe(0);
    foreach (RetentionPolicy::neverTargeted() as $table) {
        expect(preg_match('/UPDATE\s+'.$table.'\b/i', $runner))->toBe(0);
    }
});

it('plans without writing anything', function () {
    retToolCall();
    retToolCall(['agent_key' => 'executive']);
    retToolCall(['created_at' => retAt('2027-05-30 10:00')]);

    $plan = app(RetentionRunner::class)->plan();

    expect($plan['tables']['tool_calls'])->toMatchArray([
        'keep_days' => 180,
        'eligible_before' => '2026-12-03',
        'watermark' => null,
        'eligible_rows' => 1,
        'kept_forever_rows' => 1,
    ])
        ->and(RetentionRun::count())->toBe(0)
        ->and(DailyAggregate::count())->toBe(0)
        ->and(DB::table('tool_calls')->count())->toBe(3);

    Artisan::call('hq:retention:plan');
    expect(Artisan::output())->toContain('tool_calls')->and(RetentionRun::count())->toBe(0);
});

it('rolls up and removes only unattended detail older than its window, with the lineage on record', function () {
    $prunable = [
        retToolCall(['duration_ms' => 100]),
        retToolCall(['duration_ms' => 200, 'created_at' => retAt('2026-11-01 23:30')]),
        retToolCall(['duration_ms' => 300, 'created_at' => retAt('2026-11-02 00:30')]),
        retToolCall(['status' => 'failed', 'duration_ms' => 50]),
    ];

    $agentRun = AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'answered']);
    $aiRun = retAiRun(['agent_key' => 'executive']);

    $kept = [
        retToolCall(['agent_key' => 'executive']),
        retToolCall(['status' => 'uncertain']),
        retToolCall(['ai_run_id' => $aiRun]),
        retToolCall(['correlation_id' => $agentRun->correlation_id]),
        $requesting = retToolCall(),
    ];
    DB::table('approvals')->insert([
        'uuid' => (string) Str::uuid(), 'tool_key' => 'diagnostics.approval_demo', 'tool_version' => '1.0.0',
        'payload' => '{}', 'payload_hash' => str_repeat('a', 64), 'risk_tier' => 'r4', 'action_class' => 'approval_required',
        'requesting_tool_call_id' => $requesting, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $recent = retToolCall(['created_at' => retAt('2027-05-30 10:00')]);

    $run = app(RetentionRunner::class)->run();

    expect($run->status)->toBe(RetentionRun::SUCCEEDED)
        ->and($run->policy_version)->toBe('retention.v1')
        ->and($run->policy['detail_keep_days']['tool_calls'])->toBe(180)
        ->and(DB::table('tool_calls')->whereIn('id', $prunable)->count())->toBe(0)
        ->and(DB::table('tool_calls')->whereIn('id', $kept)->count())->toBe(5)
        ->and(DB::table('tool_calls')->where('id', $recent)->exists())->toBeTrue()
        ->and(DB::table('approvals')->value('requesting_tool_call_id'))->toBe($requesting);

    // 23:30 and 00:30 Dhaka on either side of midnight are two different days.
    $aggregates = DailyAggregate::where('source_table', 'tool_calls')->orderBy('day')->orderBy('rows', 'desc')->get();
    expect($aggregates)->toHaveCount(3)
        ->and($aggregates->sum('rows'))->toBe(4)
        ->and($aggregates->pluck('retention_run_id')->unique()->all())->toBe([$run->id]);

    $first = $aggregates->first();
    expect($first->day->toDateString())->toBe('2026-11-01')
        ->and($first->rows)->toBe(2)
        ->and($first->sum_duration_ms)->toBe(300)
        ->and($first->dimension['status'])->toBe('succeeded')
        ->and($first->dimension['tool_key'])->toBe('erp.poll_overview')
        ->and($first->min_source_id)->toBe($prunable[0])
        ->and($first->max_source_id)->toBe($prunable[1])
        ->and($first->measures)->toBe(['retried' => 0]);

    $summary = $run->summary['tables']['tool_calls'];
    expect($summary['rows_aggregated_and_deleted'])->toBe(4)
        ->and($summary['min_source_id'])->toBe($prunable[0])
        ->and($summary['max_source_id'])->toBe($prunable[3])
        // Bounded: 31 days from the oldest row, so 1 to 31 November; 1 and 2 December wait for the next pass.
        ->and($summary['stopped_by'])->toBe('max_days')
        ->and($summary['watermark_after'])->toBe('2026-12-01');

    $audit = AuditLog::where('action', 'retention.completed')->sole();
    expect($audit->after['uuid'])->toBe($run->uuid)
        ->and($audit->after['summary']['tables']['tool_calls']['rows_aggregated_and_deleted'])->toBe(4);
});

it('is idempotent: a second pass removes nothing twice and only moves the watermark to the cutoff', function () {
    retToolCall();
    retToolCall(['created_at' => retAt('2026-12-02 09:00')]);

    $first = app(RetentionRunner::class)->run();
    expect(DailyAggregate::sum('rows'))->toBe(1)
        ->and(RetentionWatermark::find('tool_calls')->through_day->toDateString())->toBe('2026-12-01');

    $second = app(RetentionRunner::class)->run();
    expect($second->summary['tables']['tool_calls']['rows_aggregated_and_deleted'])->toBe(1)
        ->and($second->summary['tables']['tool_calls']['stopped_by'])->toBeNull()
        ->and(RetentionWatermark::find('tool_calls')->through_day->toDateString())->toBe('2026-12-02')
        ->and(DailyAggregate::where('retention_run_id', $first->id)->sum('rows'))->toBe(1)
        ->and(DailyAggregate::where('retention_run_id', $second->id)->sum('rows'))->toBe(1);

    $third = app(RetentionRunner::class)->run();
    expect($third->summary['tables']['tool_calls']['rows_aggregated_and_deleted'])->toBe(0)
        ->and($third->summary['tables']['tool_calls']['days_drained'])->toBe(0)
        ->and(DailyAggregate::sum('rows'))->toBe(2)
        ->and(DB::table('tool_calls')->count())->toBe(0);
});

it('keeps model calls that belong to an agent, an evaluation trail or a kept tool call, and sums the rest', function () {
    $pruned = [
        retAiRun(['input_tokens' => 10, 'output_tokens' => 5, 'estimated_cost_usd' => '0.010000']),
        retAiRun(['input_tokens' => 30, 'output_tokens' => 15, 'estimated_cost_usd' => '0.020000', 'actual_cost_usd' => '0.025000', 'cost_source' => 'provider']),
    ];
    $keptByAgent = retAiRun(['agent_key' => 'support']);
    $keptByToolCall = retAiRun();
    retToolCall(['ai_run_id' => $keptByToolCall]);
    $inFlight = retAiRun(['status' => 'pending']);

    app(RetentionRunner::class)->run();

    expect(DB::table('ai_runs')->whereIn('id', $pruned)->count())->toBe(0)
        ->and(DB::table('ai_runs')->whereIn('id', [$keptByAgent, $keptByToolCall, $inFlight])->count())->toBe(3);

    // A reported cost carries its source, so the two runs are two groupings of the same day.
    $aggregates = DailyAggregate::where('source_table', 'ai_runs')->get()->keyBy(fn ($a) => $a->dimension['cost_source'] ?? 'estimate');
    expect($aggregates)->toHaveCount(2)
        ->and($aggregates->sum('rows'))->toBe(2)
        ->and($aggregates->sum('sum_tokens_in'))->toBe(40)
        ->and($aggregates->sum('sum_tokens_out'))->toBe(20)
        ->and($aggregates->sum('sum_duration_ms'))->toBe(1800)
        // The reported cost when there is one, the estimate otherwise: 0.010 + 0.025.
        ->and((float) $aggregates['estimate']->sum_cost_usd)->toBe(0.01)
        ->and((float) $aggregates['provider']->sum_cost_usd)->toBe(0.025)
        ->and((float) $aggregates['provider']->measures['estimated_cost_usd'])->toBe(0.02)
        ->and($aggregates['provider']->dimension)->toMatchArray(['task' => 'probe', 'provider' => 'anthropic', 'status' => 'succeeded']);
});

it('keeps an uncertain connector call, one that shares a kept tool call\'s key, and one made for an agent run', function () {
    $insert = fn (array $a) => DB::table('connector_calls')->insertGetId($a + [
        'connector' => 'erp', 'operation' => 'overview', 'status' => 'succeeded', 'response_status' => 200,
        'duration_ms' => 120, 'created_at' => retAt('2026-11-01 09:00'),
    ]);

    $pruned = $insert([]);
    $uncertain = $insert(['status' => 'uncertain']);
    retToolCall(['agent_key' => 'executive', 'idempotency_key' => 'k-1']);
    $keyed = $insert(['idempotency_key' => 'k-1']);
    $agentRun = AgentRun::create(['uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console', 'question' => 'q', 'autonomy_level' => 'a0', 'status' => 'answered']);
    $forAgent = $insert(['correlation_id' => $agentRun->correlation_id]);
    $forJob = $insert(['correlation_id' => (string) Str::uuid()]);

    app(RetentionRunner::class)->run();

    expect(DB::table('connector_calls')->whereIn('id', [$pruned, $forJob])->exists())->toBeFalse()
        ->and(DB::table('connector_calls')->whereIn('id', [$uncertain, $keyed, $forAgent])->count())->toBe(3)
        // jsonb orders keys its own way, so the grouping is compared as a map, not a sequence.
        ->and(DailyAggregate::where('source_table', 'connector_calls')->sole()->dimension)
        ->toEqual(['connector' => 'erp', 'operation' => 'overview', 'status' => 'succeeded', 'response_status' => 200]);
});

it('reduces old processed event payloads to a digest, leaves the rest whole, and refuses to replay a digest', function () {
    $payload = ['order_number' => 'LLD-1', 'customer_phone' => '01711000000', '_store' => 'laladia', '_correlation_id' => 'c-1'];
    $old = retEvent('processed', '2026-05-01 10:00', $payload);
    $oldDead = retEvent('dead', '2026-05-01 10:00', $payload);
    $recent = retEvent('processed', '2026-07-01 10:00', $payload);

    $run = app(RetentionRunner::class)->run();

    $digest = json_decode(DB::table('events')->where('id', $old)->value('payload'), true);
    expect(array_keys($digest))->toBe([RetentionPolicy::DIGEST_KEY])
        ->and($digest['_retention'])->toMatchArray([
            'policy' => 'retention.v1',
            'run' => $run->uuid,
            'keys' => ['_correlation_id', '_store', 'customer_phone', 'order_number'],
            'store' => 'laladia',
            'correlation_id' => 'c-1',
        ])
        ->and($digest['_retention']['sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($digest['_retention']['bytes'])->toBeGreaterThan(0)
        ->and(json_encode($digest))->not->toContain('01711000000')
        ->and(json_decode(DB::table('events')->where('id', $oldDead)->value('payload'), true))->toEqual($payload)
        ->and(json_decode(DB::table('events')->where('id', $recent)->value('payload'), true))->toEqual($payload)
        ->and($run->summary['event_payloads']['compacted'])->toBe(1);

    // A second pass finds nothing left to reduce.
    expect(app(RetentionRunner::class)->run()->summary['event_payloads']['compacted'])->toBe(0);

    $eventId = DB::table('events')->where('id', $old)->value('event_id');
    expect(Artisan::call('hq:events:replay', ['--event' => [$eventId], '--apply' => true, '--reason' => 'test']))->toBe(1)
        ->and(Artisan::output())->toContain('reduced to a digest');
});

it('never runs two passes at once, and marks a pass whose process died as abandoned', function () {
    retToolCall();

    $lock = Cache::lock('hq:retention:run', 60);
    expect($lock->get())->toBeTrue();
    expect(fn () => app(RetentionRunner::class)->run())->toThrow(RuntimeException::class, 'Another retention pass');
    $lock->release();
    expect(RetentionRun::count())->toBe(0);

    // A run left `running` long ago is marked abandoned by the next pass.
    $stale = RetentionRun::create(['uuid' => (string) Str::uuid(), 'policy_version' => 'retention.v1', 'policy' => [],
        'status' => RetentionRun::RUNNING, 'started_at' => now()->subHours(3)]);
    Artisan::call('hq:retention:run');
    expect($stale->fresh()->status)->toBe(RetentionRun::ABANDONED)
        ->and(RetentionRun::where('status', RetentionRun::SUCCEEDED)->count())->toBe(1)
        ->and(Artisan::output())->toContain('tool_calls');
});
