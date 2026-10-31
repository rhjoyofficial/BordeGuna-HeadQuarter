<?php

namespace App\Autonomy\Ladder;

use App\Ai\Pause\AiPause;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Models\Business;
use App\Models\User;
use App\Research\ResearchRefused;
use App\Support\Audit\Auditor;
use App\Support\Authorization\BusinessScope;
use App\Support\Authorization\GroupWideAuthority;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Thresholds and grants on the evidence ladder (docs/89 §3.16, ADR-048, docs/145-d).
 *
 * - **Thresholds are data Joy sets.** None ship; with none, nothing is grantable. A threshold is never
 *   edited (a trigger holds it): a change is a retirement and a new row.
 * - **The grant is human-only.** Only a person, through this class, with `hq.autonomy.grant` and group-wide
 *   authority, while AI is not paused, for a tool that is not permanently human-only, at a level no higher
 *   than the threshold allows (and never above A4), and only when the evidence meets the threshold NOW.
 *   No agent, tool, job or model can reach this class: it takes a `User`, and no tool or route calls it on
 *   anyone else's behalf.
 * - **Revoke is instant.** One update of the grant row; the resolver reads grants fresh on every decision,
 *   so the next action is A0. Revoking needs only `hq.autonomy.revoke` and the business in scope: stopping
 *   is the safe direction.
 * - **Retiring a threshold lapses every grant that rested on it**, in the same transaction.
 * - Pause All AI overrides everything: the policy engine refuses every agent call while paused.
 * - **Lapse is one-way** (ChatGPT ruling 17, 31 Oct 2026): `LadderGrantLapse` lapses a grant persistently the
 *   moment any governing condition fails (evidence, pause, unreadable pause, retired threshold, any other
 *   safety condition). Nothing reactivates it; a person makes a NEW grant here, after every condition is
 *   verified again.
 */
final class LadderGrants
{
    public const LEVELS = ['a1', 'a2', 'a3', 'a4'];

    /** Setting numbers and granting: a global switch (docs/138 ruling 13), so also group-wide authority. */
    public const PERMISSION = 'hq.autonomy.grant';

    public function __construct(
        private readonly ToolEvidenceLadder $ladder,
        private readonly BusinessScope $scope,
        private readonly Auditor $auditor,
        private readonly AiPause $pause,
        private readonly ToolRegistry $tools,
        private readonly GroupWideAuthority $authority,
        private readonly LadderGrantLapse $lapse,
    ) {}

