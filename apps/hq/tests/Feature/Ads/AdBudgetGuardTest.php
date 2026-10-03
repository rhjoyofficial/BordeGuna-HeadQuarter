<?php

use App\Ads\AdBudgetGuard;
use App\Models\Brand;
use App\Models\Business;
use App\Models\FactsAdAccount;
use App\Models\FactsAdSpend;
use App\Models\PlatformAccount;
use App\Models\Policy;
use Carbon\CarbonImmutable;
use Database\Seeders\DomainSeeder;
use Illuminate\Support\Facades\DB;

/*
 | The budget guard (stream S, docs/84 C.11, ADR-050 clarifications 3 and 9): it READS the owner's
 | limit and HQ's own monthly budget, and never writes anything, anywhere. It answers "no" whenever
 | anything is unknown, and never compares across currencies.
 */

beforeEach(function () {
    $this->seed(DomainSeeder::class);
    $this->business = Business::where('slug', 'laladia')->sole();
    $this->brand = Brand::where('code', 'LLD')->sole();
    $this->account = PlatformAccount::where('platform', 'meta_ad_account')->where('account_ref', '530866839523958')->sole();
    $this->now = CarbonImmutable::parse('2026-09-22 10:00:00', 'Asia/Dhaka');
});

function guardAccountFacts(array $overrides = []): FactsAdAccount
{
    $account = PlatformAccount::where('platform', 'meta_ad_account')->where('account_ref', '530866839523958')->sole();
    $brand = Brand::where('code', 'LLD')->sole();

    return FactsAdAccount::create(array_merge([
        'platform_account_id' => $account->id, 'business_id' => $brand->business_id, 'brand_id' => $brand->id, 'platform' => 'meta_ad_account',
        'account_ref' => 'act_530866839523958', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'status' => 'active',
        'spend_cap_minor' => 5000000, 'amount_spent_minor' => 1234550, 'spend_cap' => '50000.00', 'amount_spent' => '12345.50',
        'source' => 'meta_ads', 'api_version' => 'v26.0', 'fetched_at' => CarbonImmutable::parse('2026-09-22 09:30:00', 'Asia/Dhaka'),
    ], $overrides));
}

function guardSpend(string $date, string $spend, string $currency = 'BDT', string $ref = '120210000000000001'): void
{
    $account = PlatformAccount::where('platform', 'meta_ad_account')->where('account_ref', '530866839523958')->sole();
    $brand = Brand::where('code', 'LLD')->sole();
    FactsAdSpend::create([
        'platform_account_id' => $account->id, 'business_id' => $brand->business_id, 'brand_id' => $brand->id, 'platform' => 'meta_ad_account',
        'account_ref' => 'act_530866839523958', 'level' => 'campaign', 'object_ref' => $ref, 'campaign_ref' => $ref, 'spend_date' => $date,
        'currency' => $currency, 'spend' => $spend, 'source' => 'meta_ads', 'api_version' => 'v26.0', 'fetched_at' => now(),
    ]);
}

function guardBudget(string $amount, string $currency = 'BDT'): void
{
    Policy::create(['key' => AdBudgetGuard::POLICY_KEY, 'version' => 1, 'business_id' => Business::where('slug', 'laladia')->value('id'),
        'rules' => ['amount' => $amount, 'currency' => $currency], 'note' => 'owner set', 'active_from' => '2026-09-01 00:00:00']);
}

it('reports the owner\'s limit and HQ\'s monthly budget against month-to-date spend, in one currency', function () {
    guardAccountFacts();
    guardBudget('20000');
    guardSpend('2026-09-03', '9000.00');
    guardSpend('2026-09-21', '7500.50');
    guardSpend('2026-08-31', '99999.00'); // last month: not counted

    $status = app(AdBudgetGuard::class)->status($this->business, $this->now);

    expect($status['owner_limits'][0])->toMatchArray(['currency' => 'BDT', 'spend_cap' => '50000.00', 'amount_spent' => '12345.50',
        'remaining_under_cap' => '37654.50', 'state' => 'within'])
        ->and($status['hq_budget'])->toMatchArray(['state' => 'set', 'amount' => '20000.00', 'currency' => 'BDT', 'month' => '2026-09',
            'month_to_date_spend' => '16500.50', 'remaining' => '3499.50', 'verdict' => 'near']);
});

