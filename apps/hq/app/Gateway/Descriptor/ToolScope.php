<?php

namespace App\Gateway\Descriptor;

/** What a tool's arguments must name for the call to mean anything. */
enum ToolScope: string
{
    /** Group-wide, or HQ's own state. Needs no business. */
    case Hq = 'hq';

    case Business = 'business';

    case Store = 'store';

    case Brand = 'brand';
}
