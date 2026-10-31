<?php

namespace App\Media\Pipeline;

use InvalidArgumentException;

/** What a reel draft is asked to be. A brief, a length and a shape: no field for a person's likeness or voice. */
final readonly class MediaJobSpec
{
    public const MAX_SECONDS = 15;

    public const ASPECTS = ['9:16', '16:9', '1:1'];

    public function __construct(
        public string $brief,
        public int $seconds = 8,
        public string $aspect = '9:16',
    ) {
        if (trim($brief) === '' || mb_strlen($brief) > 2000) {
            throw new InvalidArgumentException('A video brief is 1 to 2000 characters.');
        }
        if ($seconds < 1 || $seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('A draft video is at most '.self::MAX_SECONDS.' seconds.');
        }
        if (! in_array($aspect, self::ASPECTS, true)) {
            throw new InvalidArgumentException('The aspect ratio must be one of '.implode(', ', self::ASPECTS).'.');
        }
    }
}
