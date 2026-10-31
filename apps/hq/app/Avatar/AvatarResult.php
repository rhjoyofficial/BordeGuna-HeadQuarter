<?php

namespace App\Avatar;

/** What a provider answered: a job reference it accepted, or why it failed. Never "published". */
final readonly class AvatarResult
{
    private function __construct(
        public bool $accepted,
        public ?string $jobRef,
        public ?string $error,
    ) {}

    public static function accepted(string $jobRef): self
    {
        return new self(true, $jobRef, null);
    }

    public static function failed(string $why): self
    {
        return new self(false, null, $why);
    }
}
