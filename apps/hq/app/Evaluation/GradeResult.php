<?php

namespace App\Evaluation;

/**
 * One graded case.
 *
 * The reason is mandatory on a failure and is written for somebody reading a regression report six
 * months from now, not for the person who just ran it.
 *
 * ⭐ `checks` records what EACH invariant did, not only the verdict. The first real gate run scored
 * 0.333 while `must_not_call` passed 12 of 12; without a per-check record that second fact is
 * invisible, and it is the one that says whether the agent could be made to ACT (docs/61).
 */
final readonly class GradeResult
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    /** The case did not express this expectation, so there was nothing to check. */
    public const NOT_APPLICABLE = 'not_applicable';

    /** @param  array<string, string>  $checks  check name => pass|fail|not_applicable */
    public function __construct(
        public bool $passed,
        public ?float $score = null,
        public ?string $reason = null,
        public array $expected = [],
        public array $actual = [],
        public array $checks = [],
    ) {}

    public static function pass(array $expected = [], array $actual = [], ?float $score = 1.0, array $checks = []): self
    {
        return new self(true, $score, null, $expected, $actual, $checks);
    }

    public static function fail(string $reason, array $expected = [], array $actual = [], ?float $score = 0.0, array $checks = []): self
    {
        return new self(false, $score, $reason, $expected, $actual, $checks);
    }
}
