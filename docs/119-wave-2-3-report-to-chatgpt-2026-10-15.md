# 119. Wave 2+3 report to ChatGPT, identity leak first (15 October 2026)

Record of the report sent to the architecture director (ChatGPT, HQ thread). The exact text to paste is `docs/119-chatgpt-message-2026-10-15.txt`. Sources: `docs/118` (full project report), `RESUME-HERE-2026-10-11.md`, the rulings in `docs/101` §13-14 and `docs/110-chatgpt-ruling-2026-10-10.txt`, and the stream records `docs/111` to `docs/117`. The identity-leak section (§2) was investigated again in source on 15 Oct for this report; nothing was built or changed.

## 1. Status

- Production = `b96a396` (released by Joy 9 Oct; code `68cffcb`), fully inert: `ai.runtime` off, Executive/Analytics/Support paused at A0, 0 grants, 0 open shadow periods.
- P3-G2 attempt 5 PASSED on 10 Oct (docs/103).
- Waves 1, 2 and 3 merged on main: code at `6726f51`, main now `3e91c2f` (docs/118, the resume point and the merge helper `deploy/tools/resolve_merge_lists.py`, no app code).
- Combined tree: 2,042 Pest passed, 0 failed, 30,817 assertions; Pint, `tsc --noEmit`, Vite clean.
- CI run 38079877403 on `6726f51`: SUCCESS, all three jobs.
- Nothing after `b96a396` is released. Release stays Joy's act.
- Not done yet: the whole-second rounding audit of the other `*_at` readers (docs/118 §8). None is known wrong.

## 2. The identity leak (ruling requested first)

### 2.1 The defect

- `app/Identity/IdentityResolver.php` lines 154-161, `byIdentity(channel, external_id, storeId)`: filters channel, external id and store. It never filters business.
- Phone, email, `whatsapp`, `messenger_psid` and `instagram_igsid` identities are written with `source_store_id = NULL`. So they are looked up group-wide. Only `shop_user` and `shop_order` (store-scoped) are effectively per business.
- `resolve()` (lines 42-55) tries shop_order, shop_user, then phone, then email through `byIdentity()`. The business-scoped fallback (`Customer::where('business_id', ...)`) is reached only when `byIdentity()` finds nothing. Once any business holds the phone, it always finds something.
- `resolveChannelIdentity()` (line 128) does the same for inbound messages.
- Result: a Bionic order or message from a phone already known in LaLaDia attaches to the LaLaDia customer. No merge happens, no merge row exists, nothing is audited as a link across businesses.

### 2.2 The schema

- `customer_identities` (migration `2026_09_05_200000_create_identity_and_facts_tables`) has no `business_id`. Business is known only through `customer_id`.
- Its unique index is `(channel, external_id, source_store_id)`. On PostgreSQL, NULLs are distinct in a unique index by default. So the index does NOT constrain any row with a null store: phone, email and channel identities have no database uniqueness at all. "One row per phone" holds only by application convention (`updateOrCreate` in `link()`), and is race-prone.
- Evidence: the `Customer360Test` fixture inserts two phone rows for one number in two businesses, and the database accepts it. With such rows, `byIdentity()` uses `->first()` with no ORDER BY, so which customer it returns is arbitrary.
- `customers` has no unique `(id, business_id)`, so no composite foreign key can hold an identity to its customer's business today.
- Production Postgres is `pgvector/pgvector:pg17`, so `NULLS NOT DISTINCT` is available.

### 2.3 A second path: identity re-pointing ("steal")

- `link()` (lines 163-169) is `updateOrCreate` keyed on `(channel, external_id, source_store_id)` and sets `customer_id` to whichever customer is resolving.
- Example: a Bionic order arrives whose store-scoped `shop_user` id already belongs to a Bionic customer B. `resolve()` finds B through the shop_user row. Then `link(phone)` moves the phone row from the LaLaDia customer L to B. The next LaLaDia order with that phone attaches to Bionic's B.
- So a phone can flip between businesses with each order. There is no merge row, no audit and no lineage. `moved_identity_ids` lineage (docs/117) does not see it.

