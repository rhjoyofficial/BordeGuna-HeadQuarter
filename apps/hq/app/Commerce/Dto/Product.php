<?php

namespace App\Commerce\Dto;

final readonly class Product
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public bool $isActive,
        public ?string $status,
        public float $basePrice,
        public ?string $categorySlug,
        public ?string $categoryName,
        public array $variants,
        public ?string $imageUrl = null,
        public ?int $freeDeliveryMinGrams = null,
        public ?string $shortDescription = null,
    ) {}

    public function variant(int $id): ?Variant
    {
        foreach ($this->variants as $v) {
            if ($v->id === $id) {
                return $v;
            }
        }

        return null;
    }
}
