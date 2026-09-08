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
 *   - integers stay integers; floats are formatted with a fixed precision, so 1.0 and 1 do not
 *     collide and 0.1 + 0.2 cannot drift between two runs
 *   - no insignificant whitespace, slashes unescaped, unicode unescaped
 *   - null, true and false encode as themselves rather than as strings
 *
 * What this protects against is not a clever attacker so much as an ordinary accident: a
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

        if (is_float($value)) {
            // Fixed precision so that a value which arrives as 999.0 from one path and 999.00000001
            // from another does not produce two different hashes for the same approved action.
            return round($value, self::FLOAT_PRECISION);
        }

        return $value;
    }
}
