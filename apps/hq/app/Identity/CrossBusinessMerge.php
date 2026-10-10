<?php

namespace App\Identity;

use DomainException;

/**
 * Two customers of different businesses cannot be merged, by anyone (ruling docs/103 §5 and §11: no
 * cross-brand customer merging). One phone in LaLaDia and in Bionic is two customers.
 */
final class CrossBusinessMerge extends DomainException
{
    public static function between(int $keptBusinessId, int $absorbedBusinessId): self
    {
        return new self("Customers of different businesses are never merged (business {$keptBusinessId} and business {$absorbedBusinessId}).");
    }
}
