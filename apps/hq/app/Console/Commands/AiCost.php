<?php

namespace App\Console\Commands;

use App\Ai\Budget\AiBudget;
use Illuminate\Console\Command;

/**
 * What HQ has spent on models, broken down along every dimension `ai_runs` already carries.
 *
 * The rollup works because a run records its correlation id, business, agent, task and input ref, so
 * cost per run, per agent, per business, per task and per scheduled job all fall out of the same
 * rows without a separate accounting pipeline (docs/48 J.3).
 */
class AiCost extends Command
{
    protected $signature = 'hq:ai:cost {--period=monthly : daily or monthly}';

    protected $description = 'Report AI spend and token usage by model, tier, task, agent and business.';

    public function handle(AiBudget $budget): int
    {
        $period = in_array($this->option('period'), ['daily', 'monthly'], true) ? $this->option('period') : 'monthly';
        $report = $budget->report($period);

        $this->line("<comment>AI spend, {$report['period']} since {$report['since']}</comment>");
        $this->line(sprintf('  %d runs, $%s total, %d ran degraded',
            $report['runs'], number_format($report['total_usd'], 6), $report['degraded_runs']));

        if ($report['runs'] === 0) {
            $this->newLine();
            $this->info('No model has been called in this period.');

            return self::SUCCESS;
        }

        foreach (['by_model' => 'Model', 'by_tier' => 'Tier', 'by_task' => 'Task', 'by_agent' => 'Agent', 'by_business' => 'Business', 'by_status' => 'Status'] as $key => $label) {
            if ($report[$key] === []) {
                continue;
            }

            $this->newLine();
            $this->table(
                [$label, 'runs', 'input', 'output', 'cached', 'USD'],
                array_map(fn (array $r) => [
                    $r['key'], $r['runs'],
                    number_format($r['input_tokens']), number_format($r['output_tokens']), number_format($r['cached_tokens']),
                    number_format($r['usd'], 6),
                ], $report[$key]),
            );
        }

        $this->newLine();
        $pressure = $budget->pressure();
        $this->line(sprintf('Cap (%s): $%s of $%s, %d%%',
            $pressure['scope'],
            number_format($pressure['spent'], 4),
            is_finite($pressure['cap']) ? number_format($pressure['cap'], 2) : 'unbounded',
            $pressure['share_percent'],
        ));

        return self::SUCCESS;
    }
}
