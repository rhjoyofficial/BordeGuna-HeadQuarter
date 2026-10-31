<?php

namespace App\OwnerConsole\Brief;

final readonly class OwnerSend
{
    public function __construct(public bool $ok, public ?string $ref = null, public ?string $error = null, public bool $uncertain = false) {}

    public static function sent(?string $ref = null): self
    {
        return new self(true, $ref);
    }

    public static function refused(string $why): self
    {
        return new self(false, null, $why);
    }

    /** The request may have reached the provider (a timeout): never reported as delivered, never blindly repeated. */
    public static function uncertain(string $why): self
    {
        return new self(false, null, $why, true);
    }
}
