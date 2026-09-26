<?php

namespace App\Ads\Dto;

use InvalidArgumentException;

/**
 * Money as an ad platform states it, turned into a decimal STRING in the currency's major unit.
 *
 * Meta states budgets, the account spending limit and the amount spent "in basic unit of the
 * currency, for example 'cents' for USD" (Ad Account reference, read 22 September 2026), with an
 * offset per currency (Currencies page: BDT 100, USD 100). Insights `spend` is already a decimal in
 * the major unit. Two helpers, so no caller divides by a number it guessed.
 *
 * ⛔ Never a float: money that went through a float is money somebody has to reconcile later.
 */
final class AdMoney
{
    /** Offsets read from Meta's Currencies page on 22 September 2026. An unknown currency is refused, not guessed. */
    public const OFFSETS = ['BDT' => 100, 'USD' => 100];

    /** "12345" basic units of BDT -> "123.45". Null stays null (no limit set is not a limit of zero). */
    public static function fromMinor(null|int|string $minor, string $currency): ?string
    {
        if ($minor === null || $minor === '') {
            return null;
        }
        $minor = (string) $minor;
        if (! preg_match('/^-?\d+$/', $minor)) {
            throw new InvalidArgumentException("Not an amount in basic units: [{$minor}]");
        }
        $offset = self::offset($currency);
        $digits = strlen((string) $offset) - 1; // 100 -> 2 decimal places
        $negative = str_starts_with($minor, '-');
        $abs = ltrim($negative ? substr($minor, 1) : $minor, '0');
        $abs = str_pad($abs === '' ? '0' : $abs, $digits + 1, '0', STR_PAD_LEFT);
        $major = substr($abs, 0, -$digits).'.'.substr($abs, -$digits);

        return ($negative && trim($major, '0.') !== '' ? '-' : '').$major;
    }

    /** A decimal the platform already states in the major unit ("12.3") -> "12.30" (never a float on the way). */
    public static function major(null|int|float|string $value, string $currency): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;
        if (! preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException("Not a decimal amount: [{$text}]");
        }
        $digits = strlen((string) self::offset($currency)) - 1;
        [$int, $frac] = array_pad(explode('.', $text, 2), 2, '');
        if (strlen($frac) > $digits && trim(substr($frac, $digits), '0') !== '') {
            // More precision than the currency has: refused rather than silently rounded or cut.
            throw new InvalidArgumentException("[{$text}] has more decimal places than {$currency} allows");
        }

        return $int.'.'.substr(str_pad($frac, $digits, '0'), 0, $digits);
    }

    /** A major-unit decimal ("12.30") -> basic units (1230), exactly. */
    public static function toMinor(string $major, string $currency): int
    {
        $normal = self::major($major, $currency);
        $negative = str_starts_with($normal, '-');
        [$int, $frac] = explode('.', ltrim($normal, '-'), 2);
        $minor = (int) $int * self::offset($currency) + (int) $frac;

        return $negative ? -$minor : $minor;
    }

    /**
     * Sums major-unit decimals of ONE currency exactly, in basic units.
     *
     * @param  iterable<string|null>  $amounts
     */
    public static function sum(iterable $amounts, string $currency): string
    {
        $minor = 0;
        foreach ($amounts as $amount) {
            if ($amount !== null && $amount !== '') {
                $minor += self::toMinor((string) $amount, $currency);
            }
        }

        return (string) self::fromMinor((string) $minor, $currency);
    }

    public static function offset(string $currency): int
    {
        $currency = strtoupper($currency);
        if (! isset(self::OFFSETS[$currency])) {
            throw new InvalidArgumentException("Currency [{$currency}] has no recorded offset; add it from Meta's Currencies page before reading it.");
        }

        return self::OFFSETS[$currency];
    }
}
