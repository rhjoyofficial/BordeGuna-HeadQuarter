<?php

namespace App\Tools\Commerce;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Orders\ErpOperationKey;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\ErpConnector;
use App\Erp\ErpRegistry;
use App\Gateway\Descriptor\Channel;
use App\Gateway\Descriptor\CredentialRequirement;
use App\Gateway\Descriptor\DataClass;
use App\Gateway\Descriptor\MonetaryImpact;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\Reversibility;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\Descriptor\ToolCardinality;
use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Models\Business;
use App\Models\ToolCall;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Throwable;

/**
 * `cancel_sales_order`: HQ cancels an order it placed, under the ERP's own rule (docs/88 A point 3,
 * docs/79 D and C.15). The compensating tool of `commerce.take_order`, built INERT.
 *
 * ⛔⛔ INERT BY CONSTRUCTION, the same three ways as `take_order`: `supportedStores` is the same
 * empty list (a store that may not take an order through HQ may not cancel one through HQ either),
 * no ERP token holds `sales.orders.cancel`, and no agent may execute it or holds it.
 *
 * ⛔ R4, approval-required, and the person who asked never decides it. IRREVERSIBLE: a cancelled
 * order is re-created (a new proposal, a new approval), never un-cancelled. It reaches ONLY an order
 * HQ placed (the ERP answers 404 for any other), and ONLY while nothing has been delivered: once
 * anything has left, the ERP refuses with its own sentence and compensation ends; a short close or a
 * return is a person's act in the ERP, because money and goods may move.
 *
 * ⚠ VERIFICATION FIRST (ADR-031). After the POST the order is read back by its reference, and the
 * result is `succeeded` only when the ERP shows it cancelled under THIS request's key. A POST that
 * never answered, a read-back that failed, or a read-back that disagrees is `uncertain`, named.
 */
final class CancelSalesOrderTool implements PreviewableTool, Tool
{
    public const KEY = 'commerce.cancel_sales_order';

