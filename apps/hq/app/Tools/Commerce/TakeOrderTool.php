<?php

namespace App\Tools\Commerce;

use App\Commerce\Exceptions\Refused;
use App\Commerce\Exceptions\Uncertain;
use App\Commerce\Exceptions\Unsupported;
use App\Commerce\Exceptions\ValidationFailed;
use App\Commerce\Orders\DraftReconciliation;
use App\Commerce\Orders\ErpOperationKey;
use App\Commerce\Orders\OrderReconciliation;
use App\Commerce\Orders\TakeOrderShape;
use App\Erp\Dto\ErpOrderRead;
use App\Erp\ErpPlatform;
use App\Erp\ErpRegistry;
use App\Facts\ErpOrderReadBackFact;
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
use App\Models\Store;
use App\Models\ToolCall;
use App\Tools\PreviewableTool;
use App\Tools\Tool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Throwable;

/**
 * `take_order`: the one business capability for writing an order (docs/79, ADR-046). Its first stage
 * (docs/83 C.3) left a DRAFT in the ERP and nothing after it; its second (docs/88 A point 3) turns
 * that draft into a sales order that WAITS in the ERP's approval inbox. Both stages are inert.
 *
 * ⛔⛔ INERT BY CONSTRUCTION, three times over.
 *   1. `supportedStores` is `config('hq.orders.take_order_stores')`, which ships EMPTY: every call
 *      names a store and the gateway refuses every store. Admitting one is a deploy and the P4-G2
 *      gate (docs/79 G), never a switch.
 *   2. No ERP token holds `sales.orders.draft` or `sales.orders.record`; the ERP refuses each write
 *      with 403 until a token is issued for them by name (`hq:api:token --write`).
 *   3. `agentExecutable: false`, and no agent's tool list names it: it is not in any agent's
 *      catalogue, and an agent's proposal of it runs nothing (ADR-031, A0).
 *
 * ⛔ R4, NOT R3 (docs/83 B). R4 is "external side effect, recoverable" and its baseline is approval;
 * R3 is "internal write" and runs without one. An ERP order is an external record. Every call is
 * approval-required with no value threshold (V.1), and the person who asked may never decide it
 * (`requesterMayDecide: false`), owner included.
 *
 * ⚠ TWO APPROVALS STAND (docs/79 F). HQ's approval, given by somebody other than the asker, says HQ
 * may write; it runs this tool. The ERP's approval, a person's act in the ERP's own inbox, says
 * goods may leave; this tool never gives it, and the order is written by the ERP's system actor so
 * it always lands pending. `effects.approved_by_hq` in the ERP's answer says false, and the
 * read-back refuses to call the order verified unless it does.
 *
 * ⚠ TWO ERP WRITES, EACH UNDER ITS OWN KEY, EACH READ BACK (ADR-031). First the draft (the ERP prices
 * and checks the proposal), read back and compared by `DraftReconciliation`; then the order made
 * from that draft (the ERP prices the draft AGAIN, now, and refuses anything that moved), read back
 * by HQ's key and compared by `OrderReconciliation`. `succeeded` only when both agree. A POST that
 * never answered, a read-back that failed, or a read-back that disagrees is `uncertain`, named;
 * never `succeeded` on the strength of a 201. `reconcile()` reads both back, in order, before the
 * gateway sends anything again.
 *
 * ⚠ NOTHING IS REDACTED FROM THE ARGUMENTS, deliberately: the approval stores the redacted payload
 * and executes exactly that (ADR-018), so a redacted phone would never reach the ERP. The OUTPUT
 * carries no name, phone or address.
 */
final class TakeOrderTool implements PreviewableTool, Tool
{
    public const KEY = 'commerce.take_order';

    /** The step name folded into the ERP key of the order made from the draft. */
    public const ORDER_STEP = 'order';

