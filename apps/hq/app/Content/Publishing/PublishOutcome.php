<?php

namespace App\Content\Publishing;

/**
 * What the platform answered. `accepted` means the platform returned a post id; it is NOT "published":
 * that is decided only after the post is read back and matches (ADR-031). `uncertain` means the answer
 * was lost or ambiguous and the post may exist; it is reconciled before any retry.
 */
final readonly class PublishOutcome
{
    private function __construct(
        public string $status,
        public ?string $postId,
        public ?string $error,
    ) {}

    public static function accepted(string $postId): self
    {
        return new self('accepted', $postId, null);
    }

    public static function uncertain(string $why): self
    {
        return new self('uncertain', null, $why);
    }

    public static function failed(string $why): self
    {
        return new self('failed', null, $why);
    }
}
