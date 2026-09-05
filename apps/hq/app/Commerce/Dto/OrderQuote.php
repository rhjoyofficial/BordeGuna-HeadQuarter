<?php

namespace App\Commerce\Dto;

final readonly class OrderQuote
{
    public function __construct(
        public float $subtotal,
        public float $discount,
        public float $shipping,
        public float $total,
        public array $lines = [],
        public ?array $coupon = null,
        public array $raw = [],
    ) {}
}
