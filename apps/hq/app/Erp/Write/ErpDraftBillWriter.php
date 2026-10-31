<?php

namespace App\Erp\Write;

use App\Erp\Write\Exceptions\ErpWriteRefused;
use App\Erp\Write\Exceptions\ErpWriteUnavailable;

/**
 * HQ's request that the ERP create a DRAFT supplier bill (capability `erp.draft_bill.create`, docs/144-erp-write-contract).
 *
 * ⛔ **Draft only.** The contract has no method to post, approve, pay or edit a bill, and the writer refuses an ERP
 * answer whose status is not `draft`. The ERP keeps its own approval inbox: HQ approves nothing in the ERP.
 * ⛔ **Needs a write token** (`erp_write_token`, a different credential from the read token) and, in HQ, a second
 * person's approval (`erp.create_draft_bill` is R4, the requester never decides it).
 * ⭐ **Idempotent**: the same idempotency key must never create a second draft (`Idempotency-Key`).
 *
 * The exact endpoint HQ expects is in docs/144-erp-write-contract.md; the ERP repository is not touched from here.
 *
 * ⭐ Contract tests (`tests/Feature/Erp/ErpDraftBillContractTest`): a valid bill returns a receipt with status
 * `draft` and a reference; the same key twice returns the same reference; a non-draft answer is refused; a 401 or
 * 403 is `ErpWriteRefused`; an unreachable ERP is `ErpWriteUnavailable`.
 */
interface ErpDraftBillWriter
{
    public function key(): string;

    /**
     * @throws ErpWriteRefused
     * @throws ErpWriteUnavailable
     */
    public function createDraftBill(string $accountRef, ErpDraftBill $bill, string $idempotencyKey): ErpDraftBillReceipt;
}
