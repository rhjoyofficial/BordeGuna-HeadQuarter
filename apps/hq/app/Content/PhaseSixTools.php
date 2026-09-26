<?php

namespace App\Content;

use App\Tools\Content\CreateDraftTool;
use App\Tools\Publishing\PublishPostTool;
use App\Tools\Research\FetchPageTool;
use App\Tools\Research\FileArtifactTool;
use App\Tools\Research\SearchTool;

/**
 * The Phase 6 tools that exist as classes and are registered NOWHERE (docs/88 A.1: "no hidden
 * registration"; "no tool is granted to an agent merely because a stream is built").
 *
 * Registering one in `config('hq.tools')` is a gate's step (P6-G1 research, P6-G2 drafting, P6-G3
 * publishing), taken in a reviewed release; a test holds that none of these is registered or held.
 */
final class PhaseSixTools
{
    public const DECLARED = [
        SearchTool::class,
        FetchPageTool::class,
        FileArtifactTool::class,
        CreateDraftTool::class,
        PublishPostTool::class,
    ];

    /** Flags, all without a row, so all off. */
    public const FLAGS = ['research.engine', 'content.drafting', 'publishing.write'];
}
