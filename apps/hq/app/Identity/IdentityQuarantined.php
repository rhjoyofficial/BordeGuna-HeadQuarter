<?php

namespace App\Identity;

use DomainException;

/**
 * The only identity that could say who this is is quarantined (docs/122): the migration or a person could not
 * place it without guessing. Resolution is refused rather than guessed; the input (an order event, a message)
 * stays replayable and the open `customer_identity_conflicts` row is the resolution requirement.
 */
final class IdentityQuarantined extends DomainException
{
    public static function key(int $businessId, string $channel): self
    {
        return new self("The [{$channel}] identity this needs is quarantined in business {$businessId}; it is not resolved by guessing. Resolve the open identity conflict first.");
    }
}
