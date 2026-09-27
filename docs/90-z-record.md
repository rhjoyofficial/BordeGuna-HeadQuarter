# 90-Z - Stream Z record: the `facts_orders` row written at the ERP order read-back (26 September 2026)

Branch `p4/facts-orders-read-back-row`, worktree `hq-wt/g`, test database `hq_test_g`, from `origin/main` at
`43d8782`. Instruction: `docs/90` A.5 (ChatGPT, 26 September): create the read-side record at read-back
time, with the evidence discipline used elsewhere, the ERP authoritative, the reconciler judging later.

An analogy first. Until now HQ's order ledger (`facts_orders`) heard about orders only from the shops, by
webhook or by re-reading the shop's admin list. An order HQ itself places in the ERP was read back after
the write to prove it existed, and then forgotten by the ledger: the proof was kept only in the tool call.
Now the read-back is also a ledger entry, written the moment the ERP's answer has been validated, with a
label on it saying which system said so, which order it means there, when that system said it was true,
which HQ call read it, and a fingerprint of exactly what was read. The ledger is a copy. The ERP is the book.

## What was built

- **`App\Facts\ErpOrderReadBackFact`**: one writer for what the ERP says about an order HQ placed. It
  validates the answer (found; `status`, `order_number` and `shop_reference` present; the answer on the
  `hq-sales-orders.v1` contract), fails closed with no row otherwise; computes the digest of the ERP's
  `data` (`CanonicalPayload::encode`); refuses to write when the same digest is already on the row (a
  replayed order, a status read of an unchanged order: nothing moves, not even `synced_at`) and when the
  answer's `as_of` is older than the one already applied (a slow reply never beats a newer one); then maps
  the ERP order into the shape the shop upserter reads and writes through **`FactsOrderUpserter`**, so one
  writer keeps one discipline (a status never regresses, the customer is resolved the same way, the totals
  land in the same columns); then fills the evidence columns. `as_of` is normalised to UTC before it is
  stored, because Eloquent writes a date's wall clock without its offset and a `+06:00` instant kept in
  Dhaka time would land six hours late, which made every later read-back read as "older" on the first run.
- **Migration `2026_09_26_100000_add_erp_read_back_evidence_to_facts_orders`**, additive: ten nullable
  columns on `facts_orders` (`source_system` shop|erp, `provenance` (ADR-024, `business` for the ERP),
  `erp_order_id`, `erp_order_number`, `erp_status`, `erp_approval_status`, `hq_key`, `correlation_id`,
  `read_back_digest`, `read_back_at`) and one index. Every existing row is a shop fact (`source_system`
  null). Nothing dropped or changed.
- **Where it is written.** `take_order` (`TakeOrderTool::judgeOrder`): after the order's read-back by HQ's
  key and BEFORE the judgement against the approved draft, so the row is the ERP's side whether or not the
  ERP agrees with the proposal; the reconcile path (an uncertain call settled by reading back) goes through
  the same place. `commerce.get_order_status` (`GetOrderStatusTool::afterRead`, a new hook on
  `ErpOrderReadTool`): the ERP's current answer replaces the row, or writes nothing when nothing changed.
  `commerce.cancel_sales_order` (`CancelSalesOrderTool::recordFact`): the read-back after a cancellation, in
  the execute and the reconcile path, before the judgement.
- **The fact's key** is the shop's: the HQ store the order was placed for (`store_slug` in the request, one
  of the business's stores, or the tool fails closed) and the reference the customer quotes
  (`shop_reference`), unique on `facts_orders` as every shop order is. `source` is `hq_ai`. The ERP order
  number and id sit beside it. The ERP's status word is kept in `erp_status`; the shop-shaped `status` reads
  `open` as `pending` (`confirmed` once the ERP calls it deliverable), `partially_delivered` as
  `processing`, `delivered`/`closed` as `delivered`, `cancelled` as `cancelled`.
- **Personal data:** the same as every shop fact (the canonical phone, the district and thana, the
  customer resolved through `IdentityResolver`), no more: no address line, no name on the row.

## Not built here, on purpose

- No write to the ERP of any kind: every request the writer causes is a read (held by a test).
- No repair: a row that disagrees with the approved draft is exactly the ERP's figures, and the tool reports
  the disagreement as `uncertain`; a row that disagrees with a later ERP answer is replaced by that answer.
  The reconciler's judgement of row against ERP (`docs/79` C.17, the `FactsReconciler` ERP path) is still to
  come; this row is the evidence it will judge.
- A status read of an order that has **no** fact row writes nothing: the ERP names the order's store by the
  ERP's store key, and HQ keeps no map from an ERP store key to an HQ store, so the row cannot be placed. On
  this build that is only an order HQ did not place through `take_order`, which the status tool cannot
  reach anyway ("none" is its answer). Recorded as an open item.
- No change to the ERP side (`76f6168` on ERP master), no screen, nothing registered, no store admitted, no
  token with a write ability, no agent holding the tools: everything as stream R left it.

## Verification (local; GitHub Actions validation deferred until the 1 October reset)

- New: `tests/Feature/Commerce/OrderReadBackFactTest.php`, 8 tests: the row with every evidence column
  from `take_order`'s read-back and the customer linked and counted; nothing written on a replayed order or
  an unchanged status read (not even a timestamp); the row following a moved order (partly delivered) and
  refusing an older answer; the ERP's side recorded (quantity 3) when the read-back disagrees with the draft
  (quantity 2) while the tool stays uncertain and sends nothing to make it agree; the cancellation read-back
  turning the same row cancelled; no row from an answer that names no order or is off the contract, and a
  store of another business failing closed; a status read with no row left alone; every request a read.
- Mutations, each applied, the file run, the code restored: six, all caught, as the stream reported before the session that ran them was cut by the usage limit on 26 September; the itemised list was lost with that session and is not reconstructed here (the tests that hold each rule are in OrderReadBackFactTest).
- The commerce, facts, ERP, posture, namespace and shared-foundation suites together: 157 passed.
- Full Pest suite on `hq_test_g`: **1,612 passed, 18,924 assertions, 0 failed** (one run on 27 September; main 1,604 plus the eight new). `vendor/bin/pint --test`: passed. `npx tsc --noEmit`: clean.
  `npx vite build`: built. No file under `resources/js` changed.

## The eight columns (docs/88 A point 21)

| | implemented | merged | registered | credentialed | live-called | agent-held | agent-granted | activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stream Z, the `facts_orders` row at read-back | yes | not yet (branch pushed for review) | n/a (no tool added; the three tools as R left them, registered nowhere for an agent) | no | no | no | no | no |

Nothing an agent could observe or use changed: no prompt, tool list, policy row or runtime path an agent
reaches. Nothing for Gate 13 or the Executive execution-integrity check.

## Open questions

1. A map from an ERP store key to an HQ store does not exist. `take_order` carries both (`store_slug` and
   `erp_store_key`), so its facts are placed; a status read of an order placed any other way cannot be.
   One column on `stores` (`erp_store_key`) would settle it, when an order placed outside `take_order`
   should ever become an HQ fact.
2. The shop upserter's `last_event_at` is written from the caller's instant as given; this stream passes
   UTC. The two shop paths pass what the shop sent; if a shop ever sends an offset other than UTC, the
   same six-hour trap applies there. Not changed here (out of scope), noted for the owner of that path.
