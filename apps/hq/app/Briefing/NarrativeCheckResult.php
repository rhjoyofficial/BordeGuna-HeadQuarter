<?php

namespace App\Briefing;

/** What `NarrativeNumberCheck` found: every figure in the narrative, and the ones no item backs. */
final readonly class NarrativeCheckResult
{
    /** @param  list<array{figure: string, type: string, normalised: ?string, matched_item_ids: list<string>, sourced: bool, why: ?string, excerpt: string}>  $figures */
    public function __construct(public array $figures) {}

    public function passed(): bool
    {
        return $this->unsourced() === [];
    }

    /** @return list<array<string, mixed>> */
    public function unsourced(): array
    {
        return array_values(array_filter($this->figures, static fn (array $f): bool => ! $f['sourced']));
    }

    public function toArray(): array
    {
        return [
            'rule' => NarrativeNumberCheck::RULE,
            'passed' => $this->passed(),
            'figures_found' => count($this->figures),
            'unsourced' => $this->unsourced(),
            'figures' => $this->figures,
        ];
    }
}
