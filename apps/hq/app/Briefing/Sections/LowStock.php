<?php

namespace App\Briefing\Sections;

use App\Briefing\BriefingRequest;
use App\Briefing\EvidenceItem;
use App\Briefing\EvidenceKind;
use App\Briefing\FreshnessState;
use App\Briefing\Lineage;
use App\Briefing\SourceFreshness;
use Illuminate\Support\Facades\DB;

/**
 * Inventory signals from the ERP low-stock read (docs/48 M.2), taken from the snapshot HQ already
 * holds. It never polls: refreshing a snapshot is `erp.poll_low_stock`, an R3 write, and a briefing
 * must not trigger one as a side effect. The ERP decides what "low" means; HQ repeats its list, with
 * the ERP's own `as_of`, and adds nothing.
 */
final class LowStock
{
    public const KEY = 'inventory';

    public const TITLE = 'Inventory signals';

    /** @param  list<SourceFreshness>  $sources */
    public function collect(BriefingRequest $request, array $sources): SectionResult
    {
        $items = [];
        $notes = [];
        $limit = Listing::limit();

        foreach ($sources as $source) {
            if ($source->readModel !== 'facts_erp_snapshots' || $source->freshness->state === FreshnessState::NotConnected) {
                continue;
            }

            $business = (string) $source->scope->business;

            if ($source->freshness->state === FreshnessState::Missing) {
                $notes[] = ['status' => 'unavailable', 'business' => $business, 'reason' => 'HQ holds no low-stock snapshot for this business. That is not the same as nothing being low.'];

                continue;
            }

            $snapshot = DB::table('facts_erp_snapshots as s')->join('businesses as b', 'b.id', '=', 's.business_id')
                ->where('b.slug', $business)->where('s.kind', 'low_stock')->first(['s.id', 's.payload', 's.job_run_ref']);
            if ($snapshot === null) {
                continue;
            }

            $lines = is_string($snapshot->payload) ? (array) json_decode($snapshot->payload, true) : (array) $snapshot->payload;
            $lines = array_values(array_filter($lines, 'is_array'));
            usort($lines, static fn (array $a, array $b): int => [(string) ($a['sku'] ?? ''), (int) ($a['product_id'] ?? 0)] <=> [(string) ($b['sku'] ?? ''), (int) ($b['product_id'] ?? 0)]);

            $ref = ['system' => 'erp', 'read_model' => 'facts_erp_snapshots', 'ref' => $source->key, 'row_id' => (int) $snapshot->id];
            $row = [['id' => (int) $snapshot->id, 'source_ref' => ['business' => $business, 'kind' => 'low_stock', 'job_run_ref' => $snapshot->job_run_ref]]];

            $items[] = new EvidenceItem(
                id: "erp.low_stock.count.business:{$business}", kind: EvidenceKind::Metric, section: self::KEY,
                label: "Products on the ERP low-stock list, {$business}", value: count($lines), unit: 'count', scope: $source->scope,
                source: $ref, asOf: $source->asOf, freshness: $source->freshness,
                lineage: Lineage::ofRows('count_payload_lines', 'facts_erp_snapshots', $row, ['business' => $business, 'kind' => 'low_stock']),
            );

            foreach (array_slice($lines, 0, $limit) as $line) {
                $sku = (string) ($line['sku'] ?? $line['product_id'] ?? '');
                foreach (['on_hand' => 'On hand', 'threshold' => 'Low-stock threshold'] as $field => $label) {
                    if (! isset($line[$field]) || ! is_numeric($line[$field])) {
                        continue;
                    }
                    $items[] = new EvidenceItem(
                        id: "erp.low_stock.{$field}:{$business}:{$sku}", kind: EvidenceKind::Fact, section: self::KEY,
                        label: "{$label}, {$sku}", value: self::quantity($line[$field]), unit: 'units', scope: $source->scope,
                        source: $ref + ['payload_path' => "[sku={$sku}].{$field}"], asOf: $source->asOf, freshness: $source->freshness,
                        subject: $sku, details: ['name' => (string) ($line['name'] ?? ''), 'product_id' => $line['product_id'] ?? null],
                    );
                }
            }

            array_push($notes, ...Listing::truncation(count($lines), $limit, "low-stock lines of {$business}"));
        }

        return SectionResult::of(self::KEY, self::TITLE, $items, 'HQ holds no ERP low-stock snapshot in this scope.', $notes);
    }

    /** A whole quantity as an integer, a fractional one as a plain decimal with no trailing zeros. */
    private static function quantity(mixed $value): int|string
    {
        $text = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return str_contains($text, '.') ? $text : (int) $text;
    }
}