    public function __construct(private readonly ErpRegistry $registry) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_slug' => ['type' => 'string', 'maxLength' => 64],
                'store_slug' => ['type' => 'string', 'maxLength' => 64],
                'reference' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 255],
                'conversation_uuid' => ['type' => 'string', 'maxLength' => 64],
            ],
            'required' => ['business_slug', 'store_slug', 'reference', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function outputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'business' => ['type' => 'string'],
            'source' => ['type' => 'string'],
            'contract' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'order_number' => ['type' => 'string'],
            'shop_reference' => ['type' => 'string'],
            'erp_order_id' => ['type' => 'integer'],
            'cancellation_key' => ['type' => 'string'],
            'replayed' => ['type' => 'boolean'],
            'verified' => ['type' => 'boolean'],
            'effects' => ['type' => 'object'],
            'next_step' => ['type' => 'string'],
        ]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Cancel an order HQ placed (in the ERP)',
            purpose: 'Cancels one sales order HQ placed, in the ERP, under the ERP\'s own rule: only while it is open and nothing has '
                .'been delivered. Refused for any order HQ did not place and for any order with a delivery against it. Irreversible.',
            riskTier: RiskTier::R4,
            permission: 'hq.tools.commerce.write',
            auditAction: 'tool.commerce_cancel_sales_order',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            permanentlyHumanOnly: false,
            supportedStores: array_values((array) config('hq.orders.take_order_stores', [])),
            policyClass: CancelSalesOrderPolicy::class,
            scopeFrom: 'store:args.store_slug|business:args.business_slug',
            credentials: [new CredentialRequirement(ErpRegistry::PROVIDER, 'args.business_slug|business.slug')],
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'store_slug', 'reference', 'reason', 'conversation_uuid'],
            timeoutSeconds: 30,
            redact: [],
            outputSchema: $this->outputSchema(),
            replayWithinSeconds: 0,
            channel: Channel::Internal,
            monetaryImpact: MonetaryImpact::High,
            consentRequired: null,
            customerFacing: true,
            reversibility: Reversibility::Irreversible,
            compensatingTool: null,
            dataClass: DataClass::Personal,
            cardinality: ToolCardinality::OnePerBusiness,
            requesterMayDecide: false,
        );
    }

    public function preview(array $payload, ToolContext $context): array
    {
        return [
            'would_write' => 'a cancellation of order '.($payload['reference'] ?? '?').' in the ERP, if HQ placed it and nothing has been delivered',
            'reason' => $payload['reason'] ?? null,
            'after_the_cancellation' => 'nothing: no stock, journal, refund or courier; a cancelled open order never touched any of them',
            'refused_when' => 'HQ did not place the order, it is already cancelled, or anything has been delivered against it (a return is a person\'s act in the ERP)',
            'irreversible' => true,
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $business = $this->business($context, $payload);
        if ($business === null) {
            return ToolResult::failed('Name the business the order belongs to.');
        }

        $erp = $this->registry->for($business);
        $reference = (string) $payload['reference'];
        $key = ErpOperationKey::for($context->idempotencyKey, $business->id, 'cancel');

        try {
            $cancelled = $erp->cancelSalesOrder($reference, [
                'idempotency_key' => $key,
                'reason' => (string) $payload['reason'],
                'hq_reference' => [
                    'approval_uuid' => ErpOperationKey::approvalUuid($context->idempotencyKey),
                    'conversation_uuid' => $payload['conversation_uuid'] ?? null,
                ],
            ]);
        } catch (Refused|ValidationFailed|Unsupported $e) {
            // Already cancelled, deliveries against it, not HQ's order, a key reused, a token
            // without the ability: answers to report, never retried.
            return ToolResult::failedBy($e);
        }

        // ⚠ The postcondition is the READ-BACK, never the 200 (ADR-031).
        try {
            $read = $erp->readSalesOrder($reference);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The ERP accepted the cancellation, and reading the order back failed, so it is not verified: '.$e->getMessage());
        }
        if (! $read->found) {
            return ToolResult::uncertain('The ERP accepted the cancellation, and has no order under that reference when read back.');
        }

        return $this->judge($read, $key, $business, (bool) ($cancelled->data['replayed'] ?? false));
    }

    /**
     * An earlier call with this key ended `uncertain`: read the order back before anything is sent
     * again. Cancelled under this key: `succeeded`. Not cancelled: null, and the gateway may send it
     * again safely, because the ERP answers one key once. Cancelled under another key, or unreadable:
     * `uncertain`, for a person.
     */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        $business = $context->businessId === null ? null : Business::find($context->businessId);
        if ($business === null) {
            return null;
        }
        $prior = ToolCall::query()->where('tool_key', self::KEY)->where('idempotency_key', $context->idempotencyKey)
            ->whereNotNull('input')->orderBy('id')->first();
        if ($prior === null || ! is_string($prior->input['reference'] ?? null)) {
            return null;
        }

        try {
            $read = $this->registry->for($business)->readSalesOrder($prior->input['reference']);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The order could not be read back from the ERP to settle an uncertain cancellation: '.$e->getMessage());
        }
        if (! $read->found || ($read->data['cancellation']['requested'] ?? false) !== true) {
            return null;
        }

        return $this->judge($read, ErpOperationKey::for($context->idempotencyKey, $business->id, 'cancel'), $business, null);
    }

    private function judge(ErpOrderRead $read, string $key, Business $business, ?bool $replayed): ToolResult
    {
        $data = $read->data;
        $ref = 'erp-order:'.($data['id'] ?? '?');

        if (($data['status'] ?? null) !== 'cancelled') {
            return ToolResult::uncertain('The ERP accepted the cancellation, and the order reads ['.(string) ($data['status'] ?? 'none').'] when read back, not cancelled; nothing was changed to make it.', $ref);
        }
        if (($data['cancellation']['requested'] ?? false) !== true || ($data['cancellation']['idempotency_key'] ?? null) !== $key) {
            return ToolResult::uncertain('The order is cancelled, and not under this request\'s key: the ERP records another cancellation ['.(string) ($data['cancellation']['idempotency_key'] ?? 'none').'].', $ref);
        }

        return ToolResult::succeeded([
            'business' => $business->slug,
            'source' => 'erp',
            'contract' => ErpConnector::SALES_ORDERS_CONTRACT,
            'status' => (string) $data['status'],
            'order_number' => (string) ($data['order_number'] ?? ''),
            'shop_reference' => (string) ($data['shop_reference'] ?? ''),
            'erp_order_id' => (int) ($data['id'] ?? 0),
            'cancellation_key' => $key,
            'replayed' => (bool) $replayed,
            'verified' => true,
            'effects' => (array) ($data['effects'] ?? []),
            'next_step' => (string) ($data['next_step'] ?? ''),
        ], $ref);
    }

    private function business(ToolContext $context, array $payload): ?Business
    {
        if ($context->businessId !== null) {
            return Business::find($context->businessId);
        }
        $slug = $payload['business_slug'] ?? null;

        return is_string($slug) ? Business::where('slug', $slug)->first() : null;
    }
}
