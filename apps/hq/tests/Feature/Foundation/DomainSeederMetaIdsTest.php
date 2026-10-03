<?php

use App\Models\Brand;
use App\Models\Business;
use App\Models\PlatformAccount;
use Database\Seeders\DomainSeeder;

/*
 | docs/95 §5 "Operations" (stream W2b item 3): `config/hq-seed.php` carries the Meta ids as they stand after
 | Joy's move into the Bor de Guna portfolio on 21 September 2026, the 5 September values are retired rather
 | than deleted, and the Instagram and WhatsApp platform accounts are placeholders that make a row only once
 | an env value names them. The seeder is idempotent and never resets what an adapter or a person recorded.
 */

function seededAccount(string $platform, string $ref): PlatformAccount
{
    return PlatformAccount::where('platform', $platform)->where('account_ref', $ref)->sole();
}

it('seeds the live Meta ids active and the stale ones retired, under the right brands, idempotently', function () {
    $this->seed(DomainSeeder::class);
    $count = PlatformAccount::count();
    $this->seed(DomainSeeder::class);

    expect(PlatformAccount::count())->toBe($count)
        ->and(seededAccount('meta_pixel', '1260982165256142'))->toMatchArray(['brand_id' => Brand::where('code', 'LLD')->value('id'), 'is_active' => true])
        ->and(seededAccount('meta_pixel', '689321244257515'))->toMatchArray(['brand_id' => Brand::where('code', 'BNC')->value('id'), 'is_active' => true])
        ->and(seededAccount('meta_ad_account', '530866839523958'))->toMatchArray(['brand_id' => Brand::where('code', 'LLD')->value('id'), 'is_active' => true])
        ->and(seededAccount('meta_ad_account', '547593411643381'))->toMatchArray(['brand_id' => Brand::where('code', 'BNC')->value('id'), 'is_active' => true])
        ->and(seededAccount('meta_pixel', '1773890070472625')->is_active)->toBeFalse()
        ->and(seededAccount('meta_ad_account', '28164410319885213')->is_active)->toBeFalse()
        ->and(PlatformAccount::where('platform', 'meta_ad_account')->where('is_active', true)->pluck('account_ref')->sort()->values()->all())->toBe(['530866839523958', '547593411643381']);
});

it('retires a stale row that production already holds instead of deleting it, keeping its id', function () {
    $business = Business::create(['slug' => 'bionic', 'name' => 'Bionic', 'erp_business_id' => 2, 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
    $brand = Brand::create(['business_id' => $business->id, 'code' => 'BNC', 'slug' => 'bionic-garden', 'name' => 'Bionic Garden', 'is_active' => true]);
    $stale = PlatformAccount::create(['brand_id' => $brand->id, 'platform' => 'meta_pixel', 'account_ref' => '1773890070472625', 'name' => 'Bionic pixel', 'is_active' => true, 'approval_status' => 'approved']);

    $this->seed(DomainSeeder::class);

    $fresh = $stale->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->approval_status)->toBe('approved')
        ->and($fresh->name)->toContain('retired 3 Oct 2026');
});

it('never resets what an adapter verified or a person switched off', function () {
    $this->seed(DomainSeeder::class);
    seededAccount('meta_pixel', '1260982165256142')->update(['approval_status' => 'approved', 'capabilities' => ['send_events' => true]]);
    seededAccount('facebook_page', '100085015853889')->update(['is_active' => false]);

    $this->seed(DomainSeeder::class);

    expect(seededAccount('meta_pixel', '1260982165256142'))->toMatchArray(['approval_status' => 'approved', 'capabilities' => ['send_events' => true]])
        ->and(seededAccount('facebook_page', '100085015853889')->is_active)->toBeFalse();
});

it('creates no Instagram or WhatsApp row while the env placeholders are unfilled, and one per brand once they are', function () {
    $this->seed(DomainSeeder::class);
    expect(PlatformAccount::whereIn('platform', ['instagram_account', 'whatsapp_number'])->count())->toBe(0);

    $accounts = config('hq-seed.platform_accounts');
    foreach ($accounts as &$row) {
        if ($row['platform'] === 'instagram_account' && $row['brand'] === 'LLD') {
            $row['account_ref'] = '17841400000000001';
        }
        if ($row['platform'] === 'whatsapp_number' && $row['brand'] === 'BNC') {
            $row['account_ref'] = ' 106540352242922 ';
        }
        if ($row['platform'] === 'whatsapp_number' && $row['brand'] === 'LLD') {
            $row['account_ref'] = '';
        }
    }
    unset($row);
    config(['hq-seed.platform_accounts' => $accounts]);

    $this->seed(DomainSeeder::class);

    expect(PlatformAccount::whereIn('platform', ['instagram_account', 'whatsapp_number'])->count())->toBe(2)
        ->and(seededAccount('instagram_account', '17841400000000001'))->toMatchArray(['brand_id' => Brand::where('code', 'LLD')->value('id'), 'name' => 'laladiaglobal', 'is_active' => true, 'approval_status' => 'unknown'])
        ->and(seededAccount('whatsapp_number', '106540352242922')->brand_id)->toBe(Brand::where('code', 'BNC')->value('id'));
});
