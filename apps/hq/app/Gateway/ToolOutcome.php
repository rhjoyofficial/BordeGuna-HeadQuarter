<?php

namespace App\Gateway;

use App\Gateway\Policy\PolicyOutcome;
use App\Tools\ToolResult;

/**
 * What the gateway gives back.
 *
 * Richer than ToolResult on purpose: a caller needs to tell "the tool ran and failed" apart from
 * "policy refused before anything ran" apart from "the arguments were wrong". Collapsing those into
 * one failure would lose exactly the distinction the control plane exists to make.
 */
final readonly class ToolOutcome
{
    public function __construct(
        public OutcomeStatus $status,
        public array $output = [],
        public ?string $error = null,
        public ?FailureClass $failure = null,
        public PolicyOutcome $policy = PolicyOutcome::Deny,
        public ?string $policyReason = null,
        public ?int $toolCallId = null,
        public bool $replayed = false,
        public int $durationMs = 0,
        public ?string $providerRef = null,
        /** Set when the call was parked for a human, or executed under an approval. */
        public ?int $approvalId = null,
    ) {}

    public function ok(): bool
    {
        return $this->status->ok();
    }

    public static function fromToolResult(ToolResult $result, PolicyOutcome $policy, int $durationMs): self
    {
        $status = match ($result->status) {
            ToolResult::STATUS_SUCCEEDED => OutcomeStatus::Succeeded,
            ToolResult::STATUS_UNCERTAIN => OutcomeStatus::Uncertain,
            default => OutcomeStatus::Failed,
        };

        return new self(
            status: $status,
            output: $result->output,
            error: $result->error,
            failure: $status === OutcomeStatus::Uncertain ? FailureClass::Uncertain
                : ($status === OutcomeStatus::Failed ? FailureClass::Refused : null),
            policy: $policy,
            durationMs: $durationMs,
            providerRef: $result->providerRef,
        );
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'output' => $this->output,
            'error' => $this->error,
            'failure' => $this->failure?->value,
            'policy' => $this->policy->value,
            'policy_reason' => $this->policyReason,
            'tool_call_id' => $this->toolCallId,
            'replayed' => $this->replayed,
            'duration_ms' => $this->durationMs,
            'approval_id' => $this->approvalId,
        ];
    }
}
