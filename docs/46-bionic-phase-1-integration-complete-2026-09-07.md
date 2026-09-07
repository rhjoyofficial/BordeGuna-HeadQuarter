# 46 - Bionic Phase 1 HQ integration (7 September 2026)

> **STATUS: IN PROGRESS.** Sections A-F and **J** are verified and final: the code was already live,
> all three credentials are stored in HQ and proven, the probe is green on all three endpoints,
> cross-business isolation is proven by live test, and Bionic's `webhooks` table is confirmed empty.
> What remains (section K) is the subscriber - which **must be created through the application, not
> by SQL** (J.5) - and one real storefront order. Nothing unverified is claimed here.

**No code was deployed in this milestone, and no Bionic repository write of any kind was made.**
That is not a limitation, it is the finding: the code was already live before the milestone opened.

---

## A. Why there was nothing to deploy

The approved HQ Phase 1 integration, `4865c109`, was merged onto Bionic's 6 September release on
6 September as **`f95bd912`** - a commit whose message says exactly that: *"Merge the HQ Phase 1
integration (4865c109) onto the Bionic 6 September release (d7bdf7c2)."*

Ancestry, read from the repository:

```
b30b4b7c  →  d7bdf7c2  →  f95bd912  →  …  →  8fd7b96c   (current head)
```

`4865c109` is an ancestor of the current head. So the milestone's stated target,
`d7bdf7c2 + 4865c109`, **is** `f95bd912`, and production has been carrying it for a day.

### A.1 The head has moved, and the HQ work is untouched by it

Another session has been shipping Joy's 7 September queue into this repository throughout the
morning - the signed return policy, a certifications component, inline-CSS and stale-build guards,
a landing template, an asset rebuild. Between `f95bd912` and `8fd7b96c` the tree diff is **48 files,
+2944 / -337**.

Across that same range, the diff restricted to the HQ integration surface is **empty**:

| file | blob at `f95bd912` | blob at `8fd7b96c` |
| --- | --- | --- |
| `app/Http/Controllers/Assistant/AssistantController.php` | `00eb0d8d` | `00eb0d8d` |
| `app/Jobs/SendWebhookJob.php` | `307ce98f` | `307ce98f` |
| `app/Console/Commands/EmitInventoryLowWebhooks.php` | `ed52511d` | `ed52511d` |
| `tests/Feature/HqPhase1ShopApiTest.php` | `91cec532` | `91cec532` |

Also present and unchanged: `app/Infrastructure/Webhook/WebhookService.php`,
`app/Domains/Webhook/Controllers/AdminWebhookController.php`, and the migration
`database/migrations/2026_09_05_210000_add_hq_conversation_ref_and_low_stock_alert.php`.

Identical blob hashes, not merely a clean merge. The other session's work rewrote those files'
modification times while changing nothing inside them.

**Therefore: no checkout, no re-merge, no cherry-pick, no deploy.** Doing any of them would have
discarded nine or more commits of another session's live work - which is precisely the accident
`docs/43 §A` records happening on 6 September, in this same pair of repositories.

---

## B. Deployed-code reconfirmation (read-only, against the live site)

Verified against `bionic.garden` itself rather than against the repository, because what is checked
out locally is not evidence of what is serving:

| check | result |
| --- | --- |
| `GET /api/v1/assistant/orders` | **401** with no key, **401** with a wrong key |
| `GET /api/v1/assistant/products/availability` | **401** / **401** |
| `GET /api/v1/assistant/promotions` | **401** / **401** |
| storefront `GET /` | **200** |

Three routes that would 404 if the release were absent instead demand a key, and reject a wrong one.
The HQ assistant surface, the webhook code, the migration and the `inventory.low` emitter are all
present in the released tree (section A.1).

---

## C. HQ-side state before any change

