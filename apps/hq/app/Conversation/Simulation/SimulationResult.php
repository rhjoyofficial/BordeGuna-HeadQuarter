<?php

namespace App\Conversation\Simulation;

/**
 * What one replayed scenario showed: pass or fail, what was expected, what the core did, and every
 * mismatch in words. `expected` and `observed` are kept whole so a failure is readable without
 * re-running.
 *
 * @param  array<string, mixed>  $expected
 * @param  array<string, mixed>  $observed
 * @param  list<string>  $mismatches
 */
final readonly class SimulationResult
{
    public function __construct(
        public string $key,
        public bool $passed,
        public array $expected,
        public array $observed,
        public array $mismatches,
        public ?string $conversationUuid = null,
    ) {}
}
