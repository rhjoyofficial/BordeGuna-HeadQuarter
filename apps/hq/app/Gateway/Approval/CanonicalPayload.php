<?php

namespace App\Gateway\Approval;

/**
 * The canonical encoding an approval's hash is taken over (ADR-018).
 *
 * ⚠ There is exactly ONE encoder, used by both the writer and the verifier. Two encoders would
 * eventually disagree about something small, and every approval would then look tampered with,
 * which is the worst possible failure mode for a tamper check: it cries wolf until somebody
 * disables it.
 *
 * The rules:
 *   - object keys sorted, recursively, so key order cannot change the hash
 *   - lists keep their order, because order is meaning in a list
 *   - no insignificant whitespace, slashes unescaped, unicode unescaped
 *   - every scalar is TAGGED with its kind, so a number and a string that look alike do not collide
 *   - a number is written in one normalised decimal form, so 250, 250.0 and 250.00 are one value
 *
 * ⚠ That last rule is not a nicety, and getting it wrong was a real defect caught in CI. A payload
 * is hashed when it is stored and re-hashed when it is executed, with a PostgreSQL jsonb round trip
 * in between. jsonb does not promise to hand `250.0` back as a float rather than an int, so a
 * naive encoder produced two different hashes for one unchanged payload and the tamper check
 * accused an untouched approval of being tampered with. A check that cries wolf gets disabled, so
 * this is the failure mode that matters most.
 *
 * Tagging keeps the distinction that actually matters: the number 1 and the string "1" still hash
 * differently, while 1 and 1.0 do not, because they are the same amount of money.
 *
 * What this protects against is not usually a clever attacker but an ordinary accident: a
 * re-serialisation, a retry that rebuilds arguments, a framework that reorders a JSON object. Any
 * of those would silently change what executes under a human's approval.
 */
final class CanonicalPayload
{
    /** Enough precision for money and quantities; short enough that float noise cannot change it. */
    private const FLOAT_PRECISION = 10;

    public static function encode(array $payload): string
    {
        return (string) json_encode(
            self::normalise($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    /** Constant-time comparison, because this is a security check and not a debug aid. */
    public static function matches(array $payload, string $expectedHash): bool
    {
        return hash_equals($expectedHash, self::hash($payload));
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                // A list's order is part of its meaning: three order lines are not a set.
                return array_map(static fn ($item) => self::normalise($item), $value);
            }

            $normalised = [];
            foreach ($value as $key => $item) {
                $normalised[(string) $key] = self::normalise($item);
            }
            ksort($normalised, SORT_STRING);

            return $normalised;
        }

        // Scalars are tagged so that a number and a string that print the same do not collide.
        return match (true) {
            is_int($value), is_float($value) => 'n:'.self::number($value),
            is_bool($value) => 'b:'.($value ? '1' : '0'),
            is_string($value) => 's:'.$value,
            $value === null => 'z',
            default => 'o:'.gettype($value),
        };
    }

    /**
     * One normalised decimal form for any number.
     *
     * Rounded first, so 0.1 + 0.2 cannot drift between two runs, then trailing zeros and a trailing
     * point are trimmed, so 250, 250.0 and 250.00 all become "250" and survive a jsonb round trip
     * as the same value.
     */
    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $formatted = number_format(round($value, self::FLOAT_PRECISION), self::FLOAT_PRECISION, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
