# 46 - Bionic Phase 1 HQ integration (7 September 2026)

> **STATUS: IN PROGRESS.** Sections A-F are verified and final: the code was already live, and
> **all three credentials now exist and are proven against the live site** (section F). Section G,
> the live integration itself, waits on Joy pasting those three values into HQ (section F.4) -
> a step no session may do for him, because it means handling the secrets. Nothing in G is guessed
> or pre-written; it is filled in when the steps actually run.

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
