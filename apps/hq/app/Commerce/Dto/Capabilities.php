<?php

namespace App\Commerce\Dto;

final readonly class Capabilities
{
    public function __construct(
        public array $flags = [],
    ) {}

    public function supports(string $operation): bool
    {
        return (bool) ($this->flags[$operation] ?? false);
    }

    /** What the LaLaDia platform supports today (verified 5 Sep 2026); pending shop changes are false. */
    public static function laravelShop(bool $hasAdminToken): self
    {
        return new self([
            'search' => true, 'product' => true, 'variant' => true, 'combos' => true, 'geo' => true,
            'preview' => true, 'create_order' => true, 'idempotent_create' => true, // via the shop's checkout_token (verified: CheckoutIdempotency service)
            'availability_endpoint' => false, 'promotions_endpoint' => false, 'order_lookup_by_phone' => $hasAdminToken,
            'order_status' => $hasAdminToken, 'list_orders' => $hasAdminToken, 'update_order' => false,
            'webhooks' => ['order.created', 'customer.registered', 'coupon.expired'],
        ]);
    }
}
