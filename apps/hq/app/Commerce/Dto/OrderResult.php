<?php

namespace App\Commerce\Dto;

final readonly class OrderResult
{
    public function __construct(
        public string $state,
        public ?Order $order = null,
        public ?string $providerRef = null,
        public ?string $error = null,
    ) {}

    public const CONFIRMED = 'confirmed';

    public const UNCERTAIN = 'uncertain';

    public const REFUSED = 'refused';

    public function confirmed(): bool
    {
        return $this->state === self::CONFIRMED;
    }
}
