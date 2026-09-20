<?php

namespace App\Tools\Facts;

use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Models\Store;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The typed order query (docs/48 D.6, H.3).
 *
 * ## No SQL reaches this class, and none leaves it
 *
 * The caller supplies a business, a store, a date range, a status set, a grouping dimension and a
 * metric. This class compiles that into a query. A model never sees a table name, never emits a
 * query, and cannot name a column the schema does not expose: `group_by` and `metric` are closed
 * enumerations validated by the gateway before execute() is entered, and every other filter is
 * bound. This is the ERP assistant's proven pattern and `docs/48` calls it non-negotiable.
 *
 * ## Coverage is not a footnote, it is half the output
 *
 * ⭐ HQ holds a small number of order facts and some carry no line detail. An answer built on them
 * that does not say so invites a decision it cannot support. So every result carries the rows it
 * actually matched, the date range it actually covers (not the range that was asked for), how much
 * of it lacks line detail, and how stale the newest sync is. `AgentRunner` and the analytics prompt
 * both lean on this, and `coverage.sufficient_for_trend` states the verdict rather than leaving
 * each caller to invent its own floor.
 *
 * ## Two traps that are easy to get wrong here
 *
 * ⚠ **Days are the group's days, not the server's.** `placed_at` is a timestamptz and the container
 * runs UTC, so grouping without a timezone puts a Dhaka evening's orders on the next day. Every date
 * bucket is computed `AT TIME ZONE` the configured business timezone. This is the same defect that
 * once shifted a whole day of takings in Zeno's reports.
 *
 * ⚠ **`units` is only knowable where lines exist.** An order with no line detail contributes zero
 * units and would silently drag an average down, so units are summed only over orders that HAVE
 * lines, and the count of those is reported beside it. Reporting units against the full row count
 * would be arithmetic on a denominator that does not apply.
 *
 * ## The order of the rows is a rule, not an accident (docs/81 P1-1)
 *
 * Grouped rows come back by the metric, descending, and then by the GROUP KEY, ascending: the id for
 * a business or a store (the key behind the slug), and the text itself under the "C" collation for
 * every other grouping, so the order does not depend on the database's locale. Ordering by the
 * metric alone left tied groups in whatever order the plan produced, and with a limit it let chance
 * decide which of them was in the answer at all. When a tie IS cut by the limit,
 * `coverage.tie_at_limit` says so; the key is absent whenever no tie was cut.
 */
final class QueryOrdersTool implements Tool
{
    /** Below this many rows, a trend claim is not supportable and the coverage says so. */
    private const TREND_FLOOR_ROWS = 30;

    /** And below this many days, neither is it, however many rows there are. */
    private const TREND_FLOOR_DAYS = 14;

    private const MAX_ROWS = 500;

    private const GROUPINGS = ['none', 'day', 'week', 'month', 'business', 'store', 'status', 'source', 'district', 'payment_method'];

    private const METRICS = ['orders', 'gross', 'subtotal', 'units', 'avg_order_value'];

    /** Units, summed only over rows that carry lines. One definition: the column and the sort read the same thing. */
    private const UNITS = 'coalesce(sum((select sum((l->>\'qty\')::numeric) from jsonb_array_elements(lines) l)'
        .' ) filter (where lines is not null and jsonb_array_length(lines) > 0), 0)';

    public function key(): string
    {
        return 'facts.query_orders';
    }

