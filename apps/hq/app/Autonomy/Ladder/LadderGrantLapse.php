<?php

namespace App\Autonomy\Ladder;

use App\Ai\Pause\AiPause;
use App\Gateway\Descriptor\AutonomyLevel;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Support\Audit\Auditor;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-way, fail-closed lapse of autonomy grants made through the evidence ladder (ChatGPT ruling 17,
 * 31 October 2026; docs/145-d Q5 as amended).
 *
 * A ladder grant stands only while every governing condition holds. The moment one fails, the grant is
 * LAPSED persistently (status `lapsed`, when, which condition, why, and one audit row), not merely ignored:
 *
 *   evidence_below_threshold  the evidence no longer meets the threshold's numbers;
 *   ai_paused                 Pause All AI is on;
 *   pause_unreadable          the pause state cannot be read or verified;
 *   threshold_retired         its threshold is retired, missing or no longer the one it rested on;
 *   safety_condition_invalid  any other governing condition fails (level above the threshold's, tool
 *                             unregistered or now permanently human-only, scope fields missing, or the
 *                             check itself could not be completed).
 *
 * Lapse is ONE-WAY. Turning AI back on, setting the threshold again or the evidence recovering never
 * reactivates the grant; only a new explicit grant by a person (`LadderGrants::grant`, which re-verifies
 * every condition) gives autonomy again. A database trigger refuses any update that would bring a lapsed
 * grant, or any ladder grant that left `active`, back to `active`.
 *
 * Who calls it: the autonomy resolver on every decision (a failing grant is lapsed right there, so it is A0
 * at that decision and after), the Pause All AI switch at the moment of pausing, and the sweep command.
 * Lapsing only ever removes autonomy; nothing here can grant, renew or widen anything.
 */
final class LadderGrantLapse
{
    public const CODES = ['evidence_below_threshold', 'ai_paused', 'pause_unreadable', 'threshold_retired', 'safety_condition_invalid'];

    public function __construct(
        private readonly AiPause $pause,
        private readonly ToolEvidenceLadder $ladder,
        private readonly ToolRegistry $tools,
        private readonly Auditor $auditor,
    ) {}

    /**
     * True when the grant may still be honoured right now. A grant not made through the ladder is not this
     * class's to judge (true). A ladder grant that fails any condition is lapsed here and false is returned.
     */
    public function stands(AutonomyGrant $grant): bool
    {
        if ($grant->ladder_threshold_id === null) {
            return true;
        }
        if ($grant->status !== 'active') {
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

    /** Checks every active ladder grant now and lapses each one that fails. Returns how many lapsed. */
    public function sweep(): int
    {
        $lapsed = 0;
        AutonomyGrant::query()->whereNotNull('ladder_threshold_id')->where('status', 'active')->orderBy('id')
            ->each(function (AutonomyGrant $grant) use (&$lapsed) {
                if (! $this->stands($grant)) {
                    $lapsed++;
                }
            });

        return $lapsed;
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
     * Writes the lapse once. Only an ACTIVE grant moves; a grant already lapsed or revoked is left as it is.
     * Returns whether this call lapsed it.
     */
    public function lapse(AutonomyGrant $grant, string $code, string $reason): bool
    {
        $code = in_array($code, self::CODES, true) ? $code : 'safety_condition_invalid';

        return DB::transaction(function () use ($grant, $code, $reason): bool {
            $locked = AutonomyGrant::query()->whereKey($grant->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'active') {
                return false;
            }
            $locked->forceFill(['status' => 'lapsed', 'lapsed_at' => now(), 'lapse_code' => $code, 'lapse_reason' => mb_substr($reason, 0, 2000)])->save();
            $grant->setRawAttributes($locked->getAttributes(), true);
            $this->auditor->recordSystem('autonomy.grant.lapsed', $locked,
                before: ['status' => 'active'],
                after: ['status' => 'lapsed', 'grant' => $locked->uuid, 'business_id' => $locked->business_id, 'tool_key' => $locked->tool_key, 'lapse_code' => $code,
                    'reactivates' => false],
                reason: $reason);

            return true;
        });
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
