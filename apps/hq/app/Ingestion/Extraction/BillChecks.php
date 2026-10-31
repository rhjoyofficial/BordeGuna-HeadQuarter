<?php

namespace App\Ingestion\Extraction;

/**
 * Ruling 31 Oct 2026 item 10: the DETERMINISTIC checks on an extracted bill. Arithmetic, totals, tax and currency are never a model's
 * judgement: they are computed here, in whole minor units, every time an extraction is verified, again when a person marks it
 * reviewed, and again when the ERP draft is requested. A failed check blocks both the review and the draft; it is never fixed silently.
 *
 * A citation is evidence of where a value came from, not proof that the value is true; these checks and the person's review are
 * what stand between an extraction and the ERP draft. The ERP's own approval remains authoritative after that.
 */
final class BillChecks
{
    /** Currencies a supplier bill may be drafted in. Anything else is refused, never converted. */
    public const CURRENCIES = ['BDT', 'USD'];

    /**
     * @param  array<string, mixed>  $extracted
     * @return array{passed: bool, results: list<array{check: string, ok: bool, detail: string}>}
     */
    public function run(array $extracted): array
    {
        $results = [];
        $total = $extracted['total'] ?? null;

        $currency = is_array($total) ? strtoupper((string) ($total['currency'] ?? '')) : null;
        $results[] = ['check' => 'currency', 'ok' => $currency !== null && in_array($currency, self::CURRENCIES, true),
            'detail' => $currency === null ? 'No total, so no currency was read.' : "Currency {$currency}."];

        $totalMinor = is_array($total) && is_numeric($total['value'] ?? null) ? RuleBasedBillExtractor::minor((string) $total['value']) : null;
        $results[] = ['check' => 'total_positive', 'ok' => $totalMinor !== null && $totalMinor > 0,
            'detail' => $totalMinor === null ? 'No readable total.' : 'Total '.RuleBasedBillExtractor::major($totalMinor).'.'];

        $sum = 0;
        $linesOk = true;
        foreach ((array) ($extracted['lines'] ?? []) as $i => $line) {
            $qty = (string) ($line['quantity']['value'] ?? '');
            $unit = (string) ($line['unit_price']['value'] ?? '');
            $lineTotal = (string) ($line['line_total']['value'] ?? '');
            if (! is_numeric($qty) || ! is_numeric($unit) || ! is_numeric($lineTotal)) {
                $linesOk = false;

                continue;
            }
            $expected = (int) round((float) bcmul($qty, (string) RuleBasedBillExtractor::minor($unit), 4));
            $actual = RuleBasedBillExtractor::minor($lineTotal);
            if (abs($expected - $actual) > 1) { // one paisa of rounding, the same tolerance the extractor uses
                $linesOk = false;
            }
            $sum += $actual;
        }
        $results[] = ['check' => 'line_arithmetic', 'ok' => $linesOk, 'detail' => $linesOk ? 'Every line equals quantity times unit price.' : 'A line does not equal quantity times unit price.'];

        // Tax: when the extraction names a tax amount, lines plus tax must equal the total; with no tax, lines must equal the total.
        $taxMinor = is_numeric($extracted['tax']['value'] ?? null) ? RuleBasedBillExtractor::minor((string) $extracted['tax']['value']) : 0;
        $hasLines = ($extracted['lines'] ?? []) !== [];
        $sumOk = $totalMinor !== null && $hasLines && abs($sum + $taxMinor - $totalMinor) <= 1;
        $results[] = ['check' => 'lines_plus_tax_equal_total', 'ok' => $sumOk,
            'detail' => ! $hasLines ? 'No item lines to add up.' : 'Lines '.RuleBasedBillExtractor::major($sum).' plus tax '.RuleBasedBillExtractor::major($taxMinor)
                .' against total '.($totalMinor === null ? 'none' : RuleBasedBillExtractor::major($totalMinor)).'.'];

        return ['passed' => ! in_array(false, array_column($results, 'ok'), true), 'results' => $results];
    }
}
