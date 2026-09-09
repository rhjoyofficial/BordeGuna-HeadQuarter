<?php

namespace App\Platform\Contracts;

/** Paid media, for Phase 5. Reading spend and changing spend are deliberately far apart. */
interface AdsPlatform extends PlatformProvider
{
    public const READ_INSIGHTS = 'read_insights';

    public const READ_CAMPAIGNS = 'read_campaigns';

    public const CREATE_DRAFT = 'create_draft_campaign';

    public const CHANGE_BUDGET = 'change_budget';

    public const PAUSE = 'pause_campaign';

    public const PUBLISH = 'publish_campaign';
}
