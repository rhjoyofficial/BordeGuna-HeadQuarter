<?php

namespace App\Identity\Repair;

use App\Identity\Quarantine\CrossBusinessDetector;
use Illuminate\Support\Facades\DB;

/**
 * Business-scoped aggregates after a repair, each verified against the source rows (docs/126, ruling docs/120
 * item 37). SELECT only.
 *
 * Per business: live customers, order copies, conversations, participants and messages, counted by the row's own
 * business (a thread's for its participants and messages), never by the customer it hangs under. Per affected
 * customer: the stored `orders_count` and `lifetime_value` against this business's copies (a mismatch is named).
 * Excluded rows are documented: everything the detector still reports (rows that still cross), identities still
 * quarantined by reason, and open conflicts.
 */
final class RepairRecount
{
    public function __construct(private readonly CrossBusinessDetector $detector) {}

    /**
     * @param  list<int>  $businessIds
     * @param  list<int>  $customerIds
     * @return array<string, mixed>
     */
    public function recount(array $businessIds, array $customerIds = []): array
    {
        $businesses = [];
        foreach ($businessIds as $b) {
            $businesses[] = [
                'business_id' => $b,
                'customers' => DB::table('customers')->where('business_id', $b)->whereNull('merged_into_id')->count(),
                'orders' => DB::table('facts_orders')->where('business_id', $b)->count(),
                'orders_under_own_customer' => DB::table('facts_orders as o')->join('customers as c', 'c.id', '=', 'o.customer_id')->where('o.business_id', $b)->whereColumn('c.business_id', '=', 'o.business_id')->count(),
                'orders_without_customer' => DB::table('facts_orders')->where('business_id', $b)->whereNull('customer_id')->count(),
                'conversations' => DB::table('conversations')->where('business_id', $b)->count(),
                'participants' => DB::table('conversation_participants as p')->join('conversations as v', 'v.id', '=', 'p.conversation_id')->where('v.business_id', $b)->count(),
                'messages' => DB::table('conversation_messages as m')->join('conversations as v', 'v.id', '=', 'm.conversation_id')->where('v.business_id', $b)->count(),
                'live_identities' => DB::table('customer_identities')->where('business_id', $b)->whereNull('quarantined_at')->count(),
                'quarantined_identities' => DB::table('customer_identities')->where('business_id', $b)->whereNotNull('quarantined_at')->count(),
            ];
        }

        $customers = [];
        foreach ($customerIds as $id) {
            $c = DB::table('customers')->where('id', $id)->first(['id', 'business_id', 'orders_count', 'lifetime_value']);
            if ($c === null) {
                continue;
            }
            $own = DB::table('facts_orders')->where('customer_id', $id)->where('business_id', $c->business_id);
            $count = (clone $own)->count();
            $value = number_format((float) (clone $own)->where('status', '!=', 'cancelled')->sum('gross'), 2, '.', '');
            $customers[] = [
                'customer_id' => (int) $c->id,
                'business_id' => (int) $c->business_id,
                'orders_count' => ['stored' => (int) $c->orders_count, 'from_rows' => $count, 'ok' => (int) $c->orders_count === $count],
                'lifetime_value' => ['stored' => number_format((float) $c->lifetime_value, 2, '.', ''), 'from_rows' => $value, 'ok' => number_format((float) $c->lifetime_value, 2, '.', '') === $value],
                'foreign_rows_still_under' => DB::table('facts_orders')->where('customer_id', $id)->where('business_id', '!=', $c->business_id)->count()
                    + DB::table('conversations')->where('customer_id', $id)->where('business_id', '!=', $c->business_id)->count(),
            ];
        }

        $excluded = [];
        foreach ($this->detector->detect() as $f) {
            $excluded[$f->kind][$f->reason] = ($excluded[$f->kind][$f->reason] ?? 0) + 1;
        }
        $quarantined = DB::table('customer_identities')->whereNotNull('quarantined_at')->select('quarantine_reason', DB::raw('count(*) as n'))->groupBy('quarantine_reason')->orderBy('quarantine_reason')->get()
            ->mapWithKeys(fn ($r) => [$r->quarantine_reason => (int) $r->n])->all();

        return [
            'businesses' => $businesses,
            'customers' => $customers,
            'verified' => collect($customers)->every(fn ($c) => $c['orders_count']['ok'] && $c['lifetime_value']['ok']),
            'excluded' => [
                'still_crossing' => $excluded,
                'quarantined_identities_by_reason' => $quarantined,
                'open_conflicts' => DB::table('customer_identity_conflicts')->whereNull('resolved_at')->count(),
            ],
        ];
    }
}
