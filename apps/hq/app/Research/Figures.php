<?php

namespace App\Research;

use App\Briefing\NarrativeNumberCheck;

/**
 * The figures in a piece of text, written one way (the `briefing.numbers.v1` normalisation), so that
 * "does this statement say a number its source does not" has a deterministic answer.
 *
 * Bangla digits are read as digits; thousands separators in either grouping are removed; leading and
 * trailing zeros do not count. Nothing is rounded or computed.
 */
final class Figures
{
    private const BANGLA_DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];

    /** @return list<string> */
    public static function in(string $text): array
    {
        $text = strtr($text, self::BANGLA_DIGITS);
        if (preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d{1,2}(?:,\d{2})*,\d{3}(?:\.\d+)?|\d+(?:\.\d+)?/u', $text, $m) < 1) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $f): string => NarrativeNumberCheck::normalise($f),
            $m[0],
        )));
    }

    /** The figures of `$statement` that do not appear in any of `$support`. @param list<string> $support */
    public static function unsupported(string $statement, array $support): array
    {
        $supported = [];
        foreach ($support as $text) {
            foreach (self::in($text) as $f) {
                $supported[$f] = true;
            }
        }

        return array_values(array_filter(self::in($statement), static fn (string $f): bool => ! isset($supported[$f])));
    }
}
