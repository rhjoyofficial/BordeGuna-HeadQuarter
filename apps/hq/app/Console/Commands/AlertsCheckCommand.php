<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Observe\Alerts\AlertEngine;
use Illuminate\Console\Command;

/**
 * Checks every alert rule once (docs/48 N.5, docs/78 B.2), every five minutes from the scheduler.
 * Opens, counts and resolves alerts; sends only what HQ itself pages. Reads everything else.
 */
class AlertsCheckCommand extends Command
{
    protected $signature = 'hq:alerts:check {--rule=* : check only these rule keys}';

    protected $description = 'Check the alert rules: open, count and resolve alerts, and send the ones HQ pages.';

    public function handle(AlertEngine $engine): int
    {
        $summary = $engine->check(only: array_values(array_filter((array) $this->option('rule'))));

        foreach (['opened', 'still_open', 'cleared', 'resolved'] as $bucket) {
            foreach ($summary[$bucket] as $fingerprint) {
                $this->line(str_pad(str_replace('_', ' ', $bucket), 11).$fingerprint);
            }
        }
        foreach ($summary['unknown'] as $rule) {
            $this->line("unknown    {$rule} (could not tell; its alerts were left as they were)");
        }
        foreach ($summary['failed'] as $rule) {
            $this->warn("failed     {$rule} (see the application log; its alerts were left as they were)");
        }

        $this->info(Alert::open()->count().' alert(s) open.');

        return self::SUCCESS;
    }
}
