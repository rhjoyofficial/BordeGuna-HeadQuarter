<?php

namespace App\Identity;

use DomainException;

/**
 * An identity link that would cross a business (docs/122, ruling docs/120 A2): a customer of one business,
 * a store of another, or a lookup that came back with another business's customer. Never caught to carry on.
 */
final class CrossBusinessIdentity extends DomainException
{
    public static function customer(int $customerBusinessId, int $businessId): self
    {
        return new self("An identity of business {$businessId} is never linked to a customer of business {$customerBusinessId}.");
    }

    public static function store(int $storeId, int $businessId): self
    {
        return new self("Store {$storeId} is not a store of business {$businessId}; its ids are never linked there.");
    }
}
