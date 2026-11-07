<?php

namespace App\Ads\Draft\Exceptions;

use RuntimeException;

/** The platform (or a HQ check before it) said no. `$created` lists any paused objects made before the refusal. */
final class AdsDraftRefused extends RuntimeException
{
    /** @param  array<string, string>  $created */
    public function __construct(string $message, public readonly array $created = [])
    {
        parent::__construct($message);
    }
}
