<?php

namespace App\Outcomes;

use App\Context\Provenance;

/** One registered outcome type: what it must be about, and how HQ may learn of it (docs/107 section 5). */
final readonly class OutcomeTypeDefinition
{
    /**
     * @param  list<ResultType>  $subjects  at least one `subject` reference of one of these kinds is required
     * @param  list<OutcomeSource>  $sources
     * @param  list<Provenance>  $provenances
     */
    public function __construct(
        public string $type,
        public string $domain,
        public array $subjects,
        public array $sources,
        public array $provenances,
        public string $schemaVersion = OutcomeTypes::SCHEMA_VERSION,
    ) {}

    public function allowsSubject(ResultType $type): bool
    {
        return in_array($type, $this->subjects, true);
    }

    public function allowsSource(OutcomeSource $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    public function allowsProvenance(Provenance $provenance): bool
    {
        return in_array($provenance, $this->provenances, true);
    }
}
