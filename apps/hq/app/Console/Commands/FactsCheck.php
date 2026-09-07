<?php

namespace App\Console\Commands;

use App\Facts\FactsIntegrityChecker;
use App\Models\Store;
use Illuminate\Console\Command;

/**
 * The order-fact arithmetic check, for a person looking now rather than the nightly job.
 *
 * Exits non-zero when it finds something, so it is usable as a gate after a release or a repair.
 * It never writes to business data.
 */
final class FactsCheck extends Command
{
    protected $signature = 'hq:facts:check
        {--store= : store slug; every store when omitted}
        {--hours=168 : how far back to look, by placed_at}
        {--limit=1000 : most facts to read in one run}';

    protected $description = 'Check order facts add up (read-only; reports, never repairs).';

    public function handle(FactsIntegrityChecker $checker): int
    {
        $slug = $this->option('store');
        $store = null;
        if ($slug !== null) {
            $store = Store::where('slug', $slug)->first();
            if (! $store) {
                $this->error("Unknown store [{$slug}].");

                return self::FAILURE;
            }
        }

        $since = now()->subHours((int) $this->option('hours'))->toImmutable();
        $result = $checker->check($store, $since, (int) $this->option('limit'));

        $this->info(sprintf('checked %d fact(s) since %s: %d ok, %d without line detail, %d discrepancy(ies)',
            $result['checked'], $since->toDateTimeString(), $result['ok'], $result['without_lines'], $result['discrepancies']));

        foreach ($result['findings'] as $f) {
            $this->warn("  [{$f['store']}] {$f['order_ref']}  {$f['code']}: {$f['detail']}");
        }

        if ($result['discrepancies'] > 0) {
            $this->newLine();
            $this->line('Reported, not repaired. Deciding which side of a disagreement is right is a person\'s job.');
        }

        return $result['discrepancies'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
