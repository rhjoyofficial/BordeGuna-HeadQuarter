# 144. The ERP write contract HQ expects: a DRAFT supplier bill (Q5, 31 October 2026)

This is the exact endpoint HQ's `HttpErpDraftBillWriter` calls and the document the ERP session builds against. HQ ships the client, the fake and
the tests; **the ERP repository was not touched** and this endpoint does not exist in it yet. It is the second write HQ may ever ask of the ERP (the first is
the sales-order draft of `take_order`, docs/79) and follows the same rules: HQ asks, the ERP decides, an approval inbox stays the ERP's own.

## 1. Rules (HQ side, enforced in code and tested)

1. **Draft only.** The contract has one method, `createDraftBill`. There is no post, approve, pay, edit or delete. HQ refuses any ERP answer whose status
   is not `draft`, even a 2xx, and never records "sent to the ERP" for one.
2. **A write token that is not the read token.** Credential kind `erp_write_token`, account reference = the business slug. The read token
   (`erp_api_token`) must not be able to call this endpoint, and this token should hold only the ability below.
3. **Second approval in HQ.** The tool `erp.create_draft_bill` is R4, permission `hq.tools.erp.draft_bill`, `requesterMayDecide = false`: the person who asks
   is never the one who approves, owner included. Only a document a person marked **reviewed** can be asked for.
4. **Idempotent.** HQ sends `Idempotency-Key: hq-draft-bill-<document uuid>`. The same key must return the SAME draft (HTTP 200 with the same reference),
   never a second bill.
5. **An unknown outcome is not a failure.** A timeout, a 429 or a 5xx makes the tool `uncertain`; the same key makes the retry safe. A 401, 403, 404 or 422 is a refusal.
6. **Flag** `connectors.erp_draft_bill` (OFF), registry row `erp.draft_bill.create` / `erp_http`.

## 2. Request

```
POST {HQ_ERP_BASE_URL}/api/hq/v1/businesses/{erp_business_id}/purchase-bills/drafts
Authorization: Bearer <erp_write_token>
Accept: application/json
Content-Type: application/json
Idempotency-Key: hq-draft-bill-<uuid>
X-Correlation-Id: <the idempotency key with unsafe characters removed, at most 128>
```

The path is configuration (`hq.erp.write.draft_bill_path`). `{erp_business_id}` is `businesses.erp_business_id`, and the token is issued for exactly one
business, as the read token is: the ERP refuses any other business in the URL.

```json
{
  "supplier_name": "Green Valley Traders",
  "supplier_invoice_number": "GV-2026-0042",
  "bill_date": "2026-10-20",
  "currency": "BDT",
  "total": "5300.00",
  "lines": [
    { "description": "Hilsa pickle jar", "quantity": "4", "unit_price": "450.00", "line_total": "1800.00" },
    { "description": "Mustard oil 5L", "quantity": "2", "unit_price": "1750.00", "line_total": "3500.00" }
  ],
  "source": { "system": "hq", "document_uuid": "9b0f7a0e-0000-4000-8000-000000000000" },
  "status": "draft"
}
```

- Amounts are decimal strings with exactly two decimals, in BDT (HQ refuses any other currency before sending). `quantity` is a decimal string.
- `supplier_invoice_number` and `bill_date` may be `null` (the document did not state them).
- Every field was read from a document by a program and checked by a person in HQ; the ERP must still validate it as it validates any input.
- No account, tax, product, warehouse or approver is sent: HQ does not know the ERP's chart. The ERP matches `supplier_name` to a supplier if it can and
  leaves the bill's supplier for a person to pick if it cannot; it maps lines to products or raw materials if it can and otherwise leaves them as
  **unmapped lines** for a person to map. HQ never creates a supplier or a product.

## 3. Responses

| Status | Meaning | HQ does |
|---|---|---|
| 201 | Draft created | records `sent_to_erp` with the reference |
| 200 | Same idempotency key seen before: the existing draft | same, no second draft |
| 401, 403 | Token rejected, or lacks the ability | refusal (`erp_failed`), nothing retried |
| 404 | Unknown business or route | refusal |
| 422 | Validation failed (bad amount, lines that do not add up, unknown currency) | refusal, the document goes to `erp_failed` with the reason shown |
| 429, 5xx, timeout | Unknown | `uncertain`; retry with the same key |

Success body (both 200 and 201):

```json
{ "data": { "id": 55, "number": "PB-DRAFT-0055", "status": "draft" } }
```

HQ stores `data.number` (else `data.id`) as the reference. `data.status` MUST be exactly `"draft"`.

## 4. What the ERP must guarantee

- The bill is created in the ERP's own draft state, **not posted, not approved, not paid**, and it appears in the ERP's normal approval flow like any draft.
  The ERP's maker-checker rules apply: HQ is the maker; a person in the ERP approves (`feedback_erp_maker_may_approve` governs who).
- The bill carries its source (`source.system = hq`, the document uuid) so an auditor can trace it back to the HQ document and mail.
- The ability needed is a single new one, suggested `purchasing.bills.draft.create`, that no other token holds. No journal, stock or payment effect happens at
  draft creation.
- Duplicate protection beyond the key: the ERP may additionally refuse (422) a draft with the same supplier and invoice number as an existing bill.

## 5. Recommended, not required by HQ today

A read for reconciliation after an unknown outcome: `GET .../purchase-bills/drafts?idempotency_key=...` returning the draft or 404. HQ's tool has a `reconcile`
hook that returns nothing today; the same-key retry already makes an unknown outcome safe, so this is a convenience.

## 6. Work for the ERP session

1. Add the ability and issue a separate token per business (the read token stays as it is).
2. Build `POST /api/hq/v1/businesses/{business}/purchase-bills/drafts` as above, with the idempotency store.
3. Add the unmapped-line and unmatched-supplier states to the draft bill screen so a person can finish it.
4. Tell Joy when it is live; then he stores the token, switches `connectors.erp_draft_bill` on, and a first reviewed document is sent through the approval.
5. Re-check these shapes against what the ERP actually answers, and correct this document or the client; the contract test (`ErpDraftBillContractTest` inside
   `tests/Feature/Ingestion/IngestionTest.php`) is the checklist.

## 7. Questions for ChatGPT

1. Is a draft supplier bill an acceptable second ERP write, given ADR-046's order-draft precedent? (Default: yes, draft only, second approval.)
2. Should HQ ever attach the source PDF to the ERP draft? (Default: no; the ERP holds the number, the document stays in HQ with its mail.)
