<?php

namespace App\Platform\Contracts;

/** Public comment threads on posts and ads: visible to everyone, which changes the risk entirely. */
interface CommentChannel extends PlatformProvider
{
    public const READ = 'read_comments';

    public const REPLY_PUBLIC = 'reply_public';

    /** Moving a public thread into a private message, which most platforms gate separately. */
    public const REPLY_PRIVATE = 'reply_private';

    public const HIDE = 'hide_comment';

    public const DELETE = 'delete_comment';
}
