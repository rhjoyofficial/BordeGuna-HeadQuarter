<?php

use App\Ai\CostCalculator;
use App\Ai\Exceptions\BudgetExceeded;
use App\Ai\ModelRuntime;
use App\Ai\ModelTier;
use App\Ai\RoutingContext;
use App\Ai\TaskType;
use App\Console\Commands\AgentEvaluate;
use App\Models\AiBudgetRow;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\EvaluationRun;
use App\Support\Money\UsdBdtRate;
use Carbon\CarbonImmutable;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Ai\FixtureProvider;
use Tests\Support\Money\Fx;

/*
 | Joy's rule, amounts always in BDT, applied to the text the SERVER writes about a model cost: the
 | briefing footer, the budget refusal, the operator commands. BDT comes first and the stored USD
 | figure sits beside it in brackets. Only the text changes: costs are still stored, capped and
 | compared in USD, and the rate is the owner's dated reference rate (`FxRates`), never a second source.
 */

beforeEach(function () {
    Cache::flush();
    FixtureProvider::reset();
    aiProviders();
    enableAiRuntime();
    // The owner's dated reference rate (ruling 1 Nov 2026); 130 keeps the arithmetic below easy to read.
    Fx::set('130.00', '136.50');
});

it('writes BDT first with the USD figure in brackets, and USD alone when no rate is configured', function () {
    expect(UsdBdtRate::display('0.887800'))->toBe('BDT 115.41 (USD 0.8878)')
        ->and(UsdBdtRate::display(5.0, 6))->toBe('BDT 650.00 (USD 5.000000)')
        ->and(UsdBdtRate::display(null))->toBe('BDT 0.00 (USD 0.0000)');

    // A stale rate is not shown as BDT: the text falls back to the stored USD alone.
    Fx::set('130.00', '136.50', CarbonImmutable::now()->subDays(40), CarbonImmutable::now()->subDay());
    expect(UsdBdtRate::display('0.887800'))->toBe('USD 0.8878');
});

it('formats an operator cost in BDT through the cost calculator', function () {
    expect(app(CostCalculator::class)->format('0.010000'))->toBe('BDT 1.30 (USD 0.0100)');
});

it('puts the cost on the briefing provenance footer in BDT, and leaves the stored USD alone', function () {
    aiModel('fixture', 'a', ModelTier::Medium, in: '10.0000', out: '0.0000');
    FixtureProvider::script('fixture', [FixtureProvider::reply('ok', in: 1000, out: 0)]);

    $outcome = app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']],
        new RoutingContext(businessId: null, inputRef: 'test:bdt'));

    expect($outcome->provenance())->toContain('BDT 1.30 (USD 0.0100)')
        ->and($outcome->provenance())->not->toContain('$')
        ->and((string) $outcome->run->estimated_cost_usd)->toBe('0.010000');
});

it('states a hard-cap refusal in BDT, in the exception and in the notification', function () {
    aiModel('fixture', 'a', ModelTier::Medium);
    AiBudgetRow::create(['scope_type' => 'global', 'scope_ref' => null, 'period' => 'monthly', 'limit_usd' => '1.0000', 'enforcement' => 'hard']);
    AiRun::create(['task' => 'seed', 'status' => 'succeeded', 'estimated_cost_usd' => '5.000000']);

    expect(fn () => app(ModelRuntime::class)->run(TaskType::OperatorQuestion, 'sys', [['role' => 'user', 'content' => 'hi']]))
        ->toThrow(BudgetExceeded::class, 'The AI spend cap for global is BDT 130.00 (USD 1.00) and BDT 650.00 (USD 5.0000) has been spent this period.');

    $notice = json_encode(AuditLog::where('action', 'notification.raised')->latest('id')->first()?->toArray());
    expect($notice)->toContain('BDT 650.00 (USD 5.00) against a HARD cap of BDT 130.00 (USD 1.00)');
});

it('reports spend and the cap in BDT from hq:ai:cost', function () {
    AiBudgetRow::create(['scope_type' => 'global', 'scope_ref' => null, 'period' => 'monthly', 'limit_usd' => '10.0000', 'enforcement' => 'soft']);
    AiRun::create(['task' => 'seed', 'status' => 'succeeded', 'model' => 'a', 'estimated_cost_usd' => '5.000000']);

    $this->artisan('hq:ai:cost')
        ->expectsOutputToContain('1 runs, BDT 650.00 (USD 5.000000) total')
        ->expectsOutputToContain('Cap (global): BDT 650.00 (USD 5.0000) of BDT 1,300.00 (USD 10.00), 50%')
        ->assertSuccessful();
});

it('shows the budget in BDT on a dry hq:ai:probe', function () {
    aiModel('fixture', 'a', ModelTier::Low);
    AiBudgetRow::create(['scope_type' => 'global', 'scope_ref' => null, 'period' => 'monthly', 'limit_usd' => '10.0000', 'enforcement' => 'soft']);
    AiRun::create(['task' => 'seed', 'status' => 'succeeded', 'estimated_cost_usd' => '1.000000']);

    $this->artisan('hq:ai:probe')
        ->expectsOutputToContain('spent BDT 130.00 (USD 1.0000) of BDT 1,300.00 (USD 10.00)')
        ->assertSuccessful();
});

it('prints an evaluation run cost in BDT from hq:agent:evaluate', function () {
    $run = new EvaluationRun([
        'status' => 'passed', 'cases_total' => 1, 'cases_skipped' => 0, 'cases_passed' => 1, 'cases_failed' => 0,
        'cases_unmeasured' => 0, 'agreement_rate' => '1.000', 'cost_usd' => '0.010000', 'cost_estimated_usd' => '0.010000',
        'cost_provider_usd' => null, 'cost_attribution' => ['ai_runs' => 2, 'retry_attempts' => 0, 'complete' => true],
    ]);

    $buffer = new BufferedOutput;
    $command = app(AgentEvaluate::class);
    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
    (fn () => $this->report('suite', $run))->call($command);

    expect($buffer->fetch())->toContain('cost   BDT 1.30 (USD 0.010000); estimated BDT 1.30 (USD 0.010000); provider-reported none; over 2 model call(s)');
});
