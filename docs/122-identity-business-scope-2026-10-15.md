# 122. Identity business scope, W0 + W1 (15 October 2026)

Branch `p3/identity-business-scope` from main `787b51a`, worktree `hq-wt/h`, test database `hq_test_h`. Authority: `docs/120-chatgpt-ruling-2026-10-15.txt` sections A1, A2, A4 and item 38. Nothing here is released or merged; Joy alone releases.

## Plan (committed first, `7ac66e8`)

1. W0: `docs/121-identity-containment-2026-10-15.md` and `deploy/tools/identity-scope-readonly.sql`.
2. W1 schema: one migration for business-scoped identities, backfill from reliable evidence only, ambiguous rows quarantined.
3. W1 resolver: explicit business everywhere, no group-wide fallback, no silent re-point.
4. W1 defence in depth (A4) in every listed reader and writer, and item 38 on customer search.
5. Tests: the seven ruled tests, a structural pin, migration tests over clean, duplicate and corrupted data, real Postgres for concurrency.
6. Gate and this record.

## W0 in short

`docs/121` lists every path on the production release `b96a396` that can write or re-point an identity. Live today: the shop webhooks (`order.created`, `order.updated`, `customer.registered`) through the event drain, whenever a shop delivers. Paused, off or manual: reconcile jobs 2 and 6, `hq:facts:reconcile`, the ERP read-back tools (agents paused, runtime off), Meta inbound (flags off), `hq:customers:rebuild`, the simulator with `--keep`. The human merge is already refused across businesses. Containment without a release is operational only; the options (stop the scheduler so events queue as replayable `pending` rows, or keep the current posture if no shop is delivering) are Joy's to choose. The read-only queries are in `deploy/tools/identity-scope-readonly.sql` (SELECT only, inside `BEGIN READ ONLY ... ROLLBACK`, pinned by `IdentityScopeReadonlySqlTest`). Nothing was run on production.

## W1: what changed

Think of an identity as a key on a hook: before, one hook board served every business and a key could be moved to whichever hook asked for it. Now each business has its own board, the board is bolted to the business, and a key already on a hook stays there until a person moves it with evidence.

### The migration `2026_10_15_100000_scope_customer_identities_to_business`

- `customer_identities.business_id` NOT NULL, with the composite foreign key `(customer_id, business_id) -> customers (id, business_id)`; `customers` gains unique `(id, business_id)`.
- A live row's store belongs to the same business: `(live_store_id, business_id) -> stores (id, business_id)`, `stores` gains unique `(id, business_id)`. `live_store_id` is a stored generated column equal to `source_store_id` on a live row and NULL on a quarantined one.
- The old unique `(channel, external_id, source_store_id)`, which never held a null store, is replaced by unique `(business_id, channel, external_id, source_store_id) NULLS NOT DISTINCT WHERE quarantined_at IS NULL`.
- Backfill: every row first takes its owner's business (the only value the composite key accepts). It is then quarantined, never guessed, when evidence contradicts the owner:
  - `store_business_mismatch`: a store-scoped id under another business's customer;
  - `contradicted_by_evidence`: a store-less id whose every use (orders by phone, thread participants, consent rows) is in another business than its owner's; an id with no contrary evidence stays with its owner;
  - `duplicate_same_customer`: an extra copy of one key for one customer (the lowest id stays live);
  - `duplicate_ambiguous_owner`: one key held by two customers of one business (every copy is held).
- A quarantined row keeps its customer, its references (participants, consent) and its lineage; nothing is deleted or moved. Each one gets an open `customer_identity_conflicts` row (`kind = quarantined`, source the migration).
- New table `customer_identity_conflicts`: the auditable resolution requirement. One open row per (identity, kind, claimant), counting repeats; resolution columns for the A3 repair or a person.

### The resolver and every link path

- `IdentityResolver::resolve()` (business = the store's), `resolveChannelIdentity()` (business = the platform account's), `byIdentity()` and `link()` all take and use one explicit business. The scope lives in `CustomerIdentity::scopeKey(business, channel, external id, store)`; a null store is matched as null.
- No fallback: a value known only in another business is a stranger here, and becomes a new customer of this business.
- No silent re-point: `link()` inserts with ON CONFLICT DO NOTHING against the business key and never updates `customer_id`. When the key is held by another customer of the business, the link is refused, a `link_refused` conflict row is written (and audited as `identity.link_refused` the first time); the order still resolves to the customer its stronger key named.
- A concurrent first contact that loses the insert race gives up the customer it just made and takes the winner's, so one value stays one row and one customer.
- A quarantined value is never guessed: when it is the only thing that could say who this is, resolution throws `IdentityQuarantined` (the event fails and stays replayable); when a stronger key named the customer, the quarantined value is simply not linked and the refusal is recorded.
- `Customer::canonical()` no longer follows a merge pointer into another business.
- `merge()` (already refused across businesses) moves only the business's own identities and order copies and recounts from them.
- `ConversationService`: the participant identity at ingestion, `linkByIdentity()` and `openOrFind()` are scoped to the thread's business; `addParticipant()` and `openOrFind()` refuse a customer or identity of another business (`CrossBusinessIdentity`).
- `ThreadIdentity`: its fallback lookup and its ownership check both require a live identity of the thread's business. `ConsentOperations` and the consent screen accept and offer only live identities of the customer's business.

