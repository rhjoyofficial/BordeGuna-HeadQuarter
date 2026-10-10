<?php

namespace App\Console\Commands;

use App\Evaluation\EvaluationCostAttributor;
use App\Models\EvaluationRun;
use Illuminate\Console\Command;

/**
 * Recomputes `evaluation_runs` cost columns from the `ai_runs` evidence that already exists (docs/106).
 *
 * Every evaluation run before docs/106 recorded `cost_usd = 0` because nothing wrote it. This command
 * reads, for each finished run, the model calls its results lead to (`EvaluationCostAttributor`) and
 * shows what the run cost against what it records.
 *
 * ⭐ **A dry run unless `--apply` is given.** Nothing is written without it.
 *
 * ⛔ **Never invents a cost.** It computes only from `ai_runs` rows reached through a result's
 * correlation or its linked `ai_run_id`. A run with any result it cannot account for (no evidence and no
 * recorded `model_turns = 0`) is listed as incomplete and NEVER written, even with `--apply`: a partial
 * sum stored in `cost_usd` would read as the run's cost. No time-window or average is used to fill a gap.
 *
 * ⛔ Writes the four cost columns only. No status, rate, count, verdict or autonomy input changes.
 */
class EvaluationAttributeCost extends Command
{
    protected $signature = 'hq:evaluation:attribute-cost
        {--run=* : Only these evaluation run ids (default: every finished run)}
        {--apply : Write the computed cost. Without it, nothing is written}';

    protected $description = 'Attribute evaluation run cost from existing ai_runs evidence (dry run unless --apply; complete runs only)';

    public function handle(EvaluationCostAttributor $attributor): int
    {
        $apply = (bool) $this->option('apply');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('run'))));

        $this->line($apply
            ? '<bg=yellow;fg=black> APPLY </> complete runs are written; incomplete runs are left untouched.'
            : '<bg=blue;fg=white> DRY RUN </> nothing is written. Re-run with --apply to write complete runs.');

        $runs = EvaluationRun::query()
            ->whereNotNull('finished_at')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        $rows = [];
        $written = 0;
        $incomplete = 0;

        foreach ($runs as $run) {
            $computed = $attributor->compute($run);
            $meta = $computed['cost_attribution'];
            $complete = (bool) $meta['complete'];

            $action = match (true) {
                ! $complete => 'skipped: incomplete evidence',
                $apply => 'written',
                default => 'would write',
            };

            if ($complete && $apply) {
                $run->forceFill($computed)->save();
                $written++;
            }
            if (! $complete) {
                $incomplete++;
            }

            $rows[] = [
                $run->id,
                $run->status,
                (string) $run->cost_usd,
                $computed['cost_usd'],
                $computed['cost_estimated_usd'],
                $computed['cost_provider_usd'] ?? 'null',
                $meta['ai_runs'],
                "{$meta['results_linked']}/{$meta['results_without_model_call']}/{$meta['results_unaccounted']}",
                $action,
            ];
        }

        $this->table(
            ['run', 'status', 'recorded cost_usd', 'computed cost_usd', 'estimated', 'provider', 'ai_runs', 'results linked/no-call/unaccounted', 'action'],
            $rows,
        );

        $this->line(count($runs).' run(s) read; '.($apply ? "{$written} written" : 'none written (dry run)')
            ."; {$incomplete} incomplete (never written).");

        return self::SUCCESS;
    }
}
