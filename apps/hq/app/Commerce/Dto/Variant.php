<?php

namespace App\Commerce\Dto;

final readonly class Variant
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $sku,
        public float $price,
        public float $finalPrice,
        public ?int $stock,
        public ?int $availableStock,
        public ?int $weightGrams,
        public bool $isActive,
        public ?string $discountType = null,
        public ?float $discountValue = null,
        public ?string $saleEndsAt = null,
    ) {}
}
