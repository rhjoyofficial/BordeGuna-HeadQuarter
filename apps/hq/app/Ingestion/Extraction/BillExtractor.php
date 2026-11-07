<?php

namespace App\Ingestion\Extraction;

/**
 * Structured fields from a supplier document's text (docs/144-email-ingestion).
 *
 * ⭐ **Every field carries its citation**: the exact text it was read from and where that text sits in the
 * source (`citation: {text, start, end}`, byte offsets). `ExtractionVerifier` re-checks each citation against the
 * source and drops any field it cannot prove, so a person reviewing a draft always sees the words behind a
 * number, and a field nobody can point at never reaches the screen. An extractor may be rule-based (the default)
 * or model-assisted later through `ai.complete`; both answer this shape and both pass the same verifier.
 */
interface BillExtractor
{
    public function key(): string;

    /**
     * @return array{
     *     document_type: string,
     *     supplier: ?array{value: string, citation: array{text: string, start: int, end: int}},
     *     invoice_number: ?array{value: string, citation: array{text: string, start: int, end: int}},
     *     invoice_date: ?array{value: string, citation: array{text: string, start: int, end: int}},
     *     total: ?array{value: string, currency: string, citation: array{text: string, start: int, end: int}},
     *     lines: list<array<string, mixed>>,
     *     warnings: list<string>
     * }
     */
    public function extract(string $text): array;
}
