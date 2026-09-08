<?php

namespace App\Gateway\Validation;

/**
 * Deterministic and readable by the caller: a list of what was wrong, not a boolean.
 *
 * `$normalised` is what everything downstream uses. Storing the raw arguments and validating a
 * coerced copy would mean the audit records one thing and the tool receives another, which is
 * exactly the seam an approval binding has to close later (ADR-018). One value, validated, used.
 */
final readonly class ValidationResult
{
    /** @param list<string> $errors */
    private function __construct(
        public bool $valid,
        public array $normalised,
        public array $errors = [],
    ) {}

    public static function pass(array $normalised): self
    {
        return new self(true, $normalised);
    }

    /** @param list<string> $errors */
    public static function fail(array $errors): self
    {
        return new self(false, [], $errors);
    }

    public function message(): string
    {
        return implode('; ', $this->errors);
    }
}
