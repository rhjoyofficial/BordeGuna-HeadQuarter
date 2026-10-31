<?php

namespace App\Media\Upload;

/** No scanner configured: says so. Never `clean`. */
final class NullAttachmentScanner implements AttachmentScanner
{
    public function scan(string $bytes): array
    {
        return [self::UNSCANNED, 'no virus scanner is configured (hq.media.scanner)'];
    }
}
