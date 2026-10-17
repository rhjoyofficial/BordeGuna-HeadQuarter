# 121. Identity containment evidence, stage 0 (15 October 2026)

Ruling `docs/120-chatgpt-ruling-2026-10-15.txt` A1 asks, before anything else, whether production paths still write or re-point customer identities, and for the minimum safe containment. This record answers from the code of the production release `b96a396` (read with `git show b96a396:<path>`; production itself was not touched or read). Whether each path is FED in production is a production fact; the read-only queries in `deploy/tools/identity-scope-readonly.sql` answer it (section 1 of that file) and quantify the damage (sections 2 to 6). Running them is Joy's step.

**Nothing here claims the incident is contained.** On `b96a396` no code path refuses a cross-business link; containment without a release can only be operational (a path not fed, or paused). The options in §3 are for Joy to choose; none has been applied.

## 1. The defect, in one paragraph

`IdentityResolver::byIdentity()` on `b96a396` looks up `customer_identities` by channel, external id and store, never by business. Phone, e-mail and Meta identities have no store, so they are looked up group-wide, and `link()` is an `updateOrCreate` that moves an existing identity row to whichever customer is resolving. A Bionic order or message from a phone LaLaDia already knows attaches to the LaLaDia customer, and a store-scoped match can pull the phone row across to the other business with no merge row and no audit (docs/119 §2).

## 2. Every production path on b96a396 that can write or re-point an identity

Each path below ends in `IdentityResolver::resolve()` or `resolveChannelIdentity()`, which both call the unscoped `byIdentity()` and the re-pointing `link()`.

- **Shop webhooks, `order.created` and `order.updated`.** `POST /api/webhooks/shop/{store}` (`ShopWebhookController`) verifies the store's HMAC, records an `events` row and answers 202. The drain `hq:events:process` (scheduler, every minute) hands it to `ShopOrderEventHandler`, which calls `FactsOrderUpserter::upsertFromShopPayload()`, which calls `resolve()`. LIVE whenever a shop sends webhooks to HQ for an active store with a webhook secret in the vault. docs/46 §Q records both LaLaDia and Bionic delivering in September. Query 1a/1b shows whether they still do.
  - The status-only events (`order.status_changed`, `order.payment_updated`, `shipment.*`, `payment.completed`) do NOT resolve a customer; they only update an existing fact.
- **Shop webhook `customer.registered`.** Same inbox and drain, `CustomerRegisteredHandler` calls `resolve()` with the shop user id, phone and e-mail. LIVE whenever a shop emits it (it is in `ACCEPTED_EVENTS`). It can link one store's `shop_user` id to another business's customer through the phone.
- **Nightly reconciliation, scheduled jobs 2 (LaLaDia) and 6 (Bionic).** Tool `commerce.reconcile_facts` (`ReconcileShopFactsTool` to `FactsReconciler` to `upsertFromShopPayload()`). Reported PAUSED in production. Query 1c shows their `state`. While paused they write nothing; "Run now" or resuming them makes the path live again.
- **Manual reconciliation, `hq:facts:reconcile`.** Same `FactsReconciler`. Manual only (a person on the VPS). Not live unless run.
- **ERP read-back, `ErpOrderReadBackFact::record()`.** Called by the tools `commerce.get_order_status`, `commerce.take_order` and `commerce.cancel_sales_order`, all of which call `upsertFromShopPayload()`. These are agent tools; on `b96a396` the AI runtime is off and every agent is paused at A0 with zero grants, so no run can call them. An already approved approval for one of them could still execute; query 1d counts them. Treated as NOT live, subject to query 1d.
- **Meta inbound (`conversation.inbound_received` to `MetaInboundHandler` to `ConversationService::ingest()` to `resolveChannelIdentity()`).** `POST /api/webhooks/meta` and `/api/webhooks/meta/forwarded` answer 404 while `channels.meta.inbound` (and `channels.meta.forwarded`) are off; both ship off. NOT live while the flags stay off (query 1e). Events already recorded before would still be drained.
- **`hq:customers:rebuild`.** Re-resolves every order fact through `resolve()` and re-points `facts_orders.customer_id`; reproduces the leak at scale. Manual only. Not live unless run. It must not be run on `b96a396`.
- **`hq:conversation:simulate` (the P3-G2 harness).** Runs `ingest()` inside a transaction it rolls back, unless `--keep` is given. Manual only; with `--keep` it writes simulated identities. Not live unless run.
- **Human merge, `POST /customers/merge` (`IdentityResolver::merge()`).** Moves the absorbed customer's identities to the kept customer. Already refused across businesses on `b96a396` (`CrossBusinessMerge`, docs/109). Within one business it is a deliberate, audited act. Not a leak path.
- **Nothing else.** No other class on `b96a396` writes `customer_identities` (searched: `CustomerIdentity`, `customer_identities`, `IdentityResolver`, `upsertFromShopPayload`, `readBack->record`).

