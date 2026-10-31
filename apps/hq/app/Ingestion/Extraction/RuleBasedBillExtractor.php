<?php

namespace App\Ingestion\Extraction;

/**
 * Deterministic extraction of a supplier bill: labelled invoice number, date and total, a supplier line, and
 * `description qty x unit total` item lines. No model, no network, the same answer every time (docs/144-email-ingestion
 * decision: the default is the conservative one; a model-assisted extractor is a later swap behind `BillExtractor`
 * and must pass `ExtractionVerifier` too).
 *
 * It does not guess: a field it cannot find is `null` and a warning says so; amounts are decimal strings in BDT
 * unless the document names another currency; lines are checked against their own quantity x unit price and the
 * lines against the total, and any disagreement is a warning for the person reviewing, never a silent fix.
 */
final class RuleBasedBillExtractor implements BillExtractor
{
    private const NUMBER = '\d[\d,]*(?:\.\d{1,2})?';

    public function key(): string
    {
        return 'rule_based_v1';
    }

    public function extract(string $text): array
    {
        $warnings = [];

        $supplier = $this->labelled($text, '/^[ \t]*(?:supplier|vendor|from)[ \t]*[:\-][ \t]*(?<v>.+?)[ \t]*$/imu')
            ?? $this->firstLine($text);
        $invoice = $this->labelled($text, '/(?:invoice|bill|inv)[ \t]*(?:no\.?|number|#)[ \t]*[:\-]?[ \t]*(?<v>[A-Za-z0-9][A-Za-z0-9\-\/]*)/iu');
        $date = $this->labelled($text, '/(?:invoice[ \t]+)?date(?:d)?[ \t]*[:\-]?[ \t]*(?<v>\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})/iu');
        $total = $this->total($text);
        $lines = $this->lines($text);

        foreach (['supplier' => $supplier, 'invoice_number' => $invoice, 'invoice_date' => $date, 'total' => $total] as $name => $field) {
            if ($field === null) {
                $warnings[] = "No {$name} could be read from the document.";
            }
        }
        if ($lines === []) {
            $warnings[] = 'No item lines could be read from the document.';
        }

        $sum = 0;
        foreach ($lines as $line) {
            $sum += self::minor($line['line_total']['value']);
            if (abs(self::minor($line['line_total']['value']) - (int) round((float) $line['quantity']['value'] * self::minor($line['unit_price']['value']))) > 1) {
                $warnings[] = 'The line "'.$line['description']['value'].'" does not equal its quantity times its unit price.';
            }
        }
        if ($total !== null && $lines !== [] && abs($sum - self::minor($total['value'])) > 1) {
            $warnings[] = 'The item lines add up to '.self::major($sum).' but the document total is '.$total['value'].'.';
        }

        return [
            'document_type' => 'supplier_bill',
            'supplier' => $supplier,
            'invoice_number' => $invoice,
            'invoice_date' => $date,
            'total' => $total,
            'lines' => $lines,
            'warnings' => $warnings,
        ];
    }

    /** @return array{value: string, citation: array{text: string, start: int, end: int}}|null */
    private function labelled(string $text, string $pattern): ?array
    {
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $value = trim($m['v'][0]);
        if ($value === '') {
            return null;
        }
        $start = (int) $m[0][1];

        return ['value' => $value, 'citation' => ['text' => $m[0][0], 'start' => $start, 'end' => $start + strlen($m[0][0])]];
    }

    /** The first non-empty line, when no `Supplier:` label exists: the letterhead. Marked by its citation being that line. */
    private function firstLine(string $text): ?array
    {
        if (preg_match('/^[ \t]*(?<v>\S[^\r\n]*?)[ \t]*$/mu', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        // A letterhead is words, not a labelled field or a figure.
        if (preg_match('/:|\d{3,}/', $m['v'][0]) === 1) {
            return null;
        }
        $start = (int) $m['v'][1];

        return ['value' => $m['v'][0], 'citation' => ['text' => $m['v'][0], 'start' => $start, 'end' => $start + strlen($m['v'][0])]];
    }

    /** The document total: the last "grand total / total" line, with a currency when one is written. */
    private function total(string $text): ?array
    {
        $pattern = '/(?:grand[ \t]+total|total[ \t]+amount|total[ \t]+due|amount[ \t]+due|\btotal)[ \t]*[:\-]?[ \t]*(?<c>BDT|Tk\.?|৳|USD|\$)?[ \t]*(?<v>'.self::NUMBER.')/iu';
        if (preg_match_all($pattern, $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
            return null;
        }
        // Prefer a "grand total" over a plain "total" (a subtotal line also contains the word); else the last one.
        $pick = null;
        foreach ($all as $m) {
            if ($pick === null || stripos($m[0][0], 'grand') !== false || stripos($pick[0][0], 'grand') === false) {
                $pick = $m;
            }
        }
        $currency = match (strtoupper(trim((string) ($pick['c'][0] ?? '')))) {
            'USD', '$' => 'USD',
            default => 'BDT',
        };
        $start = (int) $pick[0][1];

        return ['value' => self::major(self::minor($pick['v'][0])), 'currency' => $currency, 'citation' => ['text' => $pick[0][0], 'start' => $start, 'end' => $start + strlen($pick[0][0])]];
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $text): array
    {
        $pattern = '/^[ \t]*(?<d>\S.*?)[ \t]+(?<q>\d+(?:\.\d+)?)[ \t]*[x×@][ \t]*(?:BDT|Tk\.?|৳)?[ \t]*(?<u>'.self::NUMBER.')[ \t]+(?:BDT|Tk\.?|৳)?[ \t]*(?<t>'.self::NUMBER.')[ \t]*$/imu';
        if (preg_match_all($pattern, $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
            return [];
        }
        $lines = [];
        foreach ($all as $m) {
            $start = (int) $m[0][1];
            $citation = ['text' => $m[0][0], 'start' => $start, 'end' => $start + strlen($m[0][0])];
            $lines[] = [
                'description' => ['value' => trim($m['d'][0]), 'citation' => $citation],
                'quantity' => ['value' => $m['q'][0], 'citation' => $citation],
                'unit_price' => ['value' => self::major(self::minor($m['u'][0])), 'citation' => $citation],
                'line_total' => ['value' => self::major(self::minor($m['t'][0])), 'citation' => $citation],
            ];
        }

        return $lines;
    }

    /** "1,800.5" => 180050. Whole minor units, so no float ever holds money. */
    public static function minor(string $amount): int
    {
        $clean = str_replace(',', '', trim($amount));
        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function major(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
