<?php

namespace App\Ai\Pause;

use App\Ai\Exceptions\AiPaused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads Pause All AI (docs/138 ruling 7, docs/141). Read-only: nothing here can pause or re-enable.
 *
 * ⛔ Fail closed. The answer is "running" ONLY when the one row exists and says `paused = false`.
 * A missing table, a missing row, a value that is not a clear false, or any error while reading (the
 * database down, a broken transaction) answers PAUSED: "If switch state cannot be verified, keep AI
 * paused."
 *
 * ⛔ Never cached. Every model call and every agent tool call reads the row again, so a pause made by a
 * person in the browser stops a queue worker or the scheduler at its very next call, not after a TTL.
 * It is one primary-key read, far cheaper than the call it guards.
 *
 * Who reads it, before any model call or agent tool call: `ModelRuntime` (before routing and again
 * before every provider request, so a fallback, a truncation retry or a repair after a pause is
 * refused), `ProviderCheck`, `AgentRunner` (before the first turn and before every proposed tool call),
 * `PolicyEngine` (any gateway call made as an agent or through the agent door), Ask HQ, the support
 * wake-up, shadow probes, the sentence scheduler and the briefing narration. The list with reasons is
 * in docs/141.
 */
final class AiPause
{
    public const TABLE = 'ai_pause_state';

    public const ROW = 1;

    /**
     * The state as a person should see it.
     *
     * @return array{paused: bool, readable: bool, paused_at: string|null, paused_by_user_id: int|null, pause_reason: string|null, resumed_at: string|null, resumed_by_user_id: int|null, resume_reason: string|null}
     */
    public function state(): array
    {
        $unreadable = [
            'paused' => true, 'readable' => false,
            'paused_at' => null, 'paused_by_user_id' => null, 'pause_reason' => null,
            'resumed_at' => null, 'resumed_by_user_id' => null, 'resume_reason' => null,
        ];

        try {
            $read = fn () => DB::table(self::TABLE)->where('id', self::ROW)->first();
            // Inside a caller's transaction the read takes a savepoint, so a failed read (Postgres then
            // aborts the transaction) is rolled back to it and the caller's own work can still finish
            // and record that AI was refused.
            $row = DB::transactionLevel() > 0 ? DB::transaction($read) : $read();
        } catch (Throwable) {
            return $unreadable;
        }

        if ($row === null) {
            return $unreadable;
        }

        $running = self::isClearFalse($row->paused ?? null);

        return [
            'paused' => ! $running,
            // A value that is neither a clear true nor a clear false is not a state anyone set.
            'readable' => $running || self::isClearTrue($row->paused ?? null),
            'paused_at' => self::time($row->paused_at ?? null),
            'paused_by_user_id' => isset($row->paused_by_user_id) ? (int) $row->paused_by_user_id : null,
            'pause_reason' => $row->pause_reason ?? null,
            'resumed_at' => self::time($row->resumed_at ?? null),
            'resumed_by_user_id' => isset($row->resumed_by_user_id) ? (int) $row->resumed_by_user_id : null,
            'resume_reason' => $row->resume_reason ?? null,
        ];
    }

    /** True while paused, and whenever the state cannot be verified. */
    public function isPaused(): bool
    {
        return $this->state()['paused'];
    }

    /**
     * Refuses when paused (or unreadable). `$what` names the thing that was not done, for the record.
     *
     * @throws AiPaused
     */
    public function assertRunning(string $what): void
    {
        $state = $this->state();
        if (! $state['paused']) {
            return;
        }

        throw new AiPaused($state['readable']
            ? "Pause All AI is on, so {$what} was not done. A person with group-wide authority and hq.ai.resume must turn AI back on."
            : "The Pause All AI state could not be read, so AI is treated as paused and {$what} was not done.");
    }

    private static function isClearFalse(mixed $value): bool
    {
        return $value === false || $value === 0 || $value === '0' || $value === 'f' || $value === 'false';
    }

    private static function isClearTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
