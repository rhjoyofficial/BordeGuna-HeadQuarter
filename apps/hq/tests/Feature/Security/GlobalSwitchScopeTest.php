<?php

use App\Agents\Shadow\ShadowPeriods;
use App\Ai\ProviderSelection;
use App\Autonomy\Evidence\EvidencePolicies;
use App\Conversation\Language\LanguagePolicies;
use App\Gateway\Registry\ToolSwitch;
use App\Models\AuditLog;
use App\Models\AutonomyEvidencePolicy;
use App\Models\Business;
use App\Models\FeatureFlag;
use App\Models\LanguagePolicy;
use App\Models\ShadowPeriod;
use App\Models\ToolRegistration;
use App\Models\UserBusinessScope;
use App\Support\Authorization\GroupWideAuthority;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
 | docs/138 ruling 13, docs/136: a switch that acts on every business needs explicit group-wide
 | authority. The person holds the switch's permission AND has no business restriction; nobody named,
 | or a scope that cannot be read, is refused. The backend enforces it (route middleware, controller,
 | service, console command), the page gets no control, and the audit row carries actor, scope,
 | before, after and reason.
 */

/**
 * Every route that throws a switch acting on every business, with the permission it needs. A new
 * global switch route fails the structural tests below until it is added here WITH the guard.
 */
const GLOBAL_SWITCH_ROUTES = [
    'flags.store' => 'hq.flags.manage',
    'flags.toggle' => 'hq.flags.manage',
    'tools.toggle' => 'hq.tools.manage',
];

/** Permissions whose every write route is, by definition, a global switch. */
const GLOBAL_SWITCH_PERMISSIONS = ['hq.flags.manage', 'hq.tools.manage'];

/** The services a global switch reaches; each checks the authority itself, so no caller can skip it. */
const GLOBAL_SWITCH_SERVICES = [
    'app/Gateway/Registry/ToolSwitch.php',
    'app/Ai/ProviderSelection.php',
    'app/Agents/Shadow/ShadowPeriods.php',
    'app/Autonomy/Evidence/EvidencePolicies.php',
    'app/Conversation/Language/LanguagePolicies.php',
];

beforeEach(function () {
    Cache::flush();
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = $this->hqUser('owner');
    // An operator holds every permission through '*', so each refusal below is the business restriction.
    $this->restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $this->restricted->id, 'business_id' => Business::orderBy('id')->firstOrFail()->id]);

    $this->flag = FeatureFlag::create(['key' => 'phase3.test', 'environment' => '*', 'enabled' => false, 'description' => 'test switch']);
});

it('refuses a restricted operator who holds the permission on every global switch route, with 403 and no change', function () {
    expect($this->restricted->hasPermissionTo('hq.flags.manage'))->toBeTrue()
        ->and($this->restricted->hasPermissionTo('hq.tools.manage'))->toBeTrue();

    $this->actingAsHq($this->restricted)->post('/flags', ['key' => 'phase3.new', 'environment' => '*', 'enabled' => true, 'reason' => 'try'])
        ->assertForbidden();
    $this->actingAsHq($this->restricted)->post("/flags/{$this->flag->id}/toggle", ['reason' => 'try'])
        ->assertForbidden();
    $this->actingAsHq($this->restricted)->post('/tools/noop/toggle', ['enabled' => false, 'reason' => 'try'])
        ->assertForbidden();

    expect(FeatureFlag::where('key', 'phase3.new')->exists())->toBeFalse()
        ->and($this->flag->fresh()->enabled)->toBeFalse()
        ->and(ToolRegistration::where('key', 'noop')->exists())->toBeFalse()
        ->and(AuditLog::whereIn('action', ['feature_flag.saved', 'feature_flag.toggled', 'tool.disabled', 'tool.enabled'])->count())->toBe(0);
});

it('gives a restricted operator no switch on the flags and tools pages, with the reason', function () {
    foreach (['/flags' => 'flags/index', '/tools' => 'tools/index'] as $url => $component) {
        $this->actingAsHq($this->restricted)->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page->component($component, false)
                ->where('switch.allowed', false)
                ->where('switch.refusal', fn ($r) => str_contains((string) $r, 'limited to some businesses')));

        $this->actingAsHq($this->owner)->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page->component($component, false)
                ->where('switch.allowed', true)
                ->where('switch.refusal', null));
    }

    // The pages hide the buttons from the server's answer, not from the permission list alone.
    foreach (['resources/js/pages/flags/index.tsx', 'resources/js/pages/tools/index.tsx'] as $file) {
        expect(file_get_contents(base_path($file)))->toContain('holdsPermission && groupSwitch.allowed');
    }
});

