<?php

namespace App\Gateway\Recording;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\FailureClass;
use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyDecision;
use App\Gateway\Redaction\Redactor;
use App\Gateway\Scope\ResolvedScope;
use App\Gateway\ToolRequest;
use App\Models\ToolCall;
use Carbon\CarbonImmutable;

/**
 * Writes and completes the `tool_calls` row for every gateway invocation.
 *
 * Two writes, not one. The row is opened on entry with `status = started`, before anything can go
 * wrong, and completed on exit. A row left reading `started` therefore means the process died
 * mid-call, which is a fact worth being able to see; a recorder that wrote once at the end would
 * record nothing at all for exactly the failures hardest to diagnose.
 *
 * Everything persisted here has been through the Redactor first.
 */
final class ToolCallRecorder
{
    public function __construct(private readonly Redactor $redactor) {}

    /** Opens the row. Called before validation, so even a malformed call leaves a trace. */
    public function open(ToolRequest $request, ?ToolDescriptor $descriptor): ToolCall
    {
        $identity = $request->identity;

        return ToolCall::create([
            'tool_key' => $request->toolKey,
            'tool_version' => $descriptor?->version,
            'risk_tier' => $descriptor?->riskTier->value,
            'caller_kind' => $request->caller->value,
            'actor_type' => $identity->kind->value,
            'actor_id' => $identity->actorId(),
            'actor_label' => $identity->label,
            'agent_key' => $identity->agent?->key,
            'enabling_user_id' => $identity->enablingUser()?->getKey(),
            'business_id' => $request->businessId,
            'store_id' => $request->storeId,
            'job_run_id' => $request->jobRunId,
            'ai_run_id' => $request->aiRunId,
            'correlation_id' => $request->correlationId,
            // The raw arguments, redacted. Replaced with the validated set once validation passes,
            // so the row ends up holding exactly what the tool was given (ADR-018's habit, applied
            // before there is an approval to bind).
            'input' => $this->redactor->redact($request->args, $descriptor?->redact ?? []),
            'status' => OutcomeStatus::Started->value,
            'attempt' => $request->attempt,
            'started_at' => now(),
        ]);
    }

    public function recordValidatedArgs(ToolCall $call, array $args, ToolDescriptor $descriptor): void
    {
        $call->input = $this->redactor->redact($args, $descriptor->redact);
        $call->save();
    }

    /**
     * The decision, the rule that reached it, and the exact policy VERSION that governed it.
     *
     * The version is what makes a past decision reproducible: a policy change closes one row and
     * opens the next, so this row still resolves to rules that read as they did when they decided.
     */
    public function recordPolicy(ToolCall $call, PolicyDecision $decision): void
    {
        $call->policy_result = $decision->outcome->value;
        $call->policy_reason = "{$decision->rule}: {$decision->reason}";
        $call->policy_id = $decision->policyId;
        $call->policy_version = $decision->policyVersion;
        $call->action_class = $decision->actionClass()->value;
        $call->save();
    }

    /**
     * The business and store the call actually targeted, once resolved.
     *
     * Recorded separately from `open()` because the target may live in the tool's arguments, which
     * have not been validated yet when the row is opened.
     */
    public function recordScope(ToolCall $call, ResolvedScope $scope): void
    {
        if ($scope->businessId === null && $scope->storeId === null) {
            return;
        }
        $call->business_id = $scope->businessId;
        $call->store_id = $scope->storeId;
        $call->save();
    }

    public function recordIdempotencyKey(ToolCall $call, string $key): void
    {
        $call->idempotency_key = $key;
        $call->save();
    }

    /**
     * Completes the row.
     *
     * `$secrets` is the last line of defence for free text: a connector that interpolated a token
     * into its own exception message would otherwise write it straight into `tool_calls.error`.
     *
     * @param  list<string>  $secrets
     */
    public function complete(
        ToolCall $call,
        OutcomeStatus $status,
        array $output = [],
        ?string $error = null,
        ?FailureClass $failure = null,
        ?ToolDescriptor $descriptor = null,
        bool $replayed = false,
        array $secrets = [],
    ): ToolCall {
        $started = $call->started_at ? CarbonImmutable::parse($call->started_at) : null;

        $call->fill([
            'status' => $status->value,
            'output' => $output === [] ? null : $this->redactor->redact($output, $descriptor?->redact ?? []),
            'error' => $error === null ? null : mb_substr((string) $this->redactor->scrub($error, $secrets), 0, 2000),
            'failure_class' => $failure?->value,
            'replayed' => $replayed,
            'finished_at' => now(),
            'duration_ms' => $started ? (int) $started->diffInMilliseconds(now()) : null,
        ])->save();

        return $call;
    }
}
