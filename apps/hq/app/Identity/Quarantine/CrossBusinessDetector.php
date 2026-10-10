<?php

namespace App\Identity\Quarantine;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds every relationship that crosses a business boundary (docs/117 §2, ruling docs/110 §5 (m)):
 * pre-guard cross-business merges, the rows such a merge re-pointed, and any other row that hangs under
 * a customer of another business (an unscoped identity lookup can put it there without a merge).
 *
 * ⛔ READ-ONLY. Every statement is a SELECT. It writes no flag, no audit row, no event; running it twice
 * returns the same findings in the same order.
 */
final class CrossBusinessDetector
{
    private const KIND_ORDER = ['merge', 'merge_pointer', 'identity', 'order', 'conversation', 'memory', 'consent_event', 'consent_state'];

    /** @return list<Finding> */
    public function detect(): array
    {
        $merges = $this->crossMerges();

        $findings = [
            ...$this->mergeFindings($merges),
            ...$this->pointerFindings(),
            ...$this->movedIdentityFindings($merges),
            ...$this->storeIdentityFindings(),
            ...$this->scopedRowFindings('facts_orders', 'order', 'order_other_business', $merges),
            ...$this->scopedRowFindings('conversations', 'conversation', 'conversation_other_business', $merges),
            ...$this->memoryFindings($merges),
            ...$this->scopedRowFindings('consent_events', 'consent_event', 'consent_other_business', $merges),
            ...$this->scopedRowFindings('consent_state', 'consent_state', 'consent_other_business', $merges),
        ];

        usort($findings, fn (Finding $a, Finding $b) => [array_search($a->kind, self::KIND_ORDER, true), $a->subjectId, $a->reason]
            <=> [array_search($b->kind, self::KIND_ORDER, true), $b->subjectId, $b->reason]);

        return $findings;
    }

    /**
     * Every customer_merges row joining two businesses, with both sides' businesses.
     *
     * @return Collection<int, object>
     */
    public function crossMerges(): Collection
    {
        return DB::table('customer_merges as m')
            ->join('customers as k', 'k.id', '=', 'm.kept_id')
            ->join('customers as a', 'a.id', '=', 'm.absorbed_id')
            ->whereColumn('k.business_id', '!=', 'a.business_id')
            ->orderBy('m.id')
            ->get(['m.id', 'm.kept_id', 'm.absorbed_id', 'm.moved_identity_ids', 'm.undone_at', 'm.created_at',
                'k.business_id as kept_business_id', 'a.business_id as absorbed_business_id']);
    }

    /** @return list<Finding> */
    private function mergeFindings(Collection $merges): array
    {
        return $merges->map(fn ($m) => new Finding(
            'merge', 'customer_merges', (int) $m->id, (int) $m->kept_id, (int) $m->kept_business_id, (int) $m->absorbed_business_id, (int) $m->id,
            'cross_business_merge',
            ['absorbed_id' => (int) $m->absorbed_id, 'merged_at' => $this->iso($m->created_at), 'undone' => $m->undone_at !== null],
        ))->values()->all();
    }

    /** @return list<Finding> */
    private function pointerFindings(): array
    {
        return DB::table('customers as c')
            ->join('customers as t', 't.id', '=', 'c.merged_into_id')
            ->whereColumn('c.business_id', '!=', 't.business_id')
            ->orderBy('c.id')
            ->get(['c.id', 'c.business_id', 'c.merged_into_id', 't.business_id as target_business_id'])
            ->map(function ($c) {
                $mergeId = DB::table('customer_merges')->where('absorbed_id', $c->id)->where('kept_id', $c->merged_into_id)->max('id');

                return new Finding(
                    'merge_pointer', 'customers', (int) $c->id, (int) $c->id, (int) $c->business_id, (int) $c->target_business_id,
                    $mergeId === null ? null : (int) $mergeId, 'cross_business_merged_into', ['merged_into_id' => (int) $c->merged_into_id],
                );
            })->values()->all();
    }