### Defence in depth (A4), each filter independent of the resolver

- `FactsOrderUpserter`: refuses a customer of another business; leaves an existing copy that hangs under another business's customer where it is (moving it is the A3 repair's job); counts the customer's own business's copies only.
- `CustomerRegisteredHandler`: refuses an event whose recorded business differs from the named store's, and a resolved customer of another business.
- `hq:customers:rebuild`: skips and counts facts under another business's customer, skips quarantined values, recounts per business.
- `SupportContext`: describes only a customer of the thread's business, from that business's orders, with a business-scoped count.
- `InboxController`: orders, memory, the customer card and `customer_id` come only from a customer of the thread's business.
- Customer 360: identities and identity links are live rows of the customer's business only.
- Suppression: a phone is also withheld when only another business's order copies under this customer carry it (the pre-fix resolver filled it from them).

### Item 38, search

The customer search never matches a withheld phone or e-mail (`CrossBusinessSuppression::phoneWithheldSql` and `emailWithheldSql`, the same rule the 360 page and list use to withhold them), so a search by such a value finds nobody, in this business or another. Own values still match. An optional `business` parameter narrows the search to one business inside what the person may read.

## Tests

- `IdentityBusinessScopeTest` (14): the same phone and the same PSID in two businesses; no cross-business reassignment in any event order; a within-business re-point refused with one conflict row, a counted repeat and one audit row; store/business mismatch refused by the database (both composite keys), the event handler and the thread; ambiguous quarantined values refused, never linked past; idempotent orders and messages; no fallback for phone, e-mail, channel ids, `linkByIdentity`; no merge pointer followed across businesses; inbox and Support context; upserter, rebuild and recount leave pre-fix copies; Customer 360 and consent screen hide quarantined rows; item 38 search; the structural pin (scans `app/` and fails on any `customer_identities` lookup by channel or external id that names no business, proven to fail on a planted offender); the scope key's SQL.
- `IdentityConcurrencyTest` (3), real PostgreSQL 17 with two extra connections: the database blocks a second in-flight insert of one store-less value in one business (lock timeout `55P03`), then refuses it (`23505`), and accepts it in another business; a losing first contact in `resolve()` and in `resolveChannelIdentity()` takes the winner's customer, leaving one row and one customer.
- `IdentityBusinessScopeMigrationTest` (5): clean, duplicate and corrupted datasets planted in the old shape, migrated, and read back (business, quarantine reason, conflicts, catalogue definitions of the index and keys); a real rollback and re-apply; the refused rollback.
- `IdentityScopeReadonlySqlTest` (2): the W0 file is SELECT-only and runs against the schema.
- Existing tests adjusted only where they wrote identity rows without a business, and the simulator invariant test (the resolver now refuses the planted cross-business customer before the invariant can see it).

## Gate (15 Oct, on this branch)

- Full suite `DB_DATABASE=hq_test_h php artisan test --compact --parallel --processes=3`: 2,066 passed, 0 failed, 31,089 assertions (main's 2,042 plus 24 new).
- Pint clean (`pint --test`), `npx tsc --noEmit` clean, `npm run build` clean.
- No em dash in any file written here.

## Down

`down()` is real when nothing would be lost: it drops the conflicts table, the new keys, the generated column and the columns, and restores the old unique key. It refuses, with the reason, when any conflict has been recorded (dropping it would lose audit evidence) or when a store-scoped key now exists twice (the old key could not hold). In those cases the way back is the pre-migration backup that `deploy.sh` takes first. The release rehearsal (W7) must prove that restore.

## Deviations

- The unique key carries `WHERE quarantined_at IS NULL`, and the store/business key is enforced through `live_store_id`. Both exist only so a quarantined row can stay in place as evidence (two held copies of one value, or a store id held under the wrong business) instead of being deleted or guessed. Every live row meets the ruled constraints exactly; the composite customer key holds for every row.
- The ruling's "refuse" for an ownership conflict refuses the move, not the order: the order resolves to the customer its own stronger key named, and the conflict row is the resolution requirement. Throwing would have dead-lettered ordinary orders.

## Open questions (new)

1. A quarantined value that is the only key for an order or a message makes resolution throw; the event retries and then goes dead (replayable) until the A3 repair or a person resolves the conflict. The alternative is a provisional customer per event, which multiplies customers. I chose fail closed. Confirm, or rule a provisional-customer policy.
2. `conversations` still has a global unique `(channel, external_thread_ref)` among open threads. Thread refs embed the page or number, so they are per business in practice, and `openOrFind()` is now business-scoped, so a cross-business collision would fail loudly rather than share a thread. Should the index itself become business-scoped?
3. The follow-ups screens show `customers.primary_phone_canonical` without the docs/117 withholding (outside A4's list). Fold into W2 or W4?
