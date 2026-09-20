<?php

namespace App\Briefing\Sections;

use App\Briefing\BriefingRequest;
use App\Briefing\EvidenceItem;
use App\Briefing\EvidenceKind;
use App\Briefing\EvidenceScope;
use App\Briefing\Freshness;
use App\Briefing\FreshnessState;
use App\Briefing\Lineage;
use App\Briefing\SourceFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What is wrong, or unusual, this morning (docs/48 M.2): the latest integrity check, job runs that
 * failed since the start of yesterday, dead events, open alerts, sources past their budget, and the
 * order-volume band.
 *
 * **The band is honest or absent.** "Outside the recent band" is only a claim HQ can support where a
 * store has history: the read model must reach back over the whole of the fourteen days before
 * yesterday and hold at least thirty orders in them, the same floor `facts.query_orders` uses before
 * it will call anything a trend. Then the band is simply the lowest and the highest daily count of
 * those fourteen days, zero days included, and yesterday is outside it or it is not. No mean, no
 * deviation, nothing a reader would have to take on trust. Below the floor there is no band at all:
 * the section carries an "insufficient history" note for the store and no number.
 *
 * Group-level readings (the integrity check and the alert record have no business) are read only for
 * a group-wide caller. A caller limited to some businesses gets a note saying they were not read.
 */
final class Anomalies
{
    public const KEY = 'anomalies';

    public const TITLE = 'Anomalies';

    /**
     * @param  list<SourceFreshness>  $sources
     * @param  list<EvidenceItem>  $yesterday  the items of the yesterday section, for the band
     */
    public function collect(BriefingRequest $request, array $sources, array $yesterday): SectionResult
    {
        $items = [];
        $notes = [];

        if ($request->isGroupWide()) {
            array_push($items, ...$this->integrity($sources));
            array_push($items, ...$this->alerts($request, $notes));
        } else {
            $notes[] = ['status' => 'not_read', 'reason' => 'The integrity check and the alert record are group-level, and this briefing is limited to some businesses, so they were not read.'];
        }

        if ($request->mayRead('jobs')) {
            array_push($items, ...$this->failedRuns($request, $notes));
        } else {
            $notes[] = ['status' => 'withheld', 'reason' => 'The caller does not hold hq.jobs.view, so failed job runs were not read.'];
        }

        array_push($items, ...$this->deadEvents($request));
        array_push($items, ...$this->degradedSources($request, $sources));
        array_push($items, ...$this->bands($request, $sources, $yesterday, $notes));

        return SectionResult::of(self::KEY, self::TITLE, $items, 'Nothing was read for this section.', $notes);
    }

    /** @param  list<SourceFreshness>  $sources */
    private function integrity(array $sources): array
    {
        $source = $this->source($sources, 'hq:integrity_check');
        $call = DB::table('tool_calls')->where('tool_key', SourceReader::INTEGRITY_TOOL)->where('status', 'succeeded')
            ->orderByDesc('finished_at')->orderByDesc('id')->first(['id', 'output', 'correlation_id']);

        if ($source === null || $call === null) {
            return [];
        }

        $output = is_string($call->output) ? (array) json_decode($call->output, true) : (array) $call->output;
        $ref = ['system' => 'hq', 'read_model' => 'tool_calls', 'ref' => $source->key, 'row_id' => (int) $call->id, 'correlation_id' => $call->correlation_id];
        $make = fn (string $id, string $label, string $key): EvidenceItem => new EvidenceItem(
            id: $id, kind: EvidenceKind::Fact, section: self::KEY, label: $label, value: (int) ($output[$key] ?? 0), unit: 'count',
            scope: EvidenceScope::group(), source: $ref, asOf: $source->asOf, freshness: $source->freshness,
            details: ['store' => $output['store'] ?? null, 'hours' => $output['hours'] ?? null],
        );

        return [
            $make('integrity.discrepancies', 'Discrepancies found by the latest order-fact integrity check', 'discrepancies'),
            $make('integrity.checked', 'Order facts examined by the latest integrity check', 'checked'),
        ];
    }

