<?php

namespace App\Workflow;

use Closure;
use InvalidArgumentException;

/**
 * One declared step. Three kinds, and only three:
 *
 *   tool    a Tool Gateway call; its arguments are computed from the state
 *   signal  wait for a named signal (or a domain event, as "event:{type}"), optionally with a timeout
 *   sleep   wait until a time, computed once and persisted
 *
 * An approval is not a kind: a tool step whose policy requires approval parks the run on the approval
 * the gateway created, in the ordinary `approvals` table.
 */
final class Step
{
    public const TOOL = 'tool';

    public const SIGNAL = 'signal';

    public const SLEEP = 'sleep';

    private function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $toolKey = null,
        public readonly ?Closure $args = null,
        public readonly ?string $signal = null,
        public readonly int|Closure|null $seconds = null,
        public readonly ?Closure $when = null,
        public readonly ?int $maxAttempts = null,
        public readonly ?int $backoffSeconds = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $name) !== 1) {
            throw new InvalidArgumentException("Step name [{$name}] must be 1 to 64 of a-z 0-9 _ . -");
        }
    }

    /** @param  Closure(WorkflowState): array<string, mixed>  $args */
    public static function tool(string $name, string $toolKey, Closure $args): self
    {
        return new self($name, self::TOOL, toolKey: $toolKey, args: $args);
    }

    public static function awaitSignal(string $name, string $signal, ?int $timeoutSeconds = null): self
    {
        return new self($name, self::SIGNAL, signal: $signal, seconds: $timeoutSeconds);
    }

    public static function awaitEvent(string $name, string $eventType, ?int $timeoutSeconds = null): self
    {
        return self::awaitSignal($name, 'event:'.$eventType, $timeoutSeconds);
    }

    /** @param  int|Closure(WorkflowState): int  $seconds */
    public static function sleep(string $name, int|Closure $seconds): self
    {
        return new self($name, self::SLEEP, seconds: $seconds);
    }

    /** @param  Closure(WorkflowState): bool  $condition  false = the step is skipped */
    public function when(Closure $condition): self
    {
        return new self(...[...get_object_vars($this), 'when' => $condition]);
    }

    /** Automatic attempts for a failed or uncertain tool step, with exponential backoff from `$backoffSeconds`. */
    public function retries(int $maxAttempts, int $backoffSeconds = 60): self
    {
        return new self(...[...get_object_vars($this), 'maxAttempts' => max(1, $maxAttempts), 'backoffSeconds' => max(0, $backoffSeconds)]);
    }
}
