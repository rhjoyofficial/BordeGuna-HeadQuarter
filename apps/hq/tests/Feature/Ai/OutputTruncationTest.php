<?php

use App\Ai\Exceptions\OutputTruncated;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\ModelRuntime;
use App\Ai\ModelTier;
use App\Ai\OutputBudget;
use App\Ai\Providers\ModelResponse;
use App\Ai\TaskType;
use App\Models\AiModel;
use App\Models\AiRun;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;

/*
 | A cut-off reply is a budget, not a malformed object (docs/66, ADR-038).
 |
 | Two gates in a row recorded a `model_error` that was really `stop_reason = max_tokens`: a 4,096
 | constant, an answer that needed twice that, a schema check that called the unfinished object
 | malformed, and a repair that ran into the same wall. These tests hold the correction at both
 | layers: the policy that decides how much room a call gets, and the runtime that tells a
 | truncation apart from a validation failure and recovers from it exactly once.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    aiProviders();
    enableAiRuntime();
});

$schema = ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label'], 'additionalProperties' => false];

function askForLabel(array $schema, ?int $budget = 1024)
{
    return app(ModelRuntime::class)->run(
        task: TaskType::Classification,
        system: 'sys',
        messages: [['role' => 'user', 'content' => 'classify']],
        jsonSchema: $schema,
        maxOutputTokens: $budget,
    );
}

// ── The policy ──────────────────────────────────────────────────────────────────────────────────

it('clamps a requested budget to the model, then to the ceiling, and never below one', function () {
    $policy = new OutputBudget(['default' => 1024, 'agent_turn' => 8192, 'ceiling' => 16384, 'growth_factor' => 2]);
    $wide = new AiModel(['max_output_tokens' => 64000]);
    $narrow = new AiModel(['max_output_tokens' => 4096]);

    expect($policy->for(OutputBudget::AGENT_TURN))->toBe(8192)
        ->and($policy->for('no_such_class'))->toBe(1024)
        ->and($policy->clamp(8192, $wide))->toBe(8192)
        ->and($policy->clamp(8192, $narrow))->toBe(4096)
        ->and($policy->clamp(100000, $wide))->toBe(16384)
        ->and($policy->clamp(0, $wide))->toBe(1);
});

it('raises a truncated budget by the growth factor, stops at the smaller of model and ceiling, and says null when there is no headroom', function () {
    $policy = new OutputBudget(['ceiling' => 16384, 'growth_factor' => 2]);
    $wide = new AiModel(['max_output_tokens' => 64000]);
    $narrow = new AiModel(['max_output_tokens' => 4096]);

    expect($policy->raised(4096, $wide))->toBe(8192)
        ->and($policy->raised(8192, $wide))->toBe(16384)
        // Already at the ceiling: a retry would meet the same wall, so the honest answer is null.
        ->and($policy->raised(16384, $wide))->toBeNull()
        // Already at the model's limit, likewise.
        ->and($policy->raised(4096, $narrow))->toBeNull()
        // Room for less than a full doubling is still room.
        ->and($policy->raised(3000, $narrow))->toBe(4096);
});

it('ships an agent-turn budget at least twice the constant that failed gate 3, read from config rather than a class constant', function () {
    // 4,096 was the number an eight-call sweep could not fit in (docs/65 §F). The shipped policy
    // must clear it with room, and it must be configuration, so a longer day does not need a deploy.
    expect((new OutputBudget)->for(OutputBudget::AGENT_TURN))->toBeGreaterThanOrEqual(8192)
        ->and((int) config('hq.ai.output.agent_turn'))->toBeGreaterThanOrEqual(8192)
        ->and((new OutputBudget)->truncationRetries())->toBe(1);
});

// ── The runtime ─────────────────────────────────────────────────────────────────────────────────

it('re-asks a truncated structured reply once with a larger budget, and records both calls on the run', function () use ($schema) {
    aiModel('fixture', 'a', ModelTier::Low, in: '1.0000', out: '1.0000');

    FixtureProvider::script('fixture', [
        FixtureProvider::truncated('{"label":"unfini', in: 100, out: 1024),
        FixtureProvider::reply('{"label":"whole"}', in: 120, out: 30),
    ]);

    $outcome = askForLabel($schema, 1024);

    expect($outcome->data)->toBe(['label' => 'whole'])
        ->and(FixtureProvider::callsTo('fixture'))->toBe(2);

    // The second request carried twice the room; nothing else about it changed. It is NOT a repair
    // turn: the model is not told its reply was malformed, because it was not.
    [$first, $second] = FixtureProvider::requestsTo('fixture');
    expect($first->maxOutputTokens)->toBe(1024)
        ->and($second->maxOutputTokens)->toBe(2048)
        ->and($second->messages)->toBe($first->messages);

    // Both calls are billed, so both are counted, and the row says how the LAST reply ended and
    // how much room it had.
    $run = $outcome->run;
    expect($run->status)->toBe('succeeded')
        ->and($run->input_tokens)->toBe(220)
        ->and($run->output_tokens)->toBe(1054)
        ->and($run->stop_reason)->toBe(ModelResponse::STOP_END_TURN)
        ->and($run->max_output_tokens)->toBe(2048);
});

it('fails as a truncation, never as a malformed object, when the raised budget is cut off too', function () use ($schema) {
    aiModel('fixture', 'a', ModelTier::Low);

    FixtureProvider::script('fixture', [
        FixtureProvider::truncated('{"label":"unfini', out: 1024),
        FixtureProvider::truncated('{"label":"still unfini', out: 2048),
    ]);

    expect(fn () => askForLabel($schema, 1024))->toThrow(OutputTruncated::class, 'twice');

    // ⚠ Exactly two calls: one raise, no repair. A repair would run into the same wall, which is
    // what happened on gate 3.
    expect(FixtureProvider::callsTo('fixture'))->toBe(2);

    $run = AiRun::latest('id')->firstOrFail();
    expect($run->status)->toBe('failed')
        ->and($run->error)->toStartWith('output_truncated:')
        ->and($run->error)->not->toContain('structured_output_failed')
        ->and($run->stop_reason)->toBe(ModelResponse::STOP_MAX_TOKENS)
        ->and($run->max_output_tokens)->toBe(2048)
        ->and($run->output_tokens)->toBe(3072);
});

it('does not retry a truncation when the budget is already at the model limit, and says so', function () use ($schema) {
    // The test helper registers every model with a 4,096 limit.
    aiModel('fixture', 'a', ModelTier::Low);
    FixtureProvider::script('fixture', [FixtureProvider::truncated('{"label":"unfini', out: 4096)]);

    expect(fn () => askForLabel($schema, 4096))->toThrow(OutputTruncated::class, 'no headroom');

    expect(FixtureProvider::callsTo('fixture'))->toBe(1);
    expect(AiRun::latest('id')->firstOrFail()->stop_reason)->toBe(ModelResponse::STOP_MAX_TOKENS);
});

it('reports a repair that was itself cut off as a truncation, not as a second malformed reply', function () use ($schema) {
    aiModel('fixture', 'a', ModelTier::Low);

    FixtureProvider::script('fixture', [
        FixtureProvider::reply('not json at all'),
        FixtureProvider::truncated('{"label":"unfini', out: 1024),
    ]);

    expect(fn () => askForLabel($schema, 1024))->toThrow(OutputTruncated::class, 'repair reply');
    expect(FixtureProvider::callsTo('fixture'))->toBe(2);

    $run = AiRun::latest('id')->firstOrFail();
    expect($run->error)->toStartWith('output_truncated:')
        ->and($run->stop_reason)->toBe(ModelResponse::STOP_MAX_TOKENS);
});

it('still fails a reply that is malformed twice as a structured-output failure, so the two classes stay apart', function () use ($schema) {
    aiModel('fixture', 'a', ModelTier::Low);
    FixtureProvider::script('fixture', [FixtureProvider::reply('nope'), FixtureProvider::reply('{"wrong":"shape"}')]);

    expect(fn () => askForLabel($schema, 1024))->toThrow(StructuredOutputFailed::class);

    $run = AiRun::latest('id')->firstOrFail();
    expect($run->error)->toStartWith('structured_output_failed:')
        ->and($run->stop_reason)->toBe(ModelResponse::STOP_END_TURN);
});

it('records the stop reason and the clamped budget on an ordinary successful call', function () {
    aiModel('fixture', 'a', ModelTier::Medium);
    FixtureProvider::script('fixture', [FixtureProvider::reply('fine')]);

    $outcome = app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']]);

    expect($outcome->run->stop_reason)->toBe(ModelResponse::STOP_END_TURN)
        // No budget asked for: the policy's default, clamped to the 4,096 test model.
        ->and($outcome->run->max_output_tokens)->toBe((new OutputBudget)->default());
});

it('never sends more than the configured ceiling, whatever the caller asked for', function () {
    config(['hq.ai.output.ceiling' => 512]);
    aiModel('fixture', 'a', ModelTier::Medium);
    FixtureProvider::script('fixture', [FixtureProvider::reply('fine')]);

    app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']], maxOutputTokens: 4096);

    expect(FixtureProvider::requestsTo('fixture')[0]->maxOutputTokens)->toBe(512);
});
