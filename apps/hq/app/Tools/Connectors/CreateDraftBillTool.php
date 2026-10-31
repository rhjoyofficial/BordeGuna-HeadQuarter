<?php

namespace App\Tools\Connectors;

use App\Connectors\Capabilities;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorRequest;
use App\Erp\Write\ErpDraftBill;
use App\Erp\Write\ErpDraftBillWriter;
use App\Erp\Write\Exceptions\ErpWriteRefused;
use App\Erp\Write\Exceptions\ErpWriteUnavailable;
use App\Erp\Write\HttpErpDraftBillWriter;
use App\Gateway\Descriptor\ReadWrite;
use App\Gateway\Descriptor\RiskTier;
use App\Gateway\OutcomeClass;
use App\Ingestion\Extraction\BillChecks;
use App\Models\Business;
use App\Models\IngestedDocument;
use App\Support\Audit\Auditor;
use App\Tools\PreviewableTool;
use App\Tools\ToolContext;
use App\Tools\ToolResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * `erp.create_draft_bill`: ask the ERP to create a DRAFT supplier bill from a document a person reviewed
 * (docs/144-erp-write-contract). R4, so it always waits for approval, and `requesterMayDecide` is false: the person
 * who asks is never the one who approves ("second approval"). Draft only: the ERP's own inbox still decides.
 *
 * Idempotent on the document: the same document is the same ERP draft (the idempotency key is derived from it), so a
 * retry after an unknown outcome cannot create a second bill. An unknown outcome is `uncertain`, never `failed`.
 */
final class CreateDraftBillTool extends ConnectorTool implements PreviewableTool
{
    public function key(): string
    {
        return 'erp.create_draft_bill';
    }

    protected function title(): string
    {
        return 'Create a draft supplier bill in the ERP';
    }

    protected function purpose(): string
    {
        return 'Asks the ERP to create a DRAFT supplier bill from a reviewed document, with the supplier, lines and total a person checked. '
            .'The ERP keeps its own approval: HQ posts, pays and approves nothing. Needs the ERP write token and a second person\'s approval.';
    }

    protected function flag(): string
    {
        return 'connectors.erp_draft_bill';
    }

    protected function permission(): string
    {
        return 'hq.tools.erp.draft_bill';
    }

    protected function credentialSources(): array
    {
        return [HttpErpDraftBillWriter::CREDENTIAL => 'args.business_slug|business.slug'];
    }

    protected function arguments(): array
    {
        return ['document_uuid' => ['type' => 'string', 'minLength' => 36, 'maxLength' => 36]];
    }

    protected function required(): array
    {
        return ['document_uuid'];
    }

    protected function riskTier(): RiskTier
    {
        return RiskTier::R4;
    }

    protected function readWrite(): ReadWrite
    {
        return ReadWrite::Write;
    }

    protected function idempotent(): bool
    {
        return true;
    }

    protected function idempotencyKeyFrom(): array
    {
        return ['business_slug', 'document_uuid'];
    }

    protected function requesterMayDecide(): bool
    {
        return false;
    }

    public function preview(array $payload, ToolContext $context): array
    {
        $business = Business::query()->where('slug', $payload['business_slug'] ?? null)->first();
        $document = $business === null ? null : IngestedDocument::query()->where('business_id', $business->id)->where('uuid', $payload['document_uuid'] ?? null)->first();
        if ($document === null) {
            return ['would_send' => 'nothing: the document was not found for this business', 'external_effect' => 'none'];
        }
        try {
            $bill = $this->bill($business, $document);
        } catch (InvalidArgumentException $e) {
            return ['would_send' => 'nothing: '.$e->getMessage(), 'external_effect' => 'none'];
        }

        return ['would_send' => $bill->toPayload(), 'external_effect' => 'one DRAFT supplier bill in the ERP, awaiting the ERP\'s own approval'];
    }

