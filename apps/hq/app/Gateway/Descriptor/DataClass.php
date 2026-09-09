<?php

namespace App\Gateway\Descriptor;

/**
 * What kind of data a call handles, so it can be routed and retained accordingly (ADR-026).
 *
 * `RoutingContext::$sensitivity` has existed since M4 as a null placeholder "reserved for Phase 3:
 * routing away from providers by data class". This is what it was reserved for.
 */
enum DataClass: string
{
    /** Nothing about a person, and nothing the group would mind seeing quoted. */
    case Public = 'public';

    /** Internal business data: stock, prices, job runs. */
    case Internal = 'internal';

    /** Identifies a person: name, phone, address, order history. */
    case Personal = 'personal';

    /** Personal data whose exposure harms: payment details, government identifiers, health. */
    case Sensitive = 'sensitive';

    /** Credentials and keys. No tool should declare this; the vault holds them. */
    case Secret = 'secret';

    public function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::Personal => 2,
            self::Sensitive => 3,
            self::Secret => 4,
        };
    }

    public function isPersonal(): bool
    {
        return $this->rank() >= self::Personal->rank();
    }
}
