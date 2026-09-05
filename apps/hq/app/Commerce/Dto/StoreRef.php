<?php

namespace App\Commerce\Dto;

final readonly class StoreRef
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $adapter,
        public string $baseUrl,
        public int $businessId,
        public ?string $orderPrefix = null,
    ) {}
}