Readers that show the wrong business's data once a link is wrong (no writes, listed for completeness): the inbox order panel (`InboxController::ordersFor`), the Support model context (`SupportContext::customerBlock`, not live: runtime off), the Customer 360 page and customer list (on `b96a396` there is no docs/117 suppression yet; it is on main only).

**Summary of what is live on `b96a396` today:** shop webhooks (`order.created`, `order.updated`, `customer.registered`) through the event drain, whenever shops deliver. Everything else is paused, off or manual, subject to queries 1c to 1e.

## 3. Minimum operational containment on b96a396, as OPTIONS for Joy

Every option keeps the incoming evidence; none deletes or edits a customer, identity, order or event. None is applied.

- **Option 1: keep jobs 2 and 6 paused, do not run `hq:customers:rebuild`, `hq:facts:reconcile` or `hq:conversation:simulate --keep`, keep both Meta flags off.** No change; it is the current posture made explicit. It does not stop the webhook path.
- **Option 2: stop the drain while the shops keep delivering.** `docker compose stop scheduler` on the VPS stops `hq:events:process`. The webhook endpoint (in the `app` container) keeps accepting and recording `events` rows as `pending`; nothing resolves a customer. After the fix is released, starting the scheduler drains them in order, through the corrected resolver. This is the only option that stops the live path while keeping every input replayable in HQ.
  - Side effects: the same container runs `hq:scheduler:tick`, the approvals sweep, alerts, shadow commands, delivery reconcile, retention and Horizon snapshots, so all of those stop too. Facts go stale (orders appear in HQ only after the restart). No shadow period is open and agents are paused, so nothing customer-facing depends on it. Alerting stops, which is a monitoring loss.
  - The `events` rows have `max_attempts` and backoff only after a failure; a pending row that is never claimed does not age out. Retention does not touch `events` before March 2027 (docs/78 B.2).
- **Option 3: hold only the identity-writing event types, keep the rest of the drain.** A one-line production write: `UPDATE events SET next_attempt_at = '2099-01-01' WHERE status IN ('pending','failed') AND type IN ('order.created','order.updated','customer.registered')`. The drain skips them; they stay replayable by setting `next_attempt_at` back to null after the fix. Limitation: it only holds rows already received; every new delivery is processed at the next minute, so it would have to be repeated, which is not containment. Listed to show why it is NOT recommended on its own.
- **Option 4: refuse the deliveries at the door.** Deactivate the stores (`stores.is_active = false`) or remove the webhook secret: the endpoint answers 404 or 503. HQ then keeps nothing; replay depends on whether each shop retries or keeps an outbox, which HQ cannot verify. It conflicts with "preserve incoming events". Not recommended.

My reading, for Joy to decide: Option 2 is the minimum that actually stops the live write path while keeping inputs replayable; Option 1 is enough only if query 1b shows no shop deliveries in the recent window. Either way the read-only queries should run first, and the repair (docs/120 A3) stays unrun.

## 4. The read-only scope queries

`deploy/tools/identity-scope-readonly.sql`, one file, SELECT only, inside `BEGIN READ ONLY; ... ROLLBACK;` (PostgreSQL refuses any write in a read-only transaction), with a 60 s statement timeout. Written for the `b96a396` schema (no `customer_identities.business_id`; consent in `consent_events` and `consent_state`). It prints no phone, e-mail or message text, only counts and row ids.

