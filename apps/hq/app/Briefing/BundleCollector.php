<?php

namespace App\Briefing;

use App\Briefing\Sections\Anomalies;
use App\Briefing\Sections\LowStock;
use App\Briefing\Sections\PendingApprovals;
use App\Briefing\Sections\ScheduledToday;
use App\Briefing\Sections\SectionResult;
use App\Briefing\Sections\SourceReader;
use App\Briefing\Sections\YesterdayOrders;

/**
 * Assembles the evidence bundle from HQ's own read models (docs/48 M.1, M.4).
 *
 * Deterministic: no model, no connector, no network, no clock but the one in the request. It reads
 * order facts, ERP snapshots, the latest integrity result, job runs, events, approvals and alerts,
 * and it writes nothing. It produces `fact` and `metric` items only; inference and recommendation
 * are the narrator's kinds and never come from here.
 *
 * ⚠ It reads HQ's tables directly where the agents' read tools go through the gateway one call at a
 * time, because lineage needs row ids and those tools answer with aggregates. The gateway still
 * governs it as a whole: the only way in is `briefing.compose`, under that tool's permission, the
 * caller's business restriction and the section permissions the tool resolves.
 *
 * Not final, for one reason: a test replaces it to prove what the composer does with a bundle that is
 * wrong or cannot be collected.
 */
class BundleCollector
{
    public function __construct(
        private readonly SourceReader $sources,
        private readonly YesterdayOrders $yesterday,
        private readonly ScheduledToday $scheduled,
        private readonly Anomalies $anomalies,
        private readonly LowStock $lowStock,
        private readonly PendingApprovals $approvals,
    ) {}

    public function collect(BriefingRequest $request): EvidenceBundle
    {
        $sources = $this->sources->read($request);

        $yesterday = $this->yesterday->collect($request, $sources);
        $approvals = $this->approvals->collect($request);
        $anomalies = $this->anomalies->collect($request, $sources, $yesterday->items);

        $results = [
            $yesterday,
            $this->scheduled->collect($request),
            $anomalies,
            $this->lowStock->collect($request, $sources),
            $approvals,
            $this->decisions($approvals, $anomalies),
        ];

        foreach (ExecutiveBriefingContract::NOT_CONNECTED as $key => $reason) {
            $results[] = SectionResult::without($key, ExecutiveBriefingContract::SECTIONS[$key], BundleSection::NOT_CONNECTED, $reason);
        }

        return new EvidenceBundle(
            capability: ExecutiveBriefingContract::CAPABILITY,
            briefingDate: $request->today()['date'],
            timezone: $request->timezone,
            generatedAt: $request->now,
            yesterday: $request->yesterday(),
            today: $request->today(),
            scope: ['business' => $request->businessSlug, 'permitted_business_ids' => $request->permittedBusinessIds],
            sources: $sources,
            sections: array_map(static fn (SectionResult $r): BundleSection => $r->section, $results),
            items: array_merge(...array_map(static fn (SectionResult $r): array => $r->items, $results)),
        );
    }

    /**
     * What is waiting on a person: each pending approval, and the integrity check when it found a
     * discrepancy (money that disagrees with itself needs somebody to decide which side is right).
     * It adds no item and no judgement; it points at items the other sections already carry. What to
     * do about them is a recommendation, and that is the narrator's kind, not the collector's.
     */
    private function decisions(SectionResult $approvals, SectionResult $anomalies): SectionResult
    {
        $ids = [];
        foreach ($approvals->items as $item) {
            if (str_starts_with($item->id, 'approvals.pending.waiting_hours:')) {
                $ids[] = $item->id;
            }
        }
        foreach ($anomalies->items as $item) {
            if ($item->id === 'integrity.discrepancies' && (int) $item->value > 0) {
                $ids[] = $item->id;
            }
        }

        $key = 'decisions';
        $title = ExecutiveBriefingContract::SECTIONS[$key];

        if ($ids === []) {
            return SectionResult::without($key, $title, BundleSection::EMPTY,
                $approvals->section->status === BundleSection::WITHHELD
                    ? 'Approvals were not read for this caller, and the integrity check reports nothing to decide.'
                    : 'No approval is pending and the integrity check reports nothing to decide.');
        }

        return new SectionResult(new BundleSection($key, $title, BundleSection::OK, null, $ids));
    }
}
