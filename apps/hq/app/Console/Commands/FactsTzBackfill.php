<?php

namespace App\Console\Commands;

use App\Facts\TimeZone\TzBackfillApplier;
use App\Facts\TimeZone\TzBackfillPlanner;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * docs/133 (ruling docs/131 section 2 item 8): the time-zone backfill of `facts_orders` timestamps the old code
 * stored six hours late (W5-TZ, docs/125).
 *
 * ⭐ A DRY RUN unless `--apply` is given; the dry run writes nothing. It prints, from the stored source evidence
 *    only (`TzBackfillPlanner`): each correctable timestamp with the shop's raw string, the stored value and the
 *    corrected UTC value; the corrections by business and Dhaka day; delivered sales per day before and after;
 *    totals; and every ambiguous timestamp with its reason. No personal value is printed.
 *
 * ⛔ `--apply` is Joy's decision, after he has read the dry run, and needs the batch key it printed, `--by` (the
 *    HQ user whose decision it is) and `--reason`. Ambiguous timestamps are never changed (`TzBackfillApplier`).
 */
final class FactsTzBackfill extends Command
{
    protected $signature = 'hq:facts:tz-backfill
        {--json : print the plan as JSON}
        {--apply : correct the unambiguous timestamps of ONE reviewed batch (needs --key, --by and --reason)}
        {--key= : the batch key the dry run printed}
        {--by= : email of the HQ user whose decision this is}
        {--reason= : why, recorded on every correction row and the audit row}';

    protected $description = 'Dry run of the facts_orders time-zone backfill (ruling docs/131 item 8): evidence-only corrections, ambiguous rows listed; --apply writes one reviewed batch.';

    public function handle(TzBackfillPlanner $planner, TzBackfillApplier $applier): int
    {
        if (! $this->option('apply')) {
            $plan = $planner->plan();
            if ($this->option('json')) {
                $this->line((string) json_encode($plan + ['dry_run' => true, 'written' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $this->line('<bg=blue;fg=white> DRY RUN </> nothing is written.');
            $this->printPlan($plan);
            $this->comment($plan['correctable'] === []
                ? 'Dry run: nothing written. Nothing is correctable from the evidence; there is nothing to apply.'
                : 'Dry run: nothing written. To apply this exact batch (Joy-authorized only): '
                    ."php artisan hq:facts:tz-backfill --apply --key={$plan['batch_key']} --by=<email> --reason=\"<why>\"");

            return self::SUCCESS;
        }

        $key = (string) $this->option('key');
        $email = (string) $this->option('by');
        $reason = (string) $this->option('reason');
        if ($key === '' || $email === '' || trim($reason) === '') {
            $this->error('--apply needs --key=<batch key from the dry run>, --by=<email of the person> and --reason="<why>". Nothing was changed.');

            return self::INVALID;
        }
        $actor = User::query()->where('email', $email)->first();
        if ($actor === null) {
            $this->error("No HQ user [{$email}]. Nothing was changed.");

            return self::INVALID;
        }

        try {
            $result = $applier->apply($key, $actor, $reason);
        } catch (Throwable $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->line('<bg=yellow;fg=black> APPLY </> batch '.$key.': '.$result['status'].', '.$result['changed'].' timestamp(s) corrected.');
        if (isset($result['verification'])) {
            $v = $result['verification'];
            $this->line('verification: '.($v['ok'] ? "ok ({$v['checks']} checks)" : 'FAILED: '.implode('; ', $v['failures'])));
        }
        $this->line(($result['plan']['totals']['ambiguous'] ?? 0).' ambiguous timestamp(s) left untouched.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $plan */
    private function printPlan(array $plan): void
    {
        $t = $plan['totals'];
        $this->line("Generated {$plan['generated_at']}; days in {$plan['timezone']}.");
        $this->line("facts_orders rows {$t['facts_orders_rows']}; timestamps examined {$t['timestamps_examined']}; already correct by evidence {$t['verified_correct']}; "
            ."correctable {$t['correctable']}; ambiguous {$t['ambiguous']}.");
        foreach ($t['ambiguous_by_reason'] as $why => $n) {
            $this->line("  ambiguous, {$why}: {$n}");
        }
        $this->line("Batch key {$plan['batch_key']}");

        $this->newLine();
        $this->info('Corrections by business and Dhaka day of the stored value:');
        if ($plan['by_business_day'] === []) {
            $this->line('  none');
        }
        foreach ($plan['by_business_day'] as $g) {
            $cols = implode(', ', array_map(fn ($c, $n) => "{$c} {$n}", array_keys($g['by_column']), $g['by_column']));
            $this->line("  {$g['business']} {$g['stored_dhaka_day']}: {$g['rows']} row(s), {$g['timestamps']} timestamp(s) ({$cols}); {$g['moving_to_another_day']} move to another day");
        }

        $this->newLine();
        $this->info('Delivered sales per business and Dhaka day (the contribution report\'s source), before and after:');
        if ($plan['delivered_sales'] === []) {
            $this->line('  no delivered sale changes day');
        }
        foreach ($plan['delivered_sales'] as $d) {
            $this->line("  {$d['business']} {$d['dhaka_day']}: before {$d['before']['orders']} order(s) {$d['before']['net_revenue']} BDT, after {$d['after']['orders']} order(s) {$d['after']['net_revenue']} BDT");
        }

        $this->newLine();
        $this->info(count($plan['correctable']).' correctable timestamp(s):');
        foreach ($plan['correctable'] as $c) {
            $this->line("  facts_orders#{$c['facts_order_id']} [{$c['business']}] {$c['column']}: source \"{$c['source_string']}\" (event {$c['source_event_id']}); "
                ."stored {$c['stored_utc']} UTC (Dhaka day {$c['stored_dhaka_day']}), corrected {$c['expected_utc']} UTC (Dhaka day {$c['expected_dhaka_day']}), shift {$c['shift_seconds']}s");
        }

        $this->newLine();
        $this->info(count($plan['ambiguous']).' ambiguous timestamp(s), never changed:');
        foreach ($plan['ambiguous'] as $a) {
            $strings = $a['source_strings'] === [] ? 'no source string' : 'source '.implode(' | ', array_map(fn ($s) => "\"{$s}\"", $a['source_strings']));
            $this->line("  facts_orders#{$a['facts_order_id']} [{$a['business']}] {$a['column']}: {$a['reason']}; stored {$a['stored_utc']} UTC; {$strings}");
        }
    }
}
