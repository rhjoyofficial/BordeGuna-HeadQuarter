<?php

namespace App\Support\Events\Handlers;

use App\Identity\CrossBusinessIdentity;
use App\Identity\IdentityResolver;
use App\Models\DomainEvent;
use App\Models\Store;
use App\Support\Events\EventHandler;

/** customer.registered from a shop: link the shop user to a customer of the store's business (phone/email first). */
final class CustomerRegisteredHandler implements EventHandler
{
    public function __construct(private readonly IdentityResolver $identity) {}

    public function handle(DomainEvent $event): void
    {
        $p = $event->payload;
        $store = Store::where('slug', $p['_store'] ?? '')->firstOrFail();
        // ⛔ docs/122 (A4): the event was recorded for one business (the webhook's store); a payload naming a
        // store of another business is refused, never resolved there.
        if ($event->business_id !== null && (int) $event->business_id !== (int) $store->business_id) {
            throw CrossBusinessIdentity::store((int) $store->id, (int) $event->business_id);
        }
        $user = is_array($p['user'] ?? null) ? $p['user'] : $p;
        $customer = $this->identity->resolve($store, [
            'name' => $user['name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'email' => $user['email'] ?? null,
            'shop_user_id' => $user['id'] ?? null,
            'placed_at' => $user['created_at'] ?? null,
        ]);
        if ((int) $customer->business_id !== (int) $store->business_id) {
            throw CrossBusinessIdentity::customer((int) $customer->business_id, (int) $store->business_id);
        }
    }
}