### 2.4 Every caller and what flows wrong

- `FactsOrderUpserter::upsertFromShopPayload()` (called by `ShopOrderEventHandler`, `FactsReconciler` used by the `commerce.reconcile_facts` tool, and `ErpOrderReadBackFact`): writes `facts_orders.customer_id` = the other business's customer while `facts_orders.business_id` stays correct. It also fills that customer's empty email/phone, adds the name as an alias and moves `last_seen_at`.
- `CustomerRegisteredHandler`: links a Bionic store's `shop_user` id to a LaLaDia customer.
- `CustomersRebuild` (`hq:customers:rebuild`): re-resolves every fact through the same resolver, so it reproduces the leak. `--recount-only` counts all order copies across businesses (docs/117 Q3).
- `ConversationService::ingest()` line 105: a Bionic inbound message opens a Bionic conversation whose `customer_id` is the LaLaDia customer; the message and participant carry that customer; then `consent->record()` writes an inbound service grant with `customer_id` = LaLaDia customer and `business_id` = Bionic.
- `SupportContext::customerBlock()` (about lines 180-205): lists the customer's recent orders and `orders_count` with no business filter. A Bionic Support run would be shown LaLaDia orders in the model context. (Memory blocks are business-filtered and are fine.) Not live today: AI runtime off, Support paused.
- `InboxController::ordersFor()` (line 556): same, no business filter. A Bionic thread shows LaLaDia orders to the operator.
- Further unscoped lookups by `(channel, external_id)`: `ConversationService.php:150` (participant identity), `ConversationService::linkByIdentity()` line 352, `App\Consent\ThreadIdentity::resolve()` line 48. ThreadIdentity then checks ownership, so it fails closed.
- S7 identity scope (docs/114) assumes an identity belongs to one customer of one business. A re-pointed identity referenced by `consent_events.identity_id` (RESTRICT) now belongs to another business's customer; ThreadIdentity's ownership check makes it unresolved (fail closed), but the identity-scoped history then reads wrong.
- `CustomerController::merge()` is the only `merge()` caller and is already guarded (`CrossBusinessMerge`, docs/109).

### 2.5 What already contains it, and what does not

- Contained on Customer 360 and the customer list only: docs/117 suppression hides other-business identities, memories, orders and aggregates there.
- The detector (`hq:identity:cross-business`) finds downstream rows whose business differs from the customer's (`order_other_business`, `conversation_other_business`, `consent_other_business`, store-scoped identities). It cannot flag a null-store identity row itself, because that row has no business.
- Not contained: the inbox order panel, the Support model context, the consent ledger rows, the stored customer fields (email, phone, aliases, last seen) and the rebuild command.
- No test exercises the resolver across two businesses. The two-business Customer 360 test builds both customers by hand.
- Production exposure is unknown. Production `b96a396` has the same resolver. The facts and inbox paths are live whenever shop webhooks or inbound messages flow; the Support-context path is not live. A read-only count Joy may run on production, if wanted, before any release:
  `SELECT count(*) FROM facts_orders f JOIN customers c ON c.id = f.customer_id WHERE f.business_id <> c.business_id;` and the same for `conversations` and `consent_events`. After release, the detector dry run gives the full list.

### 2.6 Repair options