    private function alerts(BriefingRequest $request, array &$notes): array
    {
        $alerts = DB::table('alerts')->whereNull('resolved_at')
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")->orderBy('first_seen_at')->orderBy('id')
            ->get(['id', 'rule', 'fingerprint', 'severity', 'subject', 'first_seen_at', 'last_seen_at'])->all();

        $source = ['system' => 'hq', 'read_model' => 'alerts', 'ref' => 'alerts'];
        $byId = $alerts;
        usort($byId, static fn (object $a, object $b): int => $a->id <=> $b->id);

        $items = [new EvidenceItem(
            id: 'alerts.open.count', kind: EvidenceKind::Metric, section: self::KEY, label: 'Open alerts', value: count($alerts), unit: 'count',
            scope: EvidenceScope::group(), source: $source, asOf: $request->now, freshness: Freshness::live(),
            lineage: Lineage::ofRows('count_rows', 'alerts', array_map(static fn (object $a): array => [
                'id' => (int) $a->id, 'source_ref' => ['fingerprint' => (string) $a->fingerprint],
            ], $byId), ['resolved_at' => null]),
        )];

        $limit = Listing::limit();
        foreach (array_slice($alerts, 0, $limit) as $alert) {
            // An open alert is as current as the check that last saw it. A check that has stopped
            // running leaves alerts open and stale, and the item says so.
            $seen = CarbonImmutable::parse((string) $alert->last_seen_at)->utc();
            $items[] = new EvidenceItem(
                id: "alerts.open:{$alert->fingerprint}", kind: EvidenceKind::Fact, section: self::KEY, label: 'Open alert',
                value: (string) $alert->severity, unit: 'text', scope: EvidenceScope::group(),
                source: $source + ['row_id' => (int) $alert->id], asOf: $seen,
                freshness: Freshness::measure($seen, SourceReader::budget('alerts'), $request->now),
                subject: (string) $alert->subject,
                details: ['rule' => (string) $alert->rule, 'first_seen_at' => CarbonImmutable::parse((string) $alert->first_seen_at)->utc()->toIso8601String()],
            );
        }

        array_push($notes, ...Listing::truncation(count($alerts), $limit, 'alerts'));

        return $items;
    }

    private function failedRuns(BriefingRequest $request, array &$notes): array
    {
        $since = $request->yesterday()['from'];
        $runs = $request->restrict(
            DB::table('job_runs as r')->join('scheduled_jobs as j', 'j.id', '=', 'r.scheduled_job_id')
                ->leftJoin('businesses as b', 'b.id', '=', 'j.business_id')
                ->whereIn('r.status', ['failed', 'dead'])
                ->whereRaw('coalesce(r.finished_at, r.updated_at) >= ?', [$since]),
            'j.business_id',
        )->orderBy('r.id')->get(['r.id', 'r.status', 'r.correlation_id', 'r.scheduled_for', 'j.uuid', 'j.name', 'j.tool_key', 'b.slug as business'])->all();

        $source = ['system' => 'hq', 'read_model' => 'job_runs', 'ref' => 'job_runs'];
        $items = [new EvidenceItem(
            id: 'jobs.failed.count', kind: EvidenceKind::Metric, section: self::KEY, label: 'Job runs that failed since the start of yesterday',
            value: count($runs), unit: 'count', scope: new EvidenceScope(business: $request->businessSlug), source: $source,
            asOf: $request->now, freshness: Freshness::live(),
            lineage: Lineage::ofRows('count_rows', 'job_runs', array_map(static fn (object $r): array => [
                'id' => (int) $r->id, 'source_ref' => ['job_uuid' => (string) $r->uuid, 'correlation_id' => $r->correlation_id],
            ], $runs), ['status' => ['failed', 'dead'], 'finished_or_updated_from' => $since]),
        )];

        $limit = Listing::limit();
        foreach (array_slice($runs, 0, $limit) as $run) {
            $items[] = new EvidenceItem(
                id: "jobs.failed.run:{$run->id}", kind: EvidenceKind::Fact, section: self::KEY, label: "A run of the job \"{$run->name}\" ended {$run->status}",
                value: (string) $run->status, unit: 'text', scope: new EvidenceScope(business: $run->business),
                source: $source + ['row_id' => (int) $run->id], asOf: $request->now, freshness: Freshness::live(),
                subject: (string) $run->name,
                details: ['tool' => (string) $run->tool_key, 'scheduled_for' => CarbonImmutable::parse((string) $run->scheduled_for)->utc()->toIso8601String()],
            );
        }

        array_push($notes, ...Listing::truncation(count($runs), $limit, 'failed runs'));

        return $items;
    }

    private function deadEvents(BriefingRequest $request): array
    {
        $events = $request->restrict(DB::table('events')->where('status', 'dead'), 'business_id')
            ->orderBy('id')->get(['id', 'event_id', 'type'])->all();

        return [new EvidenceItem(
            id: 'events.dead.count', kind: EvidenceKind::Metric, section: self::KEY, label: 'Events in the dead letter state', value: count($events),
            unit: 'count', scope: new EvidenceScope(business: $request->businessSlug),
            source: ['system' => 'hq', 'read_model' => 'events', 'ref' => 'events'], asOf: $request->now, freshness: Freshness::live(),
            lineage: Lineage::ofRows('count_rows', 'events', array_map(static fn (object $e): array => [
                'id' => (int) $e->id, 'source_ref' => ['event_id' => (string) $e->event_id, 'type' => (string) $e->type],
            ], $events), ['status' => 'dead']),
        )];
    }

