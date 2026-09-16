<?php

namespace App\Console\Commands;

use App\Agents\Shadow\ShadowEvidence;
use App\Agents\Shadow\ShadowPeriods;
use App\Models\ShadowPeriod;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Close an agent's open shadow period by hand: the agent paused, `ai.runtime` off, the record closed
 * and the last partial day snapshotted. Closing is the safe direction, so it asks for a reason and
 * nothing else.
 */
class ShadowClose extends Command
{
    protected $signature = 'hq:shadow:close
        {agent : the agent whose open period to close}
        {--reason= : why, in words}
        {--by= : email of the HQ user whose decision this is}';

    protected $description = 'Close an agent\'s open shadow period (agent paused, runtime off).';

    public function handle(ShadowPeriods $periods, ShadowEvidence $evidence): int
    {
        $period = ShadowPeriod::openFor((string) $this->argument('agent'));
        if ($period === null) {
            $this->error('No shadow period is open for that agent.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('Say why with --reason.');

            return self::FAILURE;
        }

        $userId = null;
        if (($email = $this->option('by')) !== null) {
            $userId = User::query()->where('email', $email)->value('id');
            if ($userId === null) {
                $this->error("No HQ user has the email [{$email}].");

                return self::FAILURE;
            }
        }

        $period = $periods->close($period, $reason, $userId);
        $days = $evidence->store($period->fresh());
        $this->info("Shadow period #{$period->id} closed; ".count($days).' day row(s) written.');

        return self::SUCCESS;
    }
}
