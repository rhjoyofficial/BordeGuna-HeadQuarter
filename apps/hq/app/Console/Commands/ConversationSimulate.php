<?php

namespace App\Console\Commands;

use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\ScenarioLibrary;
use Illuminate\Console\Command;

/**
 * Replays the simulation world through the conversation core and rolls back (docs/72 addendum).
 *
 * `hq:conversation:simulate` is gate P3-G1's driver: every scripted thread, the real core, no model,
 * no provider, no trace. Exit 1 when any scenario misses, so CI and the gate can read it. It counts
 * every table a replay writes before and after and fails on any difference (docs/77 C.3.2), and with
 * `--repeat=N` it replays N times and fails unless every replay observed the same thing. `--keep`
 * commits the replay for a person to look at in the inbox, and is refused on production, where a
 * simulated customer must never sit beside a real one.
 */
class ConversationSimulate extends Command
{
    protected $signature = 'hq:conversation:simulate
        {--brand=LLD : the brand code whose simulated pages the threads arrive on}
        {--only= : run one scenario key}
        {--keep : commit the replay instead of rolling it back (never on production)}
        {--repeat=1 : replay the library this many times, each in its own transaction, and require identical observations}
        {--json : print the results as JSON}';

    protected $description = 'Replay the scripted conversation scenarios through the conversation core, with no model and no provider, and roll back';

    public function handle(ConversationSimulator $simulator): int
    {
        if ($this->option('keep') && app()->isProduction()) {
            $this->error('--keep is refused on production: a simulated customer must never sit beside a real one.');

            return self::FAILURE;
        }

        $scenarios = ScenarioLibrary::all();
        if (($only = $this->option('only')) !== null) {
            $scenarios = array_values(array_filter($scenarios, fn ($s) => $s->key === $only));
            if ($scenarios === []) {
                $this->error("No scenario is keyed [{$only}].");

                return self::FAILURE;
            }
        }

        $repeat = max(1, (int) $this->option('repeat'));
        $keep = (bool) $this->option('keep');
        if ($keep && $repeat > 1) {
            $this->error('--keep and --repeat together would commit the library more than once.');

            return self::FAILURE;
        }

        $before = ConversationSimulator::trace();
        $replays = [];
        for ($i = 0; $i < $repeat; $i++) {
            $replays[] = $simulator->replay($scenarios, (string) $this->option('brand'), $keep);
        }
        $after = ConversationSimulator::trace();

        $results = $replays[0];
        $failed = array_values(array_filter($results, fn ($r) => ! $r->passed));

        // Every later replay must have observed exactly what the first one did.
        $drift = [];
        foreach (array_slice($replays, 1) as $n => $replay) {
            foreach ($replay as $index => $result) {
                if ($this->comparable($result->observed) !== $this->comparable($results[$index]->observed)) {
                    $drift[] = sprintf('%s differs on replay %d', $result->key, $n + 2);
                }
            }
        }

        $traceDiff = [];
        if (! $keep) {
            foreach ($before as $table => $count) {
                if (($after[$table] ?? null) !== $count) {
                    $traceDiff[] = sprintf('%s: %s before, %s after', $table, var_export($count, true), var_export($after[$table] ?? null, true));
                }
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'scenarios' => count($results),
                'passed' => count($results) - count($failed),
                'failed' => count($failed),
                'kept' => $keep,
                'repeat' => $repeat,
                'deterministic' => $drift === [],
                'drift' => $drift,
                'trace' => ['clean' => $traceDiff === [], 'differences' => $traceDiff, 'before' => $before, 'after' => $after],
                'results' => array_map(fn ($r) => [
                    'key' => $r->key, 'passed' => $r->passed, 'mismatches' => $r->mismatches,
                    'observed' => $this->comparable($r->observed),
                ], $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['scenario', 'result', 'state', 'transitions', 'mismatches'], array_map(fn ($r) => [
                $r->key,
                $r->passed ? 'pass' : 'FAIL',
                $r->observed['state']?->value ?? '-',
                $r->observed['transitions'].' / audited '.$r->observed['audited'],
                implode('; ', $r->mismatches),
            ], $results));
            $this->line(sprintf('%d scenarios, %d passed, %d failed, %s.', count($results), count($results) - count($failed), count($failed), $keep ? 'kept' : 'rolled back'));
            $this->line($repeat > 1 ? ($drift === [] ? "{$repeat} replays, identical observations." : 'NOT DETERMINISTIC: '.implode('; ', $drift)) : 'one replay (use --repeat=2 to check determinism).');
            if (! $keep) {
                $this->line($traceDiff === [] ? 'trace: none; every table holds the rows it held before.' : 'TRACE LEFT: '.implode('; ', $traceDiff));
            }
        }

        if ($drift !== [] || $traceDiff !== []) {
            return self::FAILURE;
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** An observation with enums as their values, so two replays compare as plain data. */
    private function comparable(array $observed): array
    {
        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $observed);
    }
}
