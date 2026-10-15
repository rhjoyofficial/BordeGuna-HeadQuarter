<?php

namespace App\Identity\Quarantine;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a Customer 360 read (and the customer list) must not show of a customer because the relationship
 * crosses a business boundary (docs/117 §4, ruling docs/110 §5 (m): suppress questionable relationships
 * until a human-reviewed, audited correction preserves lineage).
 *
 * Structural, not flag-driven: it reads the live rows, so suppression holds before anyone runs the
 * detector command and whatever a review decides. ⛔ READ-ONLY: SELECTs only.
 */
final class CrossBusinessSuppression
{
    /**
     * Follow merges to the surviving record, but never across a business. A link into another business
     * stops at the last row of the requested business, so its page shows its own business only.
     */
    public function subject(Customer $customer): Customer
    {
        $c = $customer;
        $hops = 0;
        while ($c->merged_into_id && $hops++ < 10) {
            $next = Customer::query()->find($c->merged_into_id);
            if ($next === null || (int) $next->business_id !== (int) $c->business_id) {
                break;
            }
            $c = $next;
        }

        return $c;
    }

    public function forCustomer(Customer $customer): SuppressionView
    {
        $businessId = (int) $customer->business_id;

        // Merges where this customer is the kept side and the absorbed row is of another business.
        $kept = DB::table('customer_merges as m')
            ->join('customers as a', 'a.id', '=', 'm.absorbed_id')
            ->where('m.kept_id', $customer->id)
            ->where('a.business_id', '!=', $businessId)
            ->orderBy('m.id')
            ->get(['m.id', 'm.created_at', 'm.moved_identity_ids', 'a.primary_phone_canonical as absorbed_phone', 'a.email as absorbed_email']);

        // Merges where this customer is the absorbed side and the kept row is of another business.
        $absorbed = DB::table('customer_merges as m')
            ->join('customers as k', 'k.id', '=', 'm.kept_id')
            ->where('m.absorbed_id', $customer->id)
            ->where('k.business_id', '!=', $businessId)
            ->orderBy('m.id')
            ->get(['m.id', 'm.created_at']);

        $pointerCrosses = $customer->merged_into_id !== null
            && Customer::query()->whereKey($customer->merged_into_id)->where('business_id', '!=', $businessId)->exists();

        $moved = $kept->flatMap(fn ($m) => CrossBusinessDetector::movedIds($m->moved_identity_ids))->unique()->values()->all();
        $foreignStoreIdentities = DB::table('customer_identities as i')
            ->join('stores as s', 's.id', '=', 'i.source_store_id')
            ->where('i.customer_id', $customer->id)
            ->where('s.business_id', '!=', $businessId)
            ->pluck('i.id')->all();
        $heldIdentityIds = array_values(array_unique(array_map('intval', [
            ...DB::table('customer_identities')->where('customer_id', $customer->id)->whereIn('id', $moved === [] ? [0] : $moved)->pluck('id')->all(),
            ...$foreignStoreIdentities,
        ])));
        sort($heldIdentityIds);

        $foreignOrders = DB::table('facts_orders')->where('customer_id', $customer->id)->where('business_id', '!=', $businessId)->exists();

        // docs/126 (item 39): a participant or message row of another business's thread under this customer.
        $foreignThreadRows = DB::table('conversation_participants as p')->join('conversations as v', 'v.id', '=', 'p.conversation_id')
            ->where('p.customer_id', $customer->id)->where('v.business_id', '!=', $businessId)->exists()
            || DB::table('conversation_messages as m')->join('conversations as v', 'v.id', '=', 'm.conversation_id')
                ->where('m.customer_id', $customer->id)->where('v.business_id', '!=', $businessId)->exists();

        // docs/126 (ruling A3 point 8): business-less memories a repair left under this customer are withheld until
        // a person assigns them to a business (the row then carries one and leaves this list).
        $heldMemoryIds = self::heldMemoryIds((int) $customer->id);

        $phone = $customer->primary_phone_canonical;
        $email = $customer->email;
        $withholdPhone = $phone !== null && DB::table('customers')->where('id', $customer->id)->whereRaw(self::phoneWithheldSql('customers'))->exists();
        $withholdEmail = $email !== null && DB::table('customers')->where('id', $customer->id)->whereRaw(self::emailWithheldSql('customers'))->exists();

        return new SuppressionView(
            businessId: $businessId,
            keptMerges: $kept->map(fn ($m) => ['id' => (int) $m->id, 'at' => $m->created_at])->values()->all(),
            absorbedMerges: $absorbed->map(fn ($m) => ['id' => (int) $m->id, 'at' => $m->created_at])->values()->all(),
            pointerCrosses: $pointerCrosses,
            heldIdentityIds: $heldIdentityIds,
            unscopedMemoryCutoff: $kept->max('created_at'),
            withholdPhone: $withholdPhone,
            withholdEmail: $withholdEmail,
            recomputeAggregates: $kept->isNotEmpty() || $foreignOrders,
            heldThreadRows: $foreignThreadRows,
            heldMemoryIds: $heldMemoryIds,
        );
    }

