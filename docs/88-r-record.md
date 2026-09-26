# 88 R - Stream R record: Phase 4 order completion, HQ and ERP, all inert (26 September 2026)

Branches: HQ `p4/order-capability-inert` (worktree `hq-wt/g`, test database `hq_test_g`, built on `origin/main`
at `37a2be6`), ERP `feature/hq-sales-order-confirm` (worktree `erp-wt/hq-reads`, on ERP master `c6d6c10`, which
is ERP production `e5772ad` plus the draft-order commit `0fe39af`). Under `docs/88` A point 3, with `docs/79`
(the Phase 4 design, ADR-046) and `docs/83` C.3 (the draft write as built). Resumed on 26 September from the
ERP side's uncommitted work of 22 September, which was read whole and judged before anything was added.

## What was built

**The shape of it, in one paragraph.** A person in HQ proposes an order; somebody else approves it; HQ then
asks the ERP twice, each time under its own key and each time reading back: first for a DRAFT (the ERP prices
and checks the proposal and refuses any figure that moved), then for the ORDER made from that draft (the ERP
prices the draft AGAIN, now, and refuses anything that moved since). The order is written by the ERP's own
`CreateSalesOrder`, by the ERP's system actor, so it always lands PENDING in the ERP's approval inbox with
the ERP's approvers notified: HQ approves nothing in the ERP, and goods leave only against an order a person
there approved (`docs/79` F: "HQ's approval says HQ may write; the ERP's approval says goods may leave. Both
stand."). HQ can quote before proposing, read the order's status afterwards, and cancel it while nothing has
been delivered, each under the ERP's own rules. Payment and courier are not built: they are the ERP's, and HQ
may read the resulting state later.

### ERP side (`feature/hq-sales-order-confirm`)

- **One additive migration** in the Sales module, `2026_09_22_100000_an_hq_draft_becomes_an_order_only_through_the_erps_approval`:
  `sales_order_hq_confirmations` (the link from one draft to one order, with HQ's key, the request digest,
  the HQ references, correlation id, token id and the HQ user that asked; unique per business and key, unique
  per draft, unique per order) and `sales_order_hq_cancellations` (one per order, one per key, with the
  reason). `sales_orders` and `sales_order_drafts` are not touched; the draft stays immutable. Every index is
  named (the 64-character MySQL ceiling).
- **`HqOrderPricing`**: the ERP's own questions about an order HQ proposes, asked in one place for three
  moments (the quote, the draft, the order made from the draft), so a draft cannot be written at one price
  and turned into an order at another. `CreateSalesOrderDraft` now asks here too (behaviour unchanged; the
  draft suite's 15 tests still pass and the drafts contract only changed its `next_step` sentence).
- **`ConfirmHqSalesOrderDraft`** (`POST sales-orders`, ability `sales.orders.record`): the request must name
  the draft's own proposal fingerprint and propose exactly the draft's totals; the ERP prices the draft again
  and refuses any line or total that moved (`quote_mismatch`, both figures named, "Write a new draft"); the
  draft row is locked and the check repeated inside the transaction; the order is written by
  `CreateSalesOrder` under the SYSTEM ACTOR (which holds no `sales.orders.approve`), with `SalesOrderOrigin::website`
  and a shop reference, so it arrives pending and the approvers are notified; after the write, the order's
  totals must equal the draft's to the paisa and its approval must be pending, or the whole order is rolled
  back. The customer is the one the website's `identify` would pick, or a new Retail customer (never the
  walk-in row). Idempotent on HQ's key (the same request replays the same order; another request 409); a draft
  already turned into an order refuses a second key (409 `draft_already_confirmed`); no draft, 404.
- **`CancelHqSalesOrder`** (`POST sales-orders/{reference}/cancel`, ability `sales.orders.cancel`): the rule is
  the ERP's `CancelSalesOrder`, not a new one (only an open order with nothing delivered; the ERP's own
  sentence once anything has left); it reaches ONLY an order HQ placed (`HqPlacedOrders`: an order a colleague
  typed in answers 404 whatever its number), is idempotent on HQ's key, and records the key, the request and
  the reason.
- **Reads**: `POST sales-orders/quote` (a read sent as POST, ability `sales.view`, writes nothing, `effects.written: false`),
  `GET sales-orders/{reference}` (by shop reference or order number) and `GET sales-orders/by-key/{key}` (the
  reconcile path), each reaching only orders HQ placed.
