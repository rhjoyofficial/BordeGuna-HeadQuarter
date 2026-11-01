<?php

namespace App\Autonomy\Ladder;

use App\Ai\Pause\AiPause;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-way, fail-closed lapse of autonomy grants made through the evidence ladder (ChatGPT ruling 17,
 * 31 October 2026; docs/145-d Q5 as amended; made durable by the ruling of 1 November 2026, docs/150).
 *
 * A ladder grant stands only while every governing condition holds. The moment one fails, the grant is
 * LAPSED persistently (status `lapsed`, when, which condition, why, and one audit row), not merely ignored:
 *
 *   evidence_below_threshold  the evidence no longer meets the threshold's numbers;
 *   ai_paused                 Pause All AI is on;
 *   pause_unreadable          the pause state cannot be read or verified;
 *   threshold_retired         its threshold is retired, missing or no longer the one it rested on;
 *   grant_expired             the grant's own expiry has passed;
 *   safety_condition_invalid  any other governing condition fails (level above the threshold's, tool
 *                             unregistered or now permanently human-only, scope fields missing, or the
 *                             check itself could not be completed).
 *
 * ## The authorization failure is immediate; the persistence is durable (docs/150)
 *
 * - `stands()` evaluates every condition at decision time, independent of the grant's stored status, and
 *   answers false the moment one fails. The refusal never depends on the lapse having been written.
 * - The lapse is written by `DurableLapseWriter` on its own database session, committed before `stands()`
 *   returns, so a caller's transaction rolling back (or the worker dying after the decision) cannot undo it.
 * - A pending marker (`PendingLapses`) is set before the write and cleared only when the write is known to
 *   be committed. While it exists the grant is refused even if the conditions recover, and every decision and
 *   the minute sweep retry the write. The sweep is recovery, not the safety boundary.
 *
 * Lapse is ONE-WAY. Turning AI back on, setting the threshold again or the evidence recovering never
 * reactivates the grant; only a new explicit grant by a person (`LadderGrants::grant`, which re-verifies
 * every condition) gives autonomy again. A database trigger refuses any update that would bring a lapsed
 * grant, or any ladder grant that left `active`, back to `active`. Nothing here can grant, renew or widen.
 *
 * Who calls it: the autonomy resolver on every decision, the Pause All AI switch at the moment of pausing,
 * threshold retirement (in its own transaction, see `lapseWithCaller`) and the sweep command.
 */
final class LadderGrantLapse
{
    public const CODES = ['evidence_below_threshold', 'ai_paused', 'pause_unreadable', 'threshold_retired', 'grant_expired', 'safety_condition_invalid'];

    public function __construct(
        private readonly AiPause $pause,
        private readonly ToolEvidenceLadder $ladder,
        private readonly ToolRegistry $tools,
        private readonly DurableLapseWriter $writer,
        private readonly PendingLapses $pending,
    ) {}

    /**
     * True when the grant may still be honoured right now. A grant not made through the ladder is not this
     * class's to judge (true). A ladder grant that fails any condition, or has a lapse decided but not yet
     * durably written, is lapsed here (durably when possible) and false is returned whatever the write did.
     */
    public function stands(AutonomyGrant $grant): bool
    {
        if ($grant->ladder_threshold_id === null) {
            return true;
        }
        if ($grant->status !== 'active') {
            return false;
        }

        // A lapse already decided stays decided: refuse, and try the write again.
        if (($pending = $this->pending->get((int) $grant->getKey())) !== null) {
            $this->lapse($grant, $pending['code'], $pending['reason']);

            return false;
        }

        try {
            $failure = $this->failure($grant);
        } catch (Throwable $e) {
            $failure = ['safety_condition_invalid', 'The grant\'s conditions could not be verified: '.$e->getMessage()];
        }
        if ($failure === null) {
            return true;
        }

        $this->lapse($grant, $failure[0], $failure[1]);

        return false;
    }

    /**
     * Recovery: first writes every lapse that was decided but not durably written (whatever the conditions say
     * now), then checks every active ladder grant and lapses each one that fails. Returns how many lapsed.
     */
    public function sweep(): int
    {
        $lapsed = $this->writePending();
        AutonomyGrant::query()->whereNotNull('ladder_threshold_id')->where('status', 'active')->orderBy('id')
            ->each(function (AutonomyGrant $grant) use (&$lapsed) {
                if (! $this->stands($grant) && $grant->status === 'lapsed') {
                    $lapsed++;
                }
            });

        return $lapsed;
    }

