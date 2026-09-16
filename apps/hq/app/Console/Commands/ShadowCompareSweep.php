<?php

namespace App\Console\Commands;

use App\Conversation\ShadowComparisons;
use Illuminate\Console\Command;

/**
 * The daily half of the P3-G3 pairing (docs/78 B.1): a support-agent proposal that nothing was sent
 * for within a day becomes an `unmeasured` comparison, so the channel-shadow week counts silence
 * honestly instead of leaving it out. Writes nothing while the support agent has made no proposals,
 * which is all of today.
 */
class ShadowCompareSweep extends Command
{
    protected $signature = 'hq:conversation:compare-sweep';

    protected $description = 'Record support-agent proposals that nothing was sent for within a day as unmeasured shadow comparisons.';

    public function handle(ShadowComparisons $comparisons): int
    {
        $written = $comparisons->sweepUnmeasured();
        $this->line("{$written} unmeasured comparison(s) recorded.");

        return self::SUCCESS;
    }
}
