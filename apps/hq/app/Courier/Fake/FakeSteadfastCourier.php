<?php

namespace App\Courier\Fake;

/** The sandbox stand-in for Steadfast. See `FakeCourier`. */
final class FakeSteadfastCourier extends FakeCourier
{
    public function key(): string
    {
        return 'steadfast';
    }
}
