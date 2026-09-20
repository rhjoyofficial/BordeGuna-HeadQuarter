<?php

namespace App\Briefing\Sections;

use App\Briefing\BriefingRequest;
use App\Briefing\EvidenceScope;
use App\Briefing\Freshness;
use App\Briefing\SourceFreshness;
use App\Commerce\Support\CommerceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How old each source the bundle rests on is, against its budget (docs/48 M.3).
 *
 * **Order facts, per store.** A fact's `synced_at` moves only when an order is written, so a quiet
 * shop would look stale by that alone. The read model is as current as the later of its newest
 * `synced_at` and the last succeeded `commerce.reconcile_facts` call for the store, which is the
 * moment HQ last compared itself with the shop. A store HQ has no adapter for, and holds no facts
 * of, is `not_connected`: stated, and not a fault.
 *
 * **ERP low stock, per business.** The older of the ERP's own `as_of` and HQ's `polled_at`, because
 * a fresh poll of a stale report is still stale (`facts.snapshot_freshness` reads it the same way).
 * A business with no snapshot is `missing` when an active poll is scheduled for it and
 * `not_connected` when none is: what HQ expects is read from what HQ schedules, never assumed.
 *
 * **The integrity check.** The latest succeeded `commerce.check_fact_integrity` call, group-level.
 */
final class SourceReader
{
    public const RECONCILE_TOOL = 'commerce.reconcile_facts';

    public const INTEGRITY_TOOL = 'commerce.check_fact_integrity';

    public const LOW_STOCK_POLL_TOOL = 'erp.poll_low_stock';

    /** @return list<SourceFreshness> degraded first, then by key */
    public function read(BriefingRequest $request): array
    {
        $sources = [...$this->orderFacts($request), ...$this->lowStock($request)];

        if ($request->isGroupWide()) {
            $sources[] = $this->integrityCheck($request);
        }

        usort($sources, static fn (SourceFreshness $a, SourceFreshness $b): int => [! $a->freshness->state->degrades(), $a->key] <=> [! $b->freshness->state->degrades(), $b->key]);

        return $sources;
    }

    public static function budget(string $source): int
    {
        return max(1, (int) config("hq.briefing.freshness_budget_minutes.{$source}", 1560));
    }

    /** @return list<SourceFreshness> */
    private function orderFacts(BriefingRequest $request): array
    {
        $stores = $request->restrict(
            DB::table('stores as st')->join('businesses as b', 'b.id', '=', 'st.business_id')
                ->where('st.is_active', true)->whereNull('st.deleted_at'),
            'st.business_id',
        )->orderBy('st.slug')->get(['st.id', 'st.slug', 'st.adapter', 'b.slug as business']);

        $out = [];
        foreach ($stores as $store) {
            $held = (array) DB::table('facts_orders')->where('store_id', $store->id)
                ->selectRaw('count(*) as rows_held, max(synced_at) as newest')->first();
            $rows = (int) ($held['rows_held'] ?? 0);
            $scope = new EvidenceScope(business: $store->business, store: $store->slug);

            if ($rows === 0 && ! CommerceRegistry::supports((string) $store->adapter)) {
                $out[] = new SourceFreshness("facts_orders:{$store->slug}", 'facts_orders', $scope, null, Freshness::notConnected(), 0,
                    "HQ has no adapter for this store (adapter '{$store->adapter}'), so it holds no order facts for it.");

                continue;
            }

            $reconciled = DB::table('tool_calls')->where('tool_key', self::RECONCILE_TOOL)->where('status', 'succeeded')
                ->where('input->store_slug', $store->slug)->max('finished_at');

            $asOf = $this->later($this->instant($held['newest'] ?? null), $this->instant($reconciled));

            $out[] = new SourceFreshness("facts_orders:{$store->slug}", 'facts_orders', $scope, $asOf,
                Freshness::measure($asOf, self::budget('facts_orders'), $request->now), $rows,
                $asOf === null ? 'HQ has never written or reconciled an order fact for this store. Treat its figures as unavailable, not as zero.' : null);
        }

        return $out;
    }

    /** @return list<SourceFreshness> */
    private function lowStock(BriefingRequest $request): array
    {
        $businesses = $request->restrict(
            DB::table('businesses as b')->where('b.is_active', true)->whereNull('b.deleted_at')->whereNotNull('b.erp_business_id'),
            'b.id',
        )->orderBy('b.slug')->get(['b.id', 'b.slug']);

        $out = [];
        foreach ($businesses as $business) {
            $scope = new EvidenceScope(business: $business->slug);
            $key = "erp:{$business->slug}/low_stock";
            $snapshot = DB::table('facts_erp_snapshots')->where('business_id', $business->id)->where('kind', 'low_stock')->first(['as_of', 'polled_at']);

            if ($snapshot === null) {
                $scheduled = DB::table('scheduled_jobs')->where('tool_key', self::LOW_STOCK_POLL_TOOL)->where('state', 'active')
                    ->where(fn ($q) => $q->where('business_id', $business->id)->orWhere('payload->business_slug', $business->slug))
                    ->exists();

                $out[] = $scheduled
                    ? new SourceFreshness($key, 'facts_erp_snapshots', $scope, null, Freshness::measure(null, self::budget('erp_low_stock'), $request->now), 0,
                        'A low-stock poll is scheduled for this business and HQ holds no snapshot. Treat its stock signals as unavailable, not as none.')
                    : new SourceFreshness($key, 'facts_erp_snapshots', $scope, null, Freshness::notConnected(), 0,
                        'No low-stock poll is scheduled for this business, so HQ holds no snapshot of it.');

                continue;
            }

            $asOf = $this->earlier($this->instant($snapshot->as_of), $this->instant($snapshot->polled_at));

            $out[] = new SourceFreshness($key, 'facts_erp_snapshots', $scope, $asOf,
                Freshness::measure($asOf, self::budget('erp_low_stock'), $request->now), 1);
        }

        return $out;
    }

    private function integrityCheck(BriefingRequest $request): SourceFreshness
    {
        $finished = DB::table('tool_calls')->where('tool_key', self::INTEGRITY_TOOL)->where('status', 'succeeded')->max('finished_at');
        $asOf = $this->instant($finished);

        return new SourceFreshness('hq:integrity_check', 'tool_calls', EvidenceScope::group(), $asOf,
            Freshness::measure($asOf, self::budget('integrity_check'), $request->now), null,
            $asOf === null ? 'The order-fact integrity check has never succeeded on this HQ.' : null);
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value)->utc();
    }

    private function later(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null ? $b : ($b === null ? $a : ($a->greaterThan($b) ? $a : $b));
    }

    private function earlier(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null ? $b : ($b === null ? $a : ($a->lessThan($b) ? $a : $b));
    }
}
