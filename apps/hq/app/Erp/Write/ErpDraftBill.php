<?php

namespace App\Erp\Write;

use InvalidArgumentException;

/** What HQ asks the ERP to draft. Amounts are decimal strings in BDT; every line is checked before anything is sent. */
final readonly class ErpDraftBill
{
    /**
     * @param  list<array{description: string, quantity: string, unit_price: string, line_total: string}>  $lines
     */
    public function __construct(
        public int $erpBusinessId,
        public string $supplierName,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public string $currency,
        public string $total,
        public array $lines,
        public string $sourceDocumentUuid,
    ) {
        if ($currency !== 'BDT') {
            throw new InvalidArgumentException('Only BDT bills are drafted in the ERP.');
        }
        if (trim($supplierName) === '' || $lines === []) {
            throw new InvalidArgumentException('A draft bill needs a supplier and at least one line.');
        }
        foreach ([$total, ...array_map(static fn (array $l) => $l['line_total'], $lines)] as $amount) {
            if (preg_match('/^\d+\.\d{2}$/', $amount) !== 1) {
                throw new InvalidArgumentException("Not an amount with two decimals: [{$amount}].");
            }
        }
    }

    /** The body sent to the ERP. */
    public function toPayload(): array
    {
        return [
            'supplier_name' => $this->supplierName,
            'supplier_invoice_number' => $this->invoiceNumber,
            'bill_date' => $this->invoiceDate,
            'currency' => $this->currency,
            'total' => $this->total,
            'lines' => $this->lines,
            'source' => ['system' => 'hq', 'document_uuid' => $this->sourceDocumentUuid],
            'status' => 'draft',
        ];
    }
}
