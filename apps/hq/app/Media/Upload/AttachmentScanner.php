<?php

namespace App\Media\Upload;

/**
 * A second layer behind `AttachmentGuard`: a virus scan of the bytes. ClamAV (`ClamdAttachmentScanner`) is the
 * proven choice (docs/96: integrate, do not hand-roll a scanner). Without one configured the answer is
 * `unscanned`, which is recorded on the attachment, never reported as clean.
 */
interface AttachmentScanner
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const UNSCANNED = 'not_scanned';

    /** @return array{0: string, 1: ?string} [CLEAN|INFECTED|UNSCANNED, signature name or reason] */
    public function scan(string $bytes): array;
}
