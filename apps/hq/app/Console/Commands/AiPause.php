<?php

namespace App\Console\Commands;

use App\Ai\Pause\AiPause as PauseState;
use App\Ai\Pause\AiPauseSwitch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Pause All AI from a terminal, for when the browser is what is broken (docs/138 ruling 7, docs/141).
 *
 * It can only pause: there is no option that turns AI back on (that is `hq:ai:resume`, its own command
 * and its own permission). The person is named with --by and needs group-wide authority for hq.ai.pause
 * (ruling 13): nobody named is refused, because the switch is human-only and its audit row names who.
 * With --show it only prints the state and changes nothing.
 */
final class AiPause extends Command
{
    protected $signature = 'hq:ai:pause {--by= : email of the person pausing} {--reason= : why, kept in the audit log} {--show : print the state and change nothing}';

    protected $description = 'Pause every AI execution for the whole group (human-only, audited; cannot re-enable)';

    public function handle(AiPauseSwitch $switch, PauseState $state): int
    {
        if ($this->option('show')) {
            $this->line(self::describe($state->state()));

            return self::SUCCESS;
        }

        $by = self::person($this, (string) $this->option('by'));
        if ($by === null) {
            return self::FAILURE;
        }

        try {
            $after = $switch->pause($by, (string) $this->option('reason'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(self::describe($after));

        return self::SUCCESS;
    }

    /** The named person, or null after saying why there is none. */
    public static function person(Command $command, string $email): ?User
    {
        if (trim($email) === '') {
            $command->error('Name the person with --by=<email>: this switch is human-only and acts on every business, so the record must say who.');

            return null;
        }
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $command->error("No user with email [{$email}].");
        }

        return $user;
    }

    /** @param array<string, mixed> $state */
    public static function describe(array $state): string
    {
        if (! $state['readable']) {
            return 'Pause All AI: the state could not be read, so AI is treated as PAUSED.';
        }

        return $state['paused']
            ? 'Pause All AI: PAUSED since '.($state['paused_at'] ?? 'unknown').'. Reason: '.($state['pause_reason'] ?? 'none recorded')
            : 'Pause All AI: not paused. AI runs only where its own switches (ai.runtime, agents, grants) allow.';
    }
}