it('says fits only when every ceiling is known, fresh, in the same currency and has room', function () {
    guardAccountFacts();
    guardBudget('20000');
    guardSpend('2026-09-10', '10000.00');

    $guard = app(AdBudgetGuard::class);
    expect($guard->fits($this->business, 'act_530866839523958', '5000', 'BDT', $this->now))->toBe(['fits' => true, 'reasons' => []])
        ->and($guard->fits($this->business, '530866839523958', '10000.01', 'BDT', $this->now)['fits'])->toBeFalse();
});

it('answers no, with every reason, when anything is unknown', function () {
    $guard = app(AdBudgetGuard::class);

    // Nothing read, nothing set.
    $none = $guard->fits($this->business, 'act_530866839523958', '1', 'BDT', $this->now);
    expect($none['fits'])->toBeFalse()->and($none['reasons'])->toHaveCount(2)
        ->and($none['reasons'][0])->toContain('no facts for ad account')
        ->and($none['reasons'][1])->toContain('no monthly advertising budget');

    // No owner limit set: no outer ceiling, nothing fits, however large HQ's budget.
    guardAccountFacts(['spend_cap_minor' => null, 'spend_cap' => null]);
    guardBudget('1000000');
    $noLimit = $guard->fits($this->business, 'act_530866839523958', '1', 'BDT', $this->now);
    expect($noLimit['fits'])->toBeFalse()->and(implode(' ', $noLimit['reasons']))->toContain('no spending limit');
    expect($guard->status($this->business, $this->now)['owner_limits'][0]['state'])->toBe('no_limit_set');
});

it('treats stale account facts as an unknown ceiling', function () {
    guardAccountFacts(['fetched_at' => CarbonImmutable::parse('2026-09-21 09:00:00', 'Asia/Dhaka')]);
    guardBudget('20000');

    $r = app(AdBudgetGuard::class)->fits($this->business, 'act_530866839523958', '1', 'BDT', $this->now);

    expect($r['fits'])->toBeFalse()->and(implode(' ', $r['reasons']))->toContain('stale')
        ->and(app(AdBudgetGuard::class)->status($this->business, $this->now)['owner_limits'][0]['state'])->toBe('stale');
});

it('never compares across currencies: a USD account against a BDT budget is not comparable, never converted', function () {
    guardAccountFacts(['currency' => 'USD', 'spend_cap_minor' => 100000, 'amount_spent_minor' => 4210]);
    guardBudget('20000', 'BDT');
    guardSpend('2026-09-20', '12.00', 'USD');

    $guard = app(AdBudgetGuard::class);
    $r = $guard->fits($this->business, 'act_530866839523958', '5', 'USD', $this->now);

    expect($r['fits'])->toBeFalse()->and(implode(' ', $r['reasons']))->toContain('not comparable')
        ->and($guard->status($this->business, $this->now)['hq_budget'])->toMatchArray(['verdict' => 'not_comparable', 'spend_in_other_currencies' => ['USD']]);
});

it('writes nothing, anywhere: every query it runs is a read', function () {
    guardAccountFacts();
    guardBudget('20000');
    guardSpend('2026-09-10', '100.00');

    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|merge|truncate|alter|create|drop)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });
    $guard = app(AdBudgetGuard::class);
    $guard->status($this->business, $this->now);
    $guard->fits($this->business, 'act_530866839523958', '1', 'BDT', $this->now);

    expect($writes)->toBe([]);
});

it('refuses an amount in a currency it has no offset for, rather than guessing its decimals', function () {
    app(AdBudgetGuard::class)->fits($this->business, 'act_1', '1', 'EUR', $this->now);
})->throws(InvalidArgumentException::class, 'no recorded offset');
