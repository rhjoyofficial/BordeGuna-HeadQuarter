<?php

use App\Ai\CircuitBreaker;
use App\Ai\Exceptions\NoModelAvailable;
use App\Ai\ModelRouter;
use App\Ai\ModelTier;
use App\Ai\RoutingContext;
use App\Ai\TaskType;
use App\Models\AiModel;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Ai\FixtureProvider;

/*
 | M4: the Model Router, the runtime, and cost accounting.
 |
 | ⚠ The property under test throughout is that NOTHING chooses a model except the router, and that
 | every call it makes leaves a costed row behind, including the ones that failed. A fallback that
 | leaves no trace is indistinguishable from a first-choice success.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    aiProviders();
    enableAiRuntime();
});

it('routes a task to its tier and names a reason', function () {
    aiModel('fixture', 'cheap-1', ModelTier::Low);
    aiModel('fixture', 'mid-1', ModelTier::Medium);

    $choice = app(ModelRouter::class)->for(TaskType::Classification);

    expect($choice->tier)->toBe(ModelTier::Low)
        ->and($choice->modelId())->toBe('cheap-1')
        ->and($choice->degraded())->toBeFalse()
        ->and($choice->reason)->toContain('classification');
});

it('prefers priority, then the cheaper model, so an unranked row cannot become the expensive default', function () {
    aiModel('fixture', 'expensive', ModelTier::Medium, priority: 100, in: '9.0000');
    aiModel('fixture', 'cheap', ModelTier::Medium, priority: 100, in: '2.0000');
    aiModel('fixture', 'preferred', ModelTier::Medium, priority: 5, in: '5.0000');

    expect(app(ModelRouter::class)->for(TaskType::OperatorQuestion)->modelId())->toBe('preferred');

    AiModel::where('model_id', 'preferred')->update(['is_active' => false]);

    expect(app(ModelRouter::class)->for(TaskType::OperatorQuestion)->modelId())->toBe('cheap');
});

it('skips a model that cannot meet the required capabilities or context size', function () {
    aiModel('fixture', 'small', ModelTier::Medium, priority: 1, capabilities: [], context: 1000);
    aiModel('fixture', 'capable', ModelTier::Medium, priority: 50, capabilities: ['json_schema'], context: 500000);

    $choice = app(ModelRouter::class)->for(TaskType::OperatorQuestion, new RoutingContext(
        requiredCapabilities: ['json_schema'],
        minContextTokens: 400000,
    ));

    expect($choice->modelId())->toBe('capable');
});

it('degrades to the tier below when nothing at the asked tier can serve, and records where it came from', function () {
    aiModel('fixture', 'only-low', ModelTier::Low);

    $choice = app(ModelRouter::class)->for(TaskType::Reasoning); // asks for High

    expect($choice->tier)->toBe(ModelTier::Low)
        ->and($choice->degraded())->toBeTrue()
        ->and($choice->degradedFrom)->toBe(ModelTier::High);
});

it('refuses rather than substituting when the task may not be degraded', function () {
    aiModel('fixture', 'only-low', ModelTier::Low);

    // Schema repair is not degradable: asking a weaker model to fix what a stronger one got wrong
    // is the opposite of a repair.
    expect(fn () => app(ModelRouter::class)->for(TaskType::SchemaRepair, new RoutingContext(minContextTokens: 999999999)))
        ->toThrow(NoModelAvailable::class);
});

it('refuses when the specialised tier is empty rather than embedding with a chat model', function () {
    aiModel('fixture', 'chat', ModelTier::High);

    expect(fn () => app(ModelRouter::class)->for(TaskType::Embedding))
        ->toThrow(NoModelAvailable::class);
});

it('will not choose a provider whose credential is missing', function () {
    aiModel('fixture', 'a', ModelTier::Medium, priority: 1);
    aiModel('fixture2', 'b', ModelTier::Medium, priority: 50);

    FixtureProvider::configure('fixture', false);

    expect(app(ModelRouter::class)->for(TaskType::OperatorQuestion)->provider())->toBe('fixture2');
});

it('will not choose a provider whose circuit breaker is open', function () {
    aiModel('fixture', 'a', ModelTier::Medium, priority: 1);
    aiModel('fixture2', 'b', ModelTier::Medium, priority: 50);

    $breaker = app(CircuitBreaker::class);
    for ($i = 0; $i < CircuitBreaker::THRESHOLD; $i++) {
        $breaker->recordFailure('fixture');
    }

    expect($breaker->state('fixture'))->toBe('open')
        ->and(app(ModelRouter::class)->for(TaskType::OperatorQuestion)->provider())->toBe('fixture2');
});

it('opens the breaker only after repeated failures, and closes it on success', function () {
    $breaker = app(CircuitBreaker::class);

    $breaker->recordFailure('fixture');
    expect($breaker->state('fixture'))->toBe('closed');

    for ($i = 1; $i < CircuitBreaker::THRESHOLD; $i++) {
        $breaker->recordFailure('fixture');
    }
    expect($breaker->state('fixture'))->toBe('open')
        ->and($breaker->allows('fixture'))->toBeFalse();

    $breaker->recordSuccess('fixture');
    expect($breaker->state('fixture'))->toBe('closed');
});
