<?php

namespace App\Console\Commands;

use App\Conversation\Simulation\ConversationSimulator;
use App\Conversation\Simulation\ScenarioLibrary;
use Illuminate\Console\Command;

/**
 * Replays the simulation world through the conversation core and rolls back (docs/72 addendum).
 *
 * `hq:conversation:simulate` is gate P3-G1's driver: every scripted thread, the real core, no model,
 * no provider, no trace. Exit 1 when any scenario misses, so CI and the gate can read it. `--keep`
 * commits the replay for a person to look at in the inbox, and is refused on production, where a
 * simulated customer must never sit beside a real one.
 */
class ConversationSimulate extends Command
{
    protected $signature = 'hq:conversation:simulate
        {--brand=LLD : the brand code whose simulated pages the threads arrive on}
        {--only= : run one scenario key}
        {--keep : commit the replay instead of rolling it back (never on production)}
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

        $results = $simulator->replay($scenarios, (string) $this->option('brand'), (bool) $this->option('keep'));
        $failed = array_values(array_filter($results, fn ($r) => ! $r->passed));

        if ($this->option('json')) {
            $this->line(json_encode([
                'scenarios' => count($results),
                'passed' => count($results) - count($failed),
                'failed' => count($failed),
                'kept' => (bool) $this->option('keep'),
                'results' => array_map(fn ($r) => [
                    'key' => $r->key, 'passed' => $r->passed, 'mismatches' => $r->mismatches,
                    'observed' => array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $r->observed),
                ], $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['scenario', 'result', 'state', 'mismatches'], array_map(fn ($r) => [
                $r->key,
                $r->passed ? 'pass' : 'FAIL',
                $r->observed['state']?->value ?? '-',
                implode('; ', $r->mismatches),
            ], $results));
            $this->line(sprintf('%d scenarios, %d passed, %d failed, %s.', count($results), count($results) - count($failed), count($failed), $this->option('keep') ? 'kept' : 'rolled back'));
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
