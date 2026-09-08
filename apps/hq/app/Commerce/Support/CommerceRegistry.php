<?php

namespace App\Commerce\Support;

use App\Commerce\Adapters\LaravelShopAdapter;
use App\Commerce\Contracts\CommercePlatform;
use App\Commerce\Dto\StoreRef;
use App\Commerce\Exceptions\Unsupported;
use App\Gateway\Credentials\CredentialVault;
use App\Models\Store;

/**
 * Resolves a Store row to its adapter. `laladia` and `bionic` are two stores, one adapter class.
 *
 * ⚠ M2: credentials are no longer read here. The gateway resolves what the tool declared and opens
 * it in the CredentialVault for the duration of the call; this asks the vault (docs/50).
 *
 * There is deliberately NO adapter cache any more. An adapter now carries the secrets that were open
 * when it was built, so a cached one handed out under a later scope would be holding a stale
 * credential: exactly the class of subtle bug this refactor exists to remove. Building one is a
 * value object and two strings, which is not worth caching.
 */
final class CommerceRegistry
{
    public const ASSISTANT_PROVIDER = 'shop_assistant_key';

    public const ADMIN_PROVIDER = 'shop_admin_token';

    public function __construct(
        private readonly ConnectorCallLogger $logger,
        private readonly CredentialVault $vault,
    ) {}

    public function for(Store|string $store): CommercePlatform
    {
        $store = $store instanceof Store ? $store : Store::where('slug', $store)->firstOrFail();

        $ref = new StoreRef(
            id: $store->id, slug: $store->slug, adapter: $store->adapter, baseUrl: rtrim((string) $store->base_url, '/'),
            businessId: $store->business_id, orderPrefix: data_get($store->capabilities, 'order_prefix'),
        );

        return match ($store->adapter) {
            'laladia', 'bionic' => new LaravelShopAdapter(
                $ref,
                // Optional rather than required: the adapter works with whichever of the two a
                // caller declared, and refuses at the endpoint that needs the missing one.
                $this->vault->optional(self::ASSISTANT_PROVIDER, $store->slug),
                $this->vault->optional(self::ADMIN_PROVIDER, $store->slug),
                $this->logger,
            ),
            default => throw new Unsupported("No adapter for store [{$store->slug}] (adapter '{$store->adapter}'). WooCommerce and the Econic platform are future adapters (ADR-009, amended by ADR-015)."),
        };
    }

    /** @return list<string> */
    public function storesWithAdapters(): array
    {
        return Store::whereIn('adapter', ['laladia', 'bionic'])->where('is_active', true)->pluck('slug')->all();
    }

    /**
     * The provider/account pairs a store's adapter needs, for a diagnostic caller that has to open a
     * credential scope itself. Names the pairs; never returns a value.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function credentialPairsFor(Store|string $store): array
    {
        $slug = $store instanceof Store ? $store->slug : $store;

        return [[self::ASSISTANT_PROVIDER, $slug], [self::ADMIN_PROVIDER, $slug]];
    }
}