    /** Writes the pending lapses. Clears a marker whose grant is already lapsed, revoked or gone. */
    public function writePending(): int
    {
        $written = 0;
        foreach ($this->pending->all() as $grantId => $entry) {
            $grant = AutonomyGrant::query()->find($grantId);
            if ($grant === null || $grant->status !== 'active') {
                $this->pending->clear((int) $grantId);

                continue;
            }
            if ($this->lapse($grant, $entry['code'], $entry['reason'])) {
                $written++;
            }
        }

        return $written;
    }

    /** Lapses every active ladder grant for one reason (used at the moment AI is paused). */
    public function lapseAll(string $code, string $reason): int
    {
        $lapsed = 0;
        AutonomyGrant::query()->whereNotNull('ladder_threshold_id')->where('status', 'active')->orderBy('id')
            ->each(function (AutonomyGrant $grant) use (&$lapsed, $code, $reason) {
                if ($this->lapse($grant, $code, $reason)) {
                    $lapsed++;
                }
            });

        return $lapsed;
    }

    /**
     * Writes the lapse once, outside the caller's rollback boundary when it can. Only an ACTIVE grant moves;
     * a grant already lapsed or revoked is left as it is. Returns whether this call lapsed it. Never throws:
     * a failed write leaves the pending marker, so the grant stays refused and is retried.
     */
    public function lapse(AutonomyGrant $grant, string $code, string $reason): bool
    {
        $code = in_array($code, self::CODES, true) ? $code : 'safety_condition_invalid';
        $id = (int) $grant->getKey();
        $this->pending->mark($id, $code, $reason);

        try {
            $result = $this->writer->writeDurably($grant, $code, $reason);
        } catch (Throwable $e) {
            report($e);
            $result = null;
        }
        if ($result === DurableLapseWriter::LAPSED || $result === DurableLapseWriter::NOT_ACTIVE) {
            $this->pending->clear($id);

            return $result === DurableLapseWriter::LAPSED;
        }

        // Not visible to the dedicated session (the row is in the caller's open transaction) or that write
        // failed: write with the caller. That survives only if the caller commits, so the marker is cleared
        // only then; on a rollback it stays, and the next decision or the sweep writes the lapse again.
        return $this->lapseWithCaller($grant, $code, $reason);
    }

    /**
     * The lapse written in the caller's own transaction. Used directly where the lapse condition itself is
     * created by that transaction (retiring a threshold: if the retirement rolls back, the threshold was never
     * retired and the grant rightly stands), and as the fallback of `lapse()`.
     */
    public function lapseWithCaller(AutonomyGrant $grant, string $code, string $reason): bool
    {
        $code = in_array($code, self::CODES, true) ? $code : 'safety_condition_invalid';
        $id = (int) $grant->getKey();
        try {
            $wrote = $this->writer->writeInCaller($grant, $code, $reason);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        DB::afterCommit(fn () => $this->pending->clear($id));

        return $wrote;
    }

    /** @return array{0: string, 1: string}|null the failing condition and why, or null when everything holds */
    private function failure(AutonomyGrant $grant): ?array
    {
        $state = $this->pause->state();
        if (! $state['readable']) {
            return ['pause_unreadable', 'The Pause All AI state could not be read or verified, so the grant lapsed.'];
        }
        if ($state['paused']) {
            return ['ai_paused', 'Pause All AI was turned on, so the grant lapsed. Turning AI back on does not restore it.'];
        }

        if ($grant->expires_at !== null && ! $grant->expires_at->isFuture()) {
            return ['grant_expired', 'The grant\'s expiry has passed.'];
        }

        $threshold = AutonomyLadderThreshold::query()->find($grant->ladder_threshold_id);
        if ($threshold === null || $threshold->status !== 'active') {
            return ['threshold_retired', 'The ladder threshold the grant rested on is retired or missing.'];
        }
        if ($grant->business_id === null || $grant->tool_key === null
            || (int) $threshold->business_id !== (int) $grant->business_id || $threshold->tool_key !== $grant->tool_key) {
            return ['safety_condition_invalid', 'The grant no longer matches the business and tool of its threshold.'];
        }
        if ($grant->max_level === null || $grant->max_level->rank() > AutonomyLevel::from((string) $threshold->max_level)->rank()) {
            return ['safety_condition_invalid', 'The grant\'s level is above what its threshold allows.'];
        }
        if (! $this->tools->has((string) $grant->tool_key) || $this->tools->get((string) $grant->tool_key)->descriptor()->permanentlyHumanOnly) {
            return ['safety_condition_invalid', 'The tool is no longer registered, or is now permanently human-only.'];
        }

        $judged = $this->ladder->judge((int) $grant->business_id, (string) $grant->tool_key);
        if (! $judged['met']) {
            return ['evidence_below_threshold', 'The evidence fell below the threshold: '.implode(' ', $judged['reasons'])];
        }

        return null;
    }
}
