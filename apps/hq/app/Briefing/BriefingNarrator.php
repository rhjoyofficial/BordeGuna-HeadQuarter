<?php

namespace App\Briefing;

/**
 * The seam a narrator plugs into (docs/48 M.4: "narration is one HIGH-tier model call over the
 * assembled bundle").
 *
 * A narrator is handed the fixed trusted task and the bundle, and nothing else: no tools, no
 * question, no operator text. What it returns is not trusted. `BriefingComposer` runs
 * `NarrativeNumberCheck` over it and stores the narrative only when every figure in it is sourced.
 *
 * ⛔ No model-calling narrator exists. The container binds `NoNarrator`, and the composer does not
 * even resolve a narrator unless the flag `briefing.narration` AND the runtime flag are both on.
 * Building the real one needs a prompt and the model runtime, and both belong after the gates.
 */
interface BriefingNarrator
{
    /** @throws NarratorUnavailable when there is nothing that can narrate */
    public function narrate(string $trustedTask, EvidenceBundle $bundle): Narration;
}
