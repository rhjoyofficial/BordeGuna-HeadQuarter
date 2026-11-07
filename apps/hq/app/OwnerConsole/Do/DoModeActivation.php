<?php

namespace App\OwnerConsole\Do;

use App\OwnerConsole\Flag;

/**
 * The server-side activation gate of Do mode (ChatGPT ruling 31 Oct 2026, item 1: "Keep Do mode unavailable in the
 * production UI and disabled by a server-side feature/activation gate ... until Phase 9 evidence is reviewed and a
 * separate activation ruling is issued").
 *
 * Do mode is available only when BOTH hold:
 *  1. an activation record exists: `ACTIVATION_RULING` names the separate activation ruling. It is null, and it is a
 *     constant in code on purpose: switching a database row (the flag, or any other row) can never make it true, and
 *     setting it is a code change that goes through review, the test suite and a release Joy authorizes;
 *  2. the feature flag `console.do_mode` is ON (and that flag changes only with group-wide authority, ruling 13).
 *
 * Every door checks this: the routes (404 while inactive, `EnsureDoModeActive`), `DoPlanService` (refuses to propose,
 * start or continue, and a running plan stands still), the `hq:do-plans:advance` command, and the sidebar share.
 * Tests substitute a subclass to exercise the mechanism; production never binds one.
 */
class DoModeActivation
{
    /**
     * The id of the separate activation ruling. Null: Do mode has no activation, whatever the flag says.
     * ⛔ Pinned null by tests/Feature/OwnerConsole/DoModeGateTest.php; changing it needs the activation ruling first.
     */
    public const ACTIVATION_RULING = null;

    /** Why Do mode is unavailable, or null when it is available. */
    public function refusal(): ?string
    {
        if ($this->activationRuling() === null) {
            return 'Do mode is not activated: it stays unavailable until a separate activation ruling is recorded (ruling 31 Oct, item 1).';
        }
        if (! Flag::on(Flag::DO_MODE)) {
            return 'Do mode is switched off.';
        }

        return null;
    }

    public function active(): bool
    {
        return $this->refusal() === null;
    }

    protected function activationRuling(): ?string
    {
        return self::ACTIVATION_RULING;
    }
}
