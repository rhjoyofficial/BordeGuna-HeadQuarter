<?php

namespace App\Commerce\Dto;

final readonly class DraftAddress
{
    public function __construct(
        public string $line,
        public string $district,
        public string $thana,
        public ?string $area = null,
        public ?string $city = null,
        public ?int $zoneId = null,
    ) {}
}