it('lets the unrestricted owner switch a flag and records actor, scope, before, after and reason', function () {
    $this->actingAsHq($this->owner)->post("/flags/{$this->flag->id}/toggle", [])->assertSessionHasErrors('reason');
    $this->actingAsHq($this->owner)->post("/flags/{$this->flag->id}/toggle", ['reason' => '   '])->assertSessionHasErrors('reason');
    expect($this->flag->fresh()->enabled)->toBeFalse();

    $this->actingAsHq($this->owner)->post("/flags/{$this->flag->id}/toggle", ['reason' => 'turning the test switch on'])->assertRedirect();
    expect($this->flag->fresh()->enabled)->toBeTrue();

    $row = AuditLog::where('action', 'feature_flag.toggled')->sole();
    expect($row->actor_type)->toBe('human')
        ->and($row->actor_id)->toBe($this->owner->id)
        ->and($row->context['scope'])->toBe('group')
        ->and($row->context['permission'])->toBe('hq.flags.manage')
        ->and($row->before['enabled'])->toBeFalse()
        ->and($row->after['enabled'])->toBeTrue()
        ->and($row->reason)->toBe('turning the test switch on');
});

it('lets the owner create a flag only with a reason, auditing before and after', function () {
    $this->actingAsHq($this->owner)->post('/flags', ['key' => 'phase3.new', 'environment' => '*', 'enabled' => true])
        ->assertSessionHasErrors('reason');
    expect(FeatureFlag::where('key', 'phase3.new')->exists())->toBeFalse();

    $this->actingAsHq($this->owner)->post('/flags', ['key' => 'phase3.test', 'environment' => '*', 'enabled' => true, 'reason' => 'ship it on'])
        ->assertRedirect();

    $row = AuditLog::where('action', 'feature_flag.saved')->sole();
    expect($row->actor_id)->toBe($this->owner->id)
        ->and($row->context['scope'])->toBe('group')
        ->and($row->before['enabled'])->toBeFalse()
        ->and($row->after['enabled'])->toBeTrue()
        ->and($row->reason)->toBe('ship it on');
});

it('lets the owner switch a tool off and on, each audited with the group scope and a reason', function () {
    $this->actingAsHq($this->owner)->post('/tools/noop/toggle', ['enabled' => false, 'reason' => 'noisy'])->assertRedirect();
    $this->actingAsHq($this->owner)->post('/tools/noop/toggle', ['enabled' => true, 'reason' => 'fixed'])->assertRedirect();

    foreach (['tool.disabled' => [true, false, 'noisy'], 'tool.enabled' => [false, true, 'fixed']] as $action => [$was, $is, $reason]) {
        $row = AuditLog::where('action', $action)->sole();
        expect($row->actor_id)->toBe($this->owner->id)
            ->and($row->context['scope'])->toBe('group')
            ->and($row->context['permission'])->toBe('hq.tools.manage')
            ->and($row->after['enabled'])->toBe($is)
            ->and($row->reason)->toBe($reason);
        if ($action === 'tool.enabled') {
            expect($row->before['enabled'])->toBe($was);
        }
    }
});

it('refuses a restricted person in the services, whoever calls them', function () {
    expect(fn () => app(ToolSwitch::class)->disable('noop', 'x', $this->restricted))
        ->toThrow(AuthorizationException::class, 'limited to some businesses');
    expect(fn () => app(ToolSwitch::class)->enable('noop', 'x', $this->restricted))
        ->toThrow(AuthorizationException::class, 'limited to some businesses');

    expect(implode(' ', app(ProviderSelection::class)->personRefusals($this->restricted->email, 'switch')))->toContain('limited to some businesses')
        ->and(app(ProviderSelection::class)->personRefusals($this->owner->email, 'switch'))->toBe([]);

    expect(fn () => app(ShadowPeriods::class)->assertOpener($this->restricted->id))->toThrow(RuntimeException::class, 'limited to some businesses');
    expect(fn () => app(ShadowPeriods::class)->close(new ShadowPeriod, 'x', $this->restricted->id))->toThrow(RuntimeException::class, 'limited to some businesses');
    app(ShadowPeriods::class)->assertOpener($this->owner->id);

    $evidence = (new AutonomyEvidencePolicy)->forceFill(['status' => AutonomyEvidencePolicy::PROPOSED, 'version' => 99]);
    expect(implode(' ', app(EvidencePolicies::class)->activationRefusals($evidence, $this->restricted, 'go')))->toContain('limited to some businesses')
        ->and(implode(' ', app(EvidencePolicies::class)->activationRefusals($evidence, $this->owner, 'go')))->not->toContain('limited to some businesses');

    $language = (new LanguagePolicy)->forceFill(['status' => LanguagePolicy::PROPOSED, 'version' => 99, 'name' => 'test', 'rules' => []]);
    expect(implode(' ', app(LanguagePolicies::class)->activationRefusals($language, $this->restricted, 'go')))->toContain('limited to some businesses')
        ->and(implode(' ', app(LanguagePolicies::class)->activationRefusals($language, $this->owner, 'go')))->not->toContain('limited to some businesses');
});

