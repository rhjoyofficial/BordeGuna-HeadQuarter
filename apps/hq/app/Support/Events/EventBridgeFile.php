<?php

namespace App\Support\Events;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The file format of the restore-safe event bridge (docs/132): JSON Lines.
 *
 *   line 1      {"format":"hq-events-bridge","version":1, ...header}
 *   lines 2..n  one inbound `events` row each, payload included verbatim
 *   last line   {"trailer":true,"rows":<n>,"sha256":"<hex>"}
 *
 * The checksum is the SHA-256 of every line before the trailer, each with its "\n". A file that was
 * cut short, edited or concatenated fails it, and the importer refuses the whole file.
 */
final class EventBridgeFile
{
    public const FORMAT = 'hq-events-bridge';

    public const VERSION = 1;

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @var \HashContext */
    private $hash;

    private ?string $digest = null;

    public function __construct()
    {
        $this->hash = hash_init('sha256');
    }

    public function header(array $fields): string
    {
        return $this->add(json_encode(['format' => self::FORMAT, 'version' => self::VERSION] + $fields, self::JSON));
    }

    public function rowLine(object $row): string
    {
        return $this->add(json_encode([
            'source_row_id' => (int) $row->id,
            'event_id' => (string) $row->event_id,
            'type' => (string) $row->type,
            'direction' => (string) $row->direction,
            'source' => (string) $row->source,
            'aggregate_type' => $row->aggregate_type,
            'aggregate_id' => $row->aggregate_id,
            'business_id' => $row->business_id !== null ? (int) $row->business_id : null,
            'payload' => json_decode((string) $row->payload_json, true, 512, JSON_THROW_ON_ERROR),
            'dedupe_key' => (string) $row->dedupe_key,
            'schema_version' => (int) $row->schema_version,
            'occurred_at' => CarbonImmutable::parse($row->occurred_at)->utc()->format('Y-m-d\TH:i:s.uP'),
            'created_at' => CarbonImmutable::parse($row->created_at)->utc()->format('Y-m-d\TH:i:s.uP'),
            'status_at_export' => (string) $row->status,
            'attempts_at_export' => (int) $row->attempts,
        ], self::JSON));
    }

    public function trailer(int $rows): string
    {
        $this->digest = hash_final($this->hash);

        return json_encode(['trailer' => true, 'rows' => $rows, 'sha256' => $this->digest], self::JSON);
    }

    public function checksum(): string
    {
        return $this->digest ?? throw new RuntimeException('the trailer has not been written yet');
    }

    /**
     * Read and verify a bridge file. Throws with a reason a person can act on when anything is off;
     * nothing in the message is taken from a row's content.
     *
     * @return array{header: array<string, mixed>, rows: list<array<string, mixed>>, sha256: string}
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("{$path} is not a readable file");
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) < 2) {
            throw new RuntimeException('the file has no header and trailer');
        }
        $trailerLine = array_pop($lines);
        $hash = hash_init('sha256');
        foreach ($lines as $line) {
            hash_update($hash, $line."\n");
        }
        $digest = hash_final($hash);

        $trailer = json_decode($trailerLine, true);
        if (! is_array($trailer) || ($trailer['trailer'] ?? false) !== true || ! isset($trailer['rows'], $trailer['sha256'])) {
            throw new RuntimeException('the last line is not a bridge trailer: the file was cut short or edited');
        }
        if (! hash_equals((string) $trailer['sha256'], $digest)) {
            throw new RuntimeException('checksum mismatch: the file is not the one the export wrote');
        }

        $header = json_decode((string) array_shift($lines), true);
        if (! is_array($header) || ($header['format'] ?? null) !== self::FORMAT || ($header['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('the first line is not a version '.self::VERSION.' '.self::FORMAT.' header');
        }
        if ((int) $trailer['rows'] !== count($lines)) {
            throw new RuntimeException('the trailer counts '.(int) $trailer['rows'].' rows but the file holds '.count($lines));
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                throw new RuntimeException('line '.($i + 2).' is not a JSON object');
            }
            $rows[] = $row;
        }

        return ['header' => $header, 'rows' => $rows, 'sha256' => $digest];
    }

    /**
     * The payload as the identity of a delivery: keys sorted at every level, and without
     * `_correlation_id`, which EventRecorder stamps per request, so the same delivery recorded twice
     * (a shop retry after the restore) compares equal.
     */
    public static function canonicalPayload(mixed $payload): string
    {
        if (is_array($payload)) {
            unset($payload['_correlation_id']);
        }

        return json_encode(self::sortKeys($payload), self::JSON);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }

    private function add(string $line): string
    {
        hash_update($this->hash, $line."\n");

        return $line;
    }
}