- **Abilities**: `HqApiToken::WRITE_ABILITIES` is now `sales.orders.draft`, `sales.orders.record`,
  `sales.orders.cancel`; each is issued only by name with `--write`; `sales.orders.approve` is not there and
  never will be, held by a test.
- **Every body** is stamped `hq-sales-orders.v1` and states its effects: `sales_order_created`,
  `approval_requested`, `approved_by_hq: false`, `stock_moved: false`, `journal_posted: false`,
  `payment_taken: false`, `courier_booked: false`, and a `next_step` sentence.

### HQ side (`p4/order-capability-inert`)

- **`ErpConnector`**: `quoteSalesOrder`, `recordSalesOrder`, `readSalesOrder`, `readSalesOrderByKey`,
  `cancelSalesOrder`, and the `hq-sales-orders.v1` contract check on every body (contract, business, and for
  an order a status and an order number). A POST that never answered is `Uncertain`; 409 is `Refused`, never
  retried; 422 is `ValidationFailed` with the ERP's sentences per field; 404 on a read is an answer.
- **`commerce.take_order`, stage two.** After the draft is created and read back (`DraftReconciliation`,
  unchanged), the tool records the order from it under a second key (`ErpOperationKey`: the gateway's key,
  the business, and the step `order`), naming the draft, the proposal fingerprint and the approval uuid; reads
  the order back BY HQ'S KEY and the draft beside it; and calls the call `succeeded` only when
  `OrderReconciliation` finds nothing to report: the order is `open`, its ERP approval `pending`, not
  deliverable, made from THIS draft for THIS proposal, the same store, payment method, lines and totals, and
  `effects.approved_by_hq`, `stock_moved`, `journal_posted`, `payment_taken` and `courier_booked` all false.
  Anything else is `uncertain`, named. `reconcile()` reads the draft, then the order by key: no draft, or a
  draft and no order, hands the operation back to the gateway (the draft replays under its key, the order is
  then made); both present and agreeing is `succeeded`; anything disagreeing or unreadable stays `uncertain`.
  The output carries the order number, the shop reference, the two ERP keys and the effects, and no name,
  phone or address. The preview and the descriptor's purpose now say an order that waits in the ERP's
  approval inbox, not "a draft and nothing after it".
- **`commerce.quote_order`** (R2, read, `hq.tools.erp.read`, `agentExecutable: true` as `docs/79` D declares,
  held by no agent): the ERP's figures for an order at a store, now. The destination's name, phone, address
  line and landmark are masked in the recorded arguments; the output carries prices, totals and the promise
  only. A malformed quote is refused before the ERP (`TakeOrderShape::quoteProblems`).
- **`commerce.get_order_status`** (R2, read, `agentExecutable: true`, held by no agent): one order HQ placed,
  by reference or by HQ's key; "none" is an answer; the output names no person.
- **`commerce.cancel_sales_order`** (R4, write, approval-required, `requesterMayDecide: false`,
  `Reversibility::Irreversible`, `MonetaryImpact::High`, customer-facing on the internal channel, not
  agent-executable, held by no agent): `take_order`'s compensating tool, which `docs/79` D named and
  `docs/83` C.3 left unbuilt. INERT the same way: `supportedStores` is the same empty
  `hq.orders.take_order_stores`. Its own policy refuses a store of another business or a reason that is not a
  sentence before an approval is parked. After the POST the order is read back by reference and the call is
  `succeeded` only when the ERP shows it cancelled under THIS request's key; `reconcile()` reads back before
  anything is sent again.
- **Contract fixtures**: `tests/fixtures/erp/sales-orders` (15 bodies, digest
  `b3aaf4087f03a2bd669cc5693108f853a6772aaa0a81f0c04728cdbafcfa21b6`) and the re-copied drafts fixtures
  (digest `ef78eece9e857c0aa88dd785ff698a5ef232b0c98d18bca75bd1f31f3e9b23da`, only `next_step` changed), each
  pinned on both sides so the two repositories cannot drift.
- **`Tests\Support\Erp\SimulatedErp`**: the simulated ERP for HQ's tests (drafts, quote, orders, read-backs,
  cancel), with one named misbehaviour per option, shared by the two order test files.

## Not built here, on purpose

