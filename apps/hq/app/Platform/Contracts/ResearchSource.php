<?php

namespace App\Platform\Contracts;

/**
 * Search and page fetching for the Phase 6 research engine.
 *
 * ⛔ Declared here, NOT built here: the research engine stays in Phase 6 as approved. The interface
 * exists now only so that everything it will fetch is already covered by the provenance rule -
 * every result is `Provenance::ThirdParty` and can never raise autonomy (ADR-024).
 */
interface ResearchSource extends PlatformProvider
{
    public const SEARCH = 'search';

    public const FETCH_PAGE = 'fetch_page';

    public const READ_FEED = 'read_feed';
}
