<?php

namespace App\Media\Upload;

/** The scanner the configuration names. One place, so an upload and a mail attachment are scanned the same way. */
final class Scanners
{
    public static function make(): AttachmentScanner
    {
        return config('hq.media.scanner') === 'clamd' ? new ClamdAttachmentScanner : new NullAttachmentScanner;
    }
}
