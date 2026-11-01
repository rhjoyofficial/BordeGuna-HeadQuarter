<?php

namespace App\Console\Commands;

use App\Autonomy\Ladder\LadderGrantLapse;
use Illuminate\Console\Command;

/**
 * Checks every active ladder autonomy grant and lapses each one whose governing conditions no longer hold
 * (ChatGPT ruling 17, 31 Oct 2026). The resolver already refuses and lapses a failing grant at its next
 * decision; this makes the lapse happen even when no decision is asked for, and first writes every lapse that
 * was decided but could not be written (docs/150). It is recovery, not the safety boundary.
 *
 * ⚠ It only ever lapses. It cannot grant, renew, reactivate or widen anything, and with no ladder grant
 * (the shipped state) it does nothing.
 */
final class AutonomyLapseSweep extends Command
{
    protected $signature = 'hq:autonomy:lapse-sweep';

    protected $description = 'Lapse (one-way) every ladder autonomy grant whose evidence, threshold, pause state or safety condition fails. Never grants.';

    public function handle(LadderGrantLapse $lapse): int
    {
        $this->line('lapsed '.$lapse->sweep());

        return self::SUCCESS;
    }
}
