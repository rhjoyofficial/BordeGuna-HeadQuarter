<?php

namespace App\Support\Events\Handlers;

use App\Identity\IdentityResolver;
use App\Models\DomainEvent;
use App\Models\Store;
use App\Support\Events\EventHandler;

/** customer.registered from a shop: link the shop user to a customer (phone/email first). */
final class CustomerRegisteredHandler implements EventHandler
{
    public function __construct(private readonly IdentityResolver $identity) {}

    public function handle(DomainEvent $event): void
    {
        $p = $event->payload;
        $store = Store::where('slug', $p['_store'] ?? '')->firstOrFail();
        $user = is_array($p['user'] ?? null) ? $p['user'] : $p;
        $this->identity->resolve($store, [
            'name' => $user['name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'email' => $user['email'] ?? null,
            'shop_user_id' => $user['id'] ?? null,
            'placed_at' => $user['created_at'] ?? null,
        ]);
    }
}
