<?php

namespace App\Platform\Contracts;

/** Organic content, for Phase 6. Publishing is irreversible in the way that matters: people saw it. */
interface PublishingPlatform extends PlatformProvider
{
    public const CREATE_DRAFT = 'create_draft_post';

    public const SCHEDULE = 'schedule_post';

    public const PUBLISH = 'publish_post';

    public const READ_INSIGHTS = 'read_post_insights';

    public const DELETE = 'delete_post';
}
