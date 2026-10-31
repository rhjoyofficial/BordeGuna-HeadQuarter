<?php

use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AgentRun;
use App\Models\OwnerPreference;
use App\Support\Money\UsdBdtRate;
use Database\Seeders\AgentSeeder;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
 | Joy's rule, amounts always in BDT: every screen that shows a model cost shows BDT by default, and a
 | switch on each cost surface shows USD instead (one currency at a time). The rate is the server's (`hq.ads.draft.usd_bdt_rate`), shared on every page
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

it('keeps the rate out of the browser code, no dollar sign, and a currency switch on every cost surface', function () {
    $helpers = file_get_contents(resource_path('js/lib/format.ts')).file_get_contents(resource_path('js/components/ai-cost.tsx'));
    expect($helpers)->not->toMatch('/\b130\b/');

    foreach ([
        'js/components/console/live-panel.tsx',
        'js/pages/agents/index.tsx',
        'js/pages/agents/show.tsx',
        'js/pages/observe/cost.tsx',
    ] as $file) {
        $source = file_get_contents(resource_path($file));
        expect($source)->toContain('<AiCost ')
            ->and($source)->toContain('<AiCostToggle')
            ->and($source)->not->toContain('humanUsd')
            ->and($source)->not->toContain('`$${');
    }
});

it('shows one currency at a time, BDT by default, never the paired figure', function () {
    $format = file_get_contents(resource_path('js/lib/format.ts'));
    $component = file_get_contents(resource_path('js/components/ai-cost.tsx'));

    expect($format)->toContain("currency: AiCostCurrency = 'BDT'")
        ->and($format)->not->toContain('secondary')
        ->and($component)->not->toContain('secondary')
        // Without a server rate the switch is off and costs read USD.
        ->and($component)->toContain("currency: rate === null ? 'USD' : preferred")
        ->and($component)->toContain('disabled={!canSwitch}')
        ->and($component)->toContain('aria-label=');
});

/*
 | The preference path: this browser's localStorage, wrapped so a blocked store falls back to BDT, and
 | never `owner_preferences` (every row there is read into each agent's prompt) or any server write.
 */
it('keeps the currency choice in this browser, falling back to BDT, and never on the server', function () {
    $component = file_get_contents(resource_path('js/components/ai-cost.tsx'));

    expect($component)->toContain("AI_COST_CURRENCY_KEY = 'hq-ai-cost-currency'")
        ->and(preg_match_all('/try \{\s*(?:return )?window\.localStorage\.(getItem|setItem)/', $component))->toBe(2)
        ->and($component)->toMatch("/getItem\(AI_COST_CURRENCY_KEY\) === 'USD' \? 'USD' : 'BDT';\s*\} catch \{\s*return 'BDT';/")
        ->and($component)->not->toContain('router.')
        ->and($component)->not->toContain('fetch(')
        ->and($component)->not->toContain('axios');

    // Visiting every cost surface stores nothing for the person on the server.
    $run = bdtRateRun();
    foreach (['/dashboard', '/agents', "/agents/runs/{$run->id}", '/observe/cost'] as $url) {
        $this->actingAsHq($this->owner)->get($url)->assertOk();
    }
    expect(OwnerPreference::count())->toBe(0);
});
