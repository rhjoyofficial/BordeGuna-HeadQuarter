<?php

namespace Tests\Support\OwnerConsole;

use App\OwnerConsole\Do\DoModeActivation;

/**
 * TESTS ONLY. Stands in for the separate activation ruling Do mode does not have (ruling 31 Oct item 1), so the dark
 * mechanism can be exercised. The feature flag `console.do_mode` must still be ON: this replaces only the record.
 * Production never binds it; `DoModeActivation::ACTIVATION_RULING` stays null (DoModeGateTest pins it).
 */
final class TestDoModeActivation extends DoModeActivation
{
    protected function activationRuling(): ?string
    {
        return 'test-only-activation';
    }
}
