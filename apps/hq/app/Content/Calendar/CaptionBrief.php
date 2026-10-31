<?php

namespace App\Content\Calendar;

/**
 * Everything a caption drafter may use, and nothing else (docs/145-a): the entry's idea, the brand's
 * persona, the active playbooks' text, and the phrases it must not use. No product figure is handed over
 * as something to repeat: a figure in the caption must still match a fact read from the ERP at check time.
 */
final readonly class CaptionBrief
{
    /**
     * @param  list<string>  $productLines
     * @param  list<array{kind: string, title: string, body: string}>  $playbooks
     * @param  list<string>  $forbiddenPhrases
     */
    public function __construct(
        public string $brand,
        public string $platform,
        public string $idea,
        public ?string $occasion,
        public array $productLines,
        public ?string $persona,
        public array $playbooks,
        public array $forbiddenPhrases,
        public string $language = 'bn',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
