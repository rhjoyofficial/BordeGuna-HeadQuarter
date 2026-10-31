<?php

namespace App\OwnerConsole\Batch;

use App\Gateway\Approval\ApprovalExecutor;
use App\Gateway\Approval\ApprovalStatus;
use App\Gateway\CallerKind;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Identity\ActingIdentity;
use App\Gateway\ToolGateway;
use App\Gateway\ToolRequest;
use App\Models\Approval;
use App\Models\ApprovalBatch;
use App\Models\User;
use App\OwnerConsole\Flag;
use App\OwnerConsole\Refused;
use App\Support\Audit\Auditor;
use App\Tools\ToolRegistry;
use Illuminate\Support\Str;
use Throwable;

/**
 * Two ways to decide approvals faster, both OFF, both only a CALLER of the one approval path
 * (docs/143 B and C; docs/138 rulings 8 and 9 said one approval per message and no approve-in-place, so the
 * defaults below are the narrowest reading that still saves a person time):
 *
 *  - BATCH (Q8, flag `console.batch_approval`): one act over up to 25 pending approvals of CUSTOMER MESSAGES.
 *    Each message is its own decision: its own payload hash echoed back from what the person read, its own
 *    scope and visibility check, its own gateway pre-check (consent, policy), its own `ApprovalExecutor` call
 *    and its own audit row. A message that fails any check is skipped and the others go on; approving one
 *    never approves a sibling, and a regenerated variant has a new hash, so it is never covered.
 *  - ONE CLICK (Q9, flag `console.one_click_approve`): the same single-item act from a console card, for any
 *    approval below tier R5, after the card has shown the payload and its hash.
 *
 * ⛔ Neither decides anything the approval page could not: `ApprovalExecutor::approveAndExecute` re-reads the
 * decider's authority server-side (decide permission, separation of duties, delegation), claims the approval
 * atomically and runs the stored, hashed payload through the gateway. This class supplies an approval id and
 * the hash the person saw; it never supplies a tool key or a payload. An approval the person may not see is
 * reported "not found", the same as on the approval page.
 */
final class ApprovalActs
{
    public const MAX_BATCH = 25;

    public function __construct(
        private readonly ApprovalExecutor $executor,
        private readonly ToolGateway $gateway,
        private readonly ToolRegistry $tools,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{id: int, payload_hash: string}>  $items
     */
    public function batch(User $by, array $items): ApprovalBatch
    {
        if (! Flag::on(Flag::BATCH_APPROVAL)) {
            throw new Refused('Batch approval is switched off.');
        }
        if ($items === [] || count($items) > self::MAX_BATCH) {
            throw new Refused('Choose between 1 and '.self::MAX_BATCH.' messages.');
        }

        $results = [];
        $seen = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $results[] = $this->decideOne($by, $id, (string) ($item['payload_hash'] ?? ''), 'batch');
        }

        $executed = count(array_filter($results, fn ($r) => $r['result'] === 'executed'));
        $batch = ApprovalBatch::query()->create([
            'uuid' => (string) Str::uuid(), 'decided_by_user_id' => $by->getKey(), 'requested_count' => count($results),
            'executed_count' => $executed, 'refused_count' => count($results) - $executed, 'results' => $results,
        ]);
        $this->auditor->record('approvals.batch', $batch, after: ['requested' => count($results), 'executed' => $executed], actorUserId: $by->getKey());

        return $batch;
    }

    /** @return array{id: int, result: string, reason: string|null, payload_hash: string} */
    public function oneClick(User $by, int $approvalId, string $shownHash): array
    {
        if (! Flag::on(Flag::ONE_CLICK_APPROVE)) {
            throw new Refused('One-click approval is switched off.');
        }

        return $this->decideOne($by, $approvalId, $shownHash, 'one_click');
    }

    /** @return array{id: int, result: string, reason: string|null, payload_hash: string} */
    private function decideOne(User $by, int $id, string $shownHash, string $mode): array
    {
        $out = fn (string $result, ?string $reason = null) => $this->record($by, $mode, ['id' => $id, 'result' => $result, 'reason' => $reason, 'payload_hash' => $shownHash]);

        // 1. Visible to this person, or "not found": never a hint that a row of another business exists.
        $approval = Approval::query()->visibleTo($by)->whereKey($id)->first();
        if ($approval === null) {
            return $out('not_found', 'No such approval for you.');
        }
        // 2. The person decided on what they saw: the hash they were shown must still be the approval's.
        if ($shownHash === '' || ! hash_equals((string) $approval->payload_hash, $shownHash)) {
            return $out('payload_changed', 'The message is not the one you were shown. Open it and read it again.');
        }
        if ($approval->status !== ApprovalStatus::Pending) {
            return $out('not_pending', "This approval is {$approval->status->label()}.");
        }
        if (($barred = $approval->decisionRefusalReason($by)) !== null) {
            return $out('refused', $barred);
        }
        if (! $this->tools->has($approval->tool_key)) {
            return $out('not_eligible', 'This tool is no longer registered.');
        }
        $descriptor = $this->tools->get($approval->tool_key)->descriptor();
        if ($mode === 'batch' && ! $descriptor->customerFacing) {
            return $out('not_eligible', 'Batch review is only for customer messages.');
        }
        if ($mode === 'one_click' && $descriptor->riskTier->atLeast(RiskTier::R5)) {
            return $out('not_eligible', 'A material or irreversible action is approved on its own page.');
        }

        // 3. The gateway's own read-only authorization (scope, permission, consent, policy), BEFORE the approval is claimed:
        //    a message that would be refused is left pending for a person, not spent.
        $auth = $this->gateway->authorize(new ToolRequest(
            toolKey: $approval->tool_key, args: $approval->payload ?? [], caller: CallerKind::HumanUi, identity: ActingIdentity::human($by),
            correlationId: (string) ($approval->correlation_id ?? $approval->uuid), businessId: $approval->business_id,
            storeId: $approval->store_id, brandId: $approval->brand_id,
        ));
        if (! $auth->permitsProposal()) {
            return $out('refused', 'The gateway would refuse this now: '.($auth->reason ?? 'no reason recorded'));
        }

        // 4. The one approval path.
        try {
            $outcome = $this->executor->approveAndExecute($approval, $by, "decided by {$mode}");
        } catch (Throwable $e) {
            return $out('refused', 'The approval could not be carried out: '.class_basename($e));
        }

        return $outcome->ok() ? $out('executed') : $out('refused', $outcome->error ?? $outcome->policyReason ?? 'The gateway refused the action.');
    }

    /**
     * One audit row per item, naming the approval and the hash the person saw, never the message text.
     *
     * @param  array{id: int, result: string, reason: string|null, payload_hash: string}  $result
     * @return array{id: int, result: string, reason: string|null, payload_hash: string}
     */
    private function record(User $by, string $mode, array $result): array
    {
        $this->auditor->record('approvals.'.$mode.'_item', 'approval:'.$result['id'], after: $result + ['mode' => $mode], actorUserId: $by->getKey());

        return $result;
    }
}
