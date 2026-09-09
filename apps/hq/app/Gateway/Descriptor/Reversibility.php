<?php

namespace App\Gateway\Descriptor;

/**
 * Whether an effect can be undone, and by what (ADR-026).
 *
 * R4 is defined as "an external side effect that can be corrected without loss", and until now
 * nothing named what the correction actually is. A tier that claims correctability without naming
 * the corrector is a claim nobody can act on at 2am.
 */
enum Reversibility: string
{
    /** No external effect to undo. */
    case NoEffect = 'no_effect';

    /** HQ can undo it completely, by itself. */
    case Reversible = 'reversible';

    /** Not undone but compensated: a refund for a charge, a cancellation for an order. */
    case Compensable = 'compensable';

    /** Cannot be undone or compensated. A sent message, a publication, a deletion. */
    case Irreversible = 'irreversible';
}
