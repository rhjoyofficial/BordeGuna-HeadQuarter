<?php

namespace App\Briefing;

/** What a narrator hands back: the text, who wrote it, and the model call it cost, if there was one. */
final readonly class Narration
{
    public function __construct(
        public string $text,
        public string $narrator,
        public ?int $aiRunId = null,
    ) {}
}
