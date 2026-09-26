<?php

namespace App\Research;

/** One search result as a provider returned it: a candidate URL, not a source (docs/85 C.2). */
final readonly class SearchResult
{
    public function __construct(
        public string $url,
        public string $title,
        public int $rank,
        public string $provider,
        public ?string $publishedAt = null,
    ) {}
}
