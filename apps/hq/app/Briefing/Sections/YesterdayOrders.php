<?php

namespace App\Briefing\Sections;

use App\Briefing\BriefingRequest;
use App\Briefing\EvidenceItem;
use App\Briefing\EvidenceKind;
use App\Briefing\EvidenceScope;
use App\Briefing\Freshness;
use App\Briefing\FreshnessState;
use App\Briefing\Lineage;
use App\Briefing\Money;
use App\Briefing\SourceFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What happened yesterday, per store, per business and for the group (docs/48 M.2).
 *
 * "Yesterday" is the calendar day before the briefing date in the group timezone, with its bounds
 * taken in UTC. Five figures per store, each computed in PHP from the rows its lineage lists:
 * orders placed, the gross of the orders placed, and orders confirmed, delivered and cancelled in
 * the day (by their own timestamps, so an order placed last week and delivered yesterday counts as
 * a delivery yesterday).
 *
 * ⚠ **Gross is the gross of every order placed, whatever its status.** Whether a cancelled order
 * belongs in a day's value is a business rule nobody has given HQ, so the figure is named for
 * exactly what it sums and the cancellations are beside it.
 *
 * ⛔ **Unavailable is not zero.** A store whose read model HQ has never written produces no figure
 * at all, and a business or group total is produced only when every connected store under it
 * produced its own: a total over some of the stores would be a smaller number that looks complete.
 */
final class YesterdayOrders
{
    public const KEY = 'yesterday';

    public const TITLE = 'Yesterday';

    private const FIGURES = [
        'placed' => ['Orders placed', 'orders', 'placed_at', 'count_rows'],
        'gross' => ['Gross of orders placed (all statuses)', 'BDT', 'placed_at', 'sum_gross'],
        'confirmed' => ['Orders confirmed', 'orders', 'confirmed_at', 'count_rows'],
        'delivered' => ['Orders delivered', 'orders', 'delivered_at', 'count_rows'],
        'cancelled' => ['Orders cancelled', 'orders', 'cancelled_at', 'count_rows'],
    ];

    /** @param  list<SourceFreshness>  $sources */
    public function collect(BriefingRequest $request, array $sources): SectionResult
    {
        $window = $request->yesterday();
        $items = [];
        $notes = [];
        /** @var array<string, array<string, list<EvidenceItem>>> $byBusiness figure => business => items */
        $byBusiness = [];
        $incomplete = [];

        foreach ($sources as $source) {
            if ($source->readModel !== 'facts_orders' || $source->freshness->state === FreshnessState::NotConnected) {
                continue;
            }

            $business = (string) $source->scope->business;
            $store = (string) $source->scope->store;

            if ($source->freshness->state === FreshnessState::Missing) {
                $incomplete[$business] = true;
                $notes[] = ['status' => 'unavailable', 'store' => $store, 'business' => $business,
                    'reason' => 'HQ holds no order facts for this store, so it has no figure for yesterday. That is not the same as no orders.'];

                continue;
            }

            foreach (array_keys(self::FIGURES) as $figure) {
                $item = $this->storeFigure($figure, $store, $source, $window);
                $items[] = $item;
                $byBusiness[$figure][$business][] = $item;
            }
        }

        $groupInputs = [];
        foreach (self::FIGURES as $figure => $_) {
            foreach ($byBusiness[$figure] ?? [] as $business => $storeItems) {
                if (isset($incomplete[$business])) {
                    continue;
                }
                $rollup = $this->rollup($figure, "business:{$business}", new EvidenceScope(business: $business), $storeItems, $request->now);
                $items[] = $rollup;
                $groupInputs[$figure][] = $rollup;
            }
        }

        foreach (array_keys($incomplete) as $business) {
            $notes[] = ['status' => 'total_withheld', 'business' => $business,
                'reason' => 'A store of this business has no figure, so no business total is given: a total over some of the stores would look complete and not be.'];
        }

        if ($request->isGroupWide() && $incomplete === []) {
            foreach ($groupInputs as $figure => $rollups) {
                $items[] = $this->rollup($figure, 'group', EvidenceScope::group(), $rollups, $request->now);
            }
        } elseif ($request->isGroupWide() && $groupInputs !== []) {
            $notes[] = ['status' => 'total_withheld', 'business' => null, 'reason' => 'A store has no figure, so no group total is given.'];
        }

        return SectionResult::of(self::KEY, self::TITLE, $items,
            'HQ holds order facts for no store in this scope, so there is nothing to report for yesterday.', $notes);
    }

