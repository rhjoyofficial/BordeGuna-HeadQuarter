<?php

namespace App\Courier\Fake;

/** The sandbox stand-in for RedX. See `FakeCourier`. */
final class FakeRedxCourier extends FakeCourier
{
    public function key(): string
    {
        return 'redx';
    }
}
