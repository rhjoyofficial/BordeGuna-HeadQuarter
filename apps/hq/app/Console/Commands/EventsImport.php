<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Observe\Retention\RetentionPolicy;
use App\Support\Audit\Auditor;
use App\Support\Events\EventBridgeFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The second half of the restore-safe event bridge (docs/132): puts back the inbound events a
 * restore removed, from a file `hq:events:export-since` wrote before it.
 *
 * Safety, in the order it matters:
 *  - a dry run unless `--apply` is given, and a dry run writes nothing;
 *  - the whole file is refused when its checksum fails, and the whole apply is refused when any row
 *    conflicts with what the database holds or is malformed: nothing is half imported;
 *  - a row is inserted only when neither its event_id nor its dedupe_key is present. A row present
 *    with the same type, source and payload (a shop redelivery after the restore records the same
 *    delivery under a new event_id) is skipped; one present with a different payload is a conflict;
 *  - an inserted row is `pending` with no attempt, never processed here. Processing it is the drain's
 *    job, under the resolver of the release that runs it, and only when the drain is allowed to run
 *    (docs/131 Stage D step 10, docs/132);
 *  - one audit record names the person, the reason, the file's checksum and every inserted event_id.
 *
 * Nothing personal reaches stdout: counts, source row ids and event uuids only.
 */
final class EventsImport extends Command
{
    protected $signature = 'hq:events:import
        {file : a file written by hq:events:export-since}
        {--dry-run : report what would be inserted and write nothing (the default)}
        {--apply : insert the absent rows as pending (needs --by and --reason)}
        {--by= : email of the HQ user whose decision this is}
        {--reason= : why; recorded in the audit log}';

    protected $description = 'Re-insert inbound events lost by a restore, from an hq:events:export-since file, as pending and unprocessed (dry run by default).';

    public function handle(Auditor $auditor): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('--apply and --dry-run contradict each other; give one.');

