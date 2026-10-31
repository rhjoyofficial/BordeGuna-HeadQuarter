<?php

namespace App\Courier\Fake;

/** The sandbox stand-in for Pathao. See `FakeCourier`. */
final class FakePathaoCourier extends FakeCourier
{
    public function key(): string
    {
        return 'pathao';
    }
}
