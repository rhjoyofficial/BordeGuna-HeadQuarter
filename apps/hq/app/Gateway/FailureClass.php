<?php

namespace App\Gateway;

/**
 * The failure vocabulary, promoted from App\Commerce\Exceptions into the gateway (docs/48 D.3).
 *
 * The distinction that earns its keep is Refused versus Unavailable versus Uncertain. A business
 * rule saying no is not a transient error and must never be retried; a shop being slow is; and a
 * call whose effect MAY have landed is neither, and must be reconciled before anything is
 * attempted again (ADR-011).
 *
 * Nothing here is allowed to become a plausible-looking success.
 */
enum FailureClass: string
{
    /** A business rule said no. Retrying will get the same answer. */
    case Refused = 'refused';

    /** The far side is down, slow or rate limited. Worth another attempt. */
    case Unavailable = 'unavailable';

    /** The effect may have landed. Reconcile before retrying, never re-send blindly. */
    case Uncertain = 'uncertain';

    /** The arguments were wrong. Never reached a connector. */
    case ValidationFailed = 'validation_failed';

    /** A capability that does not exist, or is not configured. Retrying will not help. */
    case Unsupported = 'unsupported';

    /** A defect in HQ. Loud on purpose. */
    case Internal = 'internal';

    public function retryable(): bool
    {
        return $this === self::Unavailable;
    }
}
