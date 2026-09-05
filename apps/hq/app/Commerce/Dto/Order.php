<?php

namespace App\Commerce\Dto;

final readonly class Order
{
    public function __construct(
        public string $ref,
        public string $status,
        public ?string $paymentStatus,
        public ?string $paymentMethod,
        public float $grandTotal,
        public ?string $placedAt,
        public ?string $customerPhone,
        public ?string $customerName,
        public array $lines = [],
        public array $shipments = [],
        public ?string $source = null,
        public array $raw = [],
    ) {}
}
