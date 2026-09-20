<?php

namespace App\Briefing;

/**
 * Completeness, provenance and freshness, checked deterministically before anything may be narrated
 * (docs/80 point 1: "a missing, stale or incomplete evidence bundle should produce an explicit
 * degraded state rather than silently filling gaps").
 *
 * **Completeness.** Every section of the contract is present; a section with items lists items that
 * exist; a section without items says why; every item is listed by its section; no id repeats; a
 * section the caller could not read is a named gap.
 *
 * **Provenance.** Only the collector's kinds appear. Every item names its source and carries an
 * `as_of`. Every metric carries lineage that resolves: listed ids agree with their count, a digest
 * comes with the filter that selects the rows again, and input items and sources exist in the
 * bundle. And the lineage is checked against the value where it can be recomputed from the bundle
 * alone: a row count equals the number of rows listed, and a sum of items equals the sum of those
 * items. A number that disagrees with its own lineage is the fault this whole design exists to catch.
 *
 * **Freshness.** Every stale or missing source is a reason, and so is an item that is stale on its
 * own account. An `as_of` in the future is named as that.
 *
 * There is no pass with remarks. Any reason makes the bundle `degraded`.
 */
final class BundleValidator
{
    public function validate(?EvidenceBundle $bundle): ValidationReport
    {
        if ($bundle === null) {
            return ValidationReport::missing('No evidence bundle was collected.');
        }

        return new ValidationReport([
            ...$this->completeness($bundle),
            ...$this->provenance($bundle),
            ...$this->freshness($bundle),
        ]);
    }

