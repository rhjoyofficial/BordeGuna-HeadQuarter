<?php

namespace App\Ingestion\Extraction;

/**
 * Proves every extracted field against the source text, or drops it (docs/144-email-ingestion).
 *
 * A citation holds when the cited text sits at the stated offsets in the source EXACTLY, and the value the
 * field claims appears inside the cited text. A field that fails is removed and named in `warnings`, so the
 * reviewing person is told what was not trusted. This is the one place the "citation to the source text"
 * guarantee is enforced; an extractor, rule-based or model-assisted, cannot skip it.
 */
final class ExtractionVerifier
{
    /**
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    public function verify(array $extracted, string $source): array
    {
        foreach (['supplier', 'invoice_number', 'invoice_date', 'total'] as $name) {
            if (($extracted[$name] ?? null) !== null && ! $this->holds($extracted[$name], $source)) {
                $extracted['warnings'][] = "The {$name} could not be matched to the document text, so it was left out.";
                $extracted[$name] = null;
            } elseif (($extracted[$name] ?? null) !== null) {
                $extracted[$name]['citation']['page'] = self::pageAt($source, (int) $extracted[$name]['citation']['start']);
            }
        }

        $kept = [];
        foreach ((array) ($extracted['lines'] ?? []) as $line) {
            $ok = true;
            foreach (['description', 'quantity', 'unit_price', 'line_total'] as $key) {
                $ok = $ok && is_array($line[$key] ?? null) && $this->holds($line[$key], $source);
            }
            if ($ok) {
                foreach (['description', 'quantity', 'unit_price', 'line_total'] as $key) {
                    $line[$key]['citation']['page'] = self::pageAt($source, (int) $line[$key]['citation']['start']);
                }
                $kept[] = $line;
            } else {
                $extracted['warnings'][] = 'An item line could not be matched to the document text, so it was left out.';
            }
        }
        $extracted['lines'] = $kept;
        // Ruling 31 Oct item 10: arithmetic, totals, tax and currency are checked deterministically, every time, beside the citations.
        $extracted['checks'] = (new BillChecks)->run($extracted);

        return $extracted;
    }

    /** The 1-based page of a byte offset: pages are separated by a form feed (DocumentTextExtractor). Plain text is page 1. */
    public static function pageAt(string $source, int $offset): int
    {
        return 1 + substr_count(substr($source, 0, max(0, $offset)), DocumentTextExtractor::PAGE_BREAK);
    }

    /** @param  array<string, mixed>  $field */
    public function holds(array $field, string $source): bool
    {
        $c = $field['citation'] ?? null;
        if (! is_array($c) || ! is_string($c['text'] ?? null) || ! is_int($c['start'] ?? null) || ! is_int($c['end'] ?? null)) {
            return false;
        }
        if ($c['start'] < 0 || $c['end'] <= $c['start'] || $c['end'] > strlen($source)) {
            return false;
        }
        if (substr($source, $c['start'], $c['end'] - $c['start']) !== $c['text']) {
            return false;
        }
        $value = (string) ($field['value'] ?? '');
        if ($value === '') {
            return false;
        }
        // The value may be normalised (1,800.00 -> 1800.00): compare digits-only forms for numbers, text otherwise.
        $plain = str_replace(',', '', $c['text']);

        return str_contains($c['text'], $value) || str_contains($plain, $value) || (is_numeric($value) && str_contains(preg_replace('/\D/', '', $c['text']) ?? '', preg_replace('/\D/', '', $value) ?? '!'));
    }
}