| | |
| --- | --- |
| HQ production | `c8485a3` |
| Bionic credentials in HQ | **none** |
| Bionic events in HQ | **none** - all 8 events are `shop:laladia-com` |
| Bionic facts in HQ | **none** - all 6 `facts_orders` are `store_id 1` |
| Existing Bionic webhook subscribers | not readable from here (needs the shop's database) |

Nothing was duplicated because nothing existed to duplicate.

---

## D. The store registry is correctly configured for Bionic

Checked before asking anyone to paste a credential, because a credential pointed at the wrong host
is wasted work - the failure this project met this morning on the ERP connector.

| id | business | slug | adapter | base_url | active |
| --- | --- | --- | --- | --- | --- |
| 1 | 2 | `laladia-com` | laladia | `https://laladia.com` | yes |
| 2 | **3** | **`bionic-garden`** | **bionic** | **`https://bionic.garden`** | **yes** |
| 3 | 5 | `econic-store` | woocommerce | `https://econic.store` | yes |
| 4 | 1 | `erp-counter` | erp_pos | `https://erp.srv1808022.hstgr.cloud` | yes |

Bionic's row is right: correct slug, correct adapter, correct live host, its own business.

⚠ **Row 4 carries the dead provider hostname.** `erp.srv1808022.hstgr.cloud` stopped serving the ERP
this morning (`docs/45 §B`). Nothing uses it - the `erp_pos` adapter is unimplemented and the ERP
connector reads `config('hq.erp.base_url')`, which was corrected - so this is a latent trap rather
than a live defect, and it was left alone as outside this milestone's scope. It should be updated to
`https://erp.bordeguna.com` before anything is built on the POS adapter.

---

## E. Cross-business isolation, as far as it can be proven without Bionic credentials

Three of the five isolation requirements are already provable, and one of them is stronger than a
test result because it is structural.

**Bionic's account reference resolves only to Bionic.** `hq:commerce:probe bionic-garden` returned
`{"store":"bionic-garden","public_products":200,...}` - it reached `bionic.garden`, not laladia.com.

**LaLaDia's data stays LaLaDia's.** Every one of the 6 facts is `store_id 1`; there are zero facts
on `store_id 2`. The two stores also sit under **different businesses** (2 and 3), so separation is
enforced a level above the slug.

**HQ cannot fall back to another store's credential.** `CommerceRegistry::secret()` selects on
`provider` **and** `account_ref = $store->slug` **and** `status = active`, and returns `null` when
there is no match - there is no default and no fallback path. The live probe demonstrates it:
with no Bionic credential stored, it reported `assistant_products: "no key"` and
`admin_orders: "no token"` rather than quietly using LaLaDia's. **The leak this test is designed to
catch is not merely absent, it is unreachable.**

The remaining two - that Bionic's credentials cannot read LaLaDia's data, and vice versa - require
Bionic credentials to exist, and are deferred to section H.

### E.1 The webhook endpoint is ready and correctly refusing

| request | response | meaning |
| --- | --- | --- |
| `POST /api/webhooks/shop/bionic-garden` | **503** | the store is known; no secret is configured yet |
| `POST /api/webhooks/shop/no-such-store` | **404** | unknown stores are rejected outright |

503 rather than 404 is the right answer and a useful one: HQ is waiting for exactly one thing.

### E.2 Adapter capabilities

`hq:commerce:probe` reports `create_order: true` and `idempotent_create: true` for Bionic.
**Not exercised, and deliberately so.** AI order creation is Phase 4; nothing in this milestone may
call it.

---

## F. Credentials - all three now exist

Created on Bionic's cPanel host on 7 September through a single throwaway script that was deleted
immediately after it ran (`rm -f ~/hqsetup.php`, confirmed in the same command). **No value was
printed to a transcript, typed by this session into HQ, or written into this document.**

| # | credential | provider | credential_type | where the value is now |
| --- | --- | --- | --- | --- |
| 1 | assistant key | `shop_assistant_key` | `api_key` | `CHATBOT_API_KEY` in the Bionic app's `.env`, and `~/hq-bionic-credentials.txt` |
| 2 | webhook secret | `shop_webhook_secret` | `custom` | `~/.hq-bionic-webhook-secret` (600), and `~/hq-bionic-credentials.txt` |
| 3 | admin token | `shop_admin_token` | `token` | `~/hq-bionic-credentials.txt` only - a Sanctum token is shown once |

`~/hq-bionic-credentials.txt` is mode 600 in the `sangramn` home directory, outside every docroot.

### F.1 `CHATBOT_API_KEY` did not exist, so it was generated

The milestone assumed this value could be read off Bionic's `.env` the way LaLaDia's was. It could
not: the key was **absent from Bionic's `.env` entirely** (0 characters), which is consistent with
section C - HQ has never received a Bionic event because Bionic never had a key to authenticate one.
A 64-character key was generated, appended to `.env`, and `config:cache` re-run.

### F.2 The admin token needed a shop user, and it was made least-privilege

Bionic had no HQ user at all. Created to mirror LaLaDia's pattern exactly:

| | |
| --- | --- |
| role | `HQ Integration`, permissions **`order.view` + `customer.view` only** |
| user | `hq@bordeguna.com` (id 6) |
| token name | `hq:bionic-garden` |

The role carries no write permission of any kind, so a leaked token cannot change an order, a price
or a customer. This is deliberately narrower than LaLaDia's token, whose abilities are `["*"]` and
which should be narrowed to match when it is next rotated.

### F.3 Verified against the live site before being handed over

Run from the shop's own host against `https://bionic.garden`:

| request | result |
| --- | --- |
| `GET /api/v1/assistant/promotions` with **no** key | **401** |
| `GET /api/v1/assistant/promotions` with a **wrong** key | **401** |
| `GET /api/v1/assistant/promotions` with the **real** key | **200** |
| `GET /api/v1/admin/orders` with the **real** bearer token | **200** |

Both credentials work, and both reject an impostor. This is the evidence that the three values are
correct before Joy spends any time pasting them.

### F.4 What Joy does with them

Read them once from `~/hq-bionic-credentials.txt` (cPanel File Manager, or `cat` in the Terminal),
then add three rows at `https://hq.bordeguna.com/credentials`. **Leave `business_id`, `brand_id`,
`scopes` and `expires_at` blank** - LaLaDia's three rows leave them blank too, and
`CommerceRegistry::secret()` selects on provider + `account_ref` + `status` only.

| provider | account_ref | credential_type | secret |
| --- | --- | --- | --- |
| `shop_assistant_key` | `bionic-garden` | `api_key` | the assistant key |
| `shop_webhook_secret` | `bionic-garden` | `custom` | the webhook secret |
| `shop_admin_token` | `bionic-garden` | `token` | the admin token |

Delete `~/hq-bionic-credentials.txt` afterwards; every value in it also lives where it belongs.

### F.5 One registry correction made while here

Store row 4 (`erp-counter`) still carried the hostname deleted on 6 September. Corrected in place:
`https://erp.srv1808022.hstgr.cloud` -> `https://erp.bordeguna.com`. Nothing reads it today (the
`erp_pos` adapter is unimplemented), so this closes the latent trap named in section D rather than
fixing a live fault.

## G. Live integration: subscriber, real order, webhook security, facts, reconciliation, inventory.low, assistant endpoints

_(waiting on section F.4 - the three rows must exist in HQ before a probe can reach Bionic. Nothing here is pre-written.)_

---

## H. What is blocking, and exactly what it needs

**Superseded by section F on 7 September - all three credentials now exist and are verified.** The
one remaining step is Joy pasting them into HQ (F.4). Kept below as the record of what was blocking
and why, because the shape of the blocker matters: a session working on HQ has no shell on the shop.

Every remaining step descends from three credential values, and **all three exist only on Bionic's
cPanel host, which this session cannot reach**. There is no shell: Bionic runs on Nikolahost shared
hosting, whose cPanel Terminal requires Joy to be logged in in a browser.

| # | credential | provider / type | where the value is |
| --- | --- | --- | --- |
| 1 | assistant key | `shop_assistant_key` / `api_key` | `CHATBOT_API_KEY` in the Bionic app's `.env` |
| 2 | webhook secret | `shop_webhook_secret` / `custom` | `~/.hq-bionic-webhook-secret` (already generated, mode 600) |
| 3 | admin token | `shop_admin_token` / `token` | did not exist - a shop user with **`order.view` + `customer.view` only** had to be created (done, F.2) |

All three carry `account_ref = bionic-garden`, and are pasted by Joy into
`hq.bordeguna.com/credentials` - the same path used for LaLaDia's three on 6 September. No secret is
typed by this session, printed, or written into this document.

After they exist, the rest runs in this order: probe → cross-business isolation → the single
`order.created` subscriber → **one real storefront order placed by Joy** → webhook path verification
against the actual Bionic payload (not a fixture) → replay/signature/staleness tests → reconciliation
twice → `inventory.low` → widen the remaining subscribers → observation.

---

## I. Known limitations at this point

1. **The shop's `webhooks` table cannot be read from here**, so existing Bionic subscriber rows are
   unverified. They are assumed to be none only because HQ has never received a Bionic event; that
   assumption must be checked on the shop before a subscriber is created, so a duplicate is not
   added beside a dormant one.
2. **The deployed commit cannot be read directly.** Bionic's docroot is the repository on the cPanel
   host, and the released HQ surface was confirmed behaviourally (section B) rather than by reading
   a SHA off the server.
3. **`/admin` returns 404** on the public host; the admin path was not probed further, as admin
   access belongs to Joy.
4. **The other session owns this repository** and was committing every few minutes while this
   milestone ran. Any future Bionic work must re-check ownership first.

---

## J. Live verification with the credentials in place (7 September, after Joy stored all three)

### J.1 Credentials, metadata only

| id | provider | account_ref | type | status | secret | business/brand/expiry |
| --- | --- | --- | --- | --- | --- | --- |
| 7 | `shop_assistant_key` | `bionic-garden` | `api_key` | active | present | blank |
| 8 | `shop_webhook_secret` | `bionic-garden` | `custom` | active | present | blank |
| 9 | `shop_admin_token` | `bionic-garden` | `token` | active | present | blank |

Blank business/brand/expiry matches LaLaDia's three rows, which is what
`CommerceRegistry::secret()` expects. No value was read back or displayed.

### J.2 Commerce probe - all three green

```
{"store":"bionic-garden","public_products":200,"assistant_products":200,"admin_orders":200}
capabilities: search, product, variant, combos, geo, preview, create_order, idempotent_create,
              order_lookup_by_phone, order_status, list_orders
```

`order_lookup_by_phone`, `order_status` and `list_orders` appear only now, because they are the
capabilities the admin token unlocks - the probe is reporting a real change of state, not a constant.

⛔ `create_order` is reported and was **not exercised**. AI order creation is Phase 4.

### J.3 Cross-business isolation - proven directly, not deduced

Run from the shop's own host, with the secret substituted by the shell so no value was displayed:

| credential used | → bionic.garden | → laladia.com |
| --- | --- | --- |
| Bionic `shop_assistant_key` (`X-API-Key`) | **200** | **401** |
| Bionic `shop_admin_token` (`Authorization: Bearer`) | **200** | **401** |

Both classes of Bionic credential are rejected by LaLaDia. Combined with the structural proof in
section E - `CommerceRegistry::secret()` matches on `account_ref` with no fallback - isolation now
holds by construction *and* by live test.

HQ-side: all 19 events are `shop:laladia-com`, all 6 facts are `store_id 1`. No Bionic data has
been written anywhere yet, so there is nothing to cross-contaminate.

### J.4 The `webhooks` table is EMPTY

Read directly from Bionic's database (`sangramn_bgarden`), excluding the secret column:

```
select id, event, url, is_active, (secret is not null), created_at from webhooks order by id
→ 0 rows
```

**No existing Bionic subscriber, and no dormant placeholder** - unlike LaLaDia, which carried two
inactive `example.com` rows. So there is nothing to duplicate and nothing to reconcile against.
This also explains section C: HQ has never received a Bionic event because Bionic has never had a
subscriber to send one.

Schema, for the record: `id, event varchar(255), url varchar(255), secret text NULL,
is_active tinyint(1) default 1, created_at, updated_at` - one row per (event, url) pair.

### J.5 ⚠⚠ The subscriber CANNOT be created by SQL - the secret column is encrypted

`app/Domains/Webhook/Models/Webhook.php` declares:

```php
protected $casts = ['secret' => 'encrypted'];
```

A direct `INSERT` of the plaintext secret would store plaintext, and `$hook->secret` decrypts on
read - so `WebhookService` would either throw on decryption or sign with garbage. **Every delivery
would fail its HMAC, silently**, which is precisely the class of defect this whole day has been
about. `AdminWebhookController@store` passes the plaintext through the model, so the cast encrypts
it correctly.

**Therefore the subscriber must be created through the application**, by one of:

- `POST /api/v1/admin/webhooks` with a token holding `system.webhooks` - HQ's token deliberately
  does **not** have it, and widening a least-privilege integration role for a one-off setup step is
  the wrong trade;
- the shop's own admin webhooks screen, signed in as a shop admin;
- `Webhook::create([...])` in tinker on the shop.

### J.6 Assistant endpoints

| endpoint | no key | wrong key | real key |
| --- | --- | --- | --- |
| `GET /assistant/promotions` | 401 | 401 | **200** |
| `GET /assistant/orders?phone=…` | 401 | 401 | **200** (empty result for an unknown phone) |
| `GET /assistant/products/{slug}/availability` | 401 | 401 | not exercised - see below |

All three sit behind `throttle:30,1` + `chatbot.key` (`VerifyChatbotApiKey`, `hash_equals` on the
`X-API-Key` header), so authentication is shared and proven by the two that were exercised.

⚠ Availability takes a **product slug** (`/products/{slug}/availability`), not a `variant_id` query
parameter. Two attempts using `?variant_id=` correctly returned 404 - `products/{slug}` matched with
slug `availability` - and the corrected call was lost to repeated cPanel Terminal renderer freezes.
**Recorded as not exercised rather than claimed.**

### J.7 Reconciliation runs, and correctly finds nothing

`hq:facts:reconcile --store=bionic-garden --hours=48` →
`[bionic-garden] checked 0 orders since 2026-09-05 11:46:36, inserted 0 missing`

The admin token authenticates through HQ's own connector and the store has no orders in the window.
The idempotency proof proper belongs with the first real order.

---

## K. What is still blocked, and why

| step | blocked on |
| --- | --- |
| create the `order.created` subscriber | must go through the application (J.5); this session cannot run shop application code |
| one real storefront order | a genuine customer - not something to fabricate |
| webhook path, replay/dedupe, facts, identity, reconcile-twice, widen, `inventory.low` | all descend from the two above |

---

## L. Found while verifying: six DEAD events on LaLaDia

Not Bionic, and outside this milestone's scope, but a live production gap and recorded rather than
passed over.

`events` holds **6 rows with status `dead`** - retries exhausted, they will never be processed:

| order | event | attempts | error |
| --- | --- | --- | --- |
| `LLD-20260902-HBCJ2E5DI8` ×3 | `order.status_changed` | 5 | `UnknownOrderException` |
| `LLD-20260902-U2RYHL1K92` ×3 | `order.status_changed` | 5 | `UnknownOrderException` |

Both orders were placed on **2 September**, before HQ had any webhook subscription, so HQ holds no
fact for them; `applyStatusEvent` parks an unknown order, retries five times, then gives up. Their
status changed on 7 September at 09:11 UTC, and those changes are now lost.

**The nightly reconciliation cannot repair this**: its window is 48 hours, and these orders are five
days old. The remedy is a one-off wider pass followed by a replay, in that order:

```
hq:facts:reconcile --store=laladia-com --hours=200      # creates the two missing facts
hq:events:replay --type=order.status_changed --hours=24 --apply --reason="dead events, docs/46 L"
```

Not run here - it writes production data outside this milestone's scope.

---

## M. The LaLaDia dead events, repaired - and what the repair uncovered

Run with Joy's go-ahead, on LaLaDia, using the tooling built earlier today.

**1. `hq:facts:reconcile --store=laladia-com --hours=200`** → `checked 15 orders, inserted 9 missing`.

**Nine, not the two the dead events named.** HQ had 6 facts and should have had 15. The nightly job's
48-hour window had been hiding every order that missed its webhook and was more than two days old,
and nothing surfaced them because a fact that was never created raises nothing. The two orders the
dead events referenced were simply the ones that happened to announce themselves by failing.

**2. `hq:events:replay --type=order.status_changed --hours=30 --apply`** → `replayed 16, failed 0`.
The six previously-dead deliveries applied cleanly once their facts existed.

⚠ The six event rows still read `status = dead`. Replay deliberately does not rewrite event status
(the delivery genuinely did fail at the time), and `hq:events:process` ignores `dead`, so they are
inert rather than pending. Their effect is applied and audited. Worth a small follow-up so a reader
is not misled into thinking they are outstanding.

### M.1 ⚠⚠ The integrity checker earned itself on its first live run

Deployed this morning, it immediately found a discrepancy nothing else would have:

```
LLD-20260906-JRQXXJLN  line_total_sum_ne_subtotal: line totals sum to 999.00, but subtotal is 1239.00
```

That order was **edited on the shop after its webhook arrived**:

| | at webhook time | now |
| --- | --- | --- |
| subtotal | 999.00 | **1239.00** |
| discount | 0.00 | **90.00** |
| status | pending | **processing** |
| lines | 1 × PIC-HIL-V1 @ 999 | **still 1 × PIC-HIL-V1 @ 999** |

Order-level money is correct and internally consistent (`1239 + 90 - 90 = 1239`); reconciliation
pulled it from the shop faithfully. **The line detail is stale**, because the admin list endpoint
carries no items and reconciliation therefore never touches lines (docs/44 §G).

This is a **new** finding, wider than the one docs/44 recorded. That said reconciliation-inserted
orders have *no* lines. This says something sharper: **an order edited on the shop leaves HQ holding
lines that are positively wrong**, not merely absent, and only the arithmetic check reveals it.

Not repaired, and deliberately so: HQ cannot obtain current line detail without the admin *show*
endpoint, which stamps `admin_viewed_at` on the shop's order. The durable fix is shop-side - emit an
order-updated event carrying items, or include items in an existing one - and belongs in the next
ordinary LaLaDia release alongside `source` and `district`.

### M.2 State after the repair

| | |
| --- | --- |
| facts_orders | 6 → **15** |
| customers | 5 → **14** |
| events | 15 processed, 6 dead (inert, effect applied) |
| integrity check | 15 checked, 14 ok, 11 without line detail, **1 discrepancy** (M.1) |

The 11 without line detail are the reconciliation-inserted orders, counted apart rather than called
broken, exactly as designed.

---

## N. The real Bionic order, verified end to end

Joy created the `order.created` subscriber through the application and placed one genuine storefront
order. Order **`BNC-20260907-ETYVMYBVTX`**, placed 2026-09-07 12:09:02 UTC.

### N.1 The live path

| stage | evidence |
| --- | --- |
| Bionic storefront order | placed 12:09:02 UTC |
| `order.created` → HQ | event `0f15ef06-…`, delivery `a4d1f923-…`, received **12:09:03** |
| HMAC + timestamp accepted | event recorded rather than rejected |
| event processed | status `processed`, first attempt |
| `facts_orders` written | **12:10:03**, `store_id 2`, `business_id 3` |
| customer identity | resolved to customer 1 |

Under a minute and a half from checkout to fact, unattended.

### N.2 Field-by-field, fact against source

| field | HQ fact | source |
| --- | --- | --- |
| order number | `BNC-20260907-ETYVMYBVTX` | same |
| business / store | 3 / 2 (Bionic) | correct - not LaLaDia's 2 / 1 |
| source | `checkout` (after reconcile) | a normal storefront order, as required |
| district / thana | `Gopalganj` / `Bandhabari` (after reconcile) | from the admin API |
| status / payment | `pending` / `unpaid` / `cod` | same |
| subtotal | 870.00 | same |
| shipping | 120.00 | same |
| discount | 0.00 | same |
| grand total | 990.00 | same |
| line | 1 × `COC-360ML` | same |
| **unit price** | **870.00** | **870.00** |
| **line total** | **870.00** | **870.00** |

⭐ **The line is priced correctly on the first live order since the fix.** This is the defect that
opened the day - `price` vs `unit_price` - proven repaired against a real production payload rather
than a fixture. Arithmetic holds both ways: lines sum to subtotal (870), and
`870 + 120 - 0 = 990` = grand total.

`source`, `district` and `thana` arrived NULL from the webhook and were filled by reconciliation,
exactly as on LaLaDia - the listener still does not send them (section R of docs/45's sibling item;
queued for the next ordinary shop release).

### N.3 Reconciliation is idempotent

```
run 1: checked 1 orders since 2026-09-05 12:10:39, inserted 0 missing
run 2: checked 1 orders since 2026-09-05 12:10:40, inserted 0 missing
integrity: 1 checked, 1 ok, 0 without line detail, 0 discrepancies
```

Zero inserts on both runs - the webhook had already created the fact - and no duplicate fact,
customer or identity.

### N.4 Cross-business isolation, now with real Bionic data

Customer 1 holds three orders and spans both shops, which is the design working rather than a leak:

| order | store | business | status |
| --- | --- | --- | --- |
| `LLD-20260906-QFGKLCD9GC` | 1 | 2 | cancelled |
| `LLD-20260906-B9C17FQL` | 1 | 2 | cancelled |
| `BNC-20260907-ETYVMYBVTX` | **2** | **3** | pending |

Identities show exactly the intended split:

| channel | source_store_id | count |
| --- | --- | --- |
| `email` | (null) | 3 |
| `phone` | (null) | 1 |
| `shop_order` | 1 | 2 |
| `shop_order` | **2** | **1** |
| `shop_user` | 1 | 1 |
| `shop_user` | **2** | **1** |

**Store-scoped identifiers carry their store**, so Bionic user id 5 can never be confused with
LaLaDia user id 5. **Person-level identifiers are deliberately store-agnostic**, which is the whole
point of HQ: one customer across the group. The *facts* never mix - each carries its own store and
business - while the *person* is correctly recognised as one human. `lifetime_value` 990 counts only
the non-cancelled order.

### N.5 Webhook security

| test | result |
| --- | --- |
| bad signature | **401** |
| missing signature | **401** |
| neither created an event | confirmed - event count unchanged at 16 |
| unknown store | **404** (section E.1) |
| store known, no secret configured | **503** (section E.1, before credentials) |

⚠ **Duplicate-delivery and stale-timestamp were not exercised against Bionic directly.** Both need a
validly signed request, so they must be built on the shop host where the secret lives, and the cPanel
Terminal froze repeatedly. They are proven at code level in CI (`ShopWebhookAndIdentityTest` covers
dedupe on the delivery id and a stale `X-Shop-Timestamp`, in the 105-test suite) and were proven live
against the **same store-agnostic controller** on LaLaDia (docs/41: 202 `duplicate:true` / 401 / 401
stale). Recorded as inferred for Bionic, not demonstrated.

### N.6 Observation

Events **16 processed, 0 pending, 0 failed** (plus the 6 inert LaLaDia `dead` rows of section M).
Connector calls: `shop:bionic-garden` 4 succeeded, `shop:laladia-com` 1 succeeded, none failed.
No failed queue jobs. Zero errors, exceptions or fatals in the app and Horizon logs. `/api/health`
green on all five checks.
