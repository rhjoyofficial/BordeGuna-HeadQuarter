<?php

namespace App\Observe\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * docs/120 item 13 (S4 Q6), docs/123 section 4: retention of the follow-up tables.
 *
 * Removed, by id, in bounded transactions: a `refused` or `declined` follow-up, and a `declined`
 * opportunity, whose decision is older than the first kept Dhaka day of `followup_refused_keep_days`
 * (365). Follow-ups go first, so an opportunity whose last follow-up has just gone can follow in the
 * same pass.
 *
 * ⛔ Never removed: an open row (proposed, approved, scheduled; proposed or open), a done or cancelled
 *    follow-up, a closed opportunity (relationship history, kept), a follow-up an outcome attribution
 *    credits, an opportunity a follow-up still serves. Keep-clauses win over the period (item 7).
 * ⛔ What a row points at stays: its audit rows are permanent (`audit_logs` is never targeted), and its
 *    consent event, customer and conversation are RESTRICT parents, untouched.
 * ⚠ The WorkflowEngine rule is separate and unchanged (`WorkflowRetention`, 180 days after a run is
 *    terminal and reconciled). A follow-up has no foreign key to its run, so pruning a run never touches
 *    the follow-up, and pruning a follow-up never touches a run.
 */
final class FollowUpRetention
{
    public function __construct(private readonly RetentionPolicy $policy) {}

    /** @return array<string, mixed> reads only */
    public function plan(CarbonImmutable $firstKept): array
    {
        return [
            'keep_days' => $this->policy->followUpKeepDays(),
            'eligible_before' => $firstKept->toDateString(),
            'eligible_follow_ups' => $this->eligibleFollowUps($firstKept)->count(),
            'kept_credited_follow_ups' => $this->decidedFollowUps($firstKept)->whereRaw('('.RetentionPolicy::FOLLOW_UP_KEEP_CONDITION.')')->count(),
            'eligible_opportunities' => $this->eligibleOpportunities($firstKept)->count(),
            'kept_served_opportunities' => $this->decidedOpportunities($firstKept)->whereRaw('('.RetentionPolicy::OPPORTUNITY_KEEP_CONDITION.')')->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function prune(CarbonImmutable $firstKept, float $deadline): array
    {
        $result = [
            'keep_days' => $this->policy->followUpKeepDays(),
            'eligible_before' => $firstKept->toDateString(),
            'follow_ups_removed' => 0,
            'opportunities_removed' => 0,
            'stopped_by' => null,
        ];

        foreach (['follow_ups' => 'follow_ups_removed', 'opportunities' => 'opportunities_removed'] as $table => $counter) {
            while (true) {
                $room = $this->policy->maxRowsPerTable() - $result[$counter];
                if ($room <= 0) {
                    $result['stopped_by'] = 'max_rows';
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $result['stopped_by'] = 'max_seconds';
                    break 2;
                }
                $limit = min($this->policy->batchSize(), $room);

                $removed = DB::transaction(function () use ($table, $firstKept, $limit) {
                    $eligible = $table === 'follow_ups' ? $this->eligibleFollowUps($firstKept) : $this->eligibleOpportunities($firstKept);
                    $alias = $table === 'follow_ups' ? 'f' : 'p';
                    $ids = $eligible->orderBy("{$alias}.id")->limit($limit)->lockForUpdate()->pluck("{$alias}.id")->map(fn ($id) => (int) $id)->all();
                    if ($ids === []) {
                        return 0;
                    }
                    $deleted = DB::table($table)->whereIn('id', $ids)->delete();
                    if ($deleted !== count($ids)) {
                        throw new RuntimeException('Follow-up retention selected '.count($ids)." rows of {$table} but removed {$deleted}.");
                    }

                    return $deleted;
                });

                $result[$counter] += $removed;
                if ($removed < $limit) {
                    break;
                }
            }
        }

        return $result;
    }

    private function eligibleFollowUps(CarbonImmutable $firstKept): Builder
    {
        return $this->decidedFollowUps($firstKept)->whereRaw('NOT ('.RetentionPolicy::FOLLOW_UP_KEEP_CONDITION.')');
    }

    private function eligibleOpportunities(CarbonImmutable $firstKept): Builder
    {
        return $this->decidedOpportunities($firstKept)->whereRaw('NOT ('.RetentionPolicy::OPPORTUNITY_KEEP_CONDITION.')');
    }

    /** Refused or declined, decided (or refused at proposal) before the window. */
    private function decidedFollowUps(CarbonImmutable $firstKept): Builder
    {
        return DB::table('follow_ups as f')
            ->whereIn('f.state', RetentionPolicy::FOLLOW_UP_PRUNABLE_STATES)
            ->whereRaw('COALESCE(f.decided_at, f.consent_checked_at, f.created_at) < ?', [$firstKept->utc()]);
    }

    private function decidedOpportunities(CarbonImmutable $firstKept): Builder
    {
        return DB::table('opportunities as p')
            ->whereIn('p.state', RetentionPolicy::OPPORTUNITY_PRUNABLE_STATES)
            ->whereRaw('COALESCE(p.decided_at, p.created_at) < ?', [$firstKept->utc()]);
    }
}
