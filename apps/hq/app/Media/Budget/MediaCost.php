<?php

namespace App\Media\Budget;

/** An estimated or reported provider cost, in the provider's billing currency (ISO 4217), as a decimal string. */
final readonly class MediaCost
{
    public function __construct(public string $amount, public string $currency, public string $basis = '') {}
}
