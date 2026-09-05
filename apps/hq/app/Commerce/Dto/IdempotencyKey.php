<?php

namespace App\Commerce\Dto;

use Illuminate\Support\Str;

final readonly class IdempotencyKey
{
    public function __construct(
        public string $value,
    ) {}

    public static function generate(): self
    {
        return new self((string) Str::uuid());
    }

    public static function for(string ...$parts): self
    {
        return new self(hash('sha256', implode('|', $parts)));
    }
}
