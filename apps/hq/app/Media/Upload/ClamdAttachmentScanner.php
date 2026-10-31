<?php

namespace App\Media\Upload;

use Throwable;

/**
 * ClamAV's `clamd` over TCP, the `INSTREAM` command: send `zINSTREAM\0`, then the bytes in length-prefixed
 * chunks, then a zero-length chunk; the reply is `stream: OK` or `stream: <signature> FOUND`. Fail safe: a
 * scanner that cannot be reached answers `not_scanned`, never `clean`, so an outage cannot make a file look safe.
 */
final class ClamdAttachmentScanner implements AttachmentScanner
{
    public function scan(string $bytes): array
    {
        $host = (string) config('hq.media.clamd.host', '127.0.0.1');
        $port = (int) config('hq.media.clamd.port', 3310);

        try {
            $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, (float) config('hq.media.clamd.timeout', 5));
            if ($socket === false) {
                return [self::UNSCANNED, 'the virus scanner could not be reached'];
            }
            stream_set_timeout($socket, (int) config('hq.media.clamd.timeout', 5));
            fwrite($socket, "zINSTREAM\0");
            foreach (str_split($bytes, 8192) as $chunk) {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0 \n");
            fclose($socket);
        } catch (Throwable) {
            return [self::UNSCANNED, 'the virus scanner failed'];
        }

        if (str_ends_with($reply, 'OK')) {
            return [self::CLEAN, null];
        }
        if (str_ends_with($reply, 'FOUND')) {
            return [self::INFECTED, trim(substr($reply, (int) strpos($reply, ':') + 1, -5))];
        }

        return [self::UNSCANNED, 'the virus scanner gave no clear answer'];
    }
}
