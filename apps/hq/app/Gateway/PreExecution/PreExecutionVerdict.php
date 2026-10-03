<?php

namespace App\Gateway\PreExecution;

/**
 * What the pre-execution checks decided: passed, or refused by one named check with its reason and its
 * structured result (for `reply_script`, a `ScriptPolicyResult` as data: counts, never the text).
 */
final readonly class PreExecutionVerdict
{
    /** @param  array<string, mixed>|null  $result */
    private function __construct(
        public bool $passed,
        public ?string $check,
        public ?string $reason,
        public ?array $result,
    ) {}

    /** @param  array<string, mixed>|null  $result  the last check's result, when one ran */
    public static function passed(?array $result = null): self
    {
        return new self(true, null, null, $result);
    }

    /** @param  array<string, mixed>  $result */
    public static function refused(string $check, string $reason, array $result): self
    {
        return new self(false, $check, $reason, $result);
    }

    /** @return array{check: ?string, passed: bool, reason: ?string, result: ?array} */
    public function toArray(): array
    {
        return ['check' => $this->check, 'passed' => $this->passed, 'reason' => $this->reason, 'result' => $this->result];
    }
}
