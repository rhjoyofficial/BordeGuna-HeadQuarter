<?php

namespace App\Console\Commands;

use App\Erp\ErpRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Describes what the ERP actually sends, key by key, so a mapping can be audited against the real
 * contract instead of against the DTO that was written from the same design document.
 *
 * The distinction this command exists to make visible is **absent versus null**. Both read as
 * `null` through `$data['x'] ?? null`, and they mean opposite things: a key the ERP deliberately
 * nulls (a permission boundary, an empty section) is the integration working, while a key that has
 * silently stopped being sent is the integration broken. Only the raw envelope can tell them apart.
 *
 * Read-only: it calls the same authenticated connector production uses, and prints structure by
 * default. `--values` adds the values, which are business figures rather than secrets, but they are
 * off by default so a routine audit does not put the shop's numbers on someone's screen.
 */
final class ErpInspect extends Command
{
    protected $signature = 'hq:erp:inspect
        {business? : HQ business slug; every business with a stored token when omitted}
        {--endpoint=* : overview, low_stock, businesses, reports. All four when omitted.}
        {--values : also print each value, not only its type}';

    protected $description = 'Describe the ERP API response as it actually arrives (read-only contract audit).';

    private const ENDPOINTS = ['overview', 'low_stock', 'businesses', 'reports'];

    public function handle(ErpRegistry $registry): int
    {
        $slugs = $this->argument('business') ? [$this->argument('business')] : $registry->businessesWithConnectors();
        if ($slugs === []) {
            $this->warn('No business has an active erp_api_token credential yet.');

            return self::SUCCESS;
        }

        $endpoints = $this->option('endpoint') ?: self::ENDPOINTS;
        $failed = 0;

        foreach ($slugs as $slug) {
            foreach ($endpoints as $endpoint) {
                $this->newLine();
                $this->line("=== {$slug} :: {$endpoint} ===");
                try {
                    $raw = $registry->for($slug)->rawRead($endpoint);
                } catch (Throwable $e) {
                    $failed++;
                    $this->error('  '.class_basename($e).': '.$e->getMessage());

                    continue;
                }

                foreach ($this->describe($raw) as $line) {
                    $this->line('  '.$line);
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One line per leaf, plus a line per container, with the dotted path and the PHP type. A list
     * is summarised by its length and its first element's shape, because a hundred identical rows
     * say nothing a single row does not.
     *
     * @return list<string>
     */
    private function describe(mixed $value, string $path = '$'): array
    {
        if (! is_array($value)) {
            return [sprintf('%-46s %s%s', $path, get_debug_type($value), $this->option('values') ? ' = '.$this->render($value) : '')];
        }

        if ($value === []) {
            return [sprintf('%-46s empty array (ambiguous: no rows, or no data key)', $path)];
        }

        if (array_is_list($value)) {
            $lines = [sprintf('%-46s list(%d)', $path, count($value))];
            foreach ($this->describe($value[0], $path.'[0]') as $line) {
                $lines[] = $line;
            }

            return $lines;
        }

        $lines = [sprintf('%-46s object{%s}', $path, implode(', ', array_keys($value)))];
        foreach ($value as $key => $child) {
            foreach ($this->describe($child, $path.'.'.$key) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"'.$value.'"',
            default => (string) $value,
        };
    }
}