    protected function run(?Business $business, array $payload, ToolContext $context): ToolResult
    {
        $document = IngestedDocument::query()->where('business_id', $business->id)->where('uuid', $payload['document_uuid'])->first();
        if ($document === null) {
            return ToolResult::failed('That document does not belong to this business.', [], OutcomeClass::ScopeDenied);
        }
        if ($document->status === IngestedDocument::SENT_TO_ERP) {
            return ToolResult::succeeded(['erp_draft_ref' => $document->erp_draft_ref, 'already' => true]);
        }
        if ($document->status !== IngestedDocument::REVIEWED) {
            return ToolResult::failed('Only a document a person has reviewed can be sent as a draft bill.', [], OutcomeClass::PolicyDenied);
        }
        // Ruling 31 Oct item 10, checked again here (defence in depth): every field confirmed by the reviewer, and the deterministic
        // arithmetic, total, tax and currency checks passing on the stored extraction.
        if (array_diff(IngestedDocument::REVIEW_FIELDS, (array) $document->review_confirmations) !== []) {
            return ToolResult::failed('Every field must be confirmed by the reviewer before a draft bill is asked for.', [], OutcomeClass::PolicyDenied);
        }
        if (! (new BillChecks)->run((array) $document->extracted)['passed']) {
            return ToolResult::failed('A deterministic check failed on this document, so no draft bill is asked for.', [], OutcomeClass::PolicyDenied);
        }
        try {
            $bill = $this->bill($business, $document);
        } catch (InvalidArgumentException $e) {
            return ToolResult::failed($e->getMessage(), [], OutcomeClass::InvalidArguments);
        }

        $resolution = app(ConnectorRegistry::class)->resolve(new ConnectorRequest(Capabilities::ERP_DRAFT_BILL_CREATE, $business->id));
        if (! $resolution->resolvedOk()) {
            return ToolResult::failed('Not connected yet: '.($resolution->reason ?? $resolution->outcome->value), ['connector_state' => $resolution->outcome->value], OutcomeClass::CapabilityUnavailable);
        }
        $writer = app(ConnectorRegistry::class)->adapter($resolution);
        if (! $writer instanceof ErpDraftBillWriter) {
            return ToolResult::failed('The connector is not an ERP draft bill writer.', [], OutcomeClass::InternalError);
        }

        try {
            $receipt = $writer->createDraftBill($business->slug, $bill, 'hq-draft-bill-'.$document->uuid);
        } catch (ErpWriteUnavailable $e) {
            return ToolResult::uncertain($e->getMessage());
        } catch (ErpWriteRefused $e) {
            $document->forceFill(['status' => IngestedDocument::ERP_FAILED, 'erp_draft_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return ToolResult::failed($e->getMessage(), ['connector_state' => 'refused'], OutcomeClass::ConnectorError);
        } catch (Throwable $e) {
            return ToolResult::uncertain($e::class);
        }

        $document->forceFill(['status' => IngestedDocument::SENT_TO_ERP, 'erp_draft_ref' => $receipt->reference, 'erp_draft_error' => null])->save();
        app(Auditor::class)->recordSystem('ingestion.draft_bill_sent_to_erp', $document, null, ['erp_draft_ref' => $receipt->reference, 'business_id' => $business->id, 'status' => $receipt->status]);

        return ToolResult::succeeded(['erp_draft_ref' => $receipt->reference, 'status' => $receipt->status], $receipt->reference);
    }

    /** @throws InvalidArgumentException when the reviewed fields are not enough for a bill */
    private function bill(Business $business, IngestedDocument $document): ErpDraftBill
    {
        $x = $document->extracted;
        if ($business->erp_business_id === null) {
            throw new InvalidArgumentException('This business has no ERP business id.');
        }
        $supplier = $x['supplier']['value'] ?? null;
        $total = $x['total']['value'] ?? null;
        if (! is_string($supplier) || ! is_string($total)) {
            throw new InvalidArgumentException('The supplier and the total must be read before a bill can be drafted.');
        }
        $lines = [];
        foreach ((array) ($x['lines'] ?? []) as $line) {
            $lines[] = ['description' => (string) $line['description']['value'], 'quantity' => (string) $line['quantity']['value'],
                'unit_price' => (string) $line['unit_price']['value'], 'line_total' => (string) $line['line_total']['value']];
        }
        $date = null;
        if (is_string($x['invoice_date']['value'] ?? null)) {
            try {
                $date = CarbonImmutable::parse($x['invoice_date']['value'])->toDateString();
            } catch (Throwable) {
                $date = null;
            }
        }

        return new ErpDraftBill((int) $business->erp_business_id, $supplier, $x['invoice_number']['value'] ?? null, $date, (string) ($x['total']['currency'] ?? 'BDT'), $total, $lines, $document->uuid);
    }
}
