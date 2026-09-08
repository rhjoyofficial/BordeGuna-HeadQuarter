<?php

use App\Gateway\Registry\ToolAvailability;
use App\Gateway\Registry\ToolRegistrySync;
use App\Models\AuditLog;
use App\Models\ToolRegistration;
use Illuminate\Support\Facades\Cache;

/*
 | The registry screen and the sync (brief sections 4 and 16).
 |
 | An unreachable control is not a control: the route, the screen, the nav link and the permission
 | are all checked, which is the standing rule that found nine dead engines in the ERP.
 */

beforeEach(fn () => Cache::flush());

it('shows the registry to a permitted operator and refuses everyone else', function () {
    $user = $this->hqUser('owner');

    $this->actingAsHq($user)->get('/tools')
        ->assertOk()
        // The second argument switches off Inertia's page-file lookup, which searches
        // resources/js/Pages while this project uses a lowercase `pages` directory. The file's
        // existence is asserted directly below instead, so nothing is taken on trust.
        ->assertInertia(fn ($page) => $page->component('tools/index', false)->has('tools'));

    $stranger = $this->hqUser('support_agent');
    $this->actingAsHq($stranger)->get('/tools')->assertForbidden();
});

it('offers the kill switch only to an operator who may manage tools', function () {
    // brand_manager can see the registry; they cannot switch anything off.
    $viewer = $this->hqUser('brand_manager');
    $this->actingAsHq($viewer)->get('/tools')->assertOk();
    $this->actingAsHq($viewer)->post('/tools/noop/toggle', ['enabled' => false, 'reason' => 'x'])->assertForbidden();

    expect(ToolRegistration::where('key', 'noop')->exists())->toBeFalse();
});

it('refuses to disable a tool without a reason', function () {
    $this->actingAsHq($this->hqUser('owner'))
        ->post('/tools/noop/toggle', ['enabled' => false, 'reason' => '  '])
        ->assertSessionHasErrors('reason');

    // "Why is this off?" asked three weeks later is the question the record has to answer.
    expect(ToolRegistration::where('key', 'noop')->where('enabled', false)->exists())->toBeFalse();
});

it('disables and re-enables a tool from the screen, auditing both', function () {
    $user = $this->hqUser('owner');

    $this->actingAsHq($user)->post('/tools/noop/toggle', ['enabled' => false, 'reason' => 'noisy'])->assertRedirect();
    expect(app(ToolAvailability::class)->isAvailable('noop'))->toBeFalse();

    Cache::flush();
    $this->actingAsHq($user)->post('/tools/noop/toggle', ['enabled' => true])->assertRedirect();
    expect(app(ToolAvailability::class)->isAvailable('noop'))->toBeTrue();

    expect(AuditLog::whereIn('action', ['tool.disabled', 'tool.enabled'])->count())->toBe(2);
});

it('treats a missing row as enabled, so a newly deployed tool works before any sync', function () {
    // The asymmetry that removes the startup-ordering problem entirely, and makes a wiped `tools`
    // table fail open to exactly the capabilities the deployed code contains.
    expect(ToolRegistration::count())->toBe(0)
        ->and(app(ToolAvailability::class)->isAvailable('noop'))->toBeTrue();
});

it('syncs rows for every registered tool, is safe to run twice, and never deletes', function () {
    $sync = app(ToolRegistrySync::class);

    $first = $sync->sync();
    expect($first['created'])->toContain('noop', 'erp.poll_overview', 'commerce.reconcile_facts')
        ->and($first['orphaned'])->toBe([]);

    $second = $sync->sync();
    expect($second['created'])->toBe([])
        ->and(count($second['existing']))->toBe(count($first['created']));

    // A row for a tool no longer in code is reported and kept: a recorded decision to disable
    // something is worth more than the tidiness of deleting it, and it should come back if the
    // class does.
    ToolRegistration::create(['key' => 'retired.tool', 'enabled' => false, 'disabled_reason' => 'double charged']);
    Cache::flush();

    expect(app(ToolAvailability::class)->orphans())->toContain('retired.tool')
        ->and($sync->sync()['orphaned'])->toContain('retired.tool')
        ->and(ToolRegistration::where('key', 'retired.tool')->exists())->toBeTrue();
});

it('links the registry from the sidebar for a permitted user', function () {
    // Route plus screen plus nav link: a screen nobody can navigate to is a screen nobody uses.
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain("url: '/tools'")
        ->and($sidebar)->toContain("permission: 'hq.tools.view'")
        ->and(file_exists(resource_path('js/pages/tools/index.tsx')))->toBeTrue();
});
