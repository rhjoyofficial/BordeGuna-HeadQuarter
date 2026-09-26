<?php

namespace App\Content\Render;

/**
 * What a renderer must be able to do for a piece of Bangla (docs/85 A.6).
 *
 * On 12 July 2026 the n8n pipeline drew "গার্ডেন" as "গার্ডনে" and "তেল" as "তলে": GraphicsMagick has no
 * shaping engine and paints a pre-base vowel sign (ি, ে, ৈ, and the two-part ো, ৌ) where it sits in the
 * code points, after its consonant, instead of before it; conjuncts made with the hasanta (্) break
 * the same way. Any text containing one of these needs a shaping engine. HQ's only renderer is a
 * browser (headless Chromium), which shapes Bangla correctly.
 */
final class BanglaText
{
    /** Pre-base and two-part vowel signs, and the hasanta that builds conjuncts. */
    private const NEEDS_SHAPING = '/[\x{09BF}\x{09C7}\x{09C8}\x{09CB}\x{09CC}\x{09CD}]/u';

    public static function isBangla(string $text): bool
    {
        return preg_match('/\p{Bengali}/u', $text) === 1;
    }

    public static function needsShaping(string $text): bool
    {
        return preg_match(self::NEEDS_SHAPING, $text) === 1;
    }
}
