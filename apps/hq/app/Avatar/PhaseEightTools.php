<?php

namespace App\Avatar;

use App\Tools\Avatar\GenerateAvatarTool;

/**
 * The Phase 8 tools (docs/88 A.1, docs/145-b). None is in `config('hq.tools')`. Ruling 31 Oct item 7 (docs/147-r2): each is in
 * `hq.flagged_tools`, ALWAYS registered with its availability gated by its flag (`avatar.generation`, no row: off), so it is
 * refused `feature_disabled` today. Switching the flag on is a gate's step taken after Joy's written consent policy (docs/95
 * item 26) and a provider; even then every call waits for approval and the business's media budget (BDT 0 by default).
 */
final class PhaseEightTools
{
    public const DECLARED = [GenerateAvatarTool::class];

    /** Flags, all without a row, so all off. */
    public const FLAGS = [AvatarGenerations::FLAG];
}
