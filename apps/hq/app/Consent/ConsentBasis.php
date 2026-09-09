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
}
