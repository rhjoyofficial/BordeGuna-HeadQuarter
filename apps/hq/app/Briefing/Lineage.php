<?php

namespace App\Briefing;

/**
 * How a value was obtained, in enough detail to obtain it again (docs/80 point 6).
 *
 * source record -> read-model row -> bundle item -> narrative. This is the middle link: the formula
 * by name, the read-model table, and the rows it was computed from. Up to `MAX_LISTED_ROWS` rows are
 * listed by id with each row's own source reference (for an order fact: the store, the shop's order
 * number and the event that last wrote it). Above that the ids are replaced by their range, their
 * count and a sha256 digest of the ordered id list, together with the filter that selects the same
 * rows again, so the set can be re-read and proven to be the same set. A rollup names the bundle
 * items it was summed from instead of rows; those items carry the rows.
 *
 * ⛔ There is no opaque aggregation. A collector computes a value FROM the rows it lists here, in
 * PHP, never by a second aggregate query, so a number and its lineage cannot disagree.
 */
final readonly class Lineage
{
    public const MAX_LISTED_ROWS = 50;

    /**
     * @param  array<string, mixed>|null  $rows  the row set, in the listed or the digest form
     * @param  list<string>  $inputItems  bundle item ids, for a value computed from other items
     * @param  array<string, mixed>  $filter  what selects the same rows again
     * @param  list<string>  $inputSources  source keys from the bundle's freshness list
     */
    private function __construct(
        public string $formula,
        public ?string $table,
        public ?array $rows,
        public array $inputItems,
        public array $filter,
        public array $inputSources = [],
    ) {}

    /**
     * @param  list<array{id: int, source_ref?: array<string, mixed>}>  $rows  ordered by id
     * @param  array<string, mixed>  $filter
     */
    public static function ofRows(string $formula, string $table, array $rows, array $filter = []): self
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $refs = array_values(array_filter(array_map(
            static fn (array $r): ?array => isset($r['source_ref']) ? ['id' => (int) $r['id']] + $r['source_ref'] : null,
            $rows,
        )));

        if (count($ids) <= self::MAX_LISTED_ROWS) {
            $set = ['mode' => 'listed', 'count' => count($ids), 'ids' => $ids, 'source_refs' => $refs];
        } else {
            $set = [
                'mode' => 'digest',
                'count' => count($ids),
                'id_min' => min($ids),
                'id_max' => max($ids),
                'ids_digest' => self::digest($ids),
                'source_refs_digest' => $refs === [] ? null : self::digest(array_map(
                    static fn (array $r): string => (string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $refs,
                )),
            ];
        }

        return new self($formula, $table, $set, [], $filter);
    }

    /** @param  list<string>  $itemIds */
    public static function ofItems(string $formula, array $itemIds): self
    {
        return new self($formula, null, null, array_values($itemIds), []);
    }

    /** @param  list<string>  $sourceKeys  for a value computed from the bundle's own freshness list */
    public static function ofSources(string $formula, array $sourceKeys): self
    {
        return new self($formula, null, null, [], [], array_values($sourceKeys));
    }

    /** The digest of an ordered list, so a reader can select the rows again and compare. */
    public static function digest(array $values): string
    {
        return hash('sha256', implode(',', $values));
    }

    public function toArray(): array
    {
        return [
            'formula' => $this->formula,
            'table' => $this->table,
            'rows' => $this->rows,
            'input_items' => $this->inputItems,
            'input_sources' => $this->inputSources,
            'filter' => $this->filter,
        ];
    }
}
