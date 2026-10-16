<?php

namespace App\Identity\Repair;

use App\Models\Business;
use App\Models\Memory;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A person assigns a business to a business-less memory the identity repair withheld (docs/126, ruling docs/120 A3
 * point 8 and item 36: "withhold business-less memories pending human assignment"). The ONLY writer of that
 * assignment; reached from the quarantine screen behind `hq.customers.quarantine.repair`.
 *
 * What it may do, and nothing else: set `memories.business_id` on ONE memory that is held (listed by a repair
 * reversal or by the live repair plan), still has no business and no brand, to one of the businesses the evidence
 * names for it (the customer's own, or the business a repair split from that customer went to), which the person
 * may read. The memory's text, customer and history are untouched; the assignment is audited with the note.
 * Assigned to the customer's own business, the memory shows on that customer's page again; assigned to the other
 * business, it is a row of another business under this customer, which the detector reports and the next repair
 * batch carries to that business's customer.
 */
final class HeldMemoryAssigner
{
    public function __construct(private readonly RepairPlanner $planner, private readonly Auditor $auditor) {}

    /**
     * Every held memory with the businesses it may be assigned to. SELECT only.
     *
     * @param  list<int>|null  $permittedBusinessIds  null = every business
     * @return list<array{memory_id: int, customer_id: int, customer_business_id: int, kind: string, created_at: ?string, excerpt: ?string, choices: list<int>}>
     */
    public function held(?array $permittedBusinessIds = null, ?RepairPlan $plan = null): array
    {
        $plan ??= $this->planner->plan();
        $candidates = [];
        foreach ($plan->humanLists['memories_unscoped'] as $m) {
            $candidates[(int) $m['memory_id']] = (int) $m['customer_id'];
        }
        foreach (DB::table('customer_merge_reversals')->orderBy('id')->get(['from_customer_id', 'withheld']) as $r) {
            foreach ((array) (json_decode((string) $r->withheld, true)['memories_unscoped'] ?? []) as $id) {
                $candidates[(int) $id] ??= (int) $r->from_customer_id;
            }
        }
        ksort($candidates);

        $out = [];
        foreach ($candidates as $memoryId => $customerId) {
            $row = DB::table('memories as m')->join('customers as c', 'c.id', '=', 'm.customer_id')
                ->where('m.id', $memoryId)->where('m.customer_id', $customerId)->whereNull('m.business_id')->whereNull('m.brand_id')->whereNull('m.deleted_at')
                ->first(['m.id', 'm.kind', 'm.key', 'm.content', 'm.created_at', 'c.business_id']);
            if ($row === null) {
                continue; // assigned already, or no longer under that customer
            }
            $choices = $this->choices($customerId, (int) $row->business_id, $plan);
            if ($permittedBusinessIds !== null && array_diff($choices, $permittedBusinessIds) !== []) {
                continue; // shown only to a person who may read every business it could belong to
            }
            $out[] = [
                'memory_id' => (int) $row->id,
                'customer_id' => $customerId,
                'customer_business_id' => (int) $row->business_id,
                'kind' => (string) $row->kind,
                'created_at' => $row->created_at === null ? null : (string) $row->created_at,
                'excerpt' => $row->content !== null ? mb_substr((string) $row->content, 0, 200) : ($row->key !== null ? (string) $row->key : null),
                'choices' => $choices,
            ];
        }

        return $out;
    }

    /** @param  list<int>|null  $permittedBusinessIds */
    public function assign(int $memoryId, int $businessId, User $actor, string $note, ?array $permittedBusinessIds = null): void
    {
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw new InvalidArgumentException('Say why this memory belongs to that business.');
        }
        $held = collect($this->held($permittedBusinessIds))->firstWhere('memory_id', $memoryId);
        if ($held === null) {
            throw new InvalidArgumentException('That memory is not held for assignment (it has a business already, or you may not read every business it could belong to).');
        }
        if (! in_array($businessId, $held['choices'], true)) {
            throw new InvalidArgumentException('The evidence names only the businesses offered for this memory.');
        }

        try {
            DB::transaction(function () use ($memoryId, $businessId, $held, $actor, $note) {
                $changed = DB::table('memories')->where('id', $memoryId)->where('customer_id', $held['customer_id'])
                    ->whereNull('business_id')->whereNull('brand_id')->update(['business_id' => $businessId, 'updated_at' => now()]);
                if ($changed !== 1) {
                    throw new InvalidArgumentException('That memory changed while you were deciding; look again.');
                }
                $this->auditor->record('memory.business_assigned', Memory::query()->findOrFail($memoryId),
                    before: ['business_id' => null, 'customer_id' => $held['customer_id']],
                    after: ['business_id' => $businessId, 'business' => Business::query()->whereKey($businessId)->value('slug')],
                    reason: $note, actorUserId: $actor->id);
            });
        } catch (QueryException $e) {
            throw new InvalidArgumentException('That business already holds a current memory with the same key for this customer; nothing was changed.');
        }
    }

    /**
     * The businesses the evidence names for a held memory of this customer: its own, and every business a repair
     * split from it went to or is proposed to go to.
     *
     * @return list<int>
     */
    private function choices(int $customerId, int $customerBusinessId, RepairPlan $plan): array
    {
        $ids = [$customerBusinessId];
        foreach (DB::table('customer_merge_reversals')->where('from_customer_id', $customerId)->pluck('to_business_id') as $id) {
            $ids[] = (int) $id;
        }
        foreach ($plan->batches as $batch) {
            foreach ($batch['splits'] as $s) {
                if ((int) $s['from_customer_id'] === $customerId) {
                    $ids[] = (int) $s['to_business_id'];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
