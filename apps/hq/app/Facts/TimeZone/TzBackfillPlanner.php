<?php

namespace App\Facts\TimeZone;

use App\Ads\Contribution\FactsOrdersDeliveredSales;
use App\Ads\Dto\AdMoney;
use App\Models\Business;
use App\Observe\Retention\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dry run of the time-zone backfill (docs/133, ruling docs/131 section 2 item 8). Reads, writes nothing.
 *
 * W5-TZ (docs/125): before `5466127`, a shop timestamp sent with an offset was parsed and handed to Eloquent,
 * which writes the wall clock without the offset into a `timestamptz` column read in a UTC session. So a
 * `+06:00` value was stored six hours late; a `Z` value was stored right. This planner finds the stored
 * `facts_orders` timestamps the old code got wrong, and ONLY from evidence:
 *
 *  - the evidence of a timestamp is the shop's raw string in the `events` rows of the same store and order
 *    (`events.payload` is jsonb, so the string survives byte for byte, offset included), read exactly as
 *    `ShopOrderEventHandler` and `FactsOrderUpserter` read it, per column;
 *  - for each string with an explicit offset, two values are computed: the instant it names (what the fixed
 *    code stores) and the wall clock read as UTC (what the old code stored). A stored value equal to the
 *    wrong reading of a non-zero-offset string, and to no correct reading and no offsetless string, is
 *    CORRECTABLE, to that one instant (all such strings must agree);
 *  - every other case is never corrected: a value equal to a correct reading is already right; a value
 *    explained only by a string without an offset, or by nothing at all (reconciliation, an ERP read-back whose
 *    payload HQ keeps only as a digest, a status event without a time, a compacted payload), or by conflicting
 *    strings, is AMBIGUOUS: listed with its reason, never changed.
 *
 * ⛔ No inference: no time window, no "the shop always sends +06:00", no nearest match. Equality to the second.
 * ⛔ No personal value is in the output: row ids, business and store slugs, column names, event ids and the
 *    shop's timestamp strings only.
 */
final class TzBackfillPlanner
{
    public const COLUMNS = ['placed_at', 'confirmed_at', 'delivered_at', 'cancelled_at'];

    public const AMBIGUOUS_NO_EVIDENCE = 'no_source_evidence';

    public const AMBIGUOUS_ERP_DIGEST_ONLY = 'erp_read_back_digest_only';

    public const AMBIGUOUS_NO_OFFSET = 'source_string_has_no_offset';

    public const AMBIGUOUS_NO_MATCH = 'no_source_string_explains_the_stored_value';

    public const AMBIGUOUS_CONFLICT = 'conflicting_source_strings';

    private const FMT = 'Y-m-d H:i:s';

    /** A clock time followed by a zone designator: `Z`, `+06:00`, `+0600` or `+06`. */
    private const OFFSET = '/\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?\s*(Z|[+-]\d{2}(?::?\d{2})?)$/i';

