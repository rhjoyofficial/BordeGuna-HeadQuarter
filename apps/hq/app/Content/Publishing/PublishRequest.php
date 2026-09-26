<?php

namespace App\Content\Publishing;

/** Exactly what was approved, addressed to one account. `imageUrl` is a public JPEG for Instagram. */
final readonly class PublishRequest
{
    public function __construct(
        public string $accountRef,
        public string $text,
        public string $logicalKey,
        public ?string $imageUrl = null,
    ) {}
}
