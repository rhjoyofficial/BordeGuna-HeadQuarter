<?php

namespace App\Commerce\Dto;

final readonly class DraftCustomer
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email = null,
    ) {}
}
