<?php

namespace App\Tools\Consent;

use App\Consent\ConsentLedger;
use App\Consent\ConsentOperations;
use App\Consent\ConsentState;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Approval;
use App\Models\Customer;
use App\Models\User;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use InvalidArgumentException;

/**
 * `consent.lift_restriction`: lifts a DO-NOT-CONTACT under two-person control (docs/114 §3, ruling (k)).
 *
 * ⭐ Built on the approval machinery, not beside it. The policy class parks EVERY call (R3 would otherwise
 * run at once), the descriptor bars the requester from deciding (`requesterMayDecide: false`, owner
 * included), and `ApprovalExecutor` re-runs the approved call through the gateway under the APPROVER, so
 * the approver must hold `hq.consent.lift_restriction` exactly as the requester had to. Until a second
 * person approves, the request is a pending approval and the do-not-contact stays in force.
 *
 * ⛔ Permanently human-only: no agent may ever lift a restriction, whatever it holds or proposes.
 *
 * Fail closed at execution too: the standing restriction is re-read at the exact scope (it may have
 * changed while the approval waited), the approval behind this run is read back from HQ's own row (the
 * idempotency key the executor sets names it) and must show two different people, or nothing is written.
 */
final class LiftRestrictionTool implements PreviewableTool, Tool
{
    public const KEY = 'consent.lift_restriction';

    public const PERMISSION = ConsentOperations::PERMISSION_LIFT_RESTRICTION;

    public function __construct(private readonly ConsentOperations $operations, private readonly ConsentLedger $ledger) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function inputSchema(): array
    {
        $channels = array_values(array_filter(array_map(fn (Channel $c) => $c->value, Channel::cases()), fn ($c) => $c !== Channel::Internal->value));

        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'customer_id' => ['type' => 'integer', 'minimum' => 1],
                // The scope the do-not-contact stands at: each omitted = "all". Named exactly, never widened.
                'identity_id' => ['type' => 'integer', 'minimum' => 1],
                'brand_id' => ['type' => 'integer', 'minimum' => 1],
                'channel' => ['type' => 'string', 'enum' => $channels],
                'evidence_ref' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 191],
                'reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 1000],
            ],
            'required' => ['business_slug', 'customer_id', 'evidence_ref', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'consent_event_id' => ['type' => 'integer'],
            'lifted_event_id' => ['type' => 'integer'],
            'customer_id' => ['type' => 'integer'],
            'identity_id' => ['type' => ['integer', 'null']],
            'brand_id' => ['type' => ['integer', 'null']],
            'channel' => ['type' => ['string', 'null']],
            'requested_by_user_id' => ['type' => 'integer'],
            'approved_by_user_id' => ['type' => 'integer'],
        ]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Lift a do-not-contact (two-person)',
            purpose: 'Records that a do-not-contact standing at exactly this scope is lifted, after a second person approved it. '
                .'Grants nothing by itself: the person still needs a live grant for any purpose. Never agent-executable.',
            riskTier: RiskTier::R3,
            permission: self::PERMISSION,
            auditAction: 'tool.consent_lift_restriction',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            permanentlyHumanOnly: true,
            policyClass: LiftRestrictionPolicy::class,
            scopeFrom: 'business:args.business_slug',
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'customer_id', 'identity_id', 'brand_id', 'channel', 'evidence_ref', 'reason'],
            timeoutSeconds: 10,
            outputSchema: $this->outputSchema(),
            channel: Channel::Internal,
            customerFacing: false,
            // Undone by recording a new do-not-contact, which any holder of hq.consent.restrict may do.
            reversibility: Reversibility::Reversible,
            dataClass: DataClass::Personal,
            cardinality: ToolCardinality::OnePerBusiness,
            requesterMayDecide: false,
        );
    }

    /** What stands at this scope and what approving would write. Reads only. */
    public function preview(array $payload, ToolContext $context): array
    {
        $customer = $this->customer($payload, $context);
        $standing = $customer === null ? null : $this->ledger->latestAtScope(
            $customer->id, $customer->business_id, $payload['brand_id'] ?? null, $payload['channel'] ?? null, null, $payload['identity_id'] ?? null,
        );

        return [
            'standing_now' => $standing === null ? 'nothing at exactly this scope' : "{$standing->state->value} by consent event #{$standing->id}, since {$standing->occurred_at?->toIso8601String()}",
            'would_write' => $standing?->state === ConsentState::DoNotContact
                ? 'a lifted event at exactly this scope; the do-not-contact ends there and nothing is granted'
                : 'nothing: only a do-not-contact standing at exactly this scope can be lifted here',
            'scope' => [
                'customer_id' => $payload['customer_id'] ?? null,
                'identity_id' => $payload['identity_id'] ?? null,
                'brand_id' => $payload['brand_id'] ?? null,
                'channel' => $payload['channel'] ?? null,
            ],
            'evidence_ref' => $payload['evidence_ref'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'two_person' => 'the person who asked may not approve; the approver must hold '.self::PERMISSION,
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $customer = $this->customer($payload, $context);
        if ($customer === null) {
            return ToolResult::failed('The customer is not a customer of the business this call is scoped to.');
        }

        // The approval this execution runs under, from HQ's own row (the executor keys the call on it).
        $approval = str_starts_with($context->idempotencyKey, 'approval:')
            ? Approval::query()->where('uuid', substr($context->idempotencyKey, 9))->where('tool_key', self::KEY)->first()
            : null;
        $approver = $context->actorUserId === null ? null : User::find($context->actorUserId);
        if ($approval === null || $approver === null || $approval->decided_by_user_id === null
            || (int) $approval->decided_by_user_id !== (int) $approver->id
            || $approval->requested_by_user_id === null
            || (int) $approval->requested_by_user_id === (int) $approver->id) {
            return ToolResult::failed('No independent approval stands behind this call: a do-not-contact is lifted only when a second person has approved it. Nothing was lifted.');
        }

        try {
            $event = $this->operations->liftDoNotContact(
                $approver,
                $customer,
                ['identity_id' => $payload['identity_id'] ?? null, 'brand_id' => $payload['brand_id'] ?? null, 'channel' => $payload['channel'] ?? null],
                (string) $payload['evidence_ref'],
                (string) $payload['reason'],
                [
                    'approval_uuid' => (string) $approval->uuid,
                    'requested_by_user_id' => (int) $approval->requested_by_user_id,
                    'approved_by_user_id' => (int) $approval->decided_by_user_id,
                ],
            );
        } catch (InvalidArgumentException $e) {
            return ToolResult::failed($e->getMessage());
        }

        return ToolResult::succeeded([
            'consent_event_id' => $event->id,
            'lifted_event_id' => (int) ($event->evidence['lifted_event_id'] ?? 0),
            'customer_id' => $customer->id,
            'identity_id' => $event->identity_id,
            'brand_id' => $event->brand_id,
            'channel' => $event->channel,
            'requested_by_user_id' => (int) $approval->requested_by_user_id,
            'approved_by_user_id' => (int) $approval->decided_by_user_id,
        ], 'consent_event:'.$event->id);
    }

    public function reconcile(ToolContext $context): ?ToolResult
    {
        return null; // HQ's own ledger: the gateway's idempotency replay covers a redelivery
    }

    /** The customer, only when they belong to the business the call is scoped to (no cross-business expansion). */
    private function customer(array $payload, ToolContext $context): ?Customer
    {
        $customer = Customer::find((int) ($payload['customer_id'] ?? 0));
        if ($customer === null || $context->businessId === null || (int) $customer->business_id !== (int) $context->businessId) {
            return null;
        }

        return $customer;
    }
}
