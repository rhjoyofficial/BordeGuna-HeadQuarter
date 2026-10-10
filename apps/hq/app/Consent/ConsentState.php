<?php

namespace App\Consent;

/**
 * The state a consent event puts a (customer, business, channel, purpose) tuple into (ADR-021).
 *
 * ⚠ `Withdrawn` is a RECORDED EVENT, never the absence of a grant. A suppression that exists only
 * as missing data cannot be told apart from data that was never collected, and the difference is
 * the entire question when somebody complains.
 */
enum ConsentState: string
{
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    /** Person-level suppression: outranks every grant on every channel for that business. */
    case DoNotContact = 'do_not_contact';

    /**
     * docs/108 §3.6: neutral at its exact scope. It ends a withdrawal or do-not-contact recorded at
     * that same scope and grants nothing; the person still needs a grant for the purpose asked.
     */
    case Lifted = 'lifted';

    /** A restriction a later `Lifted` at the same scope may end. */
    public function isRestriction(): bool
    {
        return $this === self::Withdrawn || $this === self::DoNotContact;
    }

    public function permits(): bool
    {
        return $this === self::Granted;
    }
}
