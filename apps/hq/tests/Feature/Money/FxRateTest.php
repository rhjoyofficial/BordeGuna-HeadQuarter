<?php

use App\Media\Budget\MediaBudgetRefused;
use App\Media\Budget\MediaBudgets;
use App\Media\Budget\MediaCost;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\FxRate;
use App\Models\MediaBudgetEntry;
use App\Models\UserBusinessScope;
use App\Support\Money\FxRates;
use App\Support\Money\UsdBdtRate;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\Support\Media\Budgets;
use Tests\Support\Money\Fx;

/*
 | Ruling 1 Nov 2026 (FX): the USD to BDT rate is the owner's data, dated, with a source, an expiry and an append-only history.
 | A paid media reservation uses the RESERVE rate and fails closed when the rate is missing, expired or not yet valid; the AI
 | cost display uses the REFERENCE rate as an estimate and falls back to USD. Only a named, group-wide person with
 | `hq.fx.manage` (behind 2FA) sets a new rate, with a reason, audited.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->laladia = Business::where('slug', 'laladia')->sole();
    $this->owner = $this->hqUser('owner');
});

function fxForm(array $overrides = []): array
{
    $today = CarbonImmutable::now('Asia/Dhaka');

    return $overrides + [
        'reference_rate' => '124.10', 'reserve_rate' => '136.00', 'source' => 'Bangladesh Bank reference',
        'as_of' => $today->format('Y-m-d'), 'valid_until' => $today->addDays(30)->format('Y-m-d'), 'reason' => 'November rate',
    ];
}

it('ships the ruling\'s initial rate as data: reference 123.99, reserve 136.50, dated, with a source and an expiry', function () {
    $first = FxRate::query()->oldest('id')->firstOrFail();

    expect($first->base_currency.'/'.$first->quote_currency)->toBe('USD/BDT')
        ->and((string) $first->reference_rate)->toBe('123.990000')
        ->and((string) $first->reserve_rate)->toBe('136.500000')
        ->and((string) $first->safety_margin_percent)->toBe('10.0895')
        ->and($first->source)->toBe('Bangladesh Bank reference 29 Oct 2026')
        ->and($first->as_of->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-10-29 00:00')
        ->and($first->valid_until->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s'))->toBe('2026-11-30 23:59:59')
        ->and($first->set_by_user_id)->toBeNull();

    // No rate is a config constant any more.
    expect(config('hq.media.budget.fx_to_bdt'))->toBeNull()->and(config('hq.media.budget.fx_safety_margin_percent'))->toBeNull();
});

it('reserves paid media at the reserve rate and records which rate row it used, while display uses the reference rate', function () {
    Budgets::grant($this->laladia->id, '1000.00');
    $rate = Fx::set('123.99', '136.50');

    $entry = app(MediaBudgets::class)->reserve($this->laladia->id, 'video', 'fake_video', 't', 'fx-1', new MediaCost('1.00', 'USD'));

    expect((string) $entry->estimate_bdt)->toBe('136.50')
        ->and((string) $entry->fx_rate_to_bdt)->toBe('136.500000')
        ->and($entry->fx_rate_id)->toBe($rate->id)
        ->and(UsdBdtRate::current())->toBe(123.99)
        ->and(UsdBdtRate::display('1.00'))->toBe('BDT 123.99 (USD 1.0000)');
});

it('refuses paid media when the rate is expired, not yet valid or missing, and writes nothing', function () {
    Budgets::grant($this->laladia->id, '1000.00');
    $budgets = app(MediaBudgets::class);

    Fx::set('123.99', '136.50', CarbonImmutable::now()->subDays(40), CarbonImmutable::now()->subMinute());
    expect(fn () => $budgets->reserve($this->laladia->id, 'video', 'fake_video', 't', 'stale', new MediaCost('1.00', 'USD')))
        ->toThrow(MediaBudgetRefused::class, 'The USD to BDT rate expired on')
        ->and(UsdBdtRate::current())->toBeNull();

    Fx::set('123.99', '136.50', CarbonImmutable::now()->addDay(), CarbonImmutable::now()->addDays(20));
    expect(fn () => $budgets->reserve($this->laladia->id, 'video', 'fake_video', 't', 'early', new MediaCost('1.00', 'USD')))
        ->toThrow(MediaBudgetRefused::class, 'only applies from');

    // No row at all (rolled back with the test).
    DB::statement('TRUNCATE fx_rates CASCADE');
    app()->forgetScopedInstances();
    expect(fn () => $budgets->reserve($this->laladia->id, 'video', 'fake_video', 't', 'none', new MediaCost('1.00', 'USD')))
        ->toThrow(MediaBudgetRefused::class, 'No USD to BDT rate has been set')
        ->and(UsdBdtRate::display('1.00'))->toBe('USD 1.0000');

    expect(MediaBudgetEntry::query()->count())->toBe(0);
    // A BDT-priced cost needs no rate and is unaffected.
    expect($budgets->reserve($this->laladia->id, 'video', 'p', 't', 'bdt', new MediaCost('10.00', 'BDT'))->state)->toBe('reserved');
});

it('lets the owner set a new rate: a new row, the old one kept, audited with before, after, reason and scope', function () {
    $before = FxRate::query()->count();
    $previous = FxRate::query()->latest('id')->firstOrFail();

    $this->actingAsHq($this->owner)->post('/fx-rate', fxForm())->assertSessionHasNoErrors()->assertRedirect();

    expect(FxRate::query()->count())->toBe($before + 1)
        ->and(FxRate::query()->find($previous->id))->not->toBeNull()
        ->and((string) $previous->fresh()->reserve_rate)->toBe((string) $previous->reserve_rate);

    $new = FxRate::query()->latest('id')->firstOrFail();
    expect((string) $new->reference_rate)->toBe('124.100000')
        ->and((string) $new->reserve_rate)->toBe('136.000000')
        ->and((string) $new->safety_margin_percent)->toBe('9.5890')
        ->and($new->set_by_user_id)->toBe($this->owner->id)
        ->and($new->valid_until->setTimezone('Asia/Dhaka')->format('H:i:s'))->toBe('23:59:59');

    $audit = AuditLog::query()->where('action', 'fx.rate.set')->sole();
    expect($audit->actor_type)->toBe('human')
        ->and($audit->actor_id)->toBe($this->owner->id)
        ->and($audit->reason)->toBe('November rate')
        ->and(data_get($audit->before, 'id'))->toBe($previous->id)
        ->and(data_get($audit->after, 'reserve_rate'))->toBe('136.000000')
        ->and(data_get($audit->context, 'scope'))->toBe('group')
        ->and(data_get($audit->context, 'permission'))->toBe('hq.fx.manage');

    // The next reservation uses the new rate.
    Budgets::grant($this->laladia->id, '1000.00');
    expect((string) app(MediaBudgets::class)->reserve($this->laladia->id, 'video', 'p', 't', 'after', new MediaCost('1.00', 'USD'))->estimate_bdt)->toBe('136.00');
});

it('refuses a change from anyone without group-wide hq.fx.manage, with 403, and writes nothing', function () {
    $count = FxRate::query()->count();

    foreach (['support_agent', 'brand_manager', 'finance_viewer'] as $role) {
        $this->actingAsHq($this->hqUser($role))->post('/fx-rate', fxForm())->assertForbidden();
    }

    // An operator holds the permission, but one limited to some businesses is not group-wide.
    $restricted = $this->hqUser('operator');
    UserBusinessScope::create(['user_id' => $restricted->id, 'business_id' => $this->laladia->id]);
    $this->actingAsHq($restricted)->post('/fx-rate', fxForm())->assertForbidden();

    // The service refuses the same person directly too (the third layer).
    expect(fn () => app(FxRates::class)->setRate($restricted, '124', '136', 's', fxForm()['as_of'], fxForm()['valid_until'], 'r'))
        ->toThrow(AuthorizationException::class);

    expect(FxRate::query()->count())->toBe($count)->and(AuditLog::query()->where('action', 'fx.rate.set')->count())->toBe(0);

    // The write route sits behind login, 2FA, the permission and group-wide authority.
    $middleware = Route::getRoutes()->getByName('fx-rate.store')->gatherMiddleware();
    expect($middleware)->toContain('auth', '2fa.enabled', '2fa.verified', 'permission:hq.fx.manage', 'group.wide:hq.fx.manage');
});

it('refuses a rate that is not safe to use, and writes nothing', function () {
    $count = FxRate::query()->count();
    $today = CarbonImmutable::now('Asia/Dhaka');

    foreach ([
        ['reserve_rate' => '120.00'],                                                    // reserve below reference
        ['reference_rate' => 'abc'],
        ['reference_rate' => '12.00', 'reserve_rate' => '13.00'],                        // not a plausible BDT rate
        ['reserve_rate' => '250.00'],                                                    // more than 50% over reference
        ['as_of' => $today->addDay()->format('Y-m-d'), 'valid_until' => $today->addDays(10)->format('Y-m-d')],
        ['valid_until' => $today->subDay()->format('Y-m-d')],                            // stale at once
        ['valid_until' => $today->addDays(FxRates::MAX_VALIDITY_DAYS + 1)->format('Y-m-d')],
    ] as $bad) {
        $this->actingAsHq($this->owner)->post('/fx-rate', fxForm($bad))->assertSessionHasErrors('fx_rate');
    }
    $this->actingAsHq($this->owner)->post('/fx-rate', fxForm(['reason' => ' ']))->assertSessionHasErrors('reason');

    expect(FxRate::query()->count())->toBe($count);
});

it('keeps the history append-only, in the database and in the model', function () {
    $row = FxRate::query()->latest('id')->firstOrFail();

    // Each attempt in its own savepoint, so the refused statement does not abort the test's transaction.
    expect(fn () => DB::transaction(fn () => DB::table('fx_rates')->where('id', $row->id)->update(['reserve_rate' => '1.00'])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('fx_rates')->where('id', $row->id)->delete()))->toThrow(QueryException::class, 'append-only');
    expect(fn () => $row->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class);
});

it('shows the current rate, its state, and the paged history on the Exchange rate screen', function () {
    Fx::set('124.10', '136.00');

    $this->actingAsHq($this->owner)->get('/fx-rate')->assertOk()
        ->assertInertia(fn ($page) => $page->component('fx-rate/index')
            ->where('current.reference_rate', '124.100000')
            ->where('current.state', 'valid')
            ->where('refusal', null)
            ->where('switch.allowed', true)
            ->where('history.total', FxRate::query()->count())
            ->where('history.per_page_option', '20')
            ->has('history.data', FxRate::query()->count())
            ->etc());

    // A finance viewer reads it (the same permission as AI cost) but may not change it.
    $this->actingAsHq($this->hqUser('finance_viewer'))->get('/fx-rate')->assertOk()
        ->assertInertia(fn ($page) => $page->where('switch.allowed', false)->etc());
    $this->actingAsHq($this->hqUser('support_agent'))->get('/fx-rate')->assertForbidden();

    // A stale rate says why paid media is refused.
    Fx::set('124.10', '136.00', CarbonImmutable::now()->subDays(40), CarbonImmutable::now()->subDay());
    $this->actingAsHq($this->owner)->get('/fx-rate')
        ->assertInertia(fn ($page) => $page->where('current.state', 'expired')->where('refusal', fn ($r) => str_contains((string) $r, 'expired on'))->etc());
});

it('is set only by a person: no tool, job, agent or command calls setRate', function () {
    $callers = [];
    foreach (File::allFiles(app_path()) as $file) {
        if (str_contains(File::get($file->getPathname()), '->setRate(')) {
            $callers[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    expect($callers)->toBe(['Http/Controllers/Admin/FxRateController.php']);
});
