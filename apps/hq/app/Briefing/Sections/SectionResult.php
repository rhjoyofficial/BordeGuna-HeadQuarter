<?php

namespace App\Briefing\Sections;

use App\Briefing\BundleSection;
use App\Briefing\EvidenceItem;

/** What one section collector hands back: the section, and the items it lists. */
final readonly class SectionResult
{
    /** @param  list<EvidenceItem>  $items */
    public function __construct(public BundleSection $section, public array $items = []) {}

    /**
     * @param  list<EvidenceItem>  $items
     * @param  list<array<string, mixed>>  $notes
     */
    public static function of(string $key, string $title, array $items, string $emptyReason, array $notes = []): self
    {
        $ids = array_map(static fn (EvidenceItem $i): string => $i->id, $items);

        return new self(
            $items === []
                ? new BundleSection($key, $title, BundleSection::EMPTY, $emptyReason, [], $notes)
                : new BundleSection($key, $title, BundleSection::OK, null, $ids, $notes),
            $items,
        );
    }

    public static function without(string $key, string $title, string $status, string $reason): self
    {
        return new self(new BundleSection($key, $title, $status, $reason));
    }
}
