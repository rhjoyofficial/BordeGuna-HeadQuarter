<?php

namespace App\Observe\Alerts\Rules;

use App\Observe\Alerts\AlertCondition;
use App\Observe\Alerts\AlertRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An ERP snapshot HQ has not managed to poll for longer than the poll cadence allows (docs/48 N.4,
 * closing the `docs/45` gap where a dead ERP hostname went unnoticed for a day).
 *
 * The polls run daily, so the threshold is a day plus grace (26 hours by default), measured from when
 * HQ last fetched the snapshot. `facts.snapshot_freshness` keeps its own two-hour label for how an
 * answer describes its data; that label is about honesty, this alert is about a poll that did not land.
 */
final class SnapshotStale implements AlertRule
{
    public function key(): string
    {
        return 'facts.snapshot_stale';
    }

    public function pagedBy(): ?string
    {
        return null;
    }

    public function evaluate(CarbonImmutable $now): ?array
    {
        $hours = max(1, (int) config('hq.alerts.thresholds.snapshot_stale_after_hours', 26));
        $conditions = [];

        $snapshots = DB::table('facts_erp_snapshots as s')
            ->join('businesses as b', 'b.id', '=', 's.business_id')
            ->where('b.is_active', true)
            ->orderBy('b.slug')->orderBy('s.kind')
            ->get(['b.slug', 's.kind', 's.polled_at', 's.as_of']);

        foreach ($snapshots as $snapshot) {
            $polled = CarbonImmutable::parse($snapshot->polled_at);
            $age = (int) $polled->diffInHours($now);

            if ($age < $hours) {
                continue;
            }

            $conditions[] = new AlertCondition("erp:{$snapshot->slug}/{$snapshot->kind}", 'warning',
                "The ERP {$snapshot->kind} snapshot for {$snapshot->slug} is {$age} hours old",
                "HQ last fetched it {$age} hours ago; the daily poll should have refreshed it. Answers that rest on it say so. "
                .'Check the scheduler run for erp.poll_'.$snapshot->kind.' and php artisan hq:erp:probe.',
                ['business' => $snapshot->slug, 'kind' => $snapshot->kind, 'polled_at' => $polled->toIso8601String(), 'age_hours' => $age, 'threshold_hours' => $hours]);
        }

        return $conditions;
    }
}
