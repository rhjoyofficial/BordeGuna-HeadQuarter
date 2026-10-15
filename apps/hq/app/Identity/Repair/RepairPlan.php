<?php

namespace App\Identity\Repair;

/**
 * What the repair would do, as the planner computed it from the live rows (docs/126, ruling docs/120 A3 points
 * 1 and 2). One batch per TARGET business (the business whose rows hang under another business's customer),
 * keyed by its content: the same data yields the same key, changed data yields another, and data that no longer
 * crosses yields no batch at all. A value; the planner persists nothing.
 *
 * @phpstan-type Split array{
 *   key: string, from_customer_id: int, from_business_id: int, to_business_id: int, to_customer_id: ?int, to_customer_created: bool,
 *   merge_id: ?int, clear_merge_pointer: bool, new_customer: ?array{phone: ?string, name: ?string},
 *   rows: array{orders: list<int>, conversations: list<int>, participants: list<int>, messages: list<int>, memories: list<int>,
 *     identities_move: list<int>, identities_superseded: list<int>, identities_copy: list<array{channel: string, external_id: string}>, conflicts_resolve: list<int>},
 *   withheld: array{memories_unscoped: list<int>, consent_rerecord: list<array<string, mixed>>},
 *   subjects: list<string>, review: array{needs_repair: int, disputed: int, confirmed_wrong: int, unflagged: int, authorized: bool},
 *   fingerprint: string
 * }
 */
final readonly class RepairPlan
{
    /**
     * @param  array<int, array{key: string, business_id: int, business_slug: string, splits: list<array<string, mixed>>}>  $batches  by target business id
     * @param  list<array<string, mixed>>  $ambiguous  rows whose business cannot be established without guessing
     * @param  list<array<string, mixed>>  $heldIdentities  quarantined identities the repair leaves held, with the reason
     * @param  list<array<string, mixed>>  $openConflicts  open conflicts the repair does not resolve
     * @param  array{consent_rerecord: list<array<string, mixed>>, memories_unscoped: list<array<string, mixed>>}  $humanLists  what only a person may do, across every split and every group with nothing to move
     */
    public function __construct(
        public string $generatedAt,
        public array $batches,
        public array $ambiguous,
        public array $heldIdentities,
        public array $openConflicts,
        public array $humanLists = ['consent_rerecord' => [], 'memories_unscoped' => []],
    ) {}

    public function isEmpty(): bool
    {
        return $this->batches === [];
    }

    /** @return array<string, mixed>|null */
    public function batchByKey(string $key): ?array
    {
        foreach ($this->batches as $batch) {
            if ($batch['key'] === $key) {
                return $batch;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function batchForBusiness(int $businessId): ?array
    {
        return $this->batches[$businessId] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function split(string $splitKey): ?array
    {
        foreach ($this->batches as $batch) {
            foreach ($batch['splits'] as $split) {
                if ($split['key'] === $splitKey) {
                    return $split;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'batches' => array_values($this->batches),
            'ambiguous' => $this->ambiguous,
            'held_identities' => $this->heldIdentities,
            'open_conflicts' => $this->openConflicts,
            'human_lists' => $this->humanLists,
        ];
    }

    /** @param  array<string, mixed>  $split */
    public static function splitRowCount(array $split): int
    {
        $rows = $split['rows'];

        return count($rows['orders']) + count($rows['conversations']) + count($rows['participants']) + count($rows['messages'])
            + count($rows['memories']) + count($rows['identities_move']);
    }
}
