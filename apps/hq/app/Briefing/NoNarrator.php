<?php

namespace App\Briefing;

/**
 * The narrator HQ ships with: none. Bound in the container so that switching the flag on by mistake
 * cannot reach a model; it produces a briefing that says narration was unavailable.
 */
final class NoNarrator implements BriefingNarrator
{
    public function narrate(string $trustedTask, EvidenceBundle $bundle): Narration
    {
        throw new NarratorUnavailable('No briefing narrator is installed on this HQ.');
    }
}
