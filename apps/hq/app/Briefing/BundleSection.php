<?php

namespace App\Briefing;

/**
 * One section of the briefing, present even when it has nothing to say (docs/48 M.2): "an empty
 * section that says why it is empty is honest and a missing section is a silent gap".
 */
final readonly class BundleSection
{
    /** Has items. */
    public const OK = 'ok';

    /** Looked, and found nothing: a true answer, with the reason stating what was looked at. */
    public const EMPTY = 'empty';

    /** The source does not exist yet (marketing and finance until Phase 5). */
    public const NOT_CONNECTED = 'not_connected';

    /** The caller may not read what this section reads. */
    public const WITHHELD = 'withheld';

    /** The question has no meaning for this request (what is scheduled "today" on a past date). */
    public const NOT_APPLICABLE = 'not_applicable';

    public const STATUSES = [self::OK, self::EMPTY, self::NOT_CONNECTED, self::WITHHELD, self::NOT_APPLICABLE];

    /**
     * @param  list<string>  $itemIds
     * @param  list<array<string, mixed>>  $notes  stated gaps, such as "insufficient history" for a store
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $status,
        public ?string $reason = null,
        public array $itemIds = [],
        public array $notes = [],
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'status' => $this->status,
            'reason' => $this->reason,
            'item_ids' => $this->itemIds,
            'notes' => $this->notes,
        ];
    }
}
