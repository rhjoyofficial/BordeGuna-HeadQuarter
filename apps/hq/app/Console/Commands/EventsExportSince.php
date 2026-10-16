<?php

namespace App\Console\Commands;

use App\Support\Events\EventBridgeFile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The first half of the restore-safe event bridge (docs/132).
 *
 * A restore to the pre-deploy dump brings back the `events` table as it was when the dump was taken.
 * Every webhook HQ accepted after that moment answered 202 to its shop, so the shop will not send it
 * again, and the restore would silently drop it. This command copies those rows, payload and all, to
 * a JSONL file BEFORE the restore, so `hq:events:import` can put them back afterwards.
 *
 * Read only: it issues SELECTs and writes one new file, never a database row, never an audit record.
 * Only inbound rows are copied. An outbound row is something HQ produced itself; the rows it was
 * produced from are lost by the same restore, and processing the imported inbound rows produces it
 * again. Nothing personal reaches stdout: counts, row ids, event types and the checksum only.
 */
final class EventsExportSince extends Command
{
    protected $signature = 'hq:events:export-since
        {--since-id= : copy inbound events whose row id is greater than this (the highest events.id in the dump)}
        {--since= : copy inbound events that arrived at or after this time (ISO 8601; overlap with the dump is harmless, import skips identical rows)}
        {--out= : file to write (must not exist). Default storage/app/event-bridge/events-<stamp>.jsonl}';

    protected $description = 'Copy inbound events received after a point to a checksummed JSONL file (read only), for hq:events:import after a restore.';

    public function handle(): int
    {
        $sinceId = $this->option('since-id');
        $since = $this->option('since');
        if (($sinceId === null || $sinceId === '') === ($since === null || $since === '')) {
            $this->error('give exactly one of --since-id=<events.id> or --since=<ISO 8601 time>.');

            return self::INVALID;
        }
        if ($sinceId !== null && $sinceId !== '' && ! ctype_digit((string) $sinceId)) {
            $this->error('--since-id must be a whole number (an events.id).');

            return self::INVALID;
        }
        $sinceAt = null;
        if ($since !== null && $since !== '') {
            try {
                $sinceAt = CarbonImmutable::parse((string) $since)->utc();
            } catch (Throwable) {
                $this->error('--since is not a time PHP can read; use ISO 8601, e.g. 2026-10-16T01:00:00Z.');

                return self::INVALID;
            }
        }

        $out = (string) ($this->option('out') ?: storage_path('app/event-bridge/events-'.now()->utc()->format('Ymd\THis\Z').'.jsonl'));
        if (file_exists($out)) {
            $this->error("{$out} already exists; an export never overwrites a file.");

            return self::INVALID;
        }
        $dir = dirname($out);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("cannot create {$dir}.");

            return self::FAILURE;
        }

        $query = DB::table('events')
            ->select(['id', 'event_id', 'type', 'direction', 'source', 'aggregate_type', 'aggregate_id', 'business_id', 'dedupe_key', 'schema_version', 'occurred_at', 'created_at', 'status', 'attempts'])
            ->selectRaw('payload::text AS payload_json')
            ->where('direction', 'inbound')
            ->when($sinceId !== null && $sinceId !== '', fn ($q) => $q->where('id', '>', (int) $sinceId))
            ->when($sinceAt !== null, fn ($q) => $q->where('created_at', '>=', $sinceAt));

        $handle = fopen($out, 'x');
        if ($handle === false) {
            $this->error("cannot open {$out} for writing.");

            return self::FAILURE;
        }
        @chmod($out, 0600);

        $writer = new EventBridgeFile;
        $header = $writer->header([
            'exported_at' => now()->utc()->toIso8601String(),
            'since_id' => ($sinceId !== null && $sinceId !== '') ? (int) $sinceId : null,
            'since' => $sinceAt?->toIso8601String(),
            'direction' => 'inbound',
        ]);
        fwrite($handle, $header."\n");

        $rows = 0;
        $minId = null;
        $maxId = null;
        $types = [];
        $statuses = [];
        foreach ($query->lazyById(500, 'id') as $row) {
            $line = $writer->rowLine($row);
            fwrite($handle, $line."\n");
            $rows++;
            $minId ??= (int) $row->id;
            $maxId = (int) $row->id;
            $types[$row->type] = ($types[$row->type] ?? 0) + 1;
            $statuses[$row->status] = ($statuses[$row->status] ?? 0) + 1;
        }
        fwrite($handle, $writer->trailer($rows)."\n");
        fclose($handle);

        ksort($types);
        ksort($statuses);
        $this->line("exported {$rows} inbound event(s) to {$out}");
        $this->line('  source row ids: '.($rows ? "{$minId}..{$maxId}" : 'none'));
        foreach ($types as $type => $n) {
            $this->line("  type {$type}: {$n}");
        }
        foreach ($statuses as $status => $n) {
            $this->line("  status at export {$status}: {$n}");
        }
        $this->line('  sha256 '.$writer->checksum());
        $this->info('read only: no database row was written. Import after the restore with: php artisan hq:events:import '.$out);

        return self::SUCCESS;
    }
}
