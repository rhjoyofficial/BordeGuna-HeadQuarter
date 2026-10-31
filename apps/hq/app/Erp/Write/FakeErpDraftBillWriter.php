<?php

namespace App\Erp\Write;

use App\Erp\Write\Exceptions\ErpWriteRefused;
use App\Erp\Write\Exceptions\ErpWriteUnavailable;

/**
 * An ERP that exists only inside HQ: remembers the idempotency keys it has seen (per process) and answers a
 * draft with a reference derived from the key, so the same key is the same draft. A supplier named `FAKE-REFUSE`
 * is refused and `FAKE-DOWN` is unavailable. Writes nothing anywhere.
 */
final class FakeErpDraftBillWriter implements ErpDraftBillWriter
{
    /** @var array<string, string> */
    private static array $seen = [];

    public function key(): string
    {
        return 'fake_erp';
    }

    public function createDraftBill(string $accountRef, ErpDraftBill $bill, string $idempotencyKey): ErpDraftBillReceipt
    {
        if ($bill->supplierName === 'FAKE-DOWN') {
            throw new ErpWriteUnavailable('fake_erp: the fake ERP is down.');
        }
        if ($bill->supplierName === 'FAKE-REFUSE') {
            throw new ErpWriteRefused('fake_erp: the fake ERP refused this bill.');
        }

        return new ErpDraftBillReceipt(self::$seen[$accountRef.'|'.$idempotencyKey] ??= 'FAKE-DRAFT-'.strtoupper(substr(hash('sha256', $accountRef.'|'.$idempotencyKey), 0, 8)));
    }
}
