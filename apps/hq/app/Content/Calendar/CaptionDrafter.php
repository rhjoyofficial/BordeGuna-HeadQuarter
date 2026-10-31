<?php

namespace App\Content\Calendar;

/**
 * Writes a caption for one calendar entry from the business's own playbooks (docs/145-a).
 *
 * ⛔ No implementation is bound in the application. A model-backed drafter is a later step that must go
 * through `ModelRuntime` (Pause All AI, provider routing, cost ledger); until one is bound, AI drafting
 * refuses with "not connected" and a person writes the caption. `FakeCaptionDrafter` is the sandbox.
 *
 * Whatever a drafter returns is labelled `generated` and goes through exactly the same checks as a
 * person's words (numbers, the owner's claims rules, the floor, banned words), and then a person's review.
 */
interface CaptionDrafter
{
    public function name(): string;

    /** The caption, or null when the drafter declines. Never throws for content reasons. */
    public function draft(CaptionBrief $brief): ?string;
}
