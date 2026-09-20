<?php

use App\Briefing\ExecutiveBriefingContract;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Briefing;
use App\Models\Business;
use App\Models\User;
use App\Models\UserBusinessScope;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/*
 | The read-only briefing pages (docs/80 C.1): a stored briefing's bundle with each item's source,
 | as_of, freshness and lineage, behind a permission that already exists and inside the viewer's
 | business scope.
 */

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    (new RolesAndPermissionsSeeder)->run();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00', 'Asia/Dhaka'));
});

function pageViewer(array|string $grant = 'owner'): User
{
    $user = User::factory()->create();
    is_array($grant) ? $user->givePermissionTo($grant) : $user->assignRole($grant);
    $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

function storedBriefing(User $by, array $args = []): Briefing
{
    $outcome = app(ToolGateway::class)->execute(ToolRequest::forOperator($by, 'briefing.compose', $args));

    return Briefing::where('uuid', $outcome->output['briefing_uuid'])->sole();
}

it('lists stored briefings and shows one with its sources first and every item\'s provenance', function () {
    $owner = pageViewer();
    $briefing = storedBriefing($owner);

    $this->actingAsHq($owner)->get('/observe/briefings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('observe/briefings')
            ->has('briefings', 1)
            ->where('briefings.0.uuid', $briefing->uuid)
            ->where('briefings.0.state', 'degraded')
            ->where('briefings.0.narration_state', 'off')
            ->where('contract.capability', ExecutiveBriefingContract::CAPABILITY));

    $this->actingAsHq($owner)->get("/observe/briefings/{$briefing->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('observe/briefing')
            ->where('briefing.bundle_intact', true)
            ->where('briefing.narrative', null)
            ->has('briefing.degraded_reasons')
            ->has('bundle.freshness.sources')
            ->where('bundle.freshness.missing', fn ($missing) => collect($missing)->contains('hq:integrity_check'))
            ->has('bundle.sections', count(ExecutiveBriefingContract::SECTIONS))
            ->where('bundle.items', fn ($items) => collect($items)->every(fn ($i) => isset($i['source']['ref'], $i['freshness']['state']) && array_key_exists('as_of', $i) && array_key_exists('lineage', $i))));
});

it('is behind hq.tools.facts.read, and has no route that writes', function () {
    $briefing = storedBriefing(pageViewer());

    $this->actingAsHq(pageViewer(['hq.dashboard.view']))->get('/observe/briefings')->assertForbidden();
    $this->actingAsHq(pageViewer(['hq.dashboard.view']))->get("/observe/briefings/{$briefing->uuid}")->assertForbidden();
    $this->actingAsHq(pageViewer(['hq.tools.facts.read']))->get('/observe/briefings')->assertOk();

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'observe/briefings'));
    expect($routes)->toHaveCount(2)
        ->and($routes->flatMap(fn ($r) => $r->methods())->unique()->values()->all())->toEqualCanonicalizing(['GET', 'HEAD']);
});

it('shows a person limited to some businesses only the briefings inside them', function () {
    $owner = pageViewer();
    $group = storedBriefing($owner);
    $laladia = storedBriefing($owner, ['business_slug' => 'laladia']);
    $bionic = storedBriefing($owner, ['business_slug' => 'bionic']);

    $limited = pageViewer(['hq.tools.facts.read']);
    UserBusinessScope::create(['user_id' => $limited->id, 'business_id' => Business::where('slug', 'laladia')->value('id')]);
    $own = storedBriefing($limited->fresh());

    $this->actingAsHq($limited->fresh())->get('/observe/briefings')
        ->assertInertia(fn (Assert $page) => $page->where('briefings', fn ($rows) => collect($rows)->pluck('uuid')->sort()->values()->all() === collect([$laladia->uuid, $own->uuid])->sort()->values()->all()));

    $this->actingAsHq($limited->fresh())->get("/observe/briefings/{$group->uuid}")->assertNotFound();
    $this->actingAsHq($limited->fresh())->get("/observe/briefings/{$bionic->uuid}")->assertNotFound();
    $this->actingAsHq($limited->fresh())->get("/observe/briefings/{$own->uuid}")->assertOk();
});