    /**
     * Identities a cross-business merge recorded as moved, still under its kept customer.
     *
     * @return list<Finding>
     */
    private function movedIdentityFindings(Collection $merges): array
    {
        $out = [];
        foreach ($merges as $m) {
            $ids = self::movedIds($m->moved_identity_ids);
            if ($ids === []) {
                continue;
            }
            foreach (DB::table('customer_identities')->whereIn('id', $ids)->where('customer_id', $m->kept_id)->orderBy('id')->get(['id', 'channel']) as $i) {
                $out[] = new Finding('identity', 'customer_identities', (int) $i->id, (int) $m->kept_id, (int) $m->kept_business_id, (int) $m->absorbed_business_id,
                    (int) $m->id, 'identity_moved_by_cross_business_merge', ['channel' => $i->channel]);
            }
        }

        return $out;
    }

    /** @return list<Finding> */
    private function storeIdentityFindings(): array
    {
        return DB::table('customer_identities as i')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->join('stores as s', 's.id', '=', 'i.source_store_id')
            ->whereColumn('s.business_id', '!=', 'c.business_id')
            ->orderBy('i.id')
            ->get(['i.id', 'i.channel', 'i.customer_id', 'c.business_id', 's.business_id as row_business_id'])
            ->map(fn ($r) => new Finding('identity', 'customer_identities', (int) $r->id, (int) $r->customer_id, (int) $r->business_id, (int) $r->row_business_id,
                null, 'identity_store_other_business', ['channel' => $r->channel]))
            ->values()->all();
    }

    /**
     * A table whose rows carry their own business_id and a customer_id.
     *
     * @return list<Finding>
     */
    private function scopedRowFindings(string $table, string $kind, string $reason, Collection $merges): array
    {
        return DB::table("{$table} as r")
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->whereColumn('r.business_id', '!=', 'c.business_id')
            ->orderBy('r.id')
            ->get(['r.id', 'r.customer_id', 'c.business_id', 'r.business_id as row_business_id'])
            ->map(fn ($r) => new Finding($kind, $table, (int) $r->id, (int) $r->customer_id, (int) $r->business_id, (int) $r->row_business_id,
                $this->attribute($merges, (int) $r->customer_id, (int) $r->row_business_id), $reason))
            ->values()->all();
    }

    /** @return list<Finding> */
    private function memoryFindings(Collection $merges): array
    {
        // A memory of another business, by its own business or by its brand's.
        $scoped = DB::table('memories as r')
            ->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->leftJoin('brands as b', 'b.id', '=', 'r.brand_id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNotNull('r.business_id')->whereColumn('r.business_id', '!=', 'c.business_id'))
                ->orWhere(fn ($w) => $w->whereNotNull('b.business_id')->whereColumn('b.business_id', '!=', 'c.business_id')))
            ->orderBy('r.id')
            ->get(['r.id', 'r.customer_id', 'c.business_id', DB::raw('coalesce(r.business_id, b.business_id) as row_business_id')])
            ->map(fn ($r) => new Finding('memory', 'memories', (int) $r->id, (int) $r->customer_id, (int) $r->business_id, (int) $r->row_business_id,
                $this->attribute($merges, (int) $r->customer_id, (int) $r->row_business_id), 'memory_other_business'))
            ->values()->all();

        // A memory with no business and no brand, under the kept customer of a cross-business merge, created at
        // or before that merge: it may have come from the absorbed row (the merge recorded no memory ids).
        $unscoped = [];
        foreach ($merges as $m) {
            $rows = DB::table('memories')->where('customer_id', $m->kept_id)->whereNull('business_id')->whereNull('brand_id')
                ->where('created_at', '<=', $m->created_at)->orderBy('id')->get(['id', 'kind']);
            foreach ($rows as $r) {
                $unscoped[$r->id] ??= new Finding('memory', 'memories', (int) $r->id, (int) $m->kept_id, (int) $m->kept_business_id, null, (int) $m->id,
                    'memory_unscoped_under_cross_business_merge', ['memory_kind' => $r->kind]);
            }
        }

        return [...$scoped, ...array_values($unscoped)];
    }

    /** The cross-business merge that explains a row of business $rowBusinessId under customer $customerId, if any. */
    private function attribute(Collection $merges, int $customerId, int $rowBusinessId): ?int
    {
        $m = $merges->first(fn ($m) => (int) $m->kept_id === $customerId && (int) $m->absorbed_business_id === $rowBusinessId);

        return $m === null ? null : (int) $m->id;
    }

    /** @return list<int> */
    public static function movedIds(mixed $raw): array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return array_values(array_filter(array_map('intval', (array) $decoded)));
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
