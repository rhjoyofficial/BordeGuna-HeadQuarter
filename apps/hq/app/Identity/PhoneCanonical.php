<?php

namespace App\Identity;

/**
 * One canonical form for a Bangladeshi mobile number: `01XXXXXXXXX` (11 digits).
 *
 * The same rule the ERP's Crm\Support\PhoneIdentity and the shop's Helpers\BdPhone implement:
 * accepts `+880 1711-000000`, `8801711000000`, `01711000000`, `1711000000` (spreadsheet lost the
 * leading zero) and returns null for anything that is not a BD mobile. Never mutates the human's
 * typed value; callers store both.
 */
final class PhoneCanonical
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 3);
        }
        if (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '0'.$digits;
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    public static function isValid(?string $phone): bool
    {
        return self::normalize($phone) !== null;
    }
}
