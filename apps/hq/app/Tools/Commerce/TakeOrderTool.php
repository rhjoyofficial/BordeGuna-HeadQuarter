<?php

namespace App\Tools\Commerce;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Orders\DraftReconciliation;
use App\Commerce\Orders\TakeOrderShape;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\ErpConnector;
use App\Erp\ErpRegistry;
use App\Gateway\Approval\CanonicalPayload;
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
 * `take_order`: the one business capability for writing an order (docs/79, ADR-046), built INERT as
 * its first stage (docs/83 C.3): it leaves a DRAFT sales order in the ERP and nothing after it.
 *
 * ⛔⛔ INERT BY CONSTRUCTION, three times over.
 *   1. `supportedStores` is `config('hq.orders.take_order_stores')`, which ships EMPTY: every call
 *      names a store and the gateway refuses every store. Admitting one is a deploy and the P4-G2
 *      gate (docs/79 G), never a switch.
 *   2. No ERP token holds `sales.orders.draft`; the ERP refuses the write with 403 until a token is
 *      issued for it by name (`hq:api:token --write`).
 *   3. `agentExecutable: false`, and no agent's tool list names it: it is not in any agent's
 *      catalogue, and an agent's proposal of it runs nothing (ADR-031, A0).
 *
 * ⛔ R4, NOT R3 (docs/83 B). R4 is "external side effect, recoverable" and its baseline is approval;
 * R3 is "internal write" and runs without one. An ERP draft is an external record. Every call is
 * approval-required with no value threshold (V.1), and the person who asked may never decide it
 * (`requesterMayDecide: false`), owner included.
 *
 * ⚠ VERIFICATION FIRST (ADR-031). The draft is read back from the ERP by its key after the write,
 * and compared with the proposal by `DraftReconciliation`: `succeeded` only when they agree. A POST
 * that never answered, a read-back that failed, or a read-back that disagrees is `uncertain`, with
 * the reason named; never `succeeded` on the strength of a 201.
 *
 * ⚠ NOTHING IS REDACTED FROM THE ARGUMENTS, deliberately: the approval stores the redacted payload
 * and executes exactly that (ADR-018), so a redacted phone would never reach the ERP. The OUTPUT
 * carries no name, phone or address.
 */
final class TakeOrderTool implements PreviewableTool, Tool
{
    public const KEY = 'commerce.take_order';

