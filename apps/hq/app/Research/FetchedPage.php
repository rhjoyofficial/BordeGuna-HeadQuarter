<?php

namespace App\Research;

use Carbon\CarbonImmutable;

/** A page as the fetcher read it: text only, hashed, dated. Third-party and untrusted by definition. */
final readonly class FetchedPage
{
    public function __construct(
        public string $url,
        public string $host,
        public ?string $title,
        public string $text,
        public string $contentHash,
        public int $bytes,
        public CarbonImmutable $retrievedAt,
        public ?CarbonImmutable $publishedAt = null,
    ) {}
}
