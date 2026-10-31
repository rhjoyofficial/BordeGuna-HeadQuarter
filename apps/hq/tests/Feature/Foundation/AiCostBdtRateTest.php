<?php

use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AgentRun;
use App\Support\Money\UsdBdtRate;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
 | Joy's rule, amounts always in BDT: every screen that shows a model cost shows BDT first and USD as
 | the secondary figure. The rate is the server's (`hq.ads.draft.usd_bdt_rate`), shared on every page
 | as `money.usd_bdt_rate`; the browser converts for display only and never holds a rate of its own.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AgentSeeder::class);
    $this->owner = $this->hqUser('owner');
});

function bdtRateRun(): AgentRun
{
    return AgentRun::create([
        'uuid' => (string) Str::uuid(), 'agent_key' => 'executive', 'correlation_id' => (string) Str::uuid(),
        'trigger' => 'console', 'question' => 'q', 'autonomy_level' => AutonomyLevel::A0->value,
        'shadow' => true, 'status' => 'answered', 'answer' => 'a', 'turns' => 1, 'tool_calls_count' => 0,
        'started_at' => now(), 'finished_at' => now(),
    ]);
}

it('shares the configured rate on every page that shows a model cost', function () {
    $run = bdtRateRun();

    foreach (['/dashboard', '/agents', "/agents/runs/{$run->id}", '/observe/cost'] as $url) {
        $this->actingAsHq($this->owner)->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('money.usd_bdt_rate', 130));
    }
});

it('follows the configuration, and shares null rather than a guessed rate when none is set', function () {
    config(['hq.ads.draft.usd_bdt_rate' => '121.50']);
    $this->actingAsHq($this->owner)->get('/observe/cost')
        ->assertInertia(fn ($page) => $page->where('money.usd_bdt_rate', 121.5));

    foreach (['', 'abc', '0', '-5'] as $bad) {
        config(['hq.ads.draft.usd_bdt_rate' => $bad]);
        expect(UsdBdtRate::current())->toBeNull();
    }

    config(['hq.ads.draft.usd_bdt_rate' => '']);
    $this->actingAsHq($this->owner)->get('/observe/cost')
        ->assertInertia(fn ($page) => $page->where('money.usd_bdt_rate', null));
});

it('keeps the rate out of the browser code and no dollar sign on the cost screens', function () {
    $helpers = file_get_contents(resource_path('js/lib/format.ts')).file_get_contents(resource_path('js/components/ai-cost.tsx'));
    expect($helpers)->not->toMatch('/\b130\b/');

    foreach ([
        'js/components/console/live-panel.tsx',
        'js/pages/agents/index.tsx',
        'js/pages/agents/show.tsx',
        'js/pages/observe/cost.tsx',
    ] as $file) {
        $source = file_get_contents(resource_path($file));
        expect($source)->toContain('<AiCost')
            ->and($source)->not->toContain('humanUsd')
            ->and($source)->not->toContain('`$${');
    }
});
