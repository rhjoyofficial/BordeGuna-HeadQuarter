<?php

namespace App\Avatar;

use App\Tools\Avatar\GenerateAvatarTool;

/**
 * The Phase 8 tools that exist as classes and are registered NOWHERE (docs/88 A.1, docs/145-b), the same
 * posture as `PhaseSixTools`. Registering one in `config('hq.tools')` is a gate's step taken in a reviewed
 * release, after Joy's written consent policy (docs/95 item 26) and a provider; a test holds that none is.
 */
final class PhaseEightTools
{
    public const DECLARED = [GenerateAvatarTool::class];

    /** Flags, all without a row, so all off. */
    public const FLAGS = [AvatarGenerations::FLAG];
}
