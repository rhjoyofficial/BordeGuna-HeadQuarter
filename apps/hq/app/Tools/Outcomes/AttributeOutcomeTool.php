<?php

namespace App\Tools\Outcomes;

use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Outcome;
use App\Models\User;
use App\Outcomes\ActionType;
use App\Outcomes\AttributionMethod;
use App\Outcomes\AttributionRole;
use App\Outcomes\AttributionSpec;
use App\Outcomes\OutcomeActor;
use App\Outcomes\OutcomeConflict;
use App\Outcomes\OutcomeRecorder;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `outcomes.attribute`: an operator asserts, by hand, that one HQ action played a role in a recorded
 * outcome (docs/110 §3 Q11, docs/115 §4). The ONLY way a person records an `operator_asserted`
 * attribution: there is no screen, no controller and no console command that writes the ledger
 * (`OutcomeWritePathStructureTest`), and this tool writes it through `OutcomeRecorder` like every
 * other writer, so every ledger rule (business binding, Q6, idempotency, append-only) applies unchanged.
 *
 * ⛔ Human-authorized by construction, three times over.
 *   1. `permanentlyHumanOnly: true`. An operator assertion is by definition a human's claim; an agent's
 *      claim would be `model_inferred`, which v1 refuses (`AttributionPolicy`). No approval, grant or
 *      configuration can ever let an agent run this (ADR-025): a DECISION, not a stage.
 *   2. The actor is never an argument. It is the human the gateway authenticated (`ToolContext::actorUserId`,
 *      the identity's enabling user), and a call that reaches `execute()` with no human behind it is
 *      refused here before the recorder is asked.
 *   3. `permission: hq.outcomes.attribute`, held by `owner` and `operator` only (RolesAndPermissionsSeeder).
 *
 * ⛔ R3, NOT R4 (docs/115 §4.2). It writes one row in HQ's own `outcome_attributions` and nothing else:
 *    no system of record, no provider, no customer, no money. The ladder calls that an internal write.
 *    D13: nothing reads the row in v1 (no permission, approval, gate or autonomy level), and ruling C7
 *    is encoded on the method (`isDeterministicProof()` false): an operator assertion is evidence, never
 *    independent proof. The day a consumer reads these rows is a separate ruling that may raise this bar;
 *    a human-governed ToolPolicy row can require approval for this key without a tier change.
 *
 * Confidence is the operator's, in [0, 1], stated explicitly (docs/107 D8: `operator_asserted` carries any
 * confidence; the recorder requires the human actor and the reason). Method and method version are not
 * inputs. Business scope: the gateway resolves `business_slug` and enforces the caller's BusinessScope
 * BEFORE execution; the tool then finds the outcome only inside that business (Q7: explicit restriction
 * at the boundary, never a global scope).
 */
final class AttributeOutcomeTool implements PreviewableTool, Tool
{
    public const KEY = 'outcomes.attribute';

    public const PERMISSION = 'hq.outcomes.attribute';

    public const METHOD_VERSION = 'operator_asserted.v1';