it('refuses a restricted person named on the terminal, and audits the owner with the group scope', function () {
    $this->artisan('hq:tools:toggle', ['key' => 'noop', '--disable' => true, '--reason' => 'try', '--as' => $this->restricted->email])->assertFailed();
    expect(ToolRegistration::where('key', 'noop')->exists())->toBeFalse();

    $this->artisan('hq:tools:toggle', ['key' => 'noop', '--disable' => true, '--reason' => 'noisy', '--as' => $this->owner->email])->assertSuccessful();
    $this->artisan('hq:tools:toggle', ['key' => 'noop', '--enable' => true, '--as' => $this->owner->email])->assertFailed();
    $this->artisan('hq:tools:toggle', ['key' => 'noop', '--enable' => true, '--reason' => 'fixed', '--as' => $this->owner->email])->assertSuccessful();

    expect(AuditLog::where('action', 'tool.disabled')->sole()->context)->toMatchArray(['scope' => 'group', 'authority' => 'group-wide person'])
        ->and(AuditLog::where('action', 'tool.enabled')->sole()->actor_id)->toBe($this->owner->id);
});

it('treats nobody named, a disabled person and a person without the permission as no authority', function () {
    $authority = app(GroupWideAuthority::class);

    expect($authority->refusal(null, 'hq.flags.manage'))->toContain('no person was named')
        ->and($authority->refusal($this->hqUser('brand_manager'), 'hq.flags.manage'))->toContain('needs hq.flags.manage')
        ->and($authority->refusal(tap($this->hqUser('owner'))->forceFill(['is_active' => false]), 'hq.flags.manage'))->toContain('is disabled')
        ->and($authority->refusal($this->owner, 'hq.flags.manage'))->toBeNull();
});

it('pins every global switch route and its guard, so a new one without the guard fails', function () {
    $guarded = [];
    $byPermission = [];
    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'group.wide:')) {
                $guarded[$route->getName()] = substr($m, strlen('group.wide:'));
            }
            if (is_string($m) && str_starts_with($m, 'permission:') && array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
                foreach (explode('|', substr($m, strlen('permission:'))) as $permission) {
                    if (in_array($permission, GLOBAL_SWITCH_PERMISSIONS, true)) {
                        $byPermission[$route->getName()] = $permission;
                    }
                }
            }
        }
    }
    ksort($guarded);
    ksort($byPermission);
    $pinned = GLOBAL_SWITCH_ROUTES;
    ksort($pinned);

    // Exactly the pinned routes carry the guard, each with its own permission...
    expect($guarded)->toBe($pinned)
        // ...and every write route under a global-switch permission is one of them.
        ->and($byPermission)->toBe($pinned);

    // The controller behind each route checks again, so losing the middleware still refuses.
    foreach (array_keys(GLOBAL_SWITCH_ROUTES) as $name) {
        [$class, $method] = explode('@', Route::getRoutes()->getByName($name)->getActionName());
        $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        $reflection = new ReflectionMethod($class, $method);
        $body = implode('', array_slice(explode("\n", $source), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        expect($body)->toMatch('/->assert\(\$request->user\(\)/', "{$name} ({$class}::{$method}) does not check group-wide authority itself");
    }

    // And the services those routes and the console commands reach check it too.
    foreach (GLOBAL_SWITCH_SERVICES as $file) {
        expect(str_contains((string) file_get_contents(base_path($file)), 'GroupWideAuthority'))->toBeTrue("{$file} does not check group-wide authority");
    }
});
