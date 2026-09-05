<?php

namespace App\Commerce\Dto;

final readonly class Availability
{
    public function __construct(
        public int $variantId,
        public bool $orderable,
        public string $reason,
        public ?int $stockHint,
        public ?string $productStatus = null,
        public array $promotions = [],
    ) {}
}
