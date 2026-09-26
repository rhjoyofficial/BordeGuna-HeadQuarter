<?php

namespace App\Ads\Contribution;

/** One delivered order, as its system of record states it. Money in the major unit, as a decimal string. */
final readonly class DeliveredSale
{
    /**
     * @param  list<array{sku: string, qty: string}>  $lines
     */
    public function __construct(
        public string $orderRef,
        public ?int $storeId,
        public string $deliveredAt,
        /** The platform campaign the order came from, when the shop recorded it (fbc / utm). */
        public ?string $campaignRef,
        public string $currency,
        /** Goods revenue net of the order's discount, before delivery charges. */
        public string $netRevenue,
        public array $lines,
    ) {}
}