    public function __construct(
        private readonly ErpRegistry $registry,
        private readonly DraftReconciliation $reconciliation,
    ) {}

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
                'erp_store_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'destination' => ['type' => 'object'],
                'lines' => ['type' => 'array'],
                'payment_method' => ['type' => 'string', 'enum' => ['cod']],
                'proposed' => ['type' => 'object'],
                'conversation_uuid' => ['type' => 'string', 'maxLength' => 64],
            ],
            'required' => ['business_slug', 'store_slug', 'erp_store_key', 'destination', 'lines', 'payment_method', 'proposed'],
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
            'erp_draft_id' => ['type' => 'integer'],
            'erp_key' => ['type' => 'string'],
            'proposal_fingerprint' => ['type' => 'string'],
            'store_key' => ['type' => 'string'],
            'customer_resolution' => ['type' => 'string'],
            'lines' => ['type' => 'array'],
            'goods_total' => ['type' => 'string'],
            'delivery_charge' => ['type' => 'string'],
            'agreed_total' => ['type' => 'string'],
            'replayed' => ['type' => 'boolean'],
            'verified' => ['type' => 'boolean'],
            'effects' => ['type' => 'object'],
        ]];
    }

    public function descriptor(): ToolDescriptor
    {
        return new ToolDescriptor(
            key: self::KEY,
            version: '1.0.0',
            title: 'Take an order (a draft in the ERP)',
            purpose: 'Leaves a DRAFT sales order in the ERP for a named store: the ERP prices and checks it and refuses any figure '
                .'that no longer matches. Nothing follows a draft: no order, number, approval, customer, stock, journal or message.',
            riskTier: RiskTier::R4,
            permission: 'hq.tools.commerce.write',
            auditAction: 'tool.commerce_take_order',
            scope: ToolScope::Business,
            readWrite: ReadWrite::Write,
            agentExecutable: false,
            permanentlyHumanOnly: false,
            supportedStores: array_values((array) config('hq.orders.take_order_stores', [])),
            policyClass: TakeOrderPolicy::class,
            scopeFrom: 'store:args.store_slug|business:args.business_slug',
            credentials: [new CredentialRequirement(ErpRegistry::PROVIDER, 'args.business_slug|business.slug')],
            idempotent: true,
            idempotencyKeyFrom: ['business_slug', 'store_slug', 'erp_store_key', 'conversation_uuid', 'destination', 'lines', 'payment_method', 'proposed'],
            timeoutSeconds: 30,
            redact: [],
            outputSchema: $this->outputSchema(),
            replayWithinSeconds: 0,
            channel: Channel::Internal,
            monetaryImpact: MonetaryImpact::High,
            consentRequired: null,
            customerFacing: true,
            reversibility: Reversibility::Compensable,
            // Named by the design (docs/79 D) and not built: until it exists nothing follows a draft,
            // so there is nothing to compensate. It is built with the step that confirms a draft.
            compensatingTool: 'commerce.cancel_sales_order',
            dataClass: DataClass::Personal,
            cardinality: ToolCardinality::OnePerBusiness,
            requesterMayDecide: false,
        );
    }

    public function preview(array $payload, ToolContext $context): array
    {
        $lines = collect((array) ($payload['lines'] ?? []))->map(fn ($l) => [
            'sku' => $l['sku'] ?? null, 'quantity' => $l['quantity'] ?? null, 'unit_price' => $l['unit_price'] ?? null,
        ])->values()->all();

        return [
            'would_write' => 'a DRAFT sales order in the ERP for store '.($payload['erp_store_key'] ?? '?').', priced and checked by the ERP',
            'lines' => $lines,
            'proposed' => $payload['proposed'] ?? null,
            'payment_method' => $payload['payment_method'] ?? null,
            'after_the_draft' => 'nothing: no order, order number, approval, customer, stock, journal or message',
            'refused_when' => 'any price or total no longer matches the ERP, a product is not sellable, or the store is not live',
        ];
    }

    public function execute(array $payload, ToolContext $context): ToolResult
    {
        $business = $this->business($context, $payload);
        if ($business === null) {
            return ToolResult::failed('Name the business the order is for.');
        }
        if (($problems = TakeOrderShape::problems($payload)) !== []) {
            return ToolResult::failed('The order cannot be written as it stands: '.implode(' ', $problems));
        }

        $erp = $this->registry->for($business);
        $key = self::erpKey($context->idempotencyKey, $business->id);
        $fingerprint = CanonicalPayload::hash($payload);

        try {
            $created = $erp->createSalesOrderDraft([
                'idempotency_key' => $key,
                'store_key' => (string) $payload['erp_store_key'],
                'destination' => $payload['destination'],
                'lines' => array_values($payload['lines']),
                'payment_method' => (string) $payload['payment_method'],
                'proposed' => $payload['proposed'],
                'hq_reference' => [
                    'proposal_fingerprint' => $fingerprint,
                    'conversation_uuid' => $payload['conversation_uuid'] ?? null,
                ],
            ]);
        } catch (Refused|ValidationFailed|Unsupported $e) {
            // A key reused for another order, a figure that moved, a product no longer sellable, a
            // token without the ability: answers to report, never retried.
            return ToolResult::failedBy($e);
        }

        // ⚠ The postcondition is the READ-BACK, never the 201 (ADR-031).
        return $this->verify($erp, $key, $payload, $business, $created->data['replayed'] ?? false);
    }

    /**
     * An earlier call with this key ended `uncertain`: read the draft back before anything is sent
     * again. Found and agreeing with the arguments that call executed: `succeeded`. Found and
     * disagreeing, or unreadable: still `uncertain`, for a person. Not there: null, and the gateway
     * may send it again safely, because the ERP answers one key once.
     */
    public function reconcile(ToolContext $context): ?ToolResult
    {
        $business = $context->businessId === null ? null : Business::find($context->businessId);
        if ($business === null) {
            return null;
        }
        $prior = ToolCall::query()->where('tool_key', self::KEY)->where('idempotency_key', $context->idempotencyKey)
            ->whereNotNull('input')->orderBy('id')->first();
        if ($prior === null) {
            return null;
        }

        $key = self::erpKey($context->idempotencyKey, $business->id);
        try {
            $read = $this->registry->for($business)->readSalesOrderDraft($key);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The draft could not be read back from the ERP to settle an uncertain call: '.$e->getMessage());
        }
        if (! $read->found) {
            return null;
        }

        return $this->judge($read, (array) $prior->input, $business, null);
    }

    /** The ERP's key for this operation: the gateway's key and the business, so a reconcile finds it. */
    public static function erpKey(string $gatewayKey, int $businessId): string
    {
        return 'hq:'.hash('sha256', $gatewayKey.'|business:'.$businessId);
    }

    private function verify(ErpConnector $erp, string $key, array $payload, Business $business, mixed $replayed): ToolResult
    {
        try {
            $read = $erp->readSalesOrderDraft($key);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The ERP accepted the draft, and reading it back failed, so it is not verified: '.$e->getMessage());
        }
        if (! $read->found) {
            return ToolResult::uncertain('The ERP accepted the draft, and has no draft under its key when read back.');
        }

        return $this->judge($read, $payload, $business, (bool) $replayed);
    }

    private function judge(ErpOrderRead $read, array $payload, Business $business, ?bool $replayed): ToolResult
    {
        $discrepancies = $this->reconciliation->compare($payload, $read->data);
        if ($discrepancies !== []) {
            $named = collect($discrepancies)->map(fn ($d) => "{$d['field']}: {$d['note']}")->implode(' ');

            return ToolResult::uncertain('The ERP draft does not agree with the proposal, and nothing was changed to make it: '.$named, 'erp-draft:'.($read->data['id'] ?? '?'));
        }

        $data = $read->data;

        return ToolResult::succeeded([
            'business' => $business->slug,
            'source' => 'erp',
            'contract' => ErpConnector::SALES_ORDER_DRAFTS_CONTRACT,
            'status' => (string) $data['status'],
            'erp_draft_id' => (int) ($data['id'] ?? 0),
            'erp_key' => (string) ($data['idempotency_key'] ?? ''),
            'proposal_fingerprint' => (string) ($data['hq_proposal_fingerprint'] ?? ''),
            'store_key' => (string) ($data['store']['key'] ?? ''),
            'customer_resolution' => (string) ($data['customer']['resolution'] ?? ''),
            'lines' => collect((array) ($data['lines'] ?? []))->map(fn ($l) => [
                'sku' => $l['sku'] ?? null, 'quantity' => $l['quantity'] ?? null, 'unit_price' => $l['unit_price'] ?? null,
                'line_total' => $l['line_total'] ?? null, 'made_to_order' => $l['made_to_order'] ?? null,
            ])->values()->all(),
            'goods_total' => (string) ($data['goods_total'] ?? ''),
            'delivery_charge' => (string) ($data['delivery_charge'] ?? ''),
            'agreed_total' => (string) ($data['agreed_total'] ?? ''),
            'replayed' => (bool) $replayed,
            'verified' => true,
            'effects' => (array) ($data['effects'] ?? []),
        ], 'erp-draft:'.($data['id'] ?? '?'));
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
