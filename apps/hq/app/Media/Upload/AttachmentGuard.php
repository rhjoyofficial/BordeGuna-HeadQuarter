<?php

namespace App\Media\Upload;

/**
 * What HQ is willing to store from an upload, decided from the BYTES and never from the name or the
 * browser's claimed type (docs/144-media-in-chat).
 *
 * ⛔ An allowlist, not a blocklist: a type not named here is refused, so an executable, a script, an HTML or
 * SVG page (which can run in a browser) or an archive cannot be stored by renaming it. PDFs are accepted
 * only without active content (JavaScript, launch actions, embedded files). Size limits are per kind.
 * The guard is not a virus scanner: `AttachmentScanner` is the second layer, and files are only ever served
 * back as attachments with `nosniff`, never rendered by HQ.
 */
final class AttachmentGuard
{
    /** mime => [kind, config key of the size limit in MB, default MB] */
    public const TYPES = [
        'image/jpeg' => ['image', 'image_mb', 10],
        'image/png' => ['image', 'image_mb', 10],
        'image/webp' => ['image', 'image_mb', 10],
        'video/mp4' => ['video', 'video_mb', 60],
        'video/webm' => ['video', 'video_mb', 60],
        'video/quicktime' => ['video', 'video_mb', 60],
        'application/pdf' => ['document', 'document_mb', 15],
        'text/plain' => ['document', 'text_mb', 2],
        'text/csv' => ['document', 'text_mb', 2],
        'audio/webm' => ['audio', 'audio_mb', 10],
        'audio/ogg' => ['audio', 'audio_mb', 10],
        'audio/mpeg' => ['audio', 'audio_mb', 10],
        'audio/wav' => ['audio', 'audio_mb', 10],
        'audio/mp4' => ['audio', 'audio_mb', 10],
    ];

    /** Markers of a PDF that can act on its own. */
    private const PDF_ACTIVE = ['/JavaScript', '/JS ', '/JS(', '/Launch', '/EmbeddedFile', '/RichMedia', '/XFA'];

    /**
     * @param  list<string>  $kinds  the kinds this upload may be (`image`, `video`, `document`, `audio`)
     * @return array{mime: string, kind: string}
     *
     * @throws AttachmentRejected
     */
    public function inspect(string $bytes, array $kinds): array
    {
        if ($bytes === '') {
            throw new AttachmentRejected('The file is empty.');
        }
        $mime = $this->sniff($bytes);
        if ($mime === null || ! isset(self::TYPES[$mime])) {
            throw new AttachmentRejected('This file type is not accepted. Photos (JPEG, PNG, WebP), videos (MP4, WebM, MOV), PDFs and plain text or CSV are.');
        }
        [$kind, $limitKey, $defaultMb] = self::TYPES[$mime];
        if (! in_array($kind, $kinds, true)) {
            throw new AttachmentRejected("A {$kind} file is not accepted here.");
        }
        $limit = (int) config("hq.media.limits.{$limitKey}", $defaultMb) * 1024 * 1024;
        if (strlen($bytes) > $limit) {
            throw new AttachmentRejected("The file is over the {$limit}-byte limit for a {$kind}.");
        }
        if ($mime === 'application/pdf') {
            foreach (self::PDF_ACTIVE as $marker) {
                if (str_contains($bytes, $marker)) {
                    throw new AttachmentRejected('This PDF contains active content (scripts or embedded files) and is not accepted.');
                }
            }
        }

        return ['mime' => $mime, 'kind' => $kind];
    }

    /** The real type from the leading bytes; null when it is not one HQ recognises. */
    public function sniff(string $bytes): ?string
    {
        $head = substr($bytes, 0, 16);
        $type = match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE' => 'audio/wav',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            substr($head, 4, 4) === 'ftyp' => self::ftyp($head),
            str_starts_with($head, "\x1A\x45\xDF\xA3") => self::webm($bytes),
            str_starts_with($head, 'OggS') => 'audio/ogg',
            str_starts_with($head, 'ID3'), str_starts_with($head, "\xFF\xFB"), str_starts_with($head, "\xFF\xF3") => 'audio/mpeg',
            default => null,
        };
        if ($type !== null) {
            return $type;
        }

        // Text: valid UTF-8, no control bytes, and nothing that looks like markup or a script.
        $sample = substr($bytes, 0, 8192);
        if (mb_check_encoding($sample, 'UTF-8') && preg_match('/[\x00-\x08\x0E-\x1F]/', $sample) !== 1
            && preg_match('/<\s*(script|html|svg|\?php|iframe)|^\s*#!/i', $sample) !== 1 && ! str_starts_with($sample, 'MZ')) {
            return str_contains($sample, ',') && substr_count($sample, "\n") >= 1 && preg_match('/^[^\n]*,[^\n]*\n/', $sample) === 1 ? 'text/csv' : 'text/plain';
        }

        return null;
    }

    private static function ftyp(string $head): string
    {
        $brand = substr($head, 8, 4);

        return in_array($brand, ['M4A ', 'M4B '], true) ? 'audio/mp4' : (str_starts_with($brand, 'qt') ? 'video/quicktime' : 'video/mp4');
    }

    /** A WebM container with only audio tracks is audio; this cannot tell, so it is accepted as video unless the caller wants audio. */
    private static function webm(string $bytes): string
    {
        return str_contains(substr($bytes, 0, 4096), 'V_VP') || str_contains(substr($bytes, 0, 4096), 'V_AV1') ? 'video/webm' : 'audio/webm';
    }
}
