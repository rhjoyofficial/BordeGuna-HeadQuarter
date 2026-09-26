<?php

namespace App\Content;

use App\Research\ResearchRefused;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One product fact a draft may state (ADR-051 accepted, docs/88 A.5): a price, a size, an ingredient,
 * read from a system of record at draft time (and again at publish time).
 *
 * ⛔ Product facts enter publishable content ONLY from the ERP or a shop (`erp`, `shop`), or from a
 * source a named person explicitly approved (`approved_source`, which must say who). A research
 * claim is never a product fact: a gap in the catalogue is a data task for the business, not a reason
 * to publish a web page's number as ours (ADR-051, alternatives).
 */
final readonly class ProductFact
{
    public const SYSTEMS = ['erp', 'shop', 'approved_source'];

    public function __construct(
        public string $key,
        public string $label,
        public int|string $value,
        public string $unit,
        public string $system,
        public string $ref,
        public CarbonImmutable $asOf,
        public ?string $sku = null,
        public ?int $approvedBy = null,
    ) {}

    public static function fromArray(array $fact): self
    {
        foreach (['key', 'label', 'value', 'unit', 'system', 'ref', 'as_of'] as $field) {
            if (! isset($fact[$field]) || $fact[$field] === '') {
                throw new ResearchRefused("A product fact needs [{$field}].");
            }
        }

        try {
            $asOf = CarbonImmutable::parse((string) $fact['as_of']);
        } catch (Throwable) {
            throw new ResearchRefused("A product fact's as_of is not a time.");
        }

        $self = new self(
            key: (string) $fact['key'],
            label: (string) $fact['label'],
            value: is_int($fact['value']) ? $fact['value'] : (string) $fact['value'],
            unit: (string) $fact['unit'],
            system: (string) $fact['system'],
            ref: (string) $fact['ref'],
            asOf: $asOf,
            sku: isset($fact['sku']) ? (string) $fact['sku'] : null,
            approvedBy: isset($fact['approved_by']) ? (int) $fact['approved_by'] : null,
        );
        $self->assertFromRecord();

        return $self;
    }

    public function assertFromRecord(): void
    {
        if (! in_array($this->system, self::SYSTEMS, true)) {
            throw new ResearchRefused("Product fact [{$this->key}] comes from [{$this->system}]. Product facts come only from the ERP, a shop, or a source a person approved.");
        }
        if ($this->system === 'approved_source' && $this->approvedBy === null) {
            throw new ResearchRefused("Product fact [{$this->key}] names an approved source but no person who approved it.");
        }
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key, 'label' => $this->label, 'value' => $this->value, 'unit' => $this->unit,
            'system' => $this->system, 'ref' => $this->ref, 'as_of' => $this->asOf->toIso8601String(),
            'sku' => $this->sku, 'approved_by' => $this->approvedBy,
        ];
    }
}
