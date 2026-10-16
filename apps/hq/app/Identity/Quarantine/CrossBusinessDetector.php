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
 * docs/126 (ruling docs/120 item 39 and A3): also conversation participants and messages whose thread is of
 * another business than their customer, every identity the docs/122 migration quarantined (with its reason),
 * and every open `customer_identity_conflicts` row (a refused re-point or a held row nobody has resolved).
 *
 * ⛔ READ-ONLY. Every statement is a SELECT. It writes no flag, no audit row, no event; running it twice
 * returns the same findings in the same order.
 */
final class CrossBusinessDetector
{
    private const KIND_ORDER = ['merge', 'merge_pointer', 'identity', 'identity_conflict', 'order', 'conversation', 'participant', 'message', 'memory', 'consent_event', 'consent_state'];

    /** @return list<Finding> */
    public function detect(): array
    {
        $merges = $this->crossMerges();

        $findings = [
            ...$this->mergeFindings($merges),
            ...$this->pointerFindings(),
            ...$this->movedIdentityFindings($merges),
            ...$this->storeIdentityFindings(),
            ...$this->quarantinedIdentityFindings($merges),
            ...$this->conflictFindings(),
            ...$this->scopedRowFindings('facts_orders', 'order', 'order_other_business', $merges),
            ...$this->scopedRowFindings('conversations', 'conversation', 'conversation_other_business', $merges),
            ...$this->participantFindings($merges),
            ...$this->messageFindings($merges),
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
        // A merge the repair reversed (docs/126) is lineage, not a live relationship: still listed, marked, never re-proposed.
        $reversed = DB::table('customer_merge_reversals')->whereIn('merge_id', $merges->pluck('id')->all() ?: [0])->pluck('id', 'merge_id');

        return $merges->map(fn ($m) => new Finding(
            'merge', 'customer_merges', (int) $m->id, (int) $m->kept_id, (int) $m->kept_business_id, (int) $m->absorbed_business_id, (int) $m->id,
            'cross_business_merge',
            ['absorbed_id' => (int) $m->absorbed_id, 'merged_at' => $this->iso($m->created_at), 'undone' => $m->undone_at !== null,
                'reversed_by' => $reversed->has($m->id) ? (int) $reversed->get($m->id) : null],
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
     * Every identity the docs/122 migration (or a later hold) quarantined, with the reason it was held. A
     * store-scoped row names its store's business as the row's business; a store-less one names none (it was
     * held precisely because its business could not be established without guessing).
     *
     * @return list<Finding>
     */
    private function quarantinedIdentityFindings(Collection $merges): array
    {
        return DB::table('customer_identities as i')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->leftJoin('stores as s', 's.id', '=', 'i.source_store_id')
            ->whereNotNull('i.quarantined_at')
            ->orderBy('i.id')
            ->get(['i.id', 'i.channel', 'i.customer_id', 'i.quarantine_reason', 'i.quarantined_at', 'i.source_store_id', 'c.business_id', 's.business_id as store_business_id'])
            ->map(function ($r) use ($merges) {
                $rowBusiness = $r->store_business_id !== null && (int) $r->store_business_id !== (int) $r->business_id ? (int) $r->store_business_id : null;
                $merge = $merges->first(fn ($m) => (int) $m->kept_id === (int) $r->customer_id && in_array((int) $r->id, self::movedIds($m->moved_identity_ids), true));

                return new Finding('identity', 'customer_identities', (int) $r->id, (int) $r->customer_id, (int) $r->business_id, $rowBusiness,
                    $merge === null ? null : (int) $merge->id, 'identity_quarantined',
                    ['channel' => $r->channel, 'quarantine_reason' => $r->quarantine_reason, 'quarantined_at' => $this->iso($r->quarantined_at),
                        'source_store_id' => $r->source_store_id === null ? null : (int) $r->source_store_id]);
            })
            ->values()->all();
    }

    /**
     * Every open resolution requirement (docs/122): a re-point a lookup refused, or a quarantined row. The
     * customer is the holder of the identity; the row's business is the conflict's when it differs.
     *
     * @return list<Finding>
     */
    private function conflictFindings(): array
    {
        return DB::table('customer_identity_conflicts as k')
            ->join('customer_identities as i', 'i.id', '=', 'k.identity_id')
            ->join('customers as c', 'c.id', '=', DB::raw('coalesce(k.holder_customer_id, i.customer_id)'))
            ->whereNull('k.resolved_at')
            ->orderBy('k.id')
            ->get(['k.id', 'k.kind', 'k.reason', 'k.business_id as conflict_business_id', 'k.identity_id', 'k.claimant_customer_id', 'k.occurrences', 'k.source',
                'c.id as customer_id', 'c.business_id', 'i.channel'])
            ->map(fn ($r) => new Finding('identity_conflict', 'customer_identity_conflicts', (int) $r->id, (int) $r->customer_id, (int) $r->business_id,
                (int) $r->conflict_business_id !== (int) $r->business_id ? (int) $r->conflict_business_id : null, null, 'identity_conflict_'.$r->kind,
                ['conflict_reason' => $r->reason, 'identity_id' => (int) $r->identity_id, 'channel' => $r->channel,
                    'claimant_customer_id' => $r->claimant_customer_id === null ? null : (int) $r->claimant_customer_id, 'occurrences' => (int) $r->occurrences, 'source' => $r->source]))
            ->values()->all();
    }

    /**
     * A participant row under a customer of another business than its thread (item 39), or carrying an identity
     * of another business than its thread.
     *
     * @return list<Finding>
     */
    private function participantFindings(Collection $merges): array
    {
        $byCustomer = DB::table('conversation_participants as p')
            ->join('conversations as v', 'v.id', '=', 'p.conversation_id')
            ->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->whereColumn('v.business_id', '!=', 'c.business_id')
            ->orderBy('p.id')
            ->get(['p.id', 'p.customer_id', 'p.conversation_id', 'c.business_id', 'v.business_id as row_business_id'])
            ->map(fn ($r) => new Finding('participant', 'conversation_participants', (int) $r->id, (int) $r->customer_id, (int) $r->business_id, (int) $r->row_business_id,
                $this->attribute($merges, (int) $r->customer_id, (int) $r->row_business_id), 'participant_other_business', ['conversation_id' => (int) $r->conversation_id]))
            ->values()->all();

        $byIdentity = DB::table('conversation_participants as p')
            ->join('conversations as v', 'v.id', '=', 'p.conversation_id')
            ->join('customer_identities as i', 'i.id', '=', 'p.identity_id')
            ->join('customers as c', 'c.id', '=', DB::raw('coalesce(p.customer_id, i.customer_id)'))
            ->whereColumn('v.business_id', '!=', 'i.business_id')
            ->orderBy('p.id')
            ->get(['p.id', 'p.conversation_id', 'i.id as identity_id', 'i.business_id as identity_business_id', 'c.id as customer_id', 'c.business_id', 'v.business_id as row_business_id'])
            ->map(fn ($r) => new Finding('participant', 'conversation_participants', (int) $r->id, (int) $r->customer_id, (int) $r->business_id, (int) $r->row_business_id,
                null, 'participant_identity_other_business',
                ['conversation_id' => (int) $r->conversation_id, 'identity_id' => (int) $r->identity_id, 'identity_business_id' => (int) $r->identity_business_id]))
            ->values()->all();

        return [...$byCustomer, ...$byIdentity];
    }

    /**
     * A message row under a customer of another business than its thread (item 39). Bodies are never read.
     *
     * @return list<Finding>
     */
    private function messageFindings(Collection $merges): array
    {
        return DB::table('conversation_messages as m')
            ->join('conversations as v', 'v.id', '=', 'm.conversation_id')
            ->join('customers as c', 'c.id', '=', 'm.customer_id')
            ->whereColumn('v.business_id', '!=', 'c.business_id')
            ->orderBy('m.id')
            ->get(['m.id', 'm.customer_id', 'm.conversation_id', 'm.direction', 'c.business_id', 'v.business_id as row_business_id'])
            ->map(fn ($r) => new Finding('message', 'conversation_messages', (int) $r->id, (int) $r->customer_id, (int) $r->business_id, (int) $r->row_business_id,
                $this->attribute($merges, (int) $r->customer_id, (int) $r->row_business_id), 'message_other_business', ['conversation_id' => (int) $r->conversation_id, 'direction' => $r->direction]))
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
