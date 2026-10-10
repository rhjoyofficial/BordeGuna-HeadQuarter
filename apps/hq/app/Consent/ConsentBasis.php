<?php

namespace App\Consent;

/** How the grant was obtained, kept because "who said we could" is the first question asked. */
enum ConsentBasis: string
{
    case Explicit = 'explicit';
    case Checkout = 'checkout';
    case Inbound = 'inbound';
    case Contract = 'contract';
    case Imported = 'imported';
    case Operator = 'operator';

    /**
     * docs/108 §3.4: whether a grant on this basis can be an opt-in. A customer writing to us
     * (`inbound`) or a contract to deliver an order (`contract`) is never permission to advertise.
     */
    public function evidencesOptIn(): bool
    {
        return match ($this) {
            self::Explicit, self::Checkout, self::Operator, self::Imported => true,
            self::Inbound, self::Contract => false,
        };
    }
}
