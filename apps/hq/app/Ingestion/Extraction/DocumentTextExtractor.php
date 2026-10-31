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
            return (new Parser)->parseContent($bytes)->getText();
        } catch (Throwable) {
            return null; // a damaged or protected PDF is unreadable, not an error to retry
        }
    }
}
