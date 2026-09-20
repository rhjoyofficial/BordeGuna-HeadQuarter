<?php

namespace App\Briefing\Sections;

use App\Briefing\BriefingRequest;
use App\Briefing\BundleSection;
use App\Briefing\EvidenceItem;
use App\Briefing\EvidenceKind;
use App\Briefing\EvidenceScope;
use App\Briefing\Freshness;
use App\Briefing\Lineage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What is scheduled today (docs/48 M.2): the active, approved jobs whose next slot, as the scheduler
 * itself has stored it in `next_run_at`, falls inside the briefing date in the group timezone.
 *
 * It reads the scheduler's own record and computes no slot of its own, so a job that runs several
 * times a day appears once, with its next slot. On a past date the question has no answer (the
 * scheduler keeps the next slot, not the slots a day once had), and the section says so.
 */
final class ScheduledToday
{
    public const KEY = 'scheduled_today';

    public const TITLE = 'Scheduled today';

    public function collect(BriefingRequest $request): SectionResult
    {
        if (! $request->mayRead('jobs')) {
            return SectionResult::without(self::KEY, self::TITLE, BundleSection::WITHHELD, 'The caller does not hold hq.jobs.view, so scheduled jobs are not read.');
        }
        if (! $request->isToday()) {
            return SectionResult::without(self::KEY, self::TITLE, BundleSection::NOT_APPLICABLE,
                'The scheduler keeps each job\'s next slot, not the slots of a past day, so this is only answerable for the current day.');
        }

        $window = $request->today();
        $jobs = $request->restrict(
            DB::table('scheduled_jobs as j')->leftJoin('businesses as b', 'b.id', '=', 'j.business_id')
                ->where('j.state', 'active')->where('j.approval_state', 'approved')
                ->where('j.next_run_at', '>=', $window['from'])->where('j.next_run_at', '<', $window['to']),
            'j.business_id',
        )->orderBy('j.next_run_at')->orderBy('j.id')->get(['j.id', 'j.uuid', 'j.name', 'j.tool_key', 'j.kind', 'j.next_run_at', 'b.slug as business'])->all();

        if ($jobs === []) {
            return SectionResult::of(self::KEY, self::TITLE, [], "No active, approved job has its next slot on {$window['date']}.");
        }

        $source = ['system' => 'hq', 'read_model' => 'scheduled_jobs', 'ref' => 'scheduled_jobs'];
        $filter = ['state' => 'active', 'approval_state' => 'approved', 'next_run_at_from' => $window['from'], 'next_run_at_to_exclusive' => $window['to']];

        $items = [new EvidenceItem(
            id: 'schedule.today.count',
            kind: EvidenceKind::Metric,
            section: self::KEY,
            label: "Jobs with their next slot on {$window['date']}",
            value: count($jobs),
            unit: 'count',
            scope: new EvidenceScope(business: $request->businessSlug),
            source: $source,
            asOf: $request->now,
            freshness: Freshness::live(),
            lineage: Lineage::ofRows('count_rows', 'scheduled_jobs', array_map(static fn (object $j): array => [
                'id' => (int) $j->id, 'source_ref' => ['uuid' => (string) $j->uuid, 'tool_key' => (string) $j->tool_key],
            ], $this->byId($jobs)), $filter),
        )];

        $limit = Listing::limit();
        foreach (array_slice($jobs, 0, $limit) as $job) {
            $items[] = new EvidenceItem(
                id: "schedule.today.job:{$job->uuid}",
                kind: EvidenceKind::Fact,
                section: self::KEY,
                label: "Next slot of the job \"{$job->name}\"",
                value: CarbonImmutable::parse((string) $job->next_run_at)->utc()->toIso8601String(),
                unit: 'datetime',
                scope: new EvidenceScope(business: $job->business),
                source: $source + ['row_id' => (int) $job->id],
                asOf: $request->now,
                freshness: Freshness::live(),
                subject: (string) $job->name,
                details: ['tool' => (string) $job->tool_key, 'kind' => (string) $job->kind],
            );
        }

        return SectionResult::of(self::KEY, self::TITLE, $items, '', Listing::truncation(count($jobs), $limit, 'jobs'));
    }

    /** @param  list<object>  $rows */
    private function byId(array $rows): array
    {
        usort($rows, static fn (object $a, object $b): int => $a->id <=> $b->id);

        return $rows;
    }
}