    /** @param  list<SourceFreshness>  $sources */
    private function degradedSources(BriefingRequest $request, array $sources): array
    {
        $degraded = array_values(array_filter($sources, static fn (SourceFreshness $s): bool => $s->freshness->state->degrades()));

        return [new EvidenceItem(
            id: 'sources.degraded.count', kind: EvidenceKind::Metric, section: self::KEY, label: 'Sources that are stale or missing', value: count($degraded),
            unit: 'count', scope: new EvidenceScope(business: $request->businessSlug),
            source: ['system' => 'hq', 'read_model' => 'briefing_bundle', 'ref' => 'freshness.sources'], asOf: $request->now, freshness: Freshness::live(),
            lineage: Lineage::ofSources('count_degraded_sources', array_map(static fn (SourceFreshness $s): string => $s->key, $degraded)),
        )];
    }

    /**
     * @param  list<SourceFreshness>  $sources
     * @param  list<EvidenceItem>  $yesterday
     */
    private function bands(BriefingRequest $request, array $sources, array $yesterday, array &$notes): array
    {
        $days = max(2, (int) config('hq.briefing.band.min_days', 14));
        $floor = max(1, (int) config('hq.briefing.band.min_rows', 30));
        $end = CarbonImmutable::parse($request->yesterday()['from'])->utc();
        $start = $end->setTimezone($request->timezone)->subDays($days)->utc();
        $items = [];

        foreach ($sources as $source) {
            if ($source->readModel !== 'facts_orders' || in_array($source->freshness->state, [FreshnessState::NotConnected, FreshnessState::Missing], true)) {
                continue;
            }

            $store = (string) $source->scope->store;
            $placed = $this->item($yesterday, "orders.placed.store:{$store}");
            $first = DB::table('facts_orders as f')->join('stores as st', 'st.id', '=', 'f.store_id')->where('st.slug', $store)->min('f.placed_at');
            $rows = DB::table('facts_orders as f')->join('stores as st', 'st.id', '=', 'f.store_id')->where('st.slug', $store)
                ->where('f.placed_at', '>=', $start->toIso8601String())->where('f.placed_at', '<', $end->toIso8601String())
                ->orderBy('f.id')->get(['f.id', 'f.order_ref', 'f.placed_at'])->all();

            $reachesBack = $first !== null && CarbonImmutable::parse((string) $first)->utc()->lessThan($start);

            if ($placed === null || ! $reachesBack || count($rows) < $floor) {
                $notes[] = ['status' => 'insufficient_history', 'store' => $store,
                    'reason' => "No order-volume band is given for this store: a band needs order facts reaching back over all {$days} days before yesterday and at least {$floor} orders in them, and HQ does not hold that yet."];

                continue;
            }

            $perDay = [];
            for ($d = 0; $d < $days; $d++) {
                $perDay[$start->setTimezone($request->timezone)->addDays($d)->toDateString()] = 0;
            }
            foreach ($rows as $row) {
                $day = CarbonImmutable::parse((string) $row->placed_at)->setTimezone($request->timezone)->toDateString();
                if (isset($perDay[$day])) {
                    $perDay[$day]++;
                }
            }

            $lineage = fn (string $formula): Lineage => Lineage::ofRows($formula, 'facts_orders', array_map(static fn (object $r): array => [
                'id' => (int) $r->id, 'source_ref' => ['store' => $store, 'order_ref' => (string) $r->order_ref],
            ], $rows), ['store' => $store, 'column' => 'placed_at', 'from' => $start->toIso8601String(), 'to_exclusive' => $end->toIso8601String(), 'bucket' => "calendar day in {$request->timezone}", 'days' => $days]);

            $low = min($perDay);
            $high = max($perDay);
            $make = fn (string $which, string $label, int|bool $value, string $unit, Lineage $l): EvidenceItem => new EvidenceItem(
                id: "orders.band.{$which}.store:{$store}", kind: EvidenceKind::Metric, section: self::KEY, label: "{$label}, {$store}",
                value: $value, unit: $unit, scope: $source->scope,
                source: ['system' => 'shop', 'read_model' => 'facts_orders', 'ref' => $source->key],
                asOf: $source->asOf, freshness: $source->freshness, lineage: $l,
            );

            $items[] = $make('low', "Lowest daily orders in the {$days} days before yesterday", $low, 'orders', $lineage('min_daily_count'));
            $items[] = $make('high', "Highest daily orders in the {$days} days before yesterday", $high, 'orders', $lineage('max_daily_count'));
            $items[] = $make('outside', 'Whether yesterday\'s orders fall outside that band', (int) $placed->value < $low || (int) $placed->value > $high, 'boolean',
                Lineage::ofItems('placed_below_low_or_above_high', [$placed->id, "orders.band.low.store:{$store}", "orders.band.high.store:{$store}"]));
        }

        return $items;
    }

    /** @param  list<SourceFreshness>  $sources */
    private function source(array $sources, string $key): ?SourceFreshness
    {
        foreach ($sources as $source) {
            if ($source->key === $key) {
                return $source;
            }
        }

        return null;
    }

    /** @param  list<EvidenceItem>  $items */
    private function item(array $items, string $id): ?EvidenceItem
    {
        foreach ($items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }
}
