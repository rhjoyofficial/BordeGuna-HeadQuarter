<?php

namespace App\Console\Commands;

use App\Identity\Quarantine\CrossBusinessDetector;
use App\Identity\Quarantine\Finding;
use App\Identity\Quarantine\QuarantineLedger;
use Illuminate\Console\Command;

/**
 * docs/117 (ruling docs/110 §5 (m)): lists every relationship that crosses a business boundary and, only
 * with --flag, writes a quarantine flag for each one not flagged yet (append-only, audited, idempotent).
 *
 * Dry run is the default and writes nothing. Neither mode undoes, re-points or deletes anything, and
 * neither is needed for suppression: the Customer 360 reader suppresses these relationships on its own.
 */
final class IdentityCrossBusiness extends Command
{
    protected $signature = 'hq:identity:cross-business
        {--dry-run : list findings and write nothing (the default)}
        {--flag : write a quarantine flag for each finding not flagged yet}';

    protected $description = 'Detect relationships that cross a business boundary (pre-guard merges and what they moved); optionally flag them for review.';

    public function handle(CrossBusinessDetector $detector, QuarantineLedger $ledger): int
    {
        if ($this->option('flag') && $this->option('dry-run')) {
            $this->error('Choose one: --dry-run (list only) or --flag (write flags).');

            return self::INVALID;
        }

        $findings = $detector->detect();

        if ($findings === []) {
            $this->info('No relationship crosses a business boundary. Nothing to flag.');

            return self::SUCCESS;
        }

        $this->table(
            ['kind', 'subject', 'customer', 'customer business', 'row business', 'merge', 'reason'],
            array_map(fn (Finding $f) => [
                $f->kind, "{$f->subjectTable}#{$f->subjectId}", $f->customerId, $f->customerBusinessId,
                $f->rowBusinessId ?? '-', $f->mergeId ?? 'no merge lineage', $f->reason,
            ], $findings),
        );
        $this->line(count($findings).' finding(s).');

        if (! $this->option('flag')) {
            $this->comment('Dry run: nothing written. Re-run with --flag to record quarantine flags for review.');

            return self::SUCCESS;
        }

        $written = $ledger->flag($findings);
        $this->info(count($written).' flag(s) written, '.(count($findings) - count($written)).' already flagged.');

        return self::SUCCESS;
    }
}
