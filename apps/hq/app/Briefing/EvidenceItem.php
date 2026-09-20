<?php

namespace App\Briefing;

use Carbon\CarbonImmutable;

/**
 * One typed value a narrator may speak from (docs/48 M.1, docs/80 point 1).
 *
 * `value` is the only thing a narrative may quote. `details` is context for the operator's page
 * (which tool an approval is for, a job's name) and is never a quotable figure: the unsourced-number
 * check reads `value`, and `subject` for an identifier that carries digits, and nothing else.
 */
final readonly class EvidenceItem
{
    /**
     * The units a value may carry. `datetime` values are ISO 8601 strings. No collector produces a
     * `percent` yet; the unit exists so that the day one does, a narrated percentage has an item to
     * match and cannot be a share the narrator worked out for itself.
     */
    public const UNITS = ['orders', 'BDT', 'count', 'units', 'hours', 'percent', 'datetime', 'boolean', 'text'];

    /**
     * @param  string  $id  stable: the same question about the same scope has the same id on every day
     * @param  array{system: string, read_model: string, ref: string}  $source
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $id,
        public EvidenceKind $kind,
        public string $section,
        public string $label,
        public int|string|bool|null $value,
        public string $unit,
        public EvidenceScope $scope,
        public array $source,
        public ?CarbonImmutable $asOf,
        public Freshness $freshness,
        public ?Lineage $lineage = null,
        public ?string $subject = null,
        public array $details = [],
        public ?string $note = null,
    ) {}

    /** The value as a plain decimal string when it is a number, or null. Booleans are not numbers. */
    public function numericValue(): ?string
    {
        if (is_int($this->value)) {
            return (string) $this->value;
        }

        if (is_string($this->value) && $this->unit !== 'datetime' && $this->unit !== 'text' && preg_match('/^-?\d+(\.\d+)?$/', $this->value) === 1) {
            return $this->value;
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'section' => $this->section,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'scope' => $this->scope->toArray(),
            'source' => $this->source,
            'as_of' => $this->asOf?->utc()->toIso8601String(),
            'freshness' => $this->freshness->toArray(),
            'lineage' => $this->lineage?->toArray(),
            'subject' => $this->subject,
            'details' => $this->details,
            'note' => $this->note,
        ];
    }
}
