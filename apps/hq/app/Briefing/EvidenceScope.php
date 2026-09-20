<?php

namespace App\Briefing;

/**
 * The business, brand and store a value is about. All three null is the whole group.
 *
 * Order facts carry a business and a store and no brand, so an order figure never claims a brand
 * scope it cannot support; the field exists for the sources that will carry one.
 */
final readonly class EvidenceScope
{
    public function __construct(
        public ?string $business = null,
        public ?string $brand = null,
        public ?string $store = null,
    ) {}

    public static function group(): self
    {
        return new self;
    }

    public function level(): string
    {
        return match (true) {
            $this->store !== null => 'store',
            $this->brand !== null => 'brand',
            $this->business !== null => 'business',
            default => 'group',
        };
    }

    public function toArray(): array
    {
        return ['level' => $this->level(), 'business' => $this->business, 'brand' => $this->brand, 'store' => $this->store];
    }
}