    /**
     * R1: it reads HQ's own read model and writes nothing at all, not even an audit note. The
     * cheapest thing in the tool set, and the one an agent reaches for most.
     */
    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: $this->key(),
            version: '1.0.0',
            title: 'Query order facts',
            purpose: 'Aggregates the order facts HQ holds, through typed filters, and reports the coverage behind every result.',
            riskTier: RiskTier::R1,
            permission: 'hq.tools.facts.read',
            auditAction: 'tool.facts_query_orders',
            scope: ToolScope::Hq,
            readWrite: ReadWrite::Read,
            agentExecutable: true,
            idempotent: true,
            timeoutSeconds: 30,
            outputSchema: $this->outputSchema(),
        );
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'store_slug' => ['type' => 'string', 'maxLength' => 64],
                'from' => ['type' => 'string', 'maxLength' => 32],
                'to' => ['type' => 'string', 'maxLength' => 32],
                'statuses' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 24], 'maxItems' => 12],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 32], 'maxItems' => 8],
                'group_by' => ['type' => 'string', 'enum' => self::GROUPINGS],
                'metric' => ['type' => 'string', 'enum' => self::METRICS],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ROWS],
            ],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'rows' => ['type' => 'array'],
            'coverage' => ['type' => 'object'],
            'filters' => ['type' => 'object'],
        ]];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $timezone = (string) config('hq.timezone', 'Asia/Dhaka');

        $businessId = null;
        if (($slug = $payload['business_slug'] ?? null) !== null) {
            $businessId = Business::where('slug', $slug)->value('id');
            if ($businessId === null) {
                return ToolResult::failed("HQ has no business with the slug [{$slug}].");
            }
        }

        $storeId = null;
        if (($slug = $payload['store_slug'] ?? null) !== null) {
            $store = Store::where('slug', $slug)->first();
            if ($store === null) {
                return ToolResult::failed("HQ has no store with the slug [{$slug}].");
            }
            $storeId = $store->id;
            $businessId ??= $store->business_id;
        }

        $from = $this->parseDate($payload['from'] ?? null, $timezone, false);
        $to = $this->parseDate($payload['to'] ?? null, $timezone, true);

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            return ToolResult::failed('The date range ends before it starts.');
        }

        $base = fn (): QueryBuilder => $this->filtered($businessId, $storeId, $from, $to, $payload, $context);

        $coverage = $this->coverage($base(), $from, $to, $timezone);

        // Nothing matched: return the empty result WITH its coverage rather than an error. "There
        // are no orders in that window" is a true answer, and turning it into a failure would make
        // an agent retry a query that will keep being correct.
        if ($coverage['rows_matched'] === 0) {
            return ToolResult::succeeded([
                'rows' => [],
                'coverage' => $coverage,
                'filters' => $this->echoFilters($payload, $from, $to, $timezone),
            ]);
        }

        $groupBy = (string) ($payload['group_by'] ?? 'none');
        $metric = (string) ($payload['metric'] ?? 'orders');
        $limit = min((int) ($payload['limit'] ?? 100), self::MAX_ROWS);

        [$rows, $tie] = $this->rows($base(), $groupBy, $metric, $limit, $timezone);

        // Present only when the limit cut through a tie, so a result it did not happen to is byte
        // for byte what it always was.
        if ($tie !== null) {
            $coverage['tie_at_limit'] = $tie;
        }

        return ToolResult::succeeded([
            'rows' => $rows,
            'coverage' => $coverage,
            'filters' => $this->echoFilters($payload, $from, $to, $timezone),
        ]);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // a read has nothing to reconcile
    }

    /** The filtered row set, before any grouping. */
    private function filtered(?int $businessId, ?int $storeId, ?CarbonImmutable $from, ?CarbonImmutable $to, array $payload, ToolContext $context): QueryBuilder
    {
        $query = DB::table('facts_orders');

        // The caller's own business restriction, resolved by the gateway. Null is unrestricted.
        $context->restrictBusinesses($query, 'facts_orders.business_id');

        if ($businessId !== null) {
            $query->where('facts_orders.business_id', $businessId);
        }
        if ($storeId !== null) {
            $query->where('facts_orders.store_id', $storeId);
        }
        if ($from !== null) {
            $query->where('facts_orders.placed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('facts_orders.placed_at', '<=', $to);
        }

        $statuses = $this->stringList($payload['statuses'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('facts_orders.status', $statuses);
        }

        $sources = $this->stringList($payload['sources'] ?? null);
        if ($sources !== []) {
            $query->whereIn('facts_orders.source', $sources);
        }

        return $query;
    }

    /**
     * What the result actually rests on.
     *
     * ⚠ `date_range` is the range of the rows that MATCHED, never the range that was requested.
     * Echoing the request back would let a query for a whole year look like a year of evidence when
     * it found four days of it.
     */
    private function coverage(QueryBuilder $query, ?CarbonImmutable $from, ?CarbonImmutable $to, string $timezone): array
    {
        $row = (array) $query->clone()->selectRaw(
            'count(*) as rows_matched,'
            .' count(*) filter (where lines is null or jsonb_array_length(lines) = 0) as without_line_detail,'
            .' count(*) filter (where placed_at is null) as without_placed_at,'
            .' min(placed_at) as first_placed_at,'
            .' max(placed_at) as last_placed_at,'
            .' max(synced_at) as last_synced_at,'
            .' count(distinct business_id) as businesses,'
            .' count(distinct store_id) as stores'
        )->first();

        $matched = (int) ($row['rows_matched'] ?? 0);
        $withoutLines = (int) ($row['without_line_detail'] ?? 0);
        $first = $this->toTz($row['first_placed_at'] ?? null, $timezone);
        $last = $this->toTz($row['last_placed_at'] ?? null, $timezone);
        $synced = $this->toTz($row['last_synced_at'] ?? null, $timezone);

        $spanDays = ($first !== null && $last !== null) ? $first->diffInDays($last) + 1 : 0;

        return [
            'rows_matched' => $matched,
            'without_line_detail' => $withoutLines,
            'without_line_detail_share' => $matched === 0 ? null : round($withoutLines / $matched, 3),
            'without_placed_at' => (int) ($row['without_placed_at'] ?? 0),
            'date_range' => ['from' => $first?->toIso8601String(), 'to' => $last?->toIso8601String()],
            'requested_range' => ['from' => $from?->toIso8601String(), 'to' => $to?->toIso8601String()],
            'span_days' => $spanDays,
            'businesses' => (int) ($row['businesses'] ?? 0),
            'stores' => (int) ($row['stores'] ?? 0),
            'last_synced_at' => $synced?->toIso8601String(),
            'timezone' => $timezone,
            // The verdict, so every caller reads the same floor rather than choosing one.
            'sufficient_for_trend' => $matched >= self::TREND_FLOOR_ROWS && $spanDays >= self::TREND_FLOOR_DAYS,
            'trend_floor' => ['rows' => self::TREND_FLOOR_ROWS, 'days' => self::TREND_FLOOR_DAYS],
            'generated_at' => CarbonImmutable::now($timezone)->toIso8601String(),
        ];
    }

    /**
     * The grouped rows, in a fixed order, and what the limit did to a tie.
     *
     * The grouping is an inner query; the ordering and the limit sit outside it, on plain columns.
     * That is what lets the group key be the second sort key for every grouping, including the ones
     * whose expression carries a bound timezone.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null}
     */
    private function rows(QueryBuilder $query, string $groupBy, string $metric, int $limit, string $timezone): array
    {
        [$expression, $bindings] = $this->groupExpression($groupBy, $timezone);

        $grouped = $query
            ->selectRaw($expression.' as group_key', $bindings)
            ->selectRaw(
                'count(*) as orders,'
                .' coalesce(sum(gross), 0) as gross,'
                .' coalesce(sum(subtotal), 0) as subtotal,'
                // Units only over rows that actually carry lines; the denominator is reported too.
                .' '.self::UNITS.' as units,'
                .' count(*) filter (where lines is not null and jsonb_array_length(lines) > 0) as orders_with_lines,'
                // What the rows are ordered by. Unchanged from when it was the ORDER BY itself.
                .' '.$this->orderExpression($metric).' as sort_metric'
            )
            ->groupBy('group_key');

        $ordered = fn (): QueryBuilder => DB::query()->fromSub($grouped->clone(), 'g')
            ->orderByRaw('sort_metric desc nulls last')
            ->orderByRaw($this->keyOrder($groupBy));

        // One more than was asked for: the extra row is how a tie at the cut is seen.
        $fetched = $ordered()->limit($limit + 1)->get()->map(fn ($row) => (array) $row)->all();
        $kept = array_slice($fetched, 0, $limit);
        $tie = $this->tieAtLimit($fetched, $kept, $limit, $metric, $groupBy, $grouped);

        $out = [];
        foreach ($kept as $row) {
            $orders = (int) $row['orders'];
            $gross = (float) $row['gross'];

            $out[] = [
                'group' => $this->labelFor($groupBy, $row['group_key']),
                'orders' => $orders,
                'gross' => round($gross, 2),
                'subtotal' => round((float) $row['subtotal'], 2),
                'units' => (float) $row['units'],
                'orders_with_lines' => (int) $row['orders_with_lines'],
                'avg_order_value' => $orders === 0 ? null : round($gross / $orders, 2),
            ];
        }

        return [$out, $tie];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function groupExpression(string $groupBy, string $timezone): array
    {
        // ⚠ Never interpolate $groupBy: it is validated against a closed list by the gateway before
        // this runs, and this match is the second gate rather than the first.
        return match ($groupBy) {
            'day' => ["to_char(date_trunc('day', placed_at at time zone ?), 'YYYY-MM-DD')", [$timezone]],
            'week' => ["to_char(date_trunc('week', placed_at at time zone ?), 'IYYY-\"W\"IW')", [$timezone]],
            'month' => ["to_char(date_trunc('month', placed_at at time zone ?), 'YYYY-MM')", [$timezone]],
            'business' => ['business_id::text', []],
            'store' => ['store_id::text', []],
            'status' => ['status', []],
            'source' => ["coalesce(source, 'unknown')", []],
            'district' => ["coalesce(district, 'unknown')", []],
            'payment_method' => ["coalesce(payment_method, 'unknown')", []],
            default => ["'all'", []],
        };
    }

    private function orderExpression(string $metric): string
    {
        return match ($metric) {
            'gross' => 'sum(gross)',
            'subtotal' => 'sum(subtotal)',
            'units' => self::UNITS,
            'avg_order_value' => 'sum(gross) / nullif(count(*), 0)',
            default => 'count(*)',
        };
    }

    /**
     * The second sort key: the group key itself, ascending, nulls last.
     *
     * A business or a store is grouped by its id, so it is ordered as a number (as text, 10 would
     * sort before 9). Everything else is text, ordered under the "C" collation: byte order, the same
     * on every server, where the database's own locale is whatever the host was installed with.
     */
    private function keyOrder(string $groupBy): string
    {
        return in_array($groupBy, ['business', 'store'], true)
            ? 'group_key::bigint asc nulls last'
            : 'group_key collate "C" asc nulls last';
    }

    /**
     * Whether the limit cut through a tie, and if so what a reader needs to know about it.
     *
     * @param  list<array<string, mixed>>  $fetched  up to limit + 1 rows
     * @param  list<array<string, mixed>>  $kept
     * @return array<string, mixed>|null
     */
    private function tieAtLimit(array $fetched, array $kept, int $limit, string $metric, string $groupBy, QueryBuilder $grouped): ?array
    {
        if (count($fetched) <= $limit || $kept === []) {
            return null;
        }

        $cut = $kept[count($kept) - 1]['sort_metric'];
        $firstLeftOut = $fetched[$limit]['sort_metric'];

        // Compared as the database returned them: the same numeric prints the same way.
        if ((string) $cut !== (string) $firstLeftOut || ($cut === null) !== ($firstLeftOut === null)) {
            return null;
        }

        $sharing = DB::query()->fromSub($grouped->clone(), 'g')
            ->when($cut === null, fn ($q) => $q->whereNull('sort_metric'), fn ($q) => $q->where('sort_metric', $cut))
            ->count();
        $returned = count(array_filter($kept, static fn (array $row) => (string) $row['sort_metric'] === (string) $cut));

        return [
            'metric' => $metric,
            'value' => $cut === null ? null : round((float) $cut, 2),
            'limit' => $limit,
            'groups_sharing_the_value' => $sharing,
            'of_which_returned' => $returned,
            'chosen_by' => 'group key ascending ('.(in_array($groupBy, ['business', 'store'], true) ? 'the id' : 'text, "C" collation').'), not by the metric',
            'note' => 'The limit cut through groups that tie on the metric. The groups left out rank equal to the last one shown; raise the limit to see them all.',
        ];
    }

    /** Ids are unhelpful to a reader and to a model; slugs are what everything else names. */
    private function labelFor(string $groupBy, mixed $key): string
    {
        $key = (string) $key;

        return match ($groupBy) {
            'business' => Business::find((int) $key)?->slug ?? "business:{$key}",
            'store' => Store::find((int) $key)?->slug ?? "store:{$key}",
            default => $key,
        };
    }

    private function echoFilters(array $payload, ?CarbonImmutable $from, ?CarbonImmutable $to, string $timezone): array
    {
        return [
            'business_slug' => $payload['business_slug'] ?? null,
            'store_slug' => $payload['store_slug'] ?? null,
            'from' => $from?->toIso8601String(),
            'to' => $to?->toIso8601String(),
            'statuses' => $this->stringList($payload['statuses'] ?? null),
            'sources' => $this->stringList($payload['sources'] ?? null),
            'group_by' => $payload['group_by'] ?? 'none',
            'metric' => $payload['metric'] ?? 'orders',
            'timezone' => $timezone,
        ];
    }

    /**
     * A bare date means the whole of that day in the group's timezone, so `to: 2026-09-09` includes
     * the ninth rather than stopping at its first instant.
     */
    private function parseDate(?string $value, string $timezone, bool $endOfDay): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }

        $dateOnly = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value));

        return $dateOnly && $endOfDay ? $parsed->endOfDay() : $parsed;
    }

    private function toTz(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''))
            : [];
    }
}
