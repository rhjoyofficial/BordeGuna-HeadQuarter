<?php

namespace App\Ingestion\Extraction;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The text of an attachment, or null when HQ cannot read it. PDFs go through `smalot/pdfparser` (a proven
 * package, docs/96), plain text and CSV are read as they are. A scanned image or a PDF with no text layer
 * needs OCR, which is a separate provider decision (docs/144-email-ingestion): it answers null, never a guess.
 */
final class DocumentTextExtractor
{
    public const MAX_CHARS = 200000;

    /** The form feed character that separates PDF pages in the extracted text (ruling 31 Oct item 10). */
    public const PAGE_BREAK = "\f";

    public function text(string $bytes, string $mime): ?string
    {
        $text = match (true) {
            $mime === 'application/pdf' => $this->pdf($bytes),
            str_starts_with($mime, 'text/') => $bytes,
            default => null,
        };
        if ($text === null) {
            return null;
        }
        $text = str_replace("\r\n", "\n", $text);
        $text = mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = substr($text, 0, self::MAX_CHARS);

        return trim($text) === '' ? null : $text;
    }

    private function pdf(string $bytes): ?string
    {
        try {
            // Ruling 31 Oct item 10: pages are joined with a form feed on its own line, so every citation can name the PAGE it came
            // from (ExtractionVerifier::pageAt). A page with no text still counts, so page numbers match the document.
            $pages = [];
            foreach ((new Parser)->parseContent($bytes)->getPages() as $page) {
                $pages[] = $page === null ? '' : trim(str_replace(self::PAGE_BREAK, ' ', $page->getText()));
            }

            return implode("\n".self::PAGE_BREAK."\n", $pages);
        } catch (Throwable) {
            return null; // a damaged or protected PDF is unreadable, not an error to retry
        }
    }
}
