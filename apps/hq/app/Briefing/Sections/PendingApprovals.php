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
 * Pending approvals and their clocks (docs/48 M.2).
 *
 * ⛔ The shape of each request, never its content. `hq.list_approvals` already draws this line: an
 * approval's payload is the exact arguments of a parked call and can name a customer, an amount, an
 * address. The bundle is written to be handed to a model, so it carries which tool is waiting, at
 * what tier, since when and until when, and not the payload, the reason text, the evidence or the
 * preview.
 */
final class PendingApprovals
{
    public const KEY = 'approvals';

    public const TITLE = 'Pending approvals';

    public function collect(BriefingRequest $request): SectionResult
    {
        if (! $request->mayRead('approvals')) {
            return SectionResult::without(self::KEY, self::TITLE, BundleSection::WITHHELD, 'The caller does not hold hq.approvals.view, so approvals are not read.');
        }

        $pending = $request->restrict(
            DB::table('approvals as a')->leftJoin('businesses as b', 'b.id', '=', 'a.business_id')->where('a.status', 'pending'),
            'a.business_id',
        )->orderBy('a.created_at')->orderBy('a.id')
            ->get(['a.id', 'a.uuid', 'a.tool_key', 'a.risk_tier', 'a.agent_key', 'a.requested_by_user_id', 'a.created_at', 'a.sla_due_at', 'b.slug as business'])->all();

        $source = ['system' => 'hq', 'read_model' => 'approvals', 'ref' => 'approvals'];
        $scope = new EvidenceScope(business: $request->businessSlug);
        $overdue = array_values(array_filter($pending, fn (object $a): bool => $a->sla_due_at !== null && CarbonImmutable::parse((string) $a->sla_due_at)->lessThan($request->now)));
        $rows = static function (array $approvals): array {
            usort($approvals, static fn (object $a, object $b): int => $a->id <=> $b->id);

            return array_map(static fn (object $a): array => ['id' => (int) $a->id, 'source_ref' => ['uuid' => (string) $a->uuid, 'tool_key' => (string) $a->tool_key]], $approvals);
        };

        $items = [
            new EvidenceItem(
                id: 'approvals.pending.count', kind: EvidenceKind::Metric, section: self::KEY, label: 'Approvals waiting for a decision', value: count($pending),
                unit: 'count', scope: $scope, source: $source, asOf: $request->now, freshness: Freshness::live(),
                lineage: Lineage::ofRows('count_rows', 'approvals', $rows($pending), ['status' => 'pending']),
            ),
            new EvidenceItem(
                id: 'approvals.overdue.count', kind: EvidenceKind::Metric, section: self::KEY, label: 'Pending approvals past their deadline', value: count($overdue),
                unit: 'count', scope: $scope, source: $source, asOf: $request->now, freshness: Freshness::live(),
                lineage: Lineage::ofRows('count_rows', 'approvals', $rows($overdue), ['status' => 'pending', 'sla_due_at_before' => $request->now->utc()->toIso8601String()]),
            ),
        ];

        $limit = Listing::limit();
        foreach (array_slice($pending, 0, $limit) as $approval) {
            $created = CarbonImmutable::parse((string) $approval->created_at)->utc();
            $due = $approval->sla_due_at === null ? null : CarbonImmutable::parse((string) $approval->sla_due_at)->utc();
            $itemScope = new EvidenceScope(business: $approval->business);
            $itemSource = $source + ['row_id' => (int) $approval->id];
            $details = [
                'tool' => (string) $approval->tool_key,
                'risk_tier' => (string) $approval->risk_tier,
                'requested_by' => $approval->agent_key ?? ($approval->requested_by_user_id === null ? 'system' : 'a person'),
                'waiting_since' => $created->toIso8601String(),
                'overdue' => $due !== null && $due->lessThan($request->now),
            ];

            $items[] = new EvidenceItem(
                id: "approvals.pending.waiting_hours:{$approval->uuid}", kind: EvidenceKind::Metric, section: self::KEY,
                label: "Whole hours the approval for {$approval->tool_key} has waited", value: max(0, intdiv($request->now->getTimestamp() - $created->getTimestamp(), 3600)),
                unit: 'hours', scope: $itemScope, source: $itemSource, asOf: $request->now, freshness: Freshness::live(),
                lineage: Lineage::ofRows('whole_hours_from_created_at_to_generated_at', 'approvals',
                    [['id' => (int) $approval->id, 'source_ref' => ['uuid' => (string) $approval->uuid, 'tool_key' => (string) $approval->tool_key]]],
                    ['created_at' => $created->toIso8601String(), 'generated_at' => $request->now->utc()->toIso8601String()]),
                subject: (string) $approval->uuid, details: $details,
            );

            if ($due !== null) {
                $items[] = new EvidenceItem(
                    id: "approvals.pending.due_at:{$approval->uuid}", kind: EvidenceKind::Fact, section: self::KEY,
                    label: "Deadline of the approval for {$approval->tool_key}", value: $due->toIso8601String(), unit: 'datetime',
                    scope: $itemScope, source: $itemSource, asOf: $request->now, freshness: Freshness::live(),
                    subject: (string) $approval->uuid, details: $details,
                );
            }
        }

        return SectionResult::of(self::KEY, self::TITLE, $items, '', Listing::truncation(count($pending), $limit, 'pending approvals'));
    }
}
