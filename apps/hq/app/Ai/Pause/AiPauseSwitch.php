<?php

namespace App\Ai\Pause;

use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Authorization\GroupWideAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pause All AI and turning it back on (docs/138 ruling 7 and section 3, docs/141). Two operations, never
 * one toggle:
 *
 *   - `pause()` can only ever set the state to PAUSED. It has no argument that could mean anything else,
 *     so the pause route and the pause command cannot re-enable, whatever they are sent.
 *   - `resume()` is the distinct, authorized re-enable: its own permission (`hq.ai.resume`), its own
 *     reason, its own audit row.
 *
 * Both need group-wide authority (ruling 13, `GroupWideAuthority`): a named, enabled person who holds the
 * permission and has no business restriction. Human-only by construction: each takes a `User`, never an
 * agent or "the system", and no tool, agent, workflow or scheduler class may reach this file
 * (`PauseAllAiTest` pins that).
 *
 * ⛔ What a pause does NOT do: grant or remove a permission, change consent, approve, reject or cancel an
 * approval, pause or resume a scheduled job, change `ai.runtime`, an agent's status or a grant, reset a
 * circuit breaker or restart a provider. It writes the one row and the audit row. Re-enabling likewise
 * writes only the row: it does not turn `ai.runtime` on, and every other switch is exactly where it was.
 */
final class AiPauseSwitch
{
    public const PAUSE_PERMISSION = 'hq.ai.pause';

    public const RESUME_PERMISSION = 'hq.ai.resume';

    public function __construct(
        private readonly GroupWideAuthority $authority,
        private readonly AiPause $reader,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Pause every AI execution for the whole group. Pausing while already paused is recorded again (a
     * person pressed the button) and keeps the state paused.
     *
     * @return array<string, mixed> the state after
     *
     * @throws AuthorizationException when the person lacks group-wide authority for hq.ai.pause
     */
    public function pause(User $by, string $reason): array
    {
        $this->authority->assert($by, self::PAUSE_PERMISSION);
        $reason = $this->reason($reason);

        $before = $this->reader->state();

        DB::transaction(function () use ($by, $reason) {
            $now = now();
            $exists = DB::table(AiPause::TABLE)->where('id', AiPause::ROW)->lockForUpdate()->exists();
            $values = ['paused' => true, 'paused_at' => $now, 'paused_by_user_id' => $by->getKey(), 'pause_reason' => $reason, 'updated_at' => $now];
            $exists
                ? DB::table(AiPause::TABLE)->where('id', AiPause::ROW)->update($values)
                : DB::table(AiPause::TABLE)->insert(['id' => AiPause::ROW] + $values);
        });

        $after = $this->reader->state();
        $this->auditor->record('ai.paused', AiPause::TABLE, before: $this->forAudit($before), after: $this->forAudit($after), reason: $reason,
            actorUserId: $by->getKey(), context: $this->authority->auditContext(self::PAUSE_PERMISSION, $by));

        return $after;
    }

    /**
     * The distinct re-enable. Refused when AI is not paused (there is nothing to re-enable, and a row
     * that was unreadable is made readable only by a person deciding so here).
     *
     * @return array<string, mixed> the state after
     *
     * @throws AuthorizationException when the person lacks group-wide authority for hq.ai.resume
     * @throws InvalidArgumentException when AI is not paused or the reason is empty
     */
    public function resume(User $by, string $reason): array
    {
        $this->authority->assert($by, self::RESUME_PERMISSION);
        $reason = $this->reason($reason);

        $before = $this->reader->state();
        if (! $before['paused']) {
            throw new InvalidArgumentException('AI is not paused, so there is nothing to turn back on.');
        }

        DB::transaction(function () use ($by, $reason) {
            $now = now();
            $exists = DB::table(AiPause::TABLE)->where('id', AiPause::ROW)->lockForUpdate()->exists();
            $values = ['paused' => false, 'resumed_at' => $now, 'resumed_by_user_id' => $by->getKey(), 'resume_reason' => $reason, 'updated_at' => $now];
            $exists
                ? DB::table(AiPause::TABLE)->where('id', AiPause::ROW)->update($values)
                : DB::table(AiPause::TABLE)->insert(['id' => AiPause::ROW] + $values);
        });

        $after = $this->reader->state();
        $this->auditor->record('ai.resumed', AiPause::TABLE, before: $this->forAudit($before), after: $this->forAudit($after), reason: $reason,
            actorUserId: $by->getKey(), context: $this->authority->auditContext(self::RESUME_PERMISSION, $by));

        return $after;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required: it is kept in the audit log with who did it.');
        }

        return mb_substr($reason, 0, 500);
    }

    /** @return array{paused: bool, readable: bool} the part of the state an audit row compares */
    private function forAudit(array $state): array
    {
        return ['paused' => $state['paused'], 'readable' => $state['readable']];
    }
}
