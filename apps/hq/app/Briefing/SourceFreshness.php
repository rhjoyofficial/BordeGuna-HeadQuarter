<?php

namespace App\Briefing;

use Carbon\CarbonImmutable;

/**
 * One source the bundle rests on, with its age against its budget (docs/48 M.3). The list of these
 * is the top of the bundle, stale and missing first, before any number.
 */
final readonly class SourceFreshness
{
    public function __construct(
        public string $key,
        public string $readModel,
        public EvidenceScope $scope,
        public ?CarbonImmutable $asOf,
        public Freshness $freshness,
        public ?int $rowsHeld = null,
        public ?string $note = null,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'read_model' => $this->readModel,
            'scope' => $this->scope->toArray(),
            'as_of' => $this->asOf?->utc()->toIso8601String(),
            'freshness' => $this->freshness->toArray(),
            'rows_held' => $this->rowsHeld,
            'note' => $this->note,
        ];
    }
}