    private function completeness(EvidenceBundle $bundle): array
    {
        $reasons = [];
        $ids = array_map(static fn (EvidenceItem $i): string => $i->id, $bundle->items);

        foreach (array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1)) as $duplicate) {
            $reasons[] = $this->reason('item_duplicate_id', 'completeness', (string) $duplicate, 'Two items share this id, so a figure could not be traced to one of them.', true);
        }

        foreach (array_keys(ExecutiveBriefingContract::SECTIONS) as $key) {
            if ($bundle->section($key) === null) {
                $reasons[] = $this->reason('section_missing', 'completeness', $key, "The bundle has no [{$key}] section. A missing section is a silent gap.", true);
            }
        }

        $listed = [];
        foreach ($bundle->sections as $section) {
            if (! in_array($section->status, BundleSection::STATUSES, true)) {
                $reasons[] = $this->reason('section_status_unknown', 'completeness', $section->key, "[{$section->status}] is not a section status.", true);
            }
            if ($section->status === BundleSection::OK && $section->itemIds === []) {
                $reasons[] = $this->reason('section_silent', 'completeness', $section->key, 'The section claims items and lists none.', true);
            }
            if ($section->status !== BundleSection::OK && trim((string) $section->reason) === '') {
                $reasons[] = $this->reason('section_silent', 'completeness', $section->key, 'The section has no items and does not say why.', true);
            }
            if ($section->status === BundleSection::WITHHELD) {
                $reasons[] = $this->reason('section_withheld', 'completeness', $section->key, (string) $section->reason, false);
            }
            foreach ($section->itemIds as $id) {
                $listed[$id] = true;
                if (! in_array($id, $ids, true)) {
                    $reasons[] = $this->reason('section_item_unresolved', 'completeness', $id, "The [{$section->key}] section lists an item the bundle does not carry.", true);
                }
            }
            foreach ($section->notes as $note) {
                if (in_array($note['status'] ?? null, ['withheld', 'not_read'], true)) {
                    $reasons[] = $this->reason('section_part_withheld', 'completeness', $section->key, (string) ($note['reason'] ?? ''), false);
                }
            }
        }

        foreach ($bundle->items as $item) {
            if (! isset($listed[$item->id])) {
                $reasons[] = $this->reason('item_orphaned', 'completeness', $item->id, 'No section lists this item.', true);
            }
        }

        return $reasons;
    }

    private function provenance(EvidenceBundle $bundle): array
    {
        $reasons = [];
        $sourceKeys = array_map(static fn (SourceFreshness $s): string => $s->key, $bundle->sources);

        foreach ($bundle->items as $item) {
            if (! $item->kind->isEvidence()) {
                $reasons[] = $this->reason('narrator_kind_in_bundle', 'provenance', $item->id, "A [{$item->kind->value}] is the narrator's kind and can never be evidence.", true);
            }
            foreach (['system', 'read_model', 'ref'] as $field) {
                if (trim((string) ($item->source[$field] ?? '')) === '') {
                    $reasons[] = $this->reason('item_without_source', 'provenance', $item->id, "The item's source does not name its {$field}.", true);
                }
            }
            if (! in_array($item->unit, EvidenceItem::UNITS, true)) {
                $reasons[] = $this->reason('item_unit_unknown', 'provenance', $item->id, "[{$item->unit}] is not a unit the bundle defines.", true);
            }
            if ($item->asOf === null && $item->freshness->state !== FreshnessState::Missing) {
                $reasons[] = $this->reason('item_without_as_of', 'provenance', $item->id, 'The item does not say when its value was true.', true);
            }
            if ($item->kind === EvidenceKind::Metric) {
                array_push($reasons, ...$this->lineage($bundle, $item, $sourceKeys));
            }
        }

        return $reasons;
    }

    /** @param  list<string>  $sourceKeys */
    private function lineage(EvidenceBundle $bundle, EvidenceItem $item, array $sourceKeys): array
    {
        $lineage = $item->lineage;

        // A count of degraded sources is rightly computed from an empty list when none is degraded.
        $namesNothing = $lineage !== null && $lineage->rows === null && $lineage->inputItems === [] && $lineage->inputSources === []
            && $lineage->formula !== 'count_degraded_sources';

        if ($lineage === null || trim($lineage->formula) === '' || $namesNothing) {
            return [$this->reason('metric_without_lineage', 'provenance', $item->id, 'A metric must name its formula and what it was computed from.', true)];
        }

        $reasons = [];

        if ($lineage->rows !== null) {
            $rows = $lineage->rows;
            if (trim((string) $lineage->table) === '') {
                $reasons[] = $this->reason('lineage_without_table', 'provenance', $item->id, 'The lineage lists rows and does not name their table.', true);
            }
            if (($rows['mode'] ?? null) === 'listed' && count((array) ($rows['ids'] ?? [])) !== (int) ($rows['count'] ?? -1)) {
                $reasons[] = $this->reason('lineage_disagrees', 'provenance', $item->id, 'The listed ids do not agree with their count.', true);
            }
            if (($rows['mode'] ?? null) === 'digest' && (trim((string) ($rows['ids_digest'] ?? '')) === '' || $lineage->filter === [])) {
                $reasons[] = $this->reason('lineage_not_reconstructible', 'provenance', $item->id, 'A digested row set needs its digest and the filter that selects the rows again.', true);
            }
            if ($lineage->formula === 'count_rows' && (int) ($rows['count'] ?? -1) !== $item->value) {
                $reasons[] = $this->reason('lineage_disagrees', 'provenance', $item->id, 'The value is a row count and does not equal the number of rows in its lineage.', true);
            }
        }

        $inputs = [];
        foreach ($lineage->inputItems as $id) {
            $input = $bundle->item($id);
            if ($input === null) {
                $reasons[] = $this->reason('lineage_unresolved_item', 'provenance', $item->id, "The lineage names the item [{$id}], which the bundle does not carry.", true);

                continue;
            }
            $inputs[] = $input;
        }

        foreach ($lineage->inputSources as $key) {
            if (! in_array($key, $sourceKeys, true)) {
                $reasons[] = $this->reason('lineage_unresolved_source', 'provenance', $item->id, "The lineage names the source [{$key}], which the bundle does not list.", true);
            }
        }

        if ($lineage->formula === 'count_degraded_sources' && count($lineage->inputSources) !== $item->value) {
            $reasons[] = $this->reason('lineage_disagrees', 'provenance', $item->id, 'The value does not equal the number of sources in its lineage.', true);
        }

        if ($lineage->formula === 'sum_of_items' && count($inputs) === count($lineage->inputItems)) {
            $expected = $item->unit === 'BDT'
                ? Money::fromPaisa(array_sum(array_map(static fn (EvidenceItem $i): int => Money::toPaisa((string) $i->value), $inputs)))
                : array_sum(array_map(static fn (EvidenceItem $i): int => (int) $i->value, $inputs));
            if ($expected !== $item->value) {
                $reasons[] = $this->reason('lineage_disagrees', 'provenance', $item->id, 'The value is a sum of items and does not equal the sum of those items.', true);
            }
        }

        return $reasons;
    }

    private function freshness(EvidenceBundle $bundle): array
    {
        $reasons = [];
        $reported = [];

        if ($bundle->sources === []) {
            $reasons[] = $this->reason('no_sources', 'freshness', null, 'The bundle lists no source, so nothing says how current it is.', false);
        }

        foreach ($bundle->sources as $source) {
            if (! $source->freshness->state->degrades()) {
                continue;
            }
            $reported[$source->key] = true;
            $reasons[] = match (true) {
                $source->freshness->inFuture => $this->reason('source_as_of_in_future', 'freshness', $source->key, 'The source says it is current as of a moment that has not happened yet.', false),
                $source->freshness->state === FreshnessState::Missing => $this->reason('source_missing', 'freshness', $source->key, $source->note ?? 'HQ has never recorded this source.', false),
                default => $this->reason('source_stale', 'freshness', $source->key, "The source is {$source->freshness->ageMinutes} minutes old against a budget of {$source->freshness->budgetMinutes}.", false),
            };
        }

        foreach ($bundle->items as $item) {
            // A rollup is as stale as its inputs, and a source's items are as stale as the source:
            // both are already named once, above or on the input.
            $isRollup = $item->lineage !== null && $item->lineage->inputItems !== [];
            if ($item->freshness->state->degrades() && ! $isRollup && ! isset($reported[$item->source['ref'] ?? ''])) {
                $reasons[] = $this->reason('item_stale', 'freshness', $item->id, 'The item is older than its budget.', false);
            }
        }

        return $reasons;
    }

    private function reason(string $code, string $check, ?string $subject, string $message, bool $blocks): array
    {
        return ['code' => $code, 'check' => $check, 'subject' => $subject, 'message' => $message, 'blocks_narration' => $blocks];
    }
}