    /** @return array<string, mixed> */
    public function plan(): array
    {
        $tz = (string) config('hq.timezone', 'Asia/Dhaka');
        $evidence = $this->evidence();
        $stores = DB::table('stores')->pluck('slug', 'id')->all();
        $businesses = DB::table('businesses')->pluck('slug', 'id')->all();

        $correctable = [];
        $ambiguous = [];
        $verified = 0;
        $examined = 0;

        $rows = DB::table('facts_orders')->orderBy('id')
            ->select(['id', 'business_id', 'store_id', 'order_ref', 'source_system', 'subtotal', 'discount_total', 'cancelled_at'])
            ->selectRaw(implode(', ', array_map(fn ($c) => "to_char({$c} AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS {$c}_utc", self::COLUMNS)))
            ->get();

        foreach ($rows as $row) {
            $slug = (string) ($stores[$row->store_id] ?? '');
            $byColumn = $evidence[$slug."\0".$row->order_ref] ?? [];
            foreach (self::COLUMNS as $column) {
                $stored = $row->{$column.'_utc'};
                if ($stored === null) {
                    continue;
                }
                $examined++;
                $verdict = $this->judge($stored, $byColumn[$column] ?? [], $row->source_system === 'erp');
                $base = [
                    'facts_order_id' => (int) $row->id,
                    'business_id' => (int) $row->business_id,
                    'business' => (string) ($businesses[$row->business_id] ?? $row->business_id),
                    'store' => $slug,
                    'column' => $column,
                    'stored_utc' => $stored,
                    'stored_dhaka_day' => self::day($stored, $tz),
                ];
                if ($verdict['status'] === 'verified') {
                    $verified++;
                } elseif ($verdict['status'] === 'correctable') {
                    $correctable[] = $base + [
                        'source_event_id' => $verdict['event_id'],
                        'source_string' => $verdict['string'],
                        'expected_utc' => $verdict['expected'],
                        'expected_dhaka_day' => self::day($verdict['expected'], $tz),
                        'shift_seconds' => (int) CarbonImmutable::parse($verdict['expected'], 'UTC')->diffInSeconds(CarbonImmutable::parse($stored, 'UTC'), false),
                        'counted_as_delivered_sale' => $column === 'delivered_at' && $row->cancelled_at === null,
                        'net_revenue_minor' => (int) AdMoney::toMinor((string) $row->subtotal, FactsOrdersDeliveredSales::CURRENCY)
                            - (int) AdMoney::toMinor((string) $row->discount_total, FactsOrdersDeliveredSales::CURRENCY),
                    ];
                } else {
                    $ambiguous[] = $base + ['reason' => $verdict['reason'], 'source_strings' => $verdict['strings']];
                }
            }
        }

        return [
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'timezone' => $tz,
            'batch_key' => self::batchKey($correctable),
            'totals' => [
                'facts_orders_rows' => $rows->count(),
                'timestamps_examined' => $examined,
                'verified_correct' => $verified,
                'correctable' => count($correctable),
                'ambiguous' => count($ambiguous),
                'ambiguous_by_reason' => array_count_values(array_column($ambiguous, 'reason')),
            ],
            'by_business_day' => self::byBusinessDay($correctable),
            'delivered_sales' => $this->deliveredBeforeAfter($correctable, $tz),
            'correctable' => array_map(fn ($c) => array_diff_key($c, ['net_revenue_minor' => 1, 'counted_as_delivered_sale' => 1]), $correctable),
            'ambiguous' => $ambiguous,
        ];
    }

