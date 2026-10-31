<?php

namespace App\Autonomy\Ladder;

use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\Approval;
use App\Models\AutonomyLadderThreshold;
use App\Models\ToolCall;
use Carbon\CarbonImmutable;

/**
 * The evidence ladder per tool per business (docs/89 §3.16 "evidence, not time", docs/145-d).
 *
 * What it counts, inside the threshold's window (or 90 days when there is none), from HQ's own records:
 *
 *   proposed            approvals an AGENT asked for, for this tool in this business
 *   approved            of those, a person said yes (approved or consumed)
 *   rejected            a person said no
 *   expired_or_cancelled  nobody decided, or it was withdrawn (counted, never as agreement)
 *   executed            approved and run (a resulting tool call exists)
 *   succeeded           the run's outcome was succeeded
 *   errors              the run failed or its outcome is uncertain
 *
 *   rejection_permille  = rejected per 1,000 decided (approved + rejected), floored
 *   error_permille      = errors per 1,000 executed, floored
 *
 * `judge()` compares that with the ACTIVE threshold Joy set for this tool and business. No threshold:
 * never met. Every comparison is on whole numbers, nothing is rounded up, and a zero denominator is never
 * a zero rate: with nothing executed, the error rate is unknown and the threshold is not met.
 *
 * ⛔ Read-only. It writes nothing and grants nothing; `LadderGrants` asks it, and so does the resolver
 * before it honours a grant made through the ladder.
 */
final class ToolEvidenceLadder
{
    public const DEFAULT_WINDOW_DAYS = 90;

    /** @return array<string, int|string|null> */
    public function evidence(int $businessId, string $toolKey, ?int $windowDays = null, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $window = $windowDays ?? self::DEFAULT_WINDOW_DAYS;
        $since = $at->subDays($window);

        $base = Approval::query()->where('business_id', $businessId)->where('tool_key', $toolKey)
            ->whereNotNull('agent_key')->where('created_at', '>=', $since)->where('created_at', '<=', $at);

        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $count = fn (ApprovalStatus ...$s): int => array_sum(array_map(fn (ApprovalStatus $x) => $counts[$x->value] ?? 0, $s));

        $approved = $count(ApprovalStatus::Approved, ApprovalStatus::Consumed);
        $rejected = $count(ApprovalStatus::Rejected);

        $callIds = (clone $base)->whereNotNull('resulting_tool_call_id')->pluck('resulting_tool_call_id')->all();
        $calls = $callIds === [] ? [] : ToolCall::query()->whereIn('id', $callIds)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $executed = array_sum($calls);
        $succeeded = (int) ($calls['succeeded'] ?? 0);
        $errors = (int) ($calls['failed'] ?? 0) + (int) ($calls['uncertain'] ?? 0);

        $decided = $approved + $rejected;

        return [
            'business_id' => $businessId,
            'tool_key' => $toolKey,
            'window_days' => $window,
            'since' => $since->toIso8601String(),
            'proposed' => array_sum($counts),
            'pending' => $count(ApprovalStatus::Pending),
            'approved' => $approved,
            'rejected' => $rejected,
            'expired_or_cancelled' => $count(ApprovalStatus::Expired, ApprovalStatus::Cancelled),
            'executed' => $executed,
            'succeeded' => $succeeded,
            'errors' => $errors,
            'rejection_permille' => $decided === 0 ? null : intdiv($rejected * 1000, $decided),
            'error_permille' => $executed === 0 ? null : intdiv($errors * 1000, $executed),
        ];
    }

    public function activeThreshold(int $businessId, string $toolKey): ?AutonomyLadderThreshold
    {
        return AutonomyLadderThreshold::query()->where('business_id', $businessId)->where('tool_key', $toolKey)->where('status', 'active')->first();
    }

    /**
     * Whether the evidence meets the active threshold now, and the reasons when it does not.
     *
     * @return array{met: bool, level: string, threshold: ?array<string, mixed>, evidence: array<string, mixed>, reasons: list<string>}
     */
    public function judge(int $businessId, string $toolKey, ?CarbonImmutable $at = null): array
    {
        $threshold = $this->activeThreshold($businessId, $toolKey);
        $evidence = $this->evidence($businessId, $toolKey, $threshold?->window_days, $at);

        if ($threshold === null) {
            return ['met' => false, 'level' => AutonomyLevel::A0->value, 'threshold' => null, 'evidence' => $evidence,
                'reasons' => ['No threshold is set for this tool in this business, so autonomy is never granted (the default).']];
        }

        $reasons = [];
        if ($evidence['succeeded'] < $threshold->min_approved_succeeded) {
            $reasons[] = "{$evidence['succeeded']} approved proposals ran successfully; the threshold asks for {$threshold->min_approved_succeeded}.";
        }
        if ($evidence['error_permille'] === null) {
            $reasons[] = 'Nothing approved has run yet, so the error rate is unknown.';
        } elseif ($evidence['error_permille'] > $threshold->max_error_permille) {
            $reasons[] = "Error rate {$evidence['error_permille']} per 1,000 is above the {$threshold->max_error_permille} allowed.";
        }
        if ($evidence['rejection_permille'] === null) {
            $reasons[] = 'No proposal has been decided yet, so the rejection rate is unknown.';
        } elseif ($evidence['rejection_permille'] > $threshold->max_rejection_permille) {
            $reasons[] = "People said no to {$evidence['rejection_permille']} per 1,000 decided proposals; at most {$threshold->max_rejection_permille} is allowed.";
        }

        return [
            'met' => $reasons === [],
            'level' => $reasons === [] ? (string) $threshold->max_level : AutonomyLevel::A0->value,
            'threshold' => [
                'id' => (int) $threshold->getKey(), 'uuid' => (string) $threshold->uuid, 'max_level' => (string) $threshold->max_level,
                'min_approved_succeeded' => (int) $threshold->min_approved_succeeded, 'max_error_permille' => (int) $threshold->max_error_permille,
                'max_rejection_permille' => (int) $threshold->max_rejection_permille, 'window_days' => (int) $threshold->window_days,
            ],
            'evidence' => $evidence,
            'reasons' => $reasons,
        ];
    }
}
