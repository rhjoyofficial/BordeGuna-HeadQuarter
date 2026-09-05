<?php

namespace App\Commerce\Dto;

final readonly class DraftLine
{
    public function __construct(
        public int $quantity,
        public ?int $variantId = null,
        public ?int $comboId = null,
    ) {}
}