    /**
     * Business-less memories a repair (docs/126) withheld under this customer and nobody has assigned yet: the ids a
     * reversal lists as held, still with no business. SELECT only.
     *
     * @return list<int>
     */
    public static function heldMemoryIds(int $customerId): array
    {
        $listed = DB::table('customer_merge_reversals')->where('from_customer_id', $customerId)
            ->pluck('withheld')
            ->flatMap(fn ($raw) => (array) (json_decode((string) $raw, true)['memories_unscoped'] ?? []))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($listed === []) {
            return [];
        }

        return DB::table('memories')->whereIn('id', $listed)->where('customer_id', $customerId)->whereNull('business_id')
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Among `$customerIds`, those whose stored phone must be withheld (the docs/117 and docs/122 rule the 360 page,
     * the list and the search apply). SELECT only. Used by every other screen that shows a customer's phone
     * (docs/122 Q3: the follow-ups screens), so no screen shows a phone the 360 page withholds.
     *
     * @param  list<int>  $customerIds
     * @return list<int>
     */
    public static function withheldPhoneCustomerIds(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return DB::table('customers')->whereIn('id', $customerIds)->whereRaw(self::phoneWithheldSql('customers'))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * SQL condition on a `customers` row (aliased `$c`): its stored phone may have come from another business,
     * so it is withheld, and never matched by a search (ruling docs/120 item 38). Either a cross-business merge
     * absorbed a row with that phone (docs/117), or only another business's order copies under this customer
     * carry it and none of its own business's does (the pre-fix resolver filled an empty phone from them, docs/122).
     */
    public static function phoneWithheldSql(string $c): string
    {
        return "({$c}.primary_phone_canonical IS NOT NULL AND ("
            ."EXISTS (SELECT 1 FROM customer_merges wm JOIN customers wa ON wa.id = wm.absorbed_id WHERE wm.kept_id = {$c}.id AND wa.business_id <> {$c}.business_id AND wa.primary_phone_canonical = {$c}.primary_phone_canonical)"
            ." OR (EXISTS (SELECT 1 FROM facts_orders wf WHERE wf.customer_id = {$c}.id AND wf.business_id <> {$c}.business_id AND wf.customer_phone_canonical = {$c}.primary_phone_canonical)"
            ." AND NOT EXISTS (SELECT 1 FROM facts_orders wo WHERE wo.customer_id = {$c}.id AND wo.business_id = {$c}.business_id AND wo.customer_phone_canonical = {$c}.primary_phone_canonical))))";
    }

    /** SQL condition on a `customers` row (aliased `$c`): its stored e-mail may have come from another business. */
    public static function emailWithheldSql(string $c): string
    {
        return "({$c}.email IS NOT NULL AND EXISTS (SELECT 1 FROM customer_merges wm JOIN customers wa ON wa.id = wm.absorbed_id"
            ." WHERE wm.kept_id = {$c}.id AND wa.business_id <> {$c}.business_id AND lower(wa.email) = lower({$c}.email)))";
    }

    /**
     * Restrict a memories query to rows this customer's business may show: no other business, no other
     * business's brand, and (on the kept side of a cross-business merge) no business-less memory created at
     * or before that merge, since it may have come from the absorbed row.
     */
    public function restrictMemories(Builder $query, SuppressionView $view): Builder
    {
        $query->where(fn ($q) => $q->where('business_id', $view->businessId)->orWhereNull('business_id'))
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhereIn('brand_id', DB::table('brands')->where('business_id', $view->businessId)->select('id')));

        if ($view->unscopedMemoryCutoff !== null) {
            $query->where(fn ($q) => $q->whereNotNull('business_id')->orWhereNotNull('brand_id')->orWhere('created_at', '>', $view->unscopedMemoryCutoff));
        }
        if ($view->heldMemoryIds !== []) {
            $query->whereNotIn('id', $view->heldMemoryIds);
        }

        return $query;
    }

    /** This customer's order count and lifetime value from its own business's order copies only. @return array{0: int, 1: string} */
    public function ownAggregates(Customer $customer): array
    {
        $own = DB::table('facts_orders')->where('customer_id', $customer->id)->where('business_id', $customer->business_id);

        return [
            (clone $own)->count(),
            number_format((float) (clone $own)->where('status', '!=', 'cancelled')->sum('gross'), 2, '.', ''),
        ];
    }

    /**
     * One row of the customer list with the same suppression as the 360 page: phone and email withheld when
     * a cross-business merge may have copied them, figures from this business's copies, and a held flag.
     *
     * @param  list<int>  $touched  ids from `touched()` for the page being rendered
     * @return array<string, mixed>
     */
    public function listRow(Customer $customer, array $touched): array
    {
        $row = $customer->toArray();
        $row['quarantine_held'] = false;

        if (! in_array((int) $customer->id, $touched, true)) {
            return $row;
        }

        $view = $this->forCustomer($customer);
        if ($view->withholdPhone) {
            $row['primary_phone_canonical'] = null;
        }
        if ($view->withholdEmail) {
            $row['email'] = null;
        }
        if ($view->recomputeAggregates) {
            [$row['orders_count'], $row['lifetime_value']] = $this->ownAggregates($customer);
        }
        // The list never says where a held merge led.
        $row['merged_into_id'] = null;
        $row['quarantine_held'] = $view->held();

        return $row;
    }

    /**
     * The ids among $customerIds that touch a cross-business relationship at all (so a list page computes
     * the full view only for those).
     *
     * @param  list<int>  $customerIds
     * @return list<int>
     */
    public function touched(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $viaMerge = DB::table('customer_merges as m')
            ->join('customers as k', 'k.id', '=', 'm.kept_id')
            ->join('customers as a', 'a.id', '=', 'm.absorbed_id')
            ->whereColumn('k.business_id', '!=', 'a.business_id')
            ->where(fn ($q) => $q->whereIn('m.kept_id', $customerIds)->orWhereIn('m.absorbed_id', $customerIds))
            ->get(['m.kept_id', 'm.absorbed_id'])
            ->flatMap(fn ($m) => [(int) $m->kept_id, (int) $m->absorbed_id]);

        $viaRows = DB::table('facts_orders as o')->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.customer_id', $customerIds)->whereColumn('o.business_id', '!=', 'c.business_id')->pluck('o.customer_id');

        $viaPointer = DB::table('customers as c')->join('customers as t', 't.id', '=', 'c.merged_into_id')
            ->whereIn('c.id', $customerIds)->whereColumn('c.business_id', '!=', 't.business_id')->pluck('c.id');

        // docs/126: thread rows of another business, and business-less memories a repair left for a person.
        $viaThreads = DB::table('conversation_participants as p')->join('conversations as v', 'v.id', '=', 'p.conversation_id')->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->whereIn('p.customer_id', $customerIds)->whereColumn('v.business_id', '!=', 'c.business_id')->pluck('p.customer_id')
            ->merge(DB::table('conversation_messages as m')->join('conversations as v', 'v.id', '=', 'm.conversation_id')->join('customers as c', 'c.id', '=', 'm.customer_id')
                ->whereIn('m.customer_id', $customerIds)->whereColumn('v.business_id', '!=', 'c.business_id')->pluck('m.customer_id'));
        $viaRepair = DB::table('customer_merge_reversals')->whereIn('from_customer_id', $customerIds)->pluck('from_customer_id')
            ->filter(fn ($id) => self::heldMemoryIds((int) $id) !== []);

        return collect([...$viaMerge, ...$viaRows, ...$viaPointer, ...$viaThreads, ...$viaRepair])->map(fn ($id) => (int) $id)
            ->intersect($customerIds)->unique()->sort()->values()->all();
    }
}
