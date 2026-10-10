<?php

namespace App\Console\Commands;

use App\Decisions\DecisionCorpusReader;
use App\Decisions\DecisionCorpusWindow;
use Illuminate\Console\Command;

/**
 * The decision-corpus report in the terminal (docs/111 §5): the same numbers the owner page shows,
 * group-wide (the console has no viewer to scope), printed and never stored. `--json` prints the
 * reader's array as it is, for pasting into a record.
 */
class DecisionsReport extends Command
{
    protected $signature = 'hq:decisions:report
        {--window=90d : 30d, 90d, 365d or all}
        {--business= : one business id}
        {--json : print JSON}';

    protected $description = 'Print what people kept, edited and refused of the agents\' proposals, and which corrections recur. Reads only.';

    public function handle(DecisionCorpusReader $reader): int
    {
        $window = (string) $this->option('window');
        if (! array_key_exists($window, DecisionCorpusWindow::OPTIONS)) {
            $this->error('Unknown window; use one of '.implode(', ', array_keys(DecisionCorpusWindow::OPTIONS)).'.');

            return self::INVALID;
        }
        $business = $this->option('business');
        $report = $reader->read(DecisionCorpusWindow::from($window), null, is_string($business) && ctype_digit($business) ? (int) $business : null);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $t = $report['totals'];
        $this->line("Decision corpus, window {$report['window']['key']} (".($report['window']['since'] ?? 'the beginning').' to '.$report['window']['until'].')');
        $this->line("records {$t['records']}: kept {$t['kept']}, edited {$t['edited']}, rejected {$t['rejected']}"
            .($t['edit_rate'] === null ? '' : sprintf(', edit rate %.1f%%, rejection rate %.1f%%', 100 * $t['edit_rate'], 100 * $t['rejection_rate'])));
        $this->line('stored decision words: '.collect($t['by_stored_decision'])->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
        if ($report['scope']['unscoped_records'] > 0) {
            $this->line("unscoped records (context gone or without a business): {$report['scope']['unscoped_records']}");
        }

        $this->section('By agent', $report['by_agent'], fn (array $r) => [$r['agent_key'].($r['role_key'] ? " ({$r['role_key']})" : ''), $r['records'], $r['kept'], $r['edited'], $r['rejected']]);
        $this->section('By tool', $report['by_tool'], fn (array $r) => [$r['tool'], $r['records'], $r['kept'], $r['edited'], $r['rejected']]);
        $this->section('By proposal kind', $report['by_kind'], fn (array $r) => [$r['kind'], $r['records'], $r['kept'], $r['edited'], $r['rejected']]);
        $this->section('By business', $report['by_business'], fn (array $r) => [($r['business'] ?? '(unscoped)').($r['brand'] ? " / {$r['brand']}" : ''), $r['records'], $r['kept'], $r['edited'], $r['rejected']]);

        $this->newLine();
        $this->line('Edit categories (an edit may carry several):');
        if ($report['categories'] === []) {
            $this->line('  none');
        }
        foreach ($report['categories'] as $c) {
            $this->line(sprintf('  %-48s %4d  %5.1f%%  e.g. %s', $c['label'], $c['edits'], 100 * ($c['share'] ?? 0), $this->refs($c['examples'])));
        }

        $this->newLine();
        $this->line('Argument keys people change:');
        if ($report['fields'] === []) {
            $this->line('  none');
        }
        foreach ($report['fields'] as $f) {
            $this->line(sprintf('  %-32s %-20s %4d  e.g. %s', $f['tool'], $f['key'], $f['edits'], $this->refs($f['examples'])));
        }

        $this->newLine();
        $this->line('Recurring patterns (agent, tool, category seen at least twice):');
        if ($report['patterns'] === []) {
            $this->line('  none');
        }
        foreach ($report['patterns'] as $p) {
            $this->line(sprintf('  %-12s %-28s %-48s %4d  %s  e.g. %s', $p['agent_key'] ?? '(none)', $p['tool'], $p['label'], $p['count'],
                $p['businesses'] === [] ? '' : '['.implode(', ', $p['businesses']).']', $this->refs($p['examples'])));
        }

        $g = $report['gaps'];
        $this->newLine();
        $this->line("Gaps: shadow reviews marked edited without the correction {$g['shadow_reviews_edited_without_text']}, edits without a delta {$g['edits_without_delta']}, "
            ."records whose context row is gone {$g['contexts_missing']}, edits with a reason {$g['edits_with_reason']}.");

        return self::SUCCESS;
    }

    private function section(string $title, array $rows, callable $cells): void
    {
        $this->newLine();
        $this->line($title.':');
        if ($rows === []) {
            $this->line('  none');

            return;
        }
        $this->table(['', 'records', 'kept', 'edited', 'rejected'], array_map($cells, $rows));
    }

    private function refs(array $examples): string
    {
        return implode(', ', array_map(fn (array $e) => "#{$e['id']} {$e['context_type']} {$e['context_id']}", $examples));
    }
}