- No payment adapter, no payment link, no courier booking: the ERP's responsibilities (`docs/88` A point 3).
- No `commerce.modify_sales_order` (`docs/79` C.15: v1 has none; a change is cancel then a new proposal).
- No store admitted, no ERP token issued for `sales.orders.record` or `sales.orders.cancel`, no agent holding
  any of the four tools, no `facts_orders` write from the read-back (`docs/79` C.17 remains for the
  `FactsReconciler` ERP path), no screen (stream V's rule: screens follow once the stream merges).
- No production registration: `hq:tools:sync` was not run anywhere.

## Verification (local; GitHub Actions validation deferred until the 1 October reset)

**ERP** (`erp-wt/hq-reads`, SQLite in memory as `phpunit.xml` says): `tests/Feature/HqApi/HqSalesOrderTest.php`
(15 tests) and the draft suite (15) pass together, 30 tests, 411 assertions; full suite **4,210 passed, 3 failed, 22,686 assertions, 951 s**, the three failures being `ReceivePaymentTest` Inertia-page assertions that fail identically on the untouched base `c6d6c10` in this worktree (stashed and re-run to prove it), unrelated to this stream;
`pint --test` on the changed files passed. Scratch MySQL rehearsal of the migration (local MySQL 8.4.3, not
production's 8.0.46): applied on a fresh database (both tables with their named unique keys and restrict-on-delete foreign keys), rolled back one step (both tables gone), re-applied; the scratch database was dropped after.

**HQ** (`hq_test_g`): new `tests/Feature/Commerce/OrderCompletionTest.php` (11) and
`tests/Feature/Erp/SalesOrderContractTest.php` (7); `TakeOrderTest` rewritten for the two-step write (20, four
of them new: the order write that never answered, the draft repriced at the order, the order read back
tampered, the order reconciliation); six pinned lists updated (`ToolNamespaceTest`, `AiSafetyPostureTest`,
`SharedFoundationSafetyTest`, `PolicyEngineTest`, `ToolGatewayTest`, `ApprovalCenterTest`: three R4
registrations now, three customer-facing tools, four more keys). Full Pest suite **1,298 passed, 11,690 assertions, 0 failed (354 s; 1,275 at the branch base plus the 23 new; run on 26 September after the session that started the stream was cut)**;
`vendor/bin/pint --test` passed; `npx tsc --noEmit` clean; `npx vite build` built.

**Mutations**, each applied, the test run, the code restored, 7 of 7 caught: the order reconciliation
ignoring the ERP approval status (caught); `take_order` trusting the 201 with no order read-back (caught, two
tests); the cancel judged without the status read back (caught); the cancel admitting every store (caught);
the quote redacting nothing (caught); the status output naming the recipient (caught); the connector calling a
lost order POST `Unavailable` instead of `Uncertain` (caught).

## The eight columns (docs/88 A point 21)

| | implemented | merged | registered | credentialed | live-called | agent-held | agent-granted | activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stream R, ERP: order from draft, quote, read-back, cancel | yes | not yet (branch pushed for review) | no (not deployed) | no (no token holds `sales.orders.record` or `sales.orders.cancel`) | no | no | no | no |
| Stream R, HQ: `take_order` stage two, `quote_order`, `get_order_status`, `cancel_sales_order` | yes | not yet (branch pushed for review) | no (`hq:tools:sync` not run) | no | no | no | no | no |

Nothing an agent could observe or use changed: no prompt, no agent tool list, no policy row, no runtime path
an agent reaches. The four tools are refused for every caller in production's state (no store admitted), and
the two reads, though `agentExecutable`, are in no agent's catalogue. Stream R needs nothing from Gate 13.

## Before merging and deploying, for the reviewer

1. The ERP migration is additive and was rehearsed on local MySQL 8.4.3, not production's 8.0.46; the
   scratch-MySQL rule before a production migration still applies at deploy time.
2. The two contract digests are pinned on both sides; merge the ERP branch and the HQ branch together, or the
   side merged first fails its own contract test until the other lands.
3. `take_order`'s preview and purpose changed wording (an order that waits, not a draft and nothing after it);
   the approval card for a future `take_order` request reads accordingly.

## Open questions

1. `docs/79` H.7 (a recipient other than the buyer) is still open; the ERP's draft and order carry ONE person,
   as the website does.
2. `commerce.quote_order` is `agentExecutable: true` as `docs/79` D declares, though it takes a name and a
   phone (masked when recorded). If ChatGPT or Joy prefers the eligibility check's rule (not agent-executable
   until P4-G3), it is one line.
3. Whether the `facts_orders` row should be written from the order read-back now (`docs/79` C.17), or with
   the `FactsReconciler` ERP path later. Left for later, with the reconciler.
