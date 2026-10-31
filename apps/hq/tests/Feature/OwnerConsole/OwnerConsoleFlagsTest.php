<?php

use App\Console\Commands\DoPlansAdvance;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\FeatureFlag;
use App\Models\ReplyOptionSet;
use App\Models\UserBusinessScope;
use App\OwnerConsole\Do\DoPlanService;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\OwnerConsole\Replies\ReplyOptionService;
use App\OwnerConsole\Watches\EventWatchService;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\Route;

/*
 | docs/143: the owner-console switches ship OFF; with one off nothing behind it can run; and changing any of them
 | is a global switch that needs group-wide authority (docs/138 ruling 13, item g). Five since the 31 Oct ruling removed
 | the one-click switch with its action (ruling 3).
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->owner = $this->hqUser('owner');
});

it('seeds all five flags OFF, has no one-click switch, and treats a missing row as OFF', function () {
    expect(Flag::all())->toHaveCount(5)->and(FeatureFlag::query()->where('key', 'console.one_click_approve')->exists())->toBeFalse();
    foreach (Flag::all() as $key) {
        $row = FeatureFlag::query()->where('key', $key)->where('environment', '*')->first();
        expect($row)->not->toBeNull("{$key} is seeded")->and((bool) $row->enabled)->toBeFalse("{$key} is OFF");
        expect(Flag::on($key))->toBeFalse();
    }
    FeatureFlag::query()->whereIn('key', Flag::all())->delete();
    foreach (Flag::all() as $key) {
        expect(Flag::on($key))->toBeFalse();
    }
});

it('refuses every owner-console action while its flag is off', function () {
    $lld = Brand::where('code', 'LLD')->sole()->business_id;

    expect(fn () => app(DoPlanService::class)->propose($lld, 'x', [['tool_key' => 'demo.request_approval', 'args' => ['note' => 'a'], 'summary' => 's']], $this->owner))->toThrow(Refused::class);
    expect(fn () => app(EventWatchService::class)->propose($this->owner, $lld, 'w', 'order.created', []))->toThrow(Refused::class, 'switched off');
    expect(fn () => app(ReplyOptionService::class)->discard(new ReplyOptionSet, $this->owner))->toThrow(Refused::class, 'switched off');

    $this->artisan('hq:do-plans:advance')->assertSuccessful();
    expect(DoPlansAdvance::class)->toBeString();
});

it('registers every new route behind auth, both 2FA gates and its permission', function () {
    $expected = [
        'do-plans.store' => 'permission:hq.do.propose', 'do-plans.start' => 'permission:hq.do.start', 'do-plans.cancel' => 'permission:hq.do.start',
        'watches.store' => 'permission:hq.watches.propose', 'watches.enable' => 'permission:hq.watches.enable', 'watches.disable' => 'permission:hq.watches.enable',
        'approvals.batch' => 'permission:hq.approvals.decide',
        'inbox.reply-options.choose' => 'permission:hq.inbox.manage', 'observe.briefings.send-telegram' => 'permission:hq.briefing.deliver',
    ];
    foreach ($expected as $name => $permission) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', '2fa.enabled', '2fa.verified', $permission);
    }
});

it('lets only a group-wide person change any of the five switches, and records scope, before and after (ruling 13)', function () {
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => Brand::where('code', 'LLD')->sole()->business_id]);

    foreach (Flag::all() as $key) {
        $flag = FeatureFlag::query()->where('key', $key)->firstOrFail();
        $this->actingAsHq($restricted)->post("/flags/{$flag->id}/toggle", ['reason' => 'test'])->assertForbidden();
        expect((bool) $flag->fresh()->enabled)->toBeFalse("{$key} unchanged by a restricted operator");

        $this->actingAsHq($this->owner)->post("/flags/{$flag->id}/toggle", ['reason' => 'test'])->assertRedirect();
        expect((bool) $flag->fresh()->enabled)->toBeTrue();
    }
    $row = AuditLog::query()->where('action', 'feature_flag.toggled')->latest('id')->first();
    expect($row)->not->toBeNull()->and(json_encode($row->after).json_encode($row->context))->toContain('group');
});
