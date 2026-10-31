<?php

namespace App\Courier\Fake;

/** The sandbox stand-in for CarryBee. See `FakeCourier`. */
final class FakeCarryBeeCourier extends FakeCourier
{
    public function key(): string
    {
        return 'carrybee';
    }
}