- Section 0: run time, last migration, stores.
- Section 1: liveness (events of the identity-writing types by status and in the last 7 days and 24 hours, the reconcile jobs' state, approvals for the four commerce tools, the Meta and runtime flags).
- Section 2: identities matched across businesses (store-scoped identities under another business's customer; phone identities whose number is on another business's orders; identities on participants of another business; identities whose evidence names more than one business).
- Section 3: downstream rows in another business than their customer (orders, conversations, participants, messages, consent events, consent state, memories, cross-business merges), the distinct customers touched, and customers whose stored phone only another business's orders explain.
- Section 4: duplicate null-store identity rows, split into values spanning businesses and two customers in one business.
- Section 5: identities whose customer's business differs from the rows that used them (the shop_order "steal", orders whose phone identity now belongs elsewhere), and a business-pair breakdown.
- Section 6: the first 50 affected order and conversation ids for a person to review.

A test (`IdentityScopeReadonlySqlTest`) pins the file as SELECT-only and runs it against the test schema so a typo cannot reach Joy.

## 5. Production scope count (run by Joy, 15 Oct 2026 10:31 UTC, read-only, rolled back)

Ran `deploy/tools/identity-scope-readonly.sql` on production `b96a396` (57 migrations, last `2026_10_04_100000`). Counts only; no values printed.

- Liveness: `order.created` 19 processed (newest 13 Oct 09:55 UTC), `order.updated` 11 processed (newest 12 Oct 14:02 UTC); last 7 days 7 + 6, last 24 hours 0, nothing pending. No `customer.registered` and no `conversation.inbound_received` events ever. Jobs 2 and 6 paused. No approvals for the commerce tools. `ai.runtime`, `channels.meta.inbound`, `channels.meta.forwarded` all off. So the shop webhook path is LIVE (shops still deliver); every other path is closed.
- Damage: ONE order fact in another business than its customer: `facts_orders` #16 (Bionic, business 3, store 2, placed 7 Sep 12:09 UTC) on customer #1 (LaLaDia, business 2). One customer touched. Store-scoped identities under another business's customer: 1 `shop_order`, 1 `shop_user`. Phone identities matched to another business's orders: 1. Identities whose evidence names more than one business: 3.
- Clean: no conversations, participants, messages, consent events, consent state, memories or cross-business merges affected; no duplicate null-store identities; no identity re-pointed away from its order's customer.
- What the W1 migration will do with this data: quarantine the store-scoped `shop_order` and `shop_user` rows (`store_business_mismatch`) with conflict rows; the phone identity has evidence in its owner's own business, so it stays live under LaLaDia. The W2 repair then splits order #16 onto a Bionic customer, with lineage.
- Containment: NOT yet applied; Joy's choice between Option 1 (accept the small risk until the release) and Option 2 (stop the HQ scheduler) is open. Incident NOT claimed contained.

## 6. Containment applied: Option 2 (Joy, 17 Oct 2026 03:35 UTC)

Joy chose Option 2 ("proceed with your recommendation", ruling docs/131 §1.1) and ran it himself (the auto-mode classifier refuses production mutations to Claude).

- `docker compose -f deploy/docker-compose.yml --env-file deploy/.env stop scheduler` on production `b96a396`: `bordeguna-hq-scheduler-1 Stopped`; `ps scheduler` lists nothing running.
- Read-only snapshot right after (`deploy/tools/containment-snapshot-readonly.sql`, READ ONLY, rolled back), 03:35:11 UTC: events `processed` 80, `dead` 16 (the known September rows), `processing` 0 (no drain was cut mid-run), `pending` 0, max event id 592.
- From now on: shop webhooks land as `pending` rows (id > 592) and nothing resolves a customer. No event is lost (docs/132 finding a). The release must keep the drain paused (docs/134 §5 step 4: `HQ_EVENTS_DRAIN_PAUSED=true` + `--keep-scheduler-stopped`) and replay only after the repair is verified (Stage D step 10).
- Paused with it: scheduler tick, alerts, approvals sweep, retention, shadow ticks, delivery reconciliation, Horizon snapshots. `/api/health` will report the scheduler failing; that is expected, not a new incident.
- The incident is now contained at the write path: no new cross-business link can be created until the drain resumes under the fixed resolver. The one existing mis-attached order (#16) remains until the authorized repair.
