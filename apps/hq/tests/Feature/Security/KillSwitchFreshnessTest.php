<?php

use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyEngine;
use App\Gateway\Registry\ToolAvailability;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\FeatureFlag;
use App\Models\ToolRegistration;
use App\Models\User;
use App\Support\Features\Features;
use App\Tools\ToolRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\Tools\CountingTool;

/*
 | docs/81 C.6 (2): a kill switch is never stale.
 |
 | `ToolAvailability` kept the tool switches for 30 seconds and `Features` kept the flags for 60, in
 | the shared cache store. The screens called `forget()` after writing; nothing else did. So a
 | switch changed by SQL, by a restore or by a process that did not call it kept its old answer, at
 | the gateway too. The dangerous cases: a tool turned off that stays on, `ai.runtime` turned off
 | that stays on, and `ai.freeze` turned on that stays off.
 |
 | Every change here is made the way the dangerous cases happen: straight into the table, with no
 | model event and nobody calling `forget()`.
 */

beforeEach(function () {
    Cache::flush();
    CountingTool::reset();
    app(ToolRegistry::class)->register(CountingTool::class);
});

function switchOperator(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('owner');

    return $user->fresh();
}

function countingCall(User $user)
{
    return app(ToolGateway::class)->execute(ToolRequest::forOperator($user, 'test.counting'));
}

/** The default store becomes one that outlives the process, as Redis does in production. */
function switchCacheStore(): string
{
    $path = storage_path('framework/testing/switch-cache-'.Str::random(8));
    File::ensureDirectoryExists($path);
    config(['cache.stores.switch_file' => ['driver' => 'file', 'path' => $path], 'cache.default' => 'switch_file']);
    Cache::setDefaultDriver('switch_file');

    return $path;
}

it('refuses a tool at the next gateway call once it is switched off in the table', function () {
    $user = switchOperator();
    expect(countingCall($user)->ok())->toBeTrue();

    DB::table('tools')->insert(['key' => 'test.counting', 'enabled' => false, 'disabled_reason' => 'switched off by hand', 'created_at' => now(), 'updated_at' => now()]);

    $after = countingCall($user);
    expect($after->status)->toBe(OutcomeStatus::Unavailable)
        ->and($after->error)->toContain('disabled at runtime')
        ->and(CountingTool::$executions)->toBe(1);
});

it('offers a tool again at the next call once it is switched back on in the table', function () {
    $user = switchOperator();
    DB::table('tools')->insert(['key' => 'test.counting', 'enabled' => false, 'disabled_reason' => 'off', 'created_at' => now(), 'updated_at' => now()]);
    expect(countingCall($user)->status)->toBe(OutcomeStatus::Unavailable);

    DB::table('tools')->where('key', 'test.counting')->update(['enabled' => true, 'disabled_reason' => null]);

    expect(countingCall($user)->ok())->toBeTrue();
});

it('stops every tool at the next call once the freeze flag is set in the table', function () {
    $user = switchOperator();
    expect(countingCall($user)->ok())->toBeTrue();

    DB::table('feature_flags')->insert(['key' => PolicyEngine::FREEZE_FLAG, 'environment' => '*', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    expect(countingCall($user)->status)->toBe(OutcomeStatus::Denied)
        ->and(CountingTool::$executions)->toBe(1);
});

it('turns the model runtime off at once when its flag is cleared in the table', function () {
    DB::table('feature_flags')->insert(['key' => 'ai.runtime', 'environment' => '*', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    expect(app(Features::class)->enabled('ai.runtime'))->toBeTrue();

    DB::table('feature_flags')->where('key', 'ai.runtime')->update(['enabled' => false]);

    expect(app(Features::class)->enabled('ai.runtime'))->toBeFalse();
});

it('ignores whatever another process left in a shared cache store', function () {
    $path = switchCacheStore();
    try {
        $user = switchOperator();
        ToolRegistration::create(['key' => 'test.counting', 'enabled' => false, 'disabled_reason' => 'off in the table']);
        FeatureFlag::create(['key' => 'ai.runtime', 'environment' => '*', 'enabled' => false]);

        // What a second container would have written under the old code, a second ago: no switch
        // rows at all (every tool on) and the runtime flag on.
        Cache::store('switch_file')->put('hq:tool_switches', [], 30);
        Cache::store('switch_file')->put('hq:feature_flags', ['ai.runtime' => ['*' => ['enabled' => true, 'rollout' => null]]], 60);

        // A new process: nothing resolved is reused.
        app()->forgetInstance(ToolGateway::class);
        app()->forgetInstance(Features::class);

        expect((new ToolAvailability(app(ToolRegistry::class)))->isAvailable('test.counting'))->toBeFalse()
            ->and(countingCall($user)->status)->toBe(OutcomeStatus::Unavailable)
            ->and((new Features)->enabled('ai.runtime'))->toBeFalse()
            ->and(CountingTool::$executions)->toBe(0);
    } finally {
        Cache::store('switch_file')->flush();
        File::deleteDirectory($path);
    }
});

it('keeps forget() for its callers, and it changes nothing', function () {
    $user = switchOperator();
    app(ToolAvailability::class)->forget();
    app(Features::class)->forget();

    expect(countingCall($user)->ok())->toBeTrue()
        ->and(app(Features::class)->enabled('ai.runtime'))->toBeFalse();
});
