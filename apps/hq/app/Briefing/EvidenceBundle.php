<?php

namespace App\Briefing;

use Carbon\CarbonImmutable;

/**
 * The typed evidence bundle of the Executive's standard briefing (docs/48 M.1, docs/80 point 1).
 *
 * ⛔ **This is not business truth.** Truth lives in the systems of record: the shops and the ERP.
 * HQ holds read models of them, and this bundle is a bounded, dated reading of those read models.
 * What it is, precisely, is the evidence surface a narrator is allowed to speak from: a narrative
 * may quote a value that is in here and nothing else, and `NarrativeNumberCheck` enforces that
 * after the fact instead of trusting a prompt to. Being in the bundle makes a number quotable and
 * traceable. It does not make it right, which is why every item carries its source, its `as_of`,
 * its freshness and its lineage, and why freshness is the first field and not a footnote.
 *
 * The flow (ChatGPT, 20 September): HQ and ERP read models -> this bundle -> `BundleValidator`
 * (completeness, provenance, freshness) -> narration. Collection is deterministic: the same read
 * models at the same moment give the same bundle and the same digest.
 */
final readonly class EvidenceBundle
{
    public const VERSION = 'briefing.bundle.v1';

    /**
     * @param  array{date: string, from: string, to: string}  $yesterday  the Dhaka calendar day, bounds in UTC
     * @param  array{date: string, from: string, to: string}  $today
     * @param  array{business: ?string, permitted_business_ids: ?list<int>}  $scope
     * @param  list<SourceFreshness>  $sources
     * @param  list<BundleSection>  $sections
     * @param  list<EvidenceItem>  $items
     */
    public function __construct(
        public string $capability,
        public string $briefingDate,
        public string $timezone,
        public CarbonImmutable $generatedAt,
        public array $yesterday,
        public array $today,
        public array $scope,
        public array $sources,
        public array $sections,
        public array $items,
    ) {}

    public function item(string $id): ?EvidenceItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public function section(string $key): ?BundleSection
    {
        foreach ($this->sections as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        return null;
    }

    /** @return list<SourceFreshness> the sources a reader must be told about before any number */
    public function degradedSources(): array
    {
        return array_values(array_filter($this->sources, static fn (SourceFreshness $s): bool => $s->freshness->state->degrades()));
    }

    public function toArray(): array
    {
        $byState = static fn (array $sources, FreshnessState $state): array => array_values(array_map(
            static fn (SourceFreshness $s): string => $s->key,
            array_filter($sources, static fn (SourceFreshness $s): bool => $s->freshness->state === $state),
        ));

        return [
            'version' => self::VERSION,
            'capability' => $this->capability,
            'notice' => 'Not business truth: the bounded evidence a narrator may speak from, read from HQ read models at generated_at.',
            'briefing_date' => $this->briefingDate,
            'timezone' => $this->timezone,
            'generated_at' => $this->generatedAt->utc()->toIso8601String(),
            // Freshness first, before any number (docs/48 M.3).
            'freshness' => [
                'stale' => $byState($this->sources, FreshnessState::Stale),
                'missing' => $byState($this->sources, FreshnessState::Missing),
                'not_connected' => $byState($this->sources, FreshnessState::NotConnected),
                'sources' => array_map(static fn (SourceFreshness $s): array => $s->toArray(), $this->sources),
            ],
            'yesterday' => $this->yesterday,
            'today' => $this->today,
            'scope' => $this->scope,
            'sections' => array_map(static fn (BundleSection $s): array => $s->toArray(), $this->sections),
            'items' => array_map(static fn (EvidenceItem $i): array => $i->toArray(), $this->items),
        ];
    }

    /** sha256 of the bundle as stored, so a stored briefing can be shown not to have changed. */
    public function digest(): string
    {
        return self::digestOf($this->toArray());
    }

    public static function digestOf(array $bundle): string
    {
        return hash('sha256', (string) json_encode(self::sorted($bundle), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** jsonb does not keep key order, so the digest is taken over a key-sorted copy. */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $v): mixed => is_array($v) ? self::sorted($v) : $v, $value);
    }
}
