<?php

namespace App\Briefing;

use InvalidArgumentException;

/**
 * Money as integer paisa. Order facts store `decimal(14,2)`, which PHP reads as a string; summing
 * those as floats would make a total depend on the order of addition, and a briefing figure has to
 * be the same number every time it is computed from the same rows.
 */
final class Money
{
    public static function toPaisa(string|int|float|null $amount): int
    {
        $text = trim((string) ($amount ?? '0'));

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException("[{$text}] is not an amount with at most two decimals.");
        }

        $paisa = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$paisa : $paisa;
    }

    public static function fromPaisa(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $paisa = abs($paisa);

        return sprintf('%s%d.%02d', $sign, intdiv($paisa, 100), $paisa % 100);
    }
}