    /** @param  array{tool_key: string, max_level: string, min_approved_succeeded: int, max_error_permille: int, max_rejection_permille: int, window_days: int, reason: string}  $data */
    public function setThreshold(User $by, Business $business, array $data): AutonomyLadderThreshold
    {
        $this->groupWide($by);
        $tool = $this->tool((string) ($data['tool_key'] ?? ''));
        $level = (string) ($data['max_level'] ?? '');
        if (! in_array($level, self::LEVELS, true)) {
            throw new ResearchRefused('A threshold names a level from A1 to A4. A5 is unreachable.');
        }
        $min = (int) ($data['min_approved_succeeded'] ?? 0);
        $err = (int) ($data['max_error_permille'] ?? -1);
        $rej = (int) ($data['max_rejection_permille'] ?? -1);
        $window = (int) ($data['window_days'] ?? 0);
        if ($min < 1 || $min > 100000 || $err < 0 || $err > 1000 || $rej < 0 || $rej > 1000 || $window < 7 || $window > 365) {
            throw new ResearchRefused('A threshold needs at least 1 approved successful run, error and rejection limits between 0 and 1,000 per 1,000, and a window of 7 to 365 days.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new ResearchRefused('Say why these numbers.');
        }

        return DB::transaction(function () use ($by, $business, $tool, $level, $min, $err, $rej, $window, $reason): AutonomyLadderThreshold {
            $old = AutonomyLadderThreshold::query()->where('business_id', $business->getKey())->where('tool_key', $tool)->where('status', 'active')->lockForUpdate()->first();
            if ($old !== null) {
                $this->retireLocked($by, $old, 'replaced by a new threshold: '.$reason);
            }
            $row = AutonomyLadderThreshold::query()->create([
                'business_id' => $business->getKey(), 'tool_key' => $tool, 'max_level' => $level,
                'min_approved_succeeded' => $min, 'max_error_permille' => $err, 'max_rejection_permille' => $rej, 'window_days' => $window,
                'reason' => mb_substr($reason, 0, 2000), 'status' => 'active', 'set_by' => $by->getKey(),
            ]);
            $this->auditor->record('autonomy.ladder.threshold_set', $row,
                after: ['business_id' => $business->getKey(), 'tool_key' => $tool, 'max_level' => $level, 'min_approved_succeeded' => $min,
                    'max_error_permille' => $err, 'max_rejection_permille' => $rej, 'window_days' => $window, 'replaced' => $old?->uuid],
                reason: $reason, actorUserId: $by->getKey());

            return $row;
        });
    }

    public function retireThreshold(User $by, AutonomyLadderThreshold $threshold, string $reason): AutonomyLadderThreshold
    {
        $this->groupWide($by);
        if (trim($reason) === '') {
            throw new ResearchRefused('Say why the threshold is retired.');
        }

        return DB::transaction(function () use ($by, $threshold, $reason): AutonomyLadderThreshold {
            $locked = AutonomyLadderThreshold::query()->whereKey($threshold->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new ResearchRefused('The threshold is already retired.');
            }

            return $this->retireLocked($by, $locked, $reason);
        });
    }

    /** The grant itself. Human-only by construction; every condition is read again inside the transaction. */
    public function grant(User $by, Business $business, string $toolKey, string $level, string $reason): AutonomyGrant
    {
        $this->groupWide($by);
        if ($this->pause->isPaused()) {
            throw new ResearchRefused('Pause All AI is on (or its state cannot be read). No autonomy is granted while AI is paused.');
        }
        $toolKey = $this->tool($toolKey);
        if (! in_array($level, self::LEVELS, true)) {
            throw new ResearchRefused('A grant names a level from A1 to A4.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ResearchRefused('Say why this autonomy is granted.');
        }

        return DB::transaction(function () use ($by, $business, $toolKey, $level, $reason): AutonomyGrant {
            $threshold = AutonomyLadderThreshold::query()->where('business_id', $business->getKey())->where('tool_key', $toolKey)->where('status', 'active')->lockForUpdate()->first();
            $judged = $this->ladder->judge((int) $business->getKey(), $toolKey);
            if ($threshold === null || ! $judged['met']) {
                throw new ResearchRefused('The evidence does not meet a threshold for this tool in this business: '.implode(' ', $judged['reasons']));
            }
            if (AutonomyLevel::from($level)->rank() > AutonomyLevel::from((string) $threshold->max_level)->rank()) {
                throw new ResearchRefused('The threshold allows at most '.strtoupper((string) $threshold->max_level).'.');
            }

            $grant = AutonomyGrant::query()->create([
                'name' => "ladder: {$toolKey} in business {$business->getKey()} at ".strtoupper($level),
                'reason' => mb_substr($reason, 0, 2000),
                'business_id' => $business->getKey(),
                'tool_key' => $toolKey,
                'max_level' => $level,
                'status' => 'active',
                'granted_by' => $by->getKey(),
                'granted_at' => now(),
                'ladder_threshold_id' => $threshold->getKey(),
            ]);
            $this->auditor->record('autonomy.ladder.granted', $grant,
                after: ['grant' => $grant->uuid, 'business_id' => $business->getKey(), 'tool_key' => $toolKey, 'level' => $level, 'threshold' => $threshold->uuid, 'evidence' => $judged['evidence']],
                reason: $reason, actorUserId: $by->getKey());

            return $grant;
        });
    }

    /** Instant. The safe direction: needs only the revoke permission and the business in scope. */
    public function revoke(User $by, AutonomyGrant $grant, string $reason): AutonomyGrant
    {
        if ($grant->business_id === null ? $this->scope->isRestricted($by) : ! $this->scope->allows($by, (int) $grant->business_id)) {
            throw new ResearchRefused('That grant is outside what you may act on.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ResearchRefused('Say why the grant is revoked.');
        }

        return DB::transaction(function () use ($by, $grant, $reason): AutonomyGrant {
            $locked = AutonomyGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new ResearchRefused('The grant is not active.');
            }
            $locked->forceFill(['status' => 'revoked', 'revoked_by' => $by->getKey(), 'revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 2000)])->save();
            $this->auditor->record('autonomy.grant.revoked', $locked, before: ['status' => 'active'], after: ['status' => 'revoked', 'grant' => $locked->uuid], reason: $reason, actorUserId: $by->getKey());

            return $locked;
        });
    }

    private function retireLocked(User $by, AutonomyLadderThreshold $threshold, string $reason): AutonomyLadderThreshold
    {
        $threshold->forceFill(['status' => 'retired', 'retired_by' => $by->getKey(), 'retired_at' => now()])->save();
        // Ruling 17: the grants that rested on it lapse, one-way, each with its own audit row.
        $revoked = AutonomyGrant::query()->where('ladder_threshold_id', $threshold->getKey())->where('status', 'active')->lockForUpdate()->get();
        foreach ($revoked as $grant) {
            $this->lapse->lapse($grant, 'threshold_retired', 'Its ladder threshold was retired: '.$reason);
        }
        $this->auditor->record('autonomy.ladder.threshold_retired', $threshold, before: ['status' => 'active'], after: ['status' => 'retired', 'lapsed_grants' => $revoked->pluck('uuid')->all()],
            reason: $reason, actorUserId: $by->getKey());

        return $threshold;
    }

    private function groupWide(User $by): void
    {
        if (($refusal = $this->authority->refusal($by, self::PERMISSION)) !== null) {
            throw new ResearchRefused('Thresholds and grants of autonomy need group-wide authority (docs/138 ruling 13): '.$refusal);
        }
    }

    private function tool(string $key): string
    {
        $key = trim($key);
        if (! $this->tools->has($key)) {
            throw new ResearchRefused("[{$key}] is not a registered tool.");
        }
        try {
            $descriptor = $this->tools->get($key)->descriptor();
        } catch (Throwable) {
            throw new ResearchRefused("[{$key}] cannot be read.");
        }
        if ($descriptor->permanentlyHumanOnly) {
            throw new ResearchRefused("[{$key}] is permanently human-only. No threshold or grant can ever let an agent run it.");
        }

        return $key;
    }
}
