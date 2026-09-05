<?php

namespace App\Commerce\Dto;

final readonly class OrderLine
{
    public function __construct(
        public string $name,
        public int $quantity,
        public float $unitPrice,
        public float $total,
        public ?string $sku = null,
        public ?int $variantId = null,
        public ?int $comboId = null,
    ) {}
}
