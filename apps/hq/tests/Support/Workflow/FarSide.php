<?php

namespace Tests\Support\Workflow;

/**
 * Stands in for the world outside HQ in the workflow tests: every effect a test tool "lands" is one
 * entry here, keyed by the idempotency key the gateway handed the tool. It deliberately survives a
 * simulated worker restart (the container is rebuilt; the far side is not), so "one effect" is
 * measured where it matters.
 */
final class FarSide
{
    /** @var list<array{tool: string, key: string, args: array<string, mixed>}> */
    public static array $effects = [];

    /** Planned failures per tool key: 'fail' returns a failed result, 'uncertain' lands the effect then throws Uncertain, 'unrecordable' lands it and returns output the recorder cannot store. */
    public static array $plan = [];

    public static function reset(): void
    {
        self::$effects = [];
        self::$plan = [];
    }

    public static function count(string $tool): int
    {
        return count(array_filter(self::$effects, fn (array $e) => $e['tool'] === $tool));
    }

    public static function has(string $key): bool
    {
        return array_filter(self::$effects, fn (array $e) => $e['key'] === $key) !== [];
    }

    public static function next(string $tool): ?string
    {
        if (empty(self::$plan[$tool])) {
            return null;
        }

        return array_shift(self::$plan[$tool]);
    }
}
