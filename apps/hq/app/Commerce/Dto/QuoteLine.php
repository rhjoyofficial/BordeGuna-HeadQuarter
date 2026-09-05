<?php

namespace App\Commerce\Dto;

final readonly class QuoteLine
{
    public function __construct(
        public string $label,
        public int $quantity,
        public float $unitPrice,
        public float $total,
        public ?int $variantId = null,
        public ?int $comboId = null,
    ) {}
}
