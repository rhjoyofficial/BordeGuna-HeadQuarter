<?php

namespace App\Commerce\Support;

use App\Commerce\Adapters\LaravelShopAdapter;
use App\Commerce\Contracts\CommercePlatform;
use App\Commerce\Dto\StoreRef;
use App\Commerce\Exceptions\Unsupported;
use App\Models\IntegrationCredential;
use App\Models\Store;

/**
 * Resolves a Store row to its adapter, with credentials read from integration_credentials at
 * call time (never from config or code). `laladia` and `bionic` are two stores, one adapter class.
 */
final class CommerceRegistry
{
    /** @var array<string, CommercePlatform> */
    private array $resolved = [];

    public function __construct(private readonly ConnectorCallLogger $logger) {}

    public function for(Store|string $store): CommercePlatform
    {
        $store = $store instanceof Store ? $store : Store::where('slug', $store)->firstOrFail();
        if (isset($this->resolved[$store->slug])) {
            return $this->resolved[$store->slug];
        }

        $ref = new StoreRef(
            id: $store->id, slug: $store->slug, adapter: $store->adapter, baseUrl: rtrim((string) $store->base_url, '/'),
            businessId: $store->business_id, orderPrefix: data_get($store->capabilities, 'order_prefix'),
        );

        return $this->resolved[$store->slug] = match ($store->adapter) {
            'laladia', 'bionic' => new LaravelShopAdapter(
                $ref,
                $this->secret($store, 'shop_assistant_key'),
                $this->secret($store, 'shop_admin_token'),
                $this->logger,
            ),
            default => throw new Unsupported("No adapter for store [{$store->slug}] (adapter '{$store->adapter}'). WooCommerce and the Econic platform are future adapters (ADR-009)."),
        };
    }

    /** @return list<string> */
    public function storesWithAdapters(): array
    {
        return Store::whereIn('adapter', ['laladia', 'bionic'])->where('is_active', true)->pluck('slug')->all();
    }

    private function secret(Store $store, string $provider): ?string
    {
        $credential = IntegrationCredential::query()
            ->where('provider', $provider)
            ->where('account_ref', $store->slug)
            ->where('status', 'active')
            ->first();

        return $credential?->payload['value'] ?? null;
    }
}
