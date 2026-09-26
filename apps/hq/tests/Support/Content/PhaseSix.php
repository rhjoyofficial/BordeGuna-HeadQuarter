<?php

namespace Tests\Support\Content;

use App\Content\ClaimsPolicy;
use App\Models\Brand;
use App\Models\Business;
use App\Models\ContentBrief;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Research\AddressGuard;
use App\Support\Features\Features;

/**
 * Fixtures for the Phase 6 tests (stream T, docs/88). Static helpers rather than Pest functions, so two
 * files can use them without redeclaring a global function.
 */
final class PhaseSix
{
    public static function on(string ...$flags): void
    {
        foreach ($flags as $flag) {
            FeatureFlag::updateOrCreate(['key' => $flag, 'environment' => '*'], ['enabled' => true, 'rollout' => null]);
        }
        app(Features::class)->forget();
    }

    /** Every host resolves to a public documentation address, so no test does a real DNS lookup. */
    public static function publicDns(array $overrides = []): void
    {
        app()->instance(AddressGuard::class, new AddressGuard(static fn (string $host): array => $overrides[$host] ?? ['93.184.216.34']));
    }

    /** @return array{business: Business, brand: Brand, user: User} */
    public static function world(string $slug = 'bor-de-guna-t'): array
    {
        $business = Business::create(['name' => 'Bor de Guna', 'slug' => $slug]);
        $brand = Brand::create(['business_id' => $business->id, 'code' => strtoupper(substr(md5($slug), 0, 8)), 'name' => 'LaLaDia', 'slug' => $slug.'-laladia', 'is_active' => true]);
        $user = User::factory()->create();

        return ['business' => $business, 'brand' => $brand, 'user' => $user];
    }

    /** The EXAMPLE policy (tests/fixtures/content/claims-policy.EXAMPLE.json): an example, never an owner's decision. */
    public static function examplePolicy(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/content/claims-policy.EXAMPLE.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function brief(Brand $brand, User $by, ?array $policy = null, array $banned = ['miracle', 'অলৌকিক'], int $version = 1): ContentBrief
    {
        $policy ??= self::examplePolicy();

        return ContentBrief::create([
            'brand_id' => $brand->id,
            'version' => $version,
            'persona' => 'Warm, plain Bangla; no exaggeration.',
            'claims_policy' => $policy['policy'],
            'banned_words' => $banned,
            'provenance' => 'business',
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);
    }

    /** A price read from the ERP, the only kind of source a product figure may have. */
    public static function erpPrice(string $sku, string $value): array
    {
        return ['key' => "price:{$sku}", 'label' => 'Retail price', 'value' => $value, 'unit' => 'BDT', 'system' => 'erp',
            'ref' => "erp.read_price:{$sku}", 'as_of' => now()->toIso8601String(), 'sku' => $sku];
    }

    public static function erpSize(string $sku, string $value, string $unit = 'ml'): array
    {
        return ['key' => "size:{$sku}", 'label' => 'Pack size', 'value' => $value, 'unit' => $unit, 'system' => 'erp',
            'ref' => "erp.lookup_sku:{$sku}", 'as_of' => now()->toIso8601String(), 'sku' => $sku];
    }

    public static function policy(): ClaimsPolicy
    {
        $p = self::examplePolicy();

        return ClaimsPolicy::fromArray($p['policy'], ['miracle']);
    }
}