    public function __construct(
        private readonly ErpRegistry $registry,
        private readonly DraftReconciliation $draftReconciliation,
        private readonly OrderReconciliation $orderReconciliation,
        private readonly ErpOrderReadBackFact $readBack,
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
            'approval_status' => ['type' => 'string'],
            'deliverable' => ['type' => 'boolean'],
            'erp_order_id' => ['type' => 'integer'],
            'order_number' => ['type' => 'string'],
            'shop_reference' => ['type' => 'string'],
            'erp_draft_id' => ['type' => 'integer'],
            'erp_draft_key' => ['type' => 'string'],
            'erp_key' => ['type' => 'string'],
            'proposal_fingerprint' => ['type' => 'string'],
            'store_key' => ['type' => 'string'],
            'customer_resolution' => ['type' => 'string'],
            'lines' => ['type' => 'array'],
            'goods_total' => ['type' => 'string'],
            'delivery_charge' => ['type' => 'string'],
            'agreed_total' => ['type' => 'string'],
            'delivery_promise' => ['type' => ['string', 'null']],
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
            title: 'Take an order (an order waiting for approval in the ERP)',
            purpose: 'Writes a sales order in the ERP for a named store, from a draft the ERP prices and checks first: the ERP refuses '
                .'any figure that no longer matches. The order WAITS in the ERP\'s approval inbox for a person there; goods leave only '
                .'against an approved order. No stock, journal, payment or courier follows from this call.',
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
            compensatingTool: CancelSalesOrderTool::KEY,
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
            'would_write' => 'a sales order in the ERP for store '.($payload['erp_store_key'] ?? '?').', from a draft the ERP prices and checks first',
            'lines' => $lines,
            'proposed' => $payload['proposed'] ?? null,
            'payment_method' => $payload['payment_method'] ?? null,
            'after_the_order' => 'it waits in the ERP\'s approval inbox for a person there; nothing else: no stock, journal, payment or courier, and HQ approves nothing in the ERP',
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
        $draftKey = self::erpKey($context->idempotencyKey, $business->id);
        $fingerprint = CanonicalPayload::hash($payload);

        // Step 1: the draft. The ERP prices and checks the proposal; a draft under this key replays.
        try {
            $erp->createSalesOrderDraft([
                'idempotency_key' => $draftKey,
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

        // ⚠ The draft's postcondition is its READ-BACK, never the 201 (ADR-031).
        try {
            $draft = $erp->readSalesOrderDraft($draftKey);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The ERP accepted the draft, and reading it back failed, so it is not verified: '.$e->getMessage());
        }
        if (! $draft->found) {
            return ToolResult::uncertain('The ERP accepted the draft, and has no draft under its key when read back.');
        }
        if (($disagreement = $this->draftDisagreement($draft, $payload)) !== null) {
            return $disagreement;
        }

        // Step 2: the order made from that draft. The ERP prices the draft AGAIN, now, and refuses
        // anything that moved; the order is written by the ERP's system actor and WAITS there.
        return $this->recordOrder($erp, $draftKey, $fingerprint, $payload, $business, $context);
    }

    /**
     * An earlier call with this key ended `uncertain`: read back before anything is sent again.
     * No draft under the key: null, and the gateway may send it all again safely, because the ERP
     * answers one key once. A draft and no order: null too, since sending again replays the draft
     * and then records the order. Draft and order both there and agreeing with what the uncertain
     * call executed: `succeeded`. Anything disagreeing or unreadable: still `uncertain`, for a person.
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
        $payload = (array) $prior->input;

        $draftKey = self::erpKey($context->idempotencyKey, $business->id);
        $erp = $this->registry->for($business);
        try {
            $draft = $erp->readSalesOrderDraft($draftKey);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The draft could not be read back from the ERP to settle an uncertain call: '.$e->getMessage());
        }
        if (! $draft->found) {
            return null;
        }
        if (($disagreement = $this->draftDisagreement($draft, $payload)) !== null) {
            return $disagreement;
        }

        $orderKey = ErpOperationKey::for($context->idempotencyKey, $business->id, self::ORDER_STEP);
        try {
            $order = $erp->readSalesOrderByKey($orderKey);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The order could not be read back from the ERP to settle an uncertain call: '.$e->getMessage());
        }
        if (! $order->found) {
            return null;
        }

        return $this->judgeOrder($order, $draft, $draftKey, CanonicalPayload::hash($payload), $payload, $business, null, $context->correlationId);
    }

    /** The ERP's key for the draft: the gateway's key and the business, so a reconcile finds it. */
    public static function erpKey(string $gatewayKey, int $businessId): string
    {
        return ErpOperationKey::for($gatewayKey, $businessId);
    }

    private function recordOrder(ErpPlatform $erp, string $draftKey, string $fingerprint, array $payload, Business $business, ToolContext $context): ToolResult
    {
        $orderKey = ErpOperationKey::for($context->idempotencyKey, $business->id, self::ORDER_STEP);

        try {
            $recorded = $erp->recordSalesOrder([
                'idempotency_key' => $orderKey,
                'draft_key' => $draftKey,
                'proposed' => $payload['proposed'],
                'hq_reference' => [
                    'proposal_fingerprint' => $fingerprint,
                    'approval_uuid' => ErpOperationKey::approvalUuid($context->idempotencyKey),
                    'conversation_uuid' => $payload['conversation_uuid'] ?? null,
                ],
            ]);
        } catch (Refused|ValidationFailed|Unsupported $e) {
            // The draft already became an order under another key, the ERP now prices the draft
            // differently (write a new draft), a token without the ability: answers, never retried.
            return ToolResult::failedBy($e);
        } catch (Uncertain $e) {
            // The draft stands; the order may or may not exist. The gateway reconciles before retrying.
            throw $e;
        }

        // ⚠ The order's postcondition is its READ-BACK by HQ's key, never the 201 (ADR-031).
        try {
            $order = $erp->readSalesOrderByKey($orderKey);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The ERP accepted the order, and reading it back failed, so it is not verified: '.$e->getMessage());
        }
        if (! $order->found) {
            return ToolResult::uncertain('The ERP accepted the order, and has no order under its key when read back.');
        }

        try {
            $draft = $erp->readSalesOrderDraft($draftKey);
        } catch (Throwable $e) {
            return ToolResult::uncertain('The ERP accepted the order, and reading the draft back beside it failed, so it is not verified: '.$e->getMessage());
        }

        return $this->judgeOrder($order, $draft, $draftKey, $fingerprint, $payload, $business, (bool) ($recorded->data['replayed'] ?? false), $context->correlationId);
    }

    private function draftDisagreement(ErpOrderRead $draft, array $payload): ?ToolResult
    {
        $discrepancies = $this->draftReconciliation->compare($payload, $draft->data);
        if ($discrepancies === []) {
            return null;
        }
        $named = collect($discrepancies)->map(fn ($d) => "{$d['field']}: {$d['note']}")->implode(' ');

        return ToolResult::uncertain('The ERP draft does not agree with the proposal, and nothing was changed to make it: '.$named, 'erp-draft:'.($draft->data['id'] ?? '?'));
    }

    private function judgeOrder(ErpOrderRead $order, ErpOrderRead $draft, string $draftKey, string $fingerprint, array $payload, Business $business, ?bool $replayed, ?string $correlationId = null): ToolResult
    {
        $data = $order->data;
        $ref = 'erp-order:'.($data['id'] ?? '?');

        // docs/90 A.5: what the ERP said is a fact the moment it was read back, validated by the
        // connector, BEFORE the judgement: the row copies the ERP's side whether or not it agrees
        // with the approved draft, and a disagreement below is reported, never repaired by the row.
        $this->readBack->record($this->hqStore($business, $payload), $order, $correlationId);

        $discrepancies = $this->orderReconciliation->compare($payload, $data, $draftKey, $fingerprint);
        if ($discrepancies !== []) {
            $named = collect($discrepancies)->map(fn ($d) => "{$d['field']}: {$d['note']}")->implode(' ');

            return ToolResult::uncertain('The ERP order does not agree with the approved draft, and nothing was changed to make it: '.$named, $ref);
        }

        return ToolResult::succeeded([
            'business' => $business->slug,
            'source' => 'erp',
            'contract' => ErpPlatform::SALES_ORDERS_CONTRACT,
            'status' => (string) $data['status'],
            'approval_status' => (string) ($data['approval_status'] ?? ''),
            'deliverable' => (bool) ($data['deliverable'] ?? false),
            'erp_order_id' => (int) ($data['id'] ?? 0),
            'order_number' => (string) ($data['order_number'] ?? ''),
            'shop_reference' => (string) ($data['shop_reference'] ?? ''),
            'erp_draft_id' => (int) ($draft->data['id'] ?? 0),
            'erp_draft_key' => $draftKey,
            'erp_key' => (string) ($data['hq']['idempotency_key'] ?? ''),
            'proposal_fingerprint' => (string) ($data['hq']['proposal_fingerprint'] ?? ''),
            'store_key' => (string) ($data['store']['key'] ?? ''),
            'customer_resolution' => (string) ($draft->data['customer']['resolution'] ?? ''),
            'lines' => collect((array) ($data['lines'] ?? []))->map(fn ($l) => [
                'sku' => $l['sku'] ?? null, 'quantity' => $l['quantity'] ?? null, 'unit_price' => $l['unit_price'] ?? null,
                'quantity_delivered' => $l['quantity_delivered'] ?? null,
            ])->values()->all(),
            'goods_total' => (string) ($data['goods_total'] ?? ''),
            'delivery_charge' => (string) ($data['delivery_charge'] ?? ''),
            'agreed_total' => (string) ($data['agreed_total'] ?? ''),
            'delivery_promise' => $data['delivery_promise'] ?? null,
            'replayed' => (bool) $replayed,
            'verified' => true,
            'effects' => (array) ($data['effects'] ?? []),
            'next_step' => (string) ($data['next_step'] ?? ''),
        ], $ref);
    }

    /** The HQ store the order was placed for (`store_slug`, one of this business's): the fact's store. */
    private function hqStore(Business $business, array $payload): Store
    {
        $slug = is_string($payload['store_slug'] ?? null) ? $payload['store_slug'] : '';
        $store = Store::query()->where('business_id', $business->id)->where('slug', $slug)->first();
        if ($store === null) {
            throw new \InvalidArgumentException("[store_slug] {$slug} is not a store of business {$business->slug}; the order fact has no store to belong to.");
        }

        return $store;
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
