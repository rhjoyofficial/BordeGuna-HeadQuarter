<?php

namespace App\Courier;

use Carbon\CarbonImmutable;

/** One line of a parcel's timeline, as the courier reported it. */
final readonly class ParcelEvent
{
    public function __construct(
        public CarbonImmutable $at,
        public ParcelStatus $status,
        public string $rawStatus,
        public ?string $message = null,
        /** False when the courier gave no time: `at` is then when HQ heard, and is left out of the identity. */
        public bool $timed = true,
    ) {}

    /** A stable identity, so the same event polled twice or pushed twice is one row. */
    public function dedupeKey(): string
    {
        return hash('sha256', ($this->timed ? $this->at->utc()->format('c') : '-').'|'.$this->rawStatus.'|'.($this->message ?? ''));
    }
}
