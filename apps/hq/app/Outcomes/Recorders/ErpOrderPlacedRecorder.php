<?php

namespace App\Outcomes\Recorders;

use App\Commerce\Orders\ErpOperationKey;
use App\Context\Provenance;
use App\Facts\ErpOrderReadBackFact;
use App\Gateway\OutcomeStatus;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\FactsOrder;
use App\Models\Outcome;
use App\Models\ToolCall;
use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\AttributionSpec;
use App\Outcomes\OutcomeRecorder;
use App\Outcomes\OutcomeSource;
use App\Outcomes\OutcomeSpec;
use App\Outcomes\ResultRefSpec;
use App\Outcomes\ResultType;
use App\Tools\Commerce\TakeOrderTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ILLUSTRATIVE and INERT (docs/107 section 11): nothing calls this outside its tests.
 *
 * Given the ERP read-back fact of an order HQ placed and the `commerce.take_order` tool call that
 * placed it, records `order.placed` against the ERP order and credits, only where it can be proven:
 *
 *   - the tool call: `produced`, `deterministic_key`, proven by recomputing the ERP operation key the
 *     call sent (`ErpOperationKey::for(call key, business, 'order')`) and finding it on the fact;
 *   - the approval named by the call's `approval:{uuid}` key: `approved`, `deterministic_key`;
 *   - the agent run that shares the approval's correlation id: `proposed`, `correlation`.
 *
 * ⛔ Copies no amount, status or line from the order. The reference is the ERP order number (the ERP's
 *    own key) plus HQ's read-back row; the evidence is the operation key and the read-back digest.
 */
final class ErpOrderPlacedRecorder
{
    public const TYPE = 'order.placed';

    public const METHOD_VERSION = 'erp_order_placed.v1';

    public function __construct(private readonly OutcomeRecorder $recorder) {}

    public function record(FactsOrder $fact, ToolCall $placingCall): Outcome
    {
        if ($fact->source_system !== ErpOrderReadBackFact::SOURCE_SYSTEM || ! is_string($fact->erp_order_number) || $fact->erp_order_number === ''
            || ! is_string($fact->hq_key) || $fact->hq_key === '') {
            throw new InvalidArgumentException('Only an ERP read-back fact with an order number and HQ\'s operation key can record an ERP order outcome.');
        }
        if ($placingCall->tool_key !== TakeOrderTool::KEY || $placingCall->status !== OutcomeStatus::Succeeded->value || ! is_string($placingCall->idempotency_key)) {
            throw new InvalidArgumentException('The placing call must be a succeeded commerce.take_order call.');
        }
        // The proof: the key the call sent the ERP, recomputed, is the key the ERP echoed back.
        $operationKey = ErpOperationKey::for($placingCall->idempotency_key, (int) $fact->business_id, TakeOrderTool::ORDER_STEP);
        if (! hash_equals($fact->hq_key, $operationKey)) {
            throw new InvalidArgumentException('That call did not place this order: its ERP operation key does not match the one the ERP read back.');
        }

        return DB::transaction(fn () => $this->write($fact, $placingCall, $operationKey));
    }

    private function write(FactsOrder $fact, ToolCall $placingCall, string $operationKey): Outcome
    {
        $businessId = (int) $fact->business_id;
        $natural = 'erp:'.$fact->erp_order_number;
        $occurredAt = $fact->placed_at ?? $fact->read_back_at ?? CarbonImmutable::now();

        $outcome = $this->recorder->record(new OutcomeSpec(
            type: self::TYPE,
            businessId: $businessId,
            occurredAt: CarbonImmutable::instance($occurredAt),
            source: OutcomeSource::ErpReadBack,
            sourceSystem: 'erp',
            provenance: Provenance::Business,
            idempotencyKey: self::TYPE.':erp:'.$businessId.':'.$fact->erp_order_number,
            results: [new ResultRefSpec(ResultType::ErpOrder, 'erp', $natural, (int) $fact->id)],
            storeId: (int) $fact->store_id,
            sourceAsOf: $fact->read_back_at ? CarbonImmutable::instance($fact->read_back_at) : null,
            sourceRef: $natural,
            evidence: ['hq_operation_key' => $fact->hq_key],
            correlationId: $fact->correlation_id,
        ));

        $this->recorder->attribute($outcome, new AttributionSpec(
            actionType: ActionType::ToolCall,
            actionId: (int) $placingCall->id,
            actionRef: 'tool_call:'.TakeOrderTool::KEY.':'.$placingCall->idempotency_key,
            role: AttributionRole::Produced,
            method: AttributionMethod::DeterministicKey,
            methodVersion: self::METHOD_VERSION,
            confidence: 1.0,
            idempotencyKey: 'attr:'.$outcome->idempotency_key.':tool_call:'.$placingCall->id,
            evidence: ['erp_operation_key' => $operationKey, 'step' => TakeOrderTool::ORDER_STEP],
            correlationId: $placingCall->correlation_id,
        ));

        $approvalUuid = ErpOperationKey::approvalUuid($placingCall->idempotency_key);
        $approval = $approvalUuid === null ? null : Approval::query()->where('uuid', $approvalUuid)->first();
        if ($approval !== null) {
            $this->recorder->attribute($outcome, new AttributionSpec(
                actionType: ActionType::Approval,
                actionId: (int) $approval->id,
                actionRef: 'approval:'.$approval->uuid,
                role: AttributionRole::Approved,
                method: AttributionMethod::DeterministicKey,
                methodVersion: self::METHOD_VERSION,
                confidence: 1.0,
                idempotencyKey: 'attr:'.$outcome->idempotency_key.':approval:'.$approval->id,
                evidence: ['gateway_key' => $placingCall->idempotency_key],
            ));

            $run = $approval->correlation_id === null ? null
                : AgentRun::query()->where('correlation_id', (string) $approval->correlation_id)->orderBy('id')->first();
            if ($run !== null) {
                $this->recorder->attribute($outcome, new AttributionSpec(
                    actionType: ActionType::AgentRun,
                    actionId: (int) $run->id,
                    actionRef: 'agent_run:'.$run->uuid,
                    role: AttributionRole::Proposed,
                    method: AttributionMethod::Correlation,
                    methodVersion: self::METHOD_VERSION,
                    confidence: 1.0,
                    idempotencyKey: 'attr:'.$outcome->idempotency_key.':agent_run:'.$run->id,
                    evidence: ['correlation_id' => (string) $approval->correlation_id],
                ));
            }
        }

        return $outcome;
    }
}