    /** The key of exactly this set of corrections: the same data proposes the same key, changed data another. */
    public static function batchKey(array $correctable): string
    {
        $items = array_map(fn ($c) => [$c['facts_order_id'], $c['column'], $c['stored_utc'], $c['expected_utc']], $correctable);
        usort($items, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return hash('sha256', 'tz-backfill:v1:'.json_encode($items));
    }

    /**
     * @param  list<array{event_id: string, string: string}>  $strings
     * @return array{status: string, reason?: string, event_id?: string, string?: string, expected?: string, strings: list<string>}
     */
    private function judge(string $stored, array $strings, bool $erp): array
    {
        $raw = array_values(array_unique(array_column($strings, 'string')));
        if ($strings === []) {
            return ['status' => 'ambiguous', 'reason' => $erp ? self::AMBIGUOUS_ERP_DIGEST_ONLY : self::AMBIGUOUS_NO_EVIDENCE, 'strings' => []];
        }

        $correct = $wrong = $offsetless = [];
        foreach ($strings as $s) {
            $read = self::read($s['string']);
            if ($read === null) {
                continue;
            }
            if (! $read['has_offset']) {
                if ($read['wall'] === $stored) {
                    $offsetless[] = $s;
                }

                continue;
            }
            if ($read['instant'] === $stored) {
                $correct[] = $s;
            } elseif ($read['wall'] === $stored) {
                $wrong[] = $s + ['expected' => $read['instant']];
            }
        }

        if ($wrong !== []) {
            $expected = array_values(array_unique(array_column($wrong, 'expected')));
            if ($correct !== [] || $offsetless !== [] || count($expected) !== 1) {
                return ['status' => 'ambiguous', 'reason' => self::AMBIGUOUS_CONFLICT, 'strings' => $raw];
            }

            return ['status' => 'correctable', 'event_id' => $wrong[0]['event_id'], 'string' => $wrong[0]['string'], 'expected' => $expected[0], 'strings' => $raw];
        }
        if ($correct !== []) {
            return ['status' => 'verified', 'strings' => $raw];
        }
        if ($offsetless !== []) {
            return ['status' => 'ambiguous', 'reason' => self::AMBIGUOUS_NO_OFFSET, 'strings' => $raw];
        }

        return ['status' => 'ambiguous', 'reason' => $erp ? self::AMBIGUOUS_ERP_DIGEST_ONLY : self::AMBIGUOUS_NO_MATCH, 'strings' => $raw];
    }

    /**
     * One raw string read both ways, to the second: the instant it names (UTC) and its wall clock read as UTC
     * (what the old code stored). Null when it does not parse.
     *
     * @return array{has_offset: bool, instant: string, wall: string}|null
     */
    public static function read(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || is_numeric($value)) {
            return null;
        }
        try {
            $parsed = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return [
            'has_offset' => (bool) preg_match(self::OFFSET, $value),
            'instant' => $parsed->utc()->format(self::FMT),
            'wall' => $parsed->format(self::FMT),
        ];
    }

    /**
     * Every shop string that could have written each column of each order, from the processed inbound order
     * events, keyed "{store slug}\0{order ref}" then column. Read exactly as the handler and the upserter read
     * the payload.
     *
     * @return array<string, array<string, list<array{event_id: string, string: string}>>>
     */
    private function evidence(): array
    {
        $out = [];
        $events = DB::table('events')
            ->where('direction', 'inbound')
            ->where('status', 'processed')
            ->where('source', 'like', 'shop:%')
            ->whereIn('type', ['order.created', 'order.updated', 'order.status_changed', 'shipment.status_updated', 'shipment.delivered'])
            ->orderBy('id')
            ->select(['event_id', 'type', 'payload'])
            ->cursor();

        foreach ($events as $event) {
            $payload = json_decode((string) $event->payload, true);
            if (! is_array($payload) || RetentionPolicy::isCompacted($payload)) {
                continue;
            }
            $slug = (string) ($payload['_store'] ?? '');
            $full = in_array($event->type, ['order.created', 'order.updated'], true);
            $o = $full && is_array($payload['order'] ?? null) ? $payload['order'] : $payload;
            $ref = (string) ($o['order_number'] ?? ($full ? ($o['ref'] ?? '') : ''));
            if ($slug === '' || $ref === '') {
                continue;
            }
            $add = function (string $column, mixed $value) use (&$out, $slug, $ref, $event) {
                if (is_string($value) && $value !== '') {
                    $out[$slug."\0".$ref][$column][] = ['event_id' => (string) $event->event_id, 'string' => $value];
                }
            };

            if ($full) {
                $add('placed_at', $o['placed_at'] ?? $o['created_at'] ?? null);
                foreach (['confirmed_at', 'delivered_at', 'cancelled_at'] as $c) {
                    $add($c, $o[$c] ?? null);
                }

                continue;
            }

            $to = $payload['to'] ?? $payload['new_status'] ?? null;
            $at = $payload['at'] ?? $payload['changed_at'] ?? null;
            match ($event->type) {
                'order.status_changed' => in_array($to, ['confirmed', 'delivered', 'cancelled'], true) ? $add($to.'_at', $at) : null,
                'shipment.status_updated' => $to === 'delivered' ? $add('delivered_at', $at) : null,
                'shipment.delivered' => $add('delivered_at', $payload['delivered_at'] ?? $at),
                default => null,
            };
        }

        return $out;
    }

    /**
     * Corrections grouped by business and by the Dhaka day of the stored value, with how many move to another day.
     *
     * @param  list<array<string, mixed>>  $correctable
     * @return list<array<string, mixed>>
     */
    private static function byBusinessDay(array $correctable): array
    {
        $groups = [];
        foreach ($correctable as $c) {
            $k = $c['business']."\0".$c['stored_dhaka_day'];
            $groups[$k] ??= ['business' => $c['business'], 'stored_dhaka_day' => $c['stored_dhaka_day'], 'timestamps' => 0, 'rows' => [], 'by_column' => [], 'moving_to_another_day' => 0];
            $groups[$k]['timestamps']++;
            $groups[$k]['rows'][$c['facts_order_id']] = true;
            $groups[$k]['by_column'][$c['column']] = ($groups[$k]['by_column'][$c['column']] ?? 0) + 1;
            if ($c['expected_dhaka_day'] !== $c['stored_dhaka_day']) {
                $groups[$k]['moving_to_another_day']++;
            }
        }
        ksort($groups);

        return array_values(array_map(fn ($g) => ['rows' => count($g['rows'])] + array_diff_key($g, ['rows' => 1]), $groups));
    }

    /**
     * Delivered sales (the contribution report's own source) per business and Dhaka day touched by a delivered_at
     * correction: the count and net revenue the source returns today, and what it would return after.
     *
     * @param  list<array<string, mixed>>  $correctable
     * @return list<array<string, mixed>>
     */
    private function deliveredBeforeAfter(array $correctable, string $tz): array
    {
        $moves = array_values(array_filter($correctable, fn ($c) => $c['counted_as_delivered_sale']));
        $days = [];
        foreach ($moves as $m) {
            $days[$m['business_id']][$m['stored_dhaka_day']] = true;
            $days[$m['business_id']][$m['expected_dhaka_day']] = true;
        }

        $source = new FactsOrdersDeliveredSales;
        $out = [];
        ksort($days);
        foreach ($days as $businessId => $set) {
            $business = Business::query()->find($businessId);
            if ($business === null) {
                continue;
            }
            $set = array_keys($set);
            sort($set);
            foreach ($set as $day) {
                $d = CarbonImmutable::parse($day, $tz);
                $before = $source->delivered($business, $d->startOfDay(), $d->endOfDay());
                $count = count($before);
                $minor = array_sum(array_map(fn ($s) => (int) AdMoney::toMinor((string) $s->netRevenue, FactsOrdersDeliveredSales::CURRENCY), $before));
                $afterCount = $count;
                $afterMinor = $minor;
                foreach ($moves as $m) {
                    if ((int) $m['business_id'] !== (int) $businessId || $m['stored_dhaka_day'] === $m['expected_dhaka_day']) {
                        continue;
                    }
                    if ($m['stored_dhaka_day'] === $day) {
                        $afterCount--;
                        $afterMinor -= $m['net_revenue_minor'];
                    }
                    if ($m['expected_dhaka_day'] === $day) {
                        $afterCount++;
                        $afterMinor += $m['net_revenue_minor'];
                    }
                }
                $out[] = [
                    'business' => (string) $business->slug,
                    'dhaka_day' => $day,
                    'before' => ['orders' => $count, 'net_revenue' => (string) AdMoney::fromMinor((string) $minor, FactsOrdersDeliveredSales::CURRENCY)],
                    'after' => ['orders' => $afterCount, 'net_revenue' => (string) AdMoney::fromMinor((string) $afterMinor, FactsOrdersDeliveredSales::CURRENCY)],
                ];
            }
        }

        return $out;
    }

    private static function day(string $utc, string $tz): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone($tz)->toDateString();
    }
}