    public function __construct(private readonly OutcomeRecorder $recorder) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'outcome_uuid' => ['type' => 'string', 'minLength' => 36, 'maxLength' => 36],
                'action_type' => ['type' => 'string', 'enum' => array_map(fn (ActionType $t) => $t->value, ActionType::cases())],
                'action_id' => ['type' => 'integer', 'minimum' => 1],
                'role' => ['type' => 'string', 'enum' => array_map(fn (AttributionRole $r) => $r->value, AttributionRole::cases())],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'reason' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 2000],
                'evidence_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'proposal_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['business_slug', 'outcome_uuid', 'action_type', 'action_id', 'role', 'confidence', 'reason', 'evidence_ref'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'attribution_uuid' => ['type' => 'string'],
            'outcome_uuid' => ['type' => 'string'],
            'action_ref' => ['type' => 'string'],
            'role' => ['type' => 'string'],
            'method' => ['type' => 'string'],
            'method_version' => ['type' => 'string'],
            'confidence' => ['type' => 'number'],
            'actor' => ['type' => 'string'],
            'replayed' => ['type' => 'boolean'],
        ]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Attribute an outcome (operator assertion)',
            purpose: 'Records, as the signed-in person, that one HQ action (an agent run, a tool call, an approval, a sent message...) '
                .'played a named role in a recorded business outcome, with a reason and an evidence reference. Method operator_asserted, '
                .'never proof. Writes one append-only row in HQ\'s outcome ledger; nothing outside HQ changes and nothing reads the row yet.',
            riskTier: RiskTier::R3,
            permission: self::PERMISSION,
            auditAction: 'tool.outcomes_attribute',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            permanentlyHumanOnly: true,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'outcome_uuid', 'action_type', 'action_id', 'role', 'confidence', 'reason', 'evidence_ref'],
            timeoutSeconds: 15,
            outputSchema: $this->outputSchema(),
            channel: Channel::Internal,
            monetaryImpact: MonetaryImpact::None,
            customerFacing: false,
            reversibility: Reversibility::Reversible,
            dataClass: DataClass::Internal,
            cardinality: ToolCardinality::OnePerBusiness,
        );
    }

    public function preview(array $payload, ToolContext $context): array
    {
        return [
            'would_record' => 'one operator_asserted attribution row: '.($payload['action_type'] ?? '?').' #'.($payload['action_id'] ?? '?')
                .' played the role ['.($payload['role'] ?? '?').'] in outcome '.($payload['outcome_uuid'] ?? '?'),
            'confidence' => $payload['confidence'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'evidence_ref' => $payload['evidence_ref'] ?? null,
            'external_effect' => 'none: one append-only row in HQ\'s outcome ledger, withdrawn only by a retraction row',
            'refused_when' => 'the outcome is not in the named business, the action does not exist, belongs to another business or has no business of its own, '
                .'or the same assertion was already recorded with a different meaning',
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        // 1. The actor: the human the gateway authenticated, never an argument. No human, no assertion.
        $actor = $context->actorUserId === null ? null : User::query()->find($context->actorUserId);
        if ($actor === null) {
            return ToolResult::failed('An operator assertion needs the signed-in person behind the call; this call carries no human actor, so nothing was recorded.');
        }

        // 2. The outcome, only inside the business the gateway resolved and enforced scope for (Q7).
        if ($context->businessId === null) {
            return ToolResult::failed('Name the business the outcome belongs to.');
        }
        $outcome = $context->restrictBusinesses(Outcome::query())
            ->where('business_id', $context->businessId)
            ->where('uuid', (string) $payload['outcome_uuid'])
            ->first();
        if ($outcome === null) {
            return ToolResult::failed("No outcome [{$payload['outcome_uuid']}] in that business. An outcome of another business is never reachable from here.");
        }

        // 3. The action, by kind and row id; its durable reference is composed here, its existence and
        //    business are proved by the recorder (Q6: an action with no business of its own is refused).
        $type = ActionType::from((string) $payload['action_type']);
        $actionId = (int) $payload['action_id'];
        $actionRef = $this->actionRef($type, $actionId);
        if ($actionRef === null) {
            return ToolResult::failed("The [{$type->value}] action {$actionId} does not exist.");
        }

        $spec = new AttributionSpec(
            actionType: $type,
            actionId: $actionId,
            actionRef: $actionRef,
            role: AttributionRole::from((string) $payload['role']),
            method: AttributionMethod::OperatorAsserted,
            methodVersion: self::METHOD_VERSION,
            confidence: (float) $payload['confidence'],
            idempotencyKey: 'attr:operator:'.$outcome->uuid.':'.$type->value.':'.$actionId.':'.$payload['role'],
            proposalRef: isset($payload['proposal_ref']) ? (string) $payload['proposal_ref'] : null,
            // The meaning of the assertion: what was asserted and on what evidence. The gateway call that carried it is
            // found through `correlationId` (the tool_calls row, audit action `tool.outcomes_attribute`), which is not part
            // of the meaning, so the same assertion made twice is a replay, not a conflict.
            evidence: [
                'evidence_ref' => (string) $payload['evidence_ref'],
                'asserted_through' => self::KEY,
            ],
            actor: OutcomeActor::human($actor),
            correlationId: $context->correlationId,
            reason: (string) $payload['reason'],
        );

        try {
            $row = $this->recorder->attribute($outcome, $spec);
        } catch (OutcomeConflict $e) {
            // The same assertion key with a different meaning: the ledger never overwrites. Re-judging
            // is a successor row, which v1 exposes to no tool (docs/115 §6 question 7).
            return ToolResult::failed('Already asserted with a different meaning, and the ledger never overwrites: '.$e->getMessage());
        } catch (InvalidArgumentException $e) {
            // Every ledger refusal is fail-closed and named (another business, no business of its own,
            // a missing action, an inactive method...). Reported, never retried.
            return ToolResult::failed('Attribution refused: '.$e->getMessage());
        }

        return ToolResult::succeeded([
            'attribution_uuid' => (string) $row->uuid,
            'outcome_uuid' => (string) $outcome->uuid,
            'action_ref' => $actionRef,
            'role' => $row->role->value,
            'method' => $row->method->value,
            'method_version' => (string) $row->method_version,
            'confidence' => (float) $row->confidence,
            'actor' => (string) $row->actor_label,
            'replayed' => ! $row->wasRecentlyCreated,
        ]);
    }

    /** Nothing outside HQ to look up: the recorder's own idempotency makes a repeat a replay. */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null;
    }

    /**
     * The action's durable identity, readable without a join (docs/107 §4.3): the same forms the
     * recorders use (`tool_call:{tool_key}:{idempotency_key}`, `approval:{uuid}`, `agent_run:{uuid}`),
     * the uuid where the table has one, the row id where it has not. Null when the row does not exist.
     */
    private function actionRef(ActionType $type, int $id): ?string
    {
        $row = DB::table($type->table())->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return match ($type) {
            ActionType::ToolCall => 'tool_call:'.$row->tool_key.':'.($row->idempotency_key ?? $id),
            ActionType::AgentRun, ActionType::Approval, ActionType::ContentPublication,
            ActionType::WorkflowInstance, ActionType::ConversationMessage, ActionType::FollowUp => $type->value.':'.($row->uuid ?? $id),
            ActionType::AiRun, ActionType::DecisionRecord => $type->value.':'.$id,
        };
    }
}
