<?php

namespace App\Console\Commands;

use App\Ai\Pause\AiPauseSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The distinct re-enable after Pause All AI (docs/138 ruling 7, docs/141): its own command, its own
 * permission (hq.ai.resume), group-wide authority, a reason and an audit row. It lifts the pause only; it
 * does not turn `ai.runtime` on or change any agent, grant, flag or tool.
 */
final class AiResume extends Command
{
    protected $signature = 'hq:ai:resume {--by= : email of the person turning AI back on} {--reason= : why, kept in the audit log}';

    protected $description = 'Turn AI back on after Pause All AI (a separate permission; human-only, audited)';

    public function handle(AiPauseSwitch $switch): int
    {
        $by = AiPause::person($this, (string) $this->option('by'));
        if ($by === null) {
            return self::FAILURE;
        }

        try {
            $after = $switch->resume($by, (string) $this->option('reason'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(AiPause::describe($after));

        return self::SUCCESS;
    }
}
