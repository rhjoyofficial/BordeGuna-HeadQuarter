<?php

namespace App\Ads\Draft;

/** What the platform made. Every object is paused; `allPaused()` is checked by the tool, not trusted. */
final readonly class AdsDraftResult
{
    /** @param  array{campaign: string, adset: string, creative: ?string, ad: ?string}  $refs  @param  array<string, string>  $statuses */
    public function __construct(public array $refs, public array $statuses) {}

    public function allPaused(): bool
    {
        return $this->statuses !== [] && array_filter($this->statuses, static fn (string $s) => $s !== 'PAUSED') === [];
    }
}
