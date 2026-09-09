<?php

namespace App\Gateway\Descriptor;

/**
 * What a call MOVES in the business, as a band rather than a number (ADR-026).
 *
 * ⚠ Not to be confused with `CostHint`, which says what a call costs HQ to make. A tool that spends
 * two paisa of tokens to issue a fifty thousand taka refund is cheap by one measure and the most
 * expensive thing in the system by the other. Autonomy by monetary value cannot be expressed
 * without this second number.
 *
 * A band rather than an amount because the descriptor is static: the exact figure lives in the
 * arguments of a particular call, and a static field that pretended to know it would be lying.
 * A tool whose amount varies declares the HIGHEST band it can reach.
 */
enum MonetaryImpact: string
{
    /** Moves no money and creates no obligation. */
    case None = 'none';

    /** Under 1,000 BDT, or an amount too small to be worth a human's interruption. */
    case Low = 'low';

    /** 1,000 to 20,000 BDT. */
    case Medium = 'medium';

    /** Above 20,000 BDT, or unbounded because the arguments decide. */
    case High = 'high';

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }
}
