<?php

namespace App\Evaluation;

/**
 * One graded case.
 *
 * The reason is mandatory on a failure and is written for somebody reading a regression report six
 * months from now, not for the person who just ran it.
 */
final readonly class GradeResult
{
    public function __construct(
        public bool $passed,
        public ?float $score = null,
        public ?string $reason = null,
        public array $expected = [],
        public array $actual = [],
    ) {}

    public static function pass(array $expected = [], array $actual = [], ?float $score = 1.0): self
    {
        return new self(true, $score, null, $expected, $actual);
    }

    public static function fail(string $reason, array $expected = [], array $actual = [], ?float $score = 0.0): self
    {
        return new self(false, $score, $reason, $expected, $actual);
    }
}