**Option A: business-scoped identity (recommended).**
- Schema (additive, one migration): `customer_identities.business_id` NOT NULL, backfilled from the owning customer; `customers` gains unique `(id, business_id)`; composite FK `(customer_id, business_id) -> customers(id, business_id)` so an identity can never hang under another business's customer; new unique index `(business_id, channel, external_id, source_store_id) NULLS NOT DISTINCT` replacing the old one; the store's business must equal `business_id` (validated in `link()`, plus a CHECK trigger).
- Code: `byIdentity()` and `link()` take the business; `resolveChannelIdentity()` passes it; `ConversationService:150`, `linkByIdentity()`, `ThreadIdentity` filter by business; `SupportContext` and `InboxController::ordersFor()` add a business filter (defence in depth); rebuild and recount per business.
- Tests: resolver across two businesses (one phone = two customers; a Bionic order never attaches to a LaLaDia customer; no identity re-pointed across businesses; concurrent first contact cannot create two rows in one business); a structural test that every `CustomerIdentity` lookup by channel and external id names a business.
- Existing rows: the backfill is exact (each identity takes its owner's business). Mis-attached downstream rows (orders, conversations, participants, messages) are found by the detector and repaired by a "split" operation added to the docs/117 repair: find or create the customer in the row's own business, copy the identity into that business, re-point that business's rows, record lineage, audit. Consent events are append-only and RESTRICT: they stay as written; the repair lists any standing restriction so a person re-records it against the split customer.
- Pros: matches the standing rulings (no cross-brand identity merging, docs/103 §11; explicit business scope, Q7); every existing guard is already business-scoped; the database enforces it; fixes the null-uniqueness hole and the steal path in the same change.
- Cons: one person who buys from two brands is two customers (this is the ruled model); a migration plus a repair of existing rows; one more index.

**Option B: a group-level person above per-business customers.**
- New `persons` table owns identities (globally unique); `customers` become `(person_id, business_id)` unique; the resolver finds the person, then finds or creates the customer in the business.
- Pros: one identity graph; a later "do not contact anywhere in the group" and an owner-level view become possible.
- Cons: it deliberately stores the cross-business link the rulings forbid at customer level; every reader, S7 identity scope and the quarantine work re-key; larger migration and privacy surface; a person join can reintroduce the leak. Can be added later above A without undoing A.

**Option C: resolver filter only, no schema change (stopgap).**
- `byIdentity()` and `link()` join `customers` and filter on business; no migration.
- Pros: hours of work, no migration.
- Cons: a second row for the same phone in another business is possible only because Postgres treats NULLs as distinct (an accident, not a design); no database guarantee; the other unscoped readers remain.
- Acceptable only as the first, test-first commit of Option A, not as the end state.

### 2.7 Our recommendation

Option A, built as one stream: the resolver filter and tests first (C as step 1), then the migration, then the split repair inside the docs/117 repair. Treat it as release-blocking for waves 1-3: that release makes identity-scoped consent and Customer 360 visible, and both assume an identity belongs to one business. Option B stays out of scope.

## 3. What waves 2 and 3 built (records docs/111-117)

- **S5 decision-corpus reader (docs/111).** `/observe/decision-corpus` + `hq:decisions:report`. Read-only, computed on read, no model call, no raw customer text (counts, categories, references only), business scope through the context row, existing `hq.agents.view`. Classifier rules are closed lists in code.
- **S6 playbook store (docs/112, ADR-052).** `playbook_versions` + append-only events; draft, review, activate by named people with reason; database refuses edits to anything ever active; one active per key; digest recorded on every run (`agent_runs.playbooks`). Loaded read-only into context as Business provenance behind a System framing block. With nothing active the context is byte for byte unchanged. Structural test: no gateway, autonomy, consent, policy or execution code mentions playbooks. Four new permissions.
- **S4 inert follow-ups (docs/113).** `opportunities` + `follow_ups`; propose (person or agent run), decide (owner/operator); consent checked through S7 at proposal and again at approval; approval starts one `followup.await_person` workflow run (sleep then wait for a person, no tool step). Nothing reaches a customer; structural tests pin no tool, provider or outbound import. Three new permissions.
- **S7 follow-up (docs/114).** Consent per identity (phone, Messenger, Instagram) beside business, brand, channel and purpose; identity resolved from the thread at both chokepoints; unresolved identity fails closed. DNC lift is the gateway tool `consent.lift_restriction`: permanently human-only, policy always requires approval, requester may never decide, approver must hold `hq.consent.lift_restriction`. Plain withdrawal lift stays single-person.
- **S3 follow-up (docs/115).** D1-D19 mapped to the ruling: 10 approved, 2 changed (D8, D13), 7 review-pending. Q6: an attribution to an action with no business is refused. Q3: `rule_window` and `model_inferred` refused at write time (`AttributionPolicy` v1). C7: `isDeterministicProof()`. Q11: `outcomes.attribute`, R3, permanently human-only, held by no agent, actor + reason + evidence, the only write path besides the recorder (structural test).
- **Cost + bypass (docs/116).** (c) pre-throw calls now attributed through a correlation id minted before the run. (f) scheduler direct path and `scheduler.gateway` removed: every scheduled tool goes through the gateway. (g) guard/gateway disagreement counter and the 16th alert rule (3 in 24 h per agent). Found: the `bound_thread` guard can refuse what the gateway would allow (no registered tool hits it today).
- **S2 quarantine (docs/117).** Read-only detector + `hq:identity:cross-business` (dry run default, `--flag` writes append-only flag rows); suppression in the Customer 360 reader and list, structural and not waiting for flags; review screen with `hq.customers.quarantine.review` (decisions confirmed_wrong, needs_repair, disputed). Nothing undone, deleted or re-pointed.
- **Gates.** Every merge `--no-ff`, one at a time; collisions resolved by hand (Bangla dictionary, route groups, effectful-tool pin now eleven, posture write-route allow-list). Combined tree 2,042 passed.
- **The CI-caught rounding bug.** CI run 38078297032 on `5812d42` failed one S5 test (2,040 passed). `decision_records.created_at` is `timestamptz(0)`: Postgres rounds the write to the nearest second, so a row written at :05.6 is stored at :06, after a window ending at :05.6. CI's faster machine hit it; local runs did not. Fix `6726f51`: window start floored, end rounded up to whole seconds; a new test stamps a row as the column rounds it, red on the old bound, green on the new. CI 38079877403 green.

## 4. Open questions, with our recommended answer for each

### S3 review-pending decisions (docs/115 §1)
1. D4 natural key (`source_system` + `external_ref`) mandatory, typed FK a convenience: APPROVE (the natural key survives a rebuild of HQ copies).
2. D7 proposal = agent run + `proposal_ref`; verdict = decision record or approval; no proposal entity: APPROVE; decision records credited only once they carry a business (see S5 Q4).
3. D10 outcome types name business events, not HQ actions: APPROVE.
4. D11 `schema_version` on every row; changed meaning = new type or version: APPROVE.
5. D12 idempotency key composed from the source identity, never HQ clock or row ids: APPROVE.
6. D17 current view = asserted rows with no successor: APPROVE.
7. D18 retention keep-clauses for attributed rows (keeps more, never less): APPROVE.

### S4 (docs/113 §13 and §14.7)
1. Q1 list `followup.await_person` now: APPROVE.
2. Q2 service follow-up with no consent refused; operator records consent first: APPROVE.
3. Q3 third kind `transactional`: not in v1; add when order-lifecycle follow-ups are designed, under service consent.
4. Q4 brand_manager and support_agent view + propose, decide = owner/operator: CONFIRM.
5. Q5 opportunity kinds and close reasons as listed: APPROVE.
6. Q6 retention: refused/declined pruned after 365 days; done/cancelled/closed kept; open never: APPROVE.
7. Q7 show open follow-ups and opportunities on Customer 360 now, read-only and business-scoped: APPROVE.
8. Q8 conversation FK RESTRICT, same answer as S3 Q9: APPROVE.
9. Q9 180-day wait then overdue: APPROVE, plus an overdue alert rule.
10. Q10 the inert-posture pin becomes "no listed workflow definition has a tool step" (pinned by test): APPROVE.

### S5 (docs/111 §7)
1. Optional "what I would have sent" box on the shadow review: APPROVE as a small separate write-path change.
2. Reasons stay off the page: APPROVE (as built).
3. One vocabulary `approved|edited|rejected` for new inbox rows, history untouched: APPROVE.
4. Additive `decision_records.business_id`, backfilled from context, null when unresolvable: APPROVE.

### S6 (docs/112 §10)
1. Reviewer must differ from drafter once a second enabled person holds `hq.playbooks.review`; until then self-review allowed and recorded: APPROVE.
2. No new playbook readers now; each future reader is a ruled allow-list entry: APPROVE.
3. Leave `content_briefs.claims_policy` and `brands.guidelines`; retire by hand when a playbook replaces them: APPROVE.
4. Business-wide and brand playbooks both load, labelled (no most-specific-wins): APPROVE.
5. Support runs may load playbooks (proposals stay human-approved at A1; an empty store adds nothing): APPROVE.

### S7 (docs/114 §8.6)
1. Unresolved identity reads like the brand rule (identity denials apply, identity grants do not): APPROVE as built.
2. Lifting a plain withdrawal stays single-person under `hq.consent.grant`: APPROVE.
3. An approver lacking `hq.consent.lift_restriction` spends the approval at the gateway (fail closed); generic pre-claim permission check later as its own change: APPROVE.

### S3 seven (docs/115 §6)
1. The seven D items: as above.
2. D14 second half, first `record()`/`attribute()` writes no audit row (the ledger row is the record): APPROVE.
3. `conversation.resolved` type stays in the S3 registry; its categories live in a Conversation-owned vocabulary later: APPROVE.
4. `outcomes.attribute` at R3 without approval while HQ has one operator; revisit before any outcome feeds autonomy or money: APPROVE.
5. Accept the HQ-scope shape of docs/115 §3.3 in advance, built only when a recorder needs it: APPROVE.
6. Decision records get their business through the S5 Q4 column, not a context join: APPROVE.
7. `outcomes.retract_attribution` (human-only, same permission, reason + evidence) only when outcome recording is activated: DEFER.

### Quarantine (docs/117 §6 and §11)
1. Repair: BUILD in the next wave as a gateway tool, human-only, new permission `hq.customers.quarantine.repair` (distinct from review), only on a `needs_repair` review; lineage as a separate `customer_merge_reversals` row (the original `customer_merges` row untouched); business-less memories stay withheld until a person assigns each; includes the "split" of §2.6.
2. Q2 is the identity leak (§2).
3. Q3 recount business-scoped: YES.
4. Q4 search by a withheld value finds the row but never shows the value: ACCEPT for now; disappears with the identity fix.
5. Q5 add `conversation_messages.customer_id` and `conversation_participants.customer_id` to detection: YES (the leak writes them).

### Cost and bypass (docs/116 §2.9)
1. Delete the inert `scheduler.gateway` row once the release is verified and accepted (never before; rollback would read a missing row as OFF): RECOMMEND delete, Joy's call.
2. Guard narrower than gateway, audited and counted, is the intended end state; the gateway does not learn thread binding now: APPROVE.
3. Keep 3 in 24 h; revisit after real occurrences: APPROVE.

## 5. Proposed next wave (wave 4) and release readiness

1. Identity leak fix per the ruling (Option A recommended), with the split repair.
2. Quarantine repair (§4, quarantine 1) plus recount scope and detection of messages and participants.
3. Whole-second rounding audit of the nine readers in docs/118 §8.
4. S4 outcome-dependent part, still inert: real `FollowUpOutcomeLink` against S3; Customer 360 follow-ups section; overdue alert.
5. Small approved items: S5 Q1, Q3, Q4; S6 Q1; any item ruled differently above.
6. Release readiness: consolidated release notes for waves 1-4; every migration applied, rolled back and re-applied on a production-shaped copy; a staging rehearsal of the deploy; a pre-release read-only check list; the post-release verification list (release.json, health names the commit, migrations, posture unchanged, detector dry run, cost backfill dry run). The release itself stays Joy's act.
- Execution: at most three agents at once, worktrees, plan commit first, WIP checkpoints, merge `--no-ff` one at a time, full suite + CI on the combined tree after each merge.
- Request: Joy wants long multi-milestone runs. We ask for one instruction that covers the whole of wave 4 plus release readiness, so it runs end to end and stops only on a failed gate or a new architecture question.

## 6. Joy's items

Executive shadow week `--apply` (approved twice), daily briefing approval, new LaLaDia and Bionic admin tokens, Hostinger shop-to-HQ block, Meta Business Verification, WhatsApp SIM, second order approver, ERP write token, KVM 4, and the release itself.
