<?php

namespace App\Commerce\Dto;

final readonly class Combo
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public float $price,
        public bool $isActive,
        public array $items = [],
    ) {}
}
