<?php

namespace App\Gateway\Approval;

use App\Gateway\Registry\ToolAvailability;
use App\Models\Approval;
use App\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The checks that stand between a human's "yes" and an execution.
 *
 * ⚠ **The rule, stated once and implemented here (docs/48 G.2, brief sections 9 and 27):**
 *
 *   **The PAYLOAD is immutable. The AUTHORITY is re-evaluated at execution time.**
 *
 * An approval is permission to perform one specific action. It is not a permanent exemption from
 * the rules that were in force when it was granted. Yesterday's yes does not survive a tool being
 * switched off this morning, a permission being revoked, a global freeze, or the tool's contract
 * changing underneath it.
 *
 * The division of labour is deliberate:
 *
 *   HERE, before the pipeline    everything specific to the approval itself: its state, its expiry,
 *                                the payload hash, the tool version, and that the tool still exists
 *                                and is still enabled.
 *   IN THE GATEWAY, as normal    permission, business scope, kill switches, environment, credentials
 *                                and policy, because the approved call runs the SAME pipeline as any
 *                                other and therefore re-checks all of it for free.
 *
 * That second half matters more than it looks: it means nobody has to remember to duplicate a check
 * here when a new one is added to the gateway.
 */
final class ApprovalGuard
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolAvailability $availability,
    ) {}

    /**
     * Before the approval is claimed: is it still a decision somebody may take?
     *
     * Separated from the post-claim check because the two stages legitimately expect DIFFERENT
     * states. Running one check for both was the first defect this milestone produced: a pending
     * approval failed a check that required it to be already approved, so every approval refused
     * itself.
     *
     * @return string|null the reason it may not be decided, or null when it may
     */
    public function preClaimReason(Approval $approval, ?CarbonImmutable $at = null): ?string
    {
        if (! $approval->status->isOpen()) {
            return "This approval is {$approval->status->label()}, so it cannot be decided again.";
        }

        return $this->sharedReason($approval, $at ?? CarbonImmutable::now());
    }

    /**
     * After the approval has been claimed: may it now execute?
     *
     * @return string|null the reason execution must not proceed, or null when it may
     */
    public function blockingReason(Approval $approval, ?CarbonImmutable $at = null): ?string
    {
        // Only a claimed-but-not-yet-executed approval may execute.
        if ($approval->status !== ApprovalStatus::Approved) {
            return "This approval is {$approval->status->label()}, so it cannot execute.";
        }

        return $this->sharedReason($approval, $at ?? CarbonImmutable::now());
    }

    /** Everything that is true at both stages: expiry, single use, integrity, and the tool itself. */
    private function sharedReason(Approval $approval, CarbonImmutable $at): ?string
    {

        // 1. Single use. Belt and braces: the claim is already atomic, and this refuses a second
        //    attempt that somehow reached here anyway.
        if ($approval->consumed_at !== null || $approval->resulting_tool_call_id !== null) {
            return 'This approval has already been used once, and an approval is single use.';
        }

        // 2. Expiry. An ignored approval is a refusal, never an approval.
        if ($approval->hasExpired($at)) {
            return 'This approval expired before it was executed.';
        }

        // 3. Tamper check. A mismatch cannot happen through the application, so it means the row was
        //    changed underneath it. Refuse, and never try to repair the approval.
        if (! $approval->payloadIntact()) {
            return 'The stored payload no longer matches the hash a human approved. Execution is refused and this is recorded as an integrity failure.';
        }

        // 4. The tool still has to exist.
        if (! $this->registry->has($approval->tool_key)) {
            return "[{$approval->tool_key}] is no longer registered, so an approval for it cannot execute.";
        }

        // 5. And still be enabled. A runtime kill switch turned on after the approval wins.
        $availability = $this->availability->check($approval->tool_key, $approval->business_id);
        if (! $availability['available']) {
            return (string) $availability['reason'];
        }

        // 6. And still be the same contract. A breaking change since the approval means the human
        //    approved arguments for a tool that no longer accepts them; the payload is NEVER
        //    migrated to the new shape.
        $currentVersion = $this->currentVersion($approval->tool_key);
        if ($currentVersion === null) {
            return "[{$approval->tool_key}] has no usable descriptor, so its version cannot be confirmed.";
        }
        if ($this->major($currentVersion) !== $this->major((string) $approval->tool_version)) {
            return "[{$approval->tool_key}] was version {$approval->tool_version} when this was approved and is version {$currentVersion} now. "
                .'A breaking change means the approved arguments were agreed for a different contract, so this approval is refused rather than migrated.';
        }

        return null;
    }

    private function currentVersion(string $toolKey): ?string
    {
        try {
            return $this->registry->get($toolKey)->descriptor()->version;
        } catch (Throwable) {
            return null;
        }
    }

    private function major(string $version): string
    {
        return explode('.', $version)[0];
    }
}