    /** @param  array{date: string, from: string, to: string}  $window */
    private function storeFigure(string $figure, string $store, SourceFreshness $source, array $window): EvidenceItem
    {
        [$label, $unit, $column, $formula] = self::FIGURES[$figure];

        $rows = DB::table('facts_orders as f')->join('stores as st', 'st.id', '=', 'f.store_id')
            ->where('st.slug', $store)
            ->where("f.{$column}", '>=', $window['from'])->where("f.{$column}", '<', $window['to'])
            ->orderBy('f.id')
            ->get(['f.id', 'f.order_ref', 'f.last_event_id', 'f.gross'])->all();

        $value = $figure === 'gross'
            ? Money::fromPaisa(array_sum(array_map(static fn (object $r): int => Money::toPaisa($r->gross), $rows)))
            : count($rows);

        return new EvidenceItem(
            id: "orders.{$figure}.store:{$store}",
            kind: EvidenceKind::Metric,
            section: self::KEY,
            label: "{$label}, {$store}, {$window['date']}",
            value: $value,
            unit: $unit,
            scope: $source->scope,
            source: ['system' => 'shop', 'read_model' => 'facts_orders', 'ref' => $source->key],
            asOf: $source->asOf,
            freshness: $source->freshness,
            lineage: Lineage::ofRows($formula, 'facts_orders', array_map(static fn (object $r): array => [
                'id' => (int) $r->id,
                // The row's own source: the shop's order number, and the event that last wrote it.
                'source_ref' => ['store' => $store, 'order_ref' => (string) $r->order_ref, 'last_event_id' => $r->last_event_id],
            ], $rows), ['store' => $store, 'column' => $column, 'from' => $window['from'], 'to_exclusive' => $window['to']]),
        );
    }

    /** @param  list<EvidenceItem>  $inputs */
    private function rollup(string $figure, string $suffix, EvidenceScope $scope, array $inputs, CarbonImmutable $now): EvidenceItem
    {
        [$label, $unit] = self::FIGURES[$figure];

        $value = $figure === 'gross'
            ? Money::fromPaisa(array_sum(array_map(static fn (EvidenceItem $i): int => Money::toPaisa((string) $i->value), $inputs)))
            : array_sum(array_map(static fn (EvidenceItem $i): int => (int) $i->value, $inputs));

        // A total is as old as its oldest input and as fresh as its least fresh one.
        $asOf = null;
        $worst = null;
        $rank = static fn (Freshness $f): array => [$f->state->degrades() ? 1 : 0, $f->ageMinutes ?? PHP_INT_MAX];
        foreach ($inputs as $input) {
            if ($input->asOf !== null && ($asOf === null || $input->asOf->lessThan($asOf))) {
                $asOf = $input->asOf;
            }
            if ($worst === null || $rank($input->freshness) > $rank($worst)) {
                $worst = $input->freshness;
            }
        }

        return new EvidenceItem(
            id: "orders.{$figure}.{$suffix}",
            kind: EvidenceKind::Metric,
            section: self::KEY,
            label: "{$label}, ".($scope->business ?? 'the group'),
            value: $value,
            unit: $unit,
            scope: $scope,
            source: ['system' => 'hq', 'read_model' => 'briefing_bundle', 'ref' => 'items'],
            asOf: $asOf,
            freshness: $worst ?? Freshness::measure(null, SourceReader::budget('facts_orders'), $now),
            lineage: Lineage::ofItems('sum_of_items', array_map(static fn (EvidenceItem $i): string => $i->id, $inputs)),
        );
    }
}
