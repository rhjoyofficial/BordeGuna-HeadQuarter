<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Channel;
use App\Models\PlatformAccount;
use App\Models\Store;
use Illuminate\Database\Seeder;

/**
 * Idempotent seed of the domain model from config/hq-seed.php. Safe to re-run on production:
 * rows are matched by their natural keys and never deleted.
 */
class DomainSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = config('hq-seed');
        $businesses = [];
        foreach ($cfg['businesses'] as $b) {
            $businesses[$b['slug']] = Business::updateOrCreate(['slug' => $b['slug']], [
                'name' => $b['name'], 'erp_business_id' => $b['erp_business_id'], 'timezone' => 'Asia/Dhaka', 'is_active' => true,
            ]);
        }

        $brands = [];
        foreach ($cfg['brands'] as $br) {
            $brands[$br['code']] = Brand::updateOrCreate(['code' => $br['code']], [
                'slug' => $br['slug'], 'name' => $br['name'], 'business_id' => $businesses[$br['business']]->id, 'is_active' => true,
            ]);
        }

        foreach ($cfg['stores'] as $s) {
            $store = Store::updateOrCreate(['slug' => $s['slug']], [
                'name' => $s['name'], 'business_id' => $businesses[$s['business']]->id, 'adapter' => $s['adapter'],
                'base_url' => $s['base_url'], 'is_active' => true,
            ]);
            $store->productOwners()->syncWithoutDetaching(array_map(fn ($slug) => $businesses[$slug]->id, $s['product_owners']));
        }

        foreach ($cfg['channels'] as $c) {
            Channel::updateOrCreate(['key' => $c['key']], ['name' => $c['name']]);
        }

        foreach ($cfg['platform_accounts'] as $pa) {
            $this->platformAccount($pa, $brands[$pa['brand']]);
        }
    }

    /**
     * Update-or-create by the natural key (platform, account_ref). What a re-run may change is the
     * brand, the name and whether the row is retired; what an adapter verified (approval_status,
     * capabilities, scopes) and what an operator switched off are never reset by seeding. A row marked
     * `retired` is deactivated, not deleted, so a production row keeps its id and its history. A
     * placeholder whose account_ref is still null or empty (an env value nobody has filled) makes no row.
     *
     * @param  array{platform: string, account_ref: mixed, name: string, retired?: bool}  $pa
     */
    private function platformAccount(array $pa, Brand $brand): void
    {
        $ref = trim((string) ($pa['account_ref'] ?? ''));
        if ($ref === '') {
            return;
        }
        $retired = (bool) ($pa['retired'] ?? false);

        $account = PlatformAccount::firstOrNew(['platform' => $pa['platform'], 'account_ref' => $ref]);
        if (! $account->exists) {
            $account->approval_status = 'unknown';
            $account->is_active = ! $retired;
        } elseif ($retired) {
            $account->is_active = false;
        }
        $account->brand_id = $brand->id;
        $account->name = $pa['name'];
        $account->save();
    }
}
