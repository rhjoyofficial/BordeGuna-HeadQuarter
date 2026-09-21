<?php

namespace App\Gateway\Approval;

use App\Gateway\CallerKind;
use App\Gateway\FailureClass;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\OutcomeStatus;
use App\Gateway\Policy\PolicyOutcome;
use App\Gateway\ToolGateway;
use App\Gateway\ToolOutcome;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\ToolCall;
use App\Models\User;

/**
 * Turns a human's "yes" into an execution, and is a CALLER of the Tool Gateway rather than a second
 * executor (brief section 8).
 *
 * There is deliberately no privileged approval path. The approved call goes through exactly the same
 * pipeline as any other: registry, schema, scope, permission, policy, idempotency, credentials. That
 * is what makes the authority re-check of section 27 free rather than a checklist somebody has to
 * remember to keep in step with the gateway.
 *
 * The order here is the whole security argument:
 *
 *   1. guard    the approval-specific checks: state, expiry, payload hash, tool version, tool enabled
 *   2. claim    an atomic, database-enforced transition out of `pending`, so exactly one caller wins
 *   3. execute  through the gateway, which re-checks everything else as a matter of course
 *   4. consume  spend the approval whatever the tool did
 *
 * Claiming BEFORE executing is deliberate. A failed execution must not leave a re-usable approval,
 * because that would turn one human decision into an unlimited retry budget.
 */
final class ApprovalExecutor
{
    public function __construct(
        private readonly ApprovalGuard $guard,
        private readonly ApprovalService $approvals,
        private readonly ToolGateway $gateway,
    ) {}

    /**
     * Approves and executes. Returns the outcome, or a refusal that never touched a connector.
     */
    public function approveAndExecute(Approval $approval, User $approver, ?string $note = null): ToolOutcome
    {
        // 0. Who may decide (docs/81 P0-4 and C.6): the separation rule, the decide permission and
        //    delegation, here as well as on the screen, because this is the only door to an
        //    execution and a rule the controller alone held would be skipped by the next caller.
        //    Before the claim: a refusal leaves the approval pending for somebody else.
        if (($barred = $approval->decisionRefusalReason($approver)) !== null) {
            return $this->refusal($approval, $barred);
        }

        // 1. Approval-specific checks, before anything is claimed. A refusal here leaves the
        //    approval exactly as it was, still pending and still decidable.
        if (($blocked = $this->guard->preClaimReason($approval)) !== null) {
            // A hash mismatch is not an ordinary refusal: it means the row changed underneath the
            // application, which cannot happen through any code path here.
            if (! $approval->payloadIntact()) {
                $this->approvals->recordIntegrityFailure($approval, $blocked);
            }

            return $this->refusal($approval, $blocked);
        }

        // 2. Claim it. Exactly one of any number of simultaneous callers gets a row back.
        $claimed = $this->approvals->claim($approval, $approver, $note);
        if ($claimed === null) {
            return $this->refusal($approval->fresh(), 'Somebody else decided this approval first, and an approval is single use.');
        }

        // 3. Re-run the guard now that the state has moved to `approved`, which is the state the
        //    guard requires. Anything that changed between the two reads is caught here.
        if (($blocked = $this->guard->blockingReason($claimed)) !== null) {
            if (! $claimed->payloadIntact()) {
                $this->approvals->recordIntegrityFailure($claimed, $blocked);
            }

            // The approval stays `approved` and unconsumed: a human said yes and it did not run.
            // It cannot be approved again, so a new proposal is required rather than a retry.
            return $this->refusal($claimed, $blocked);
        }

        // 4. Execute through the ONE gateway, carrying the approval so that policy's
        //    `require_approval` is satisfied and nothing else is.
        $outcome = $this->gateway->execute($this->requestFor($claimed, $approver), $claimed);

        // 5. Spend it, whatever the tool did. The approval was used either way.
        if ($outcome->toolCallId !== null && ($call = ToolCall::find($outcome->toolCallId)) !== null) {
            $this->approvals->consume($claimed, $call);
        }

        $this->approvals->recordDecision($claimed, 'approved', $approver, $note);

        return new ToolOutcome(
            status: $outcome->status,
            output: $outcome->output,
            error: $outcome->error,
            failure: $outcome->failure,
            policy: $outcome->policy,
            policyReason: $outcome->policyReason,
            toolCallId: $outcome->toolCallId,
            replayed: $outcome->replayed,
            durationMs: $outcome->durationMs,
            providerRef: $outcome->providerRef,
            approvalId: $claimed->id,
        );
    }

    /**
     * Rebuilds the request from the STORED approval.
     *
     * ⚠ Nothing here comes from a caller. The tool key and the payload are read from the row a human
     * approved, which is what makes the browser endpoint safe: it supplies an approval identifier and
     * a decision, and the server derives everything else (brief section 19).
     *
     * The call runs under the APPROVER's authority, and the original agent and correlation id are
     * preserved on the approval so the trace joins up and attribution is not lost.
     */
    private function requestFor(Approval $approval, User $approver): ToolRequest
    {
        return new ToolRequest(
            toolKey: $approval->tool_key,
            args: $approval->payload ?? [],
            caller: CallerKind::HumanUi,
            identity: ActingIdentity::human($approver),
            correlationId: (string) ($approval->correlation_id ?? $approval->uuid),
            businessId: $approval->business_id,
            storeId: $approval->store_id,
            brandId: $approval->brand_id,
            // The approval itself is the unit of single use; the gateway still owns tool-call
            // idempotency separately (ADR-011). Keying on the approval means a redelivery of this
            // execution replays rather than repeats.
            idempotencyKey: "approval:{$approval->uuid}",
        );
    }

    private function refusal(Approval $approval, string $reason): ToolOutcome
    {
        return new ToolOutcome(
            status: OutcomeStatus::Denied,
            error: $reason,
            failure: FailureClass::Refused,
            policy: PolicyOutcome::Deny,
            policyReason: $reason,
            approvalId: $approval->id,
        );
    }
}