            return self::INVALID;
        }

        $actor = null;
        if ($apply) {
            $email = (string) $this->option('by');
            $reason = (string) $this->option('reason');
            if ($email === '' || trim($reason) === '') {
                $this->error('--apply needs --by=<email of the person> and --reason="<why>".');

                return self::INVALID;
            }
            $actor = User::query()->where('email', $email)->first();
            if ($actor === null) {
                $this->error('No HQ user with that email.');

                return self::INVALID;
            }
        }

        try {
            $file = EventBridgeFile::read((string) $this->argument('file'));
        } catch (RuntimeException $e) {
            $this->error('refused, nothing written: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->line(sprintf('file verified: %d row(s), sha256 %s, exported %s', count($file['rows']), $file['sha256'], $file['header']['exported_at'] ?? '?'));

        if (! $apply) {
            $plan = $this->classify($file['rows']);
            $this->report($plan);
            $this->info('dry run: nothing was written. Add --apply --by=<email> --reason="<why>" to insert the absent rows as pending.');

            return $plan['conflict'] || $plan['invalid'] ? self::FAILURE : self::SUCCESS;
        }

        $result = DB::transaction(function () use ($file, $auditor, $actor) {
            // The check and the insert see the same table: a webhook arriving during the import waits
            // for this lock (milliseconds) instead of slipping between the two.
            DB::statement('LOCK TABLE events IN SHARE ROW EXCLUSIVE MODE');
            $plan = $this->classify($file['rows']);
            if ($plan['conflict'] || $plan['invalid']) {
                return ['plan' => $plan, 'inserted' => []];
            }

            $maxAttempts = (int) config('hq.events.default_max_attempts', 5);
            $inserted = [];
            foreach ($plan['new'] as $row) {
                DB::table('events')->insert([
                    'event_id' => $row['event_id'],
                    'type' => $row['type'],
                    'direction' => 'inbound',
                    'source' => $row['source'],
                    'aggregate_type' => $row['aggregate_type'],
                    'aggregate_id' => $row['aggregate_id'],
                    'business_id' => $row['business_id'],
                    'payload' => json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                    'dedupe_key' => $row['dedupe_key'],
                    'schema_version' => $row['schema_version'],
                    'occurred_at' => $row['occurred_at'],
                    'created_at' => $row['created_at'],
                    'processed_at' => null,
                    'attempts' => 0,
                    'max_attempts' => $maxAttempts,
                    'next_attempt_at' => null,
                    'status' => 'pending',
                    'last_error' => null,
                ]);
                $inserted[] = $row['event_id'];
            }

            $auditor->record(
                action: 'events.imported',
                target: 'events',
                reason: (string) $this->option('reason'),
                actorUserId: $actor->id,
                context: [
                    'file_sha256' => $file['sha256'],
                    'exported_at' => $file['header']['exported_at'] ?? null,
                    'since_id' => $file['header']['since_id'] ?? null,
                    'since' => $file['header']['since'] ?? null,
                    'rows_in_file' => count($file['rows']),
                    'inserted' => count($inserted),
                    'already_present' => count($plan['present']),
                    'inserted_event_ids' => $inserted,
                    'status' => 'pending',
                ],
                actorLabel: $actor->email,
            );

            return ['plan' => $plan, 'inserted' => $inserted];
        });

        $this->report($result['plan']);
        if ($result['plan']['conflict'] || $result['plan']['invalid']) {
            $this->error('refused, nothing written: the file has conflicting or malformed rows (listed above).');

            return self::FAILURE;
        }
        $this->info(sprintf('inserted %d event(s) as pending; none was processed. They wait for the drain (hq:events:process), which runs only while the scheduler is up and HQ_EVENTS_DRAIN_PAUSED is not set.', count($result['inserted'])));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{new: list<array<string, mixed>>, present: list<array<string, mixed>>, conflict: list<array{row: array<string, mixed>, why: string}>, invalid: list<array{row: array<string, mixed>, why: string}>}
     */
    private function classify(array $rows): array
    {
        $plan = ['new' => [], 'present' => [], 'conflict' => [], 'invalid' => []];
        $seenIds = [];
        $seenKeys = [];
        usort($rows, fn ($a, $b) => ((int) ($a['source_row_id'] ?? 0)) <=> ((int) ($b['source_row_id'] ?? 0)));

        foreach ($rows as $row) {
            if (($why = $this->invalid($row)) !== null) {
                $plan['invalid'][] = ['row' => $row, 'why' => $why];

                continue;
            }
            if (isset($seenIds[$row['event_id']]) || isset($seenKeys[$row['dedupe_key']])) {
                $plan['invalid'][] = ['row' => $row, 'why' => 'the file holds this event_id or dedupe key twice'];

                continue;
            }
            $seenIds[$row['event_id']] = true;
            $seenKeys[$row['dedupe_key']] = true;

            $byKey = DB::table('events')->where('dedupe_key', $row['dedupe_key'])->selectRaw('event_id, type, source, payload::text AS payload_json')->first();
            $byId = DB::table('events')->where('event_id', $row['event_id'])->select(['dedupe_key'])->first();

            if ($byKey === null && $byId === null) {
                $plan['new'][] = $row;

                continue;
            }
            if ($byKey === null) {
                $plan['conflict'][] = ['row' => $row, 'why' => 'its event_id exists under a different dedupe key'];

                continue;
            }
            $same = $byKey->type === $row['type']
                && $byKey->source === $row['source']
                && EventBridgeFile::canonicalPayload(json_decode((string) $byKey->payload_json, true)) === EventBridgeFile::canonicalPayload($row['payload']);
            if (! $same) {
                $plan['conflict'][] = ['row' => $row, 'why' => 'its dedupe key exists with a different type, source or payload'];
            } elseif ($byId !== null && $byId->dedupe_key !== $row['dedupe_key']) {
                $plan['conflict'][] = ['row' => $row, 'why' => 'its event_id exists under a different dedupe key'];
            } else {
                $plan['present'][] = $row;
            }
        }

        return $plan;
    }

    private function invalid(array $row): ?string
    {
        foreach (['source_row_id', 'event_id', 'type', 'direction', 'source', 'payload', 'dedupe_key', 'schema_version', 'occurred_at', 'created_at'] as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                return "missing {$key}";
            }
        }
        if ($row['direction'] !== 'inbound') {
            return 'not an inbound event';
        }
        if (! is_string($row['event_id']) || ! Str::isUuid($row['event_id'])) {
            return 'event_id is not a uuid';
        }
        if (! is_array($row['payload'])) {
            return 'payload is not an object';
        }
        if (RetentionPolicy::isCompacted($row['payload'])) {
            return 'payload was reduced to a digest by retention; it cannot be replayed';
        }
        if (strlen((string) $row['dedupe_key']) > 191 || strlen((string) $row['type']) > 96 || strlen((string) $row['source']) > 64) {
            return 'a column is longer than the events table allows';
        }
        if ($row['business_id'] !== null && ! DB::table('businesses')->where('id', (int) $row['business_id'])->exists()) {
            return 'its business does not exist in this database';
        }

        return null;
    }

    private function report(array $plan): void
    {
        $this->line(sprintf('  absent, to insert as pending: %d', count($plan['new'])));
        $this->line(sprintf('  already present (same delivery), skipped: %d', count($plan['present'])));
        $this->line(sprintf('  conflicting: %d', count($plan['conflict'])));
        $this->line(sprintf('  malformed: %d', count($plan['invalid'])));
        foreach (['conflict', 'invalid'] as $kind) {
            foreach ($plan[$kind] as $item) {
                $this->line(sprintf('    %s source row %s event %s: %s', $kind, $item['row']['source_row_id'] ?? '?', is_string($item['row']['event_id'] ?? null) ? $item['row']['event_id'] : '?', $item['why']));
            }
        }
    }
}
