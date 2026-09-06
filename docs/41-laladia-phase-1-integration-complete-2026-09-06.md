# 41 - LaLaDia Phase 1 Integration Complete (6 September 2026)

The production record of the shop half of Phase 1: the HQ integration commit released to laladia.com from an isolated ref, HQ credentialed for the shop, the webhook path proven on real orders, facts and customer identity built, reconciliation and the commerce probe run, and every event type subscribed. Authorised by Joy on 6 Sep 2026 with the explicit constraint that the local LaLaDia working tree — owned by another active session — must not be touched. It was not.

**Scope respected.** Bionic was inspected read-only only (its HEAD). Econic, Meta, Google, TikTok, n8n, payments, DNS, `CheckoutPricingService`, the stock policy, customer AI, AI order-taking and Phase 2 were not touched. No secret value appears in this document or in chat; the two values Joy carried by hand were displayed only on his own cPanel terminal.

## A. Exact release commits — and why an isolated feature-ref deployment was used

| | |
|---|---|
| **Previous live commit** | `5e955a2c` — "Recover the 404s and stop serving the shop at two addresses" (= `origin/main`, verified live by four behavioural markers in docs/39) |
| **Released commit** | **`4865c109`** — "HQ Phase 1: dedupable webhook envelope, inventory.low, assistant reads, order provenance", the tip of `origin/feature/hq-phase1-api` |
| **Blast radius** | exactly one commit, **12 files, +501 / −6**, one migration |
| **Production is now** | `5e955a2c + 4865c109`, nothing else |

**Why not `main`.** At the moment this milestone started, the local `LaLaDia` checkout on Joy's machine had moved: `main` was `8616e80d`, two commits past `origin/main` (`1ece3bc6` "AEO, GEO and SXO…" and `8616e80d` "Recover the URLs of the shop this platform replaced" — 64 files, +3,134), **both unpushed**, and the tree carried an uncommitted edit to `resources/views/landing/partials/_product-checkout.blade.php` modified two minutes after the last commit. Another session was working there. A fast-forward of the feature into that `main` was impossible, and any merge-then-push would have shipped unreviewed work and disturbed a live edit. Joy's decision: **deploy `origin/feature/hq-phase1-api` directly, leave local `main` and `origin/main` alone.** On the server, `~/laladia-src` was moved with `git checkout --detach origin/feature/hq-phase1-api`; the server's own `main` branch still points at `5e955a2c`. The next release from `main` will need whoever owns the SEO/AEO work to merge `feature/hq-phase1-api` into `main` first (an ordinary merge commit) — recorded in section R.

**Blast-radius proof, from origin alone** (run in the isolated clone, never in the working tree):
```
HEAD 4865c109 · origin/main 5e955a2c · merge-base 5e955a2c
git log origin/main..HEAD   -> 4865c109 (one commit)
git log HEAD..origin/main   -> (empty)
git diff --stat origin/main..HEAD -> 12 files changed, 501 insertions(+), 6 deletions(-)
git diff --stat origin/main HEAD -- public/build -> (empty: the release ships the assets production already serves)
```

## B. Test results (release gate, isolated clone)

A fresh `git clone --branch feature/hq-phase1-api` from GitHub into the scratchpad, `composer install`, `npm ci`, `.env` from `.env.example`. The working tree on `D:\WORK\LALADIA\LaLaDia` was never opened.

| Check | Result |
|---|---|
| Full PHPUnit suite (`php artisan test`, sqlite in-memory) | **1,306 passed, 1 skipped, 1 failed** in 426 s |
| The one failure | `SiblingBrandLeakTest › the shop has a real new order alert address` — asserts `ADMIN_EMAIL`/`ADMIN_PHONE` are not the shipped placeholders. It reads **`.env`**, which in the clone was `.env.example`. **Untouched by the release** (not among the 12 files). Re-run with production-shaped values (`ADMIN_EMAIL=orders@laladia.com ADMIN_PHONE=…`): **6/6 pass**. Production's `.env` carries real values. Effective result: **1,307 passed / 1 skipped** |
| `npm run typecheck` (`tsc --noEmit`) | exit 0 |
| `npm run build` (Vite) | built in 12.6 s |
| `npm run verify:bundles` | storefront carries no React; admin bundle carries React (3/3 sentinels) |
| Playwright | not run: the release changes no page, template or JavaScript (PHP, routes, jobs, one migration) |

Noted, not part of this release: a fresh Vite build produced a different CSS hash (`app-603JATka.css`) from the committed `app-B_aCXey4.css`. The committed assets are what production serves and what `4865c109` inherits unchanged from `5e955a2c`; the rebuilt files were discarded in the clone. Also, `composer install` warned the lock file is behind `composer.json` — pre-existing on `main`.

**Two clone-side incidents, neither affecting the release:** the initial Windows checkout left the index empty (13,303 tracked files vs 1,072 on disk); `git checkout -f HEAD -- .` after `core.longpaths=true` restored it, `git status` clean, all 12 release files present.

## C. Backup evidence (before any production change)

| | |
|---|---|
| Code | `~/laladia-code-before-2026-09-06-hq.tar.gz` — **147 MB** (whole `~/laladia.com`) |
| Database | `~/laladia-db-before-2026-09-06-hq.sql.gz` — **319 KB**, `mysqldump --single-transaction --routines --triggers`, credentials read from `.env` into shell variables and never echoed, `-- Dump completed on 2026-09-06 8:20:36`, **78 `CREATE TABLE`** |
| Rollback SHA | `5e955a2c` (`~/laladia-src` HEAD at backup time) |
| Baseline recorded | `failed_jobs` = 11 (max id 11, newest 2026-08-31 17:18:03: 7× SendSMSJob, 2× SendWelcomeMailJob, 2× NotifyAdminOnNewOrder), `jobs` = 0, orders = 55, 0 pending migrations, `low_stock_alerted_at` column absent |

## D. Pre-deploy read-only findings (cPanel Terminal, before anything changed)

- `~/laladia-src` HEAD `5e955a2c`, remote `git@github-laladia:rhjoyofficial/LaLaDia.git`; `git fetch` brought `origin/feature/hq-phase1-api` = `4865c109` cleanly. Four dirty entries were all **untracked stale build files** (`public/build/assets/admin-BSF9pvcQ.js`, `app-BoLggZfD.js`, `app-DL4zTy_m.css`, `flash-DFRtvlUH.js`) — irrelevant to `git archive`.
- `~/bionic-garden-app` HEAD **`b30b4b7c`**, clean (read-only look; Bionic untouched).
- **Webhook subscribers before: 2 rows, both `is_active = 0`, both pointing at `https://example.com/…`** (placeholders from 27 Aug). Nothing active, nothing pointing at HQ, so no existing subscriber could be affected by the envelope change. Bionic: 0 rows.
- `/bin/mysqldump` present; DB `sangramn_laladia_db` on 127.0.0.1; 58 GB free.
- HQ side before: `events` 0, `facts_orders` 0, `customers` 0, no shop credentials.

## E. Deployment result

Timeline (UTC): **08:23:19** — `git checkout --detach origin/feature/hq-phase1-api` in `~/laladia-src` (guarded: aborts unless HEAD is `4865c109`; server `main` left at `5e955a2c`) → `umask 022` → `git archive HEAD | tar -x -C ~/laladia.com` → `php artisan migrate --force` → `config:cache` → `route:cache` → `view:cache` → `queue:restart`. `public/index.php` extracted as `644 sangramn` (the umask trap did not recur). `bootstrap/cache/` holds `config.php`, `routes-v7.php`, `packages.php`, `services.php` afterwards.

## F. Migration result

```
2026_09_05_210000_add_hq_conversation_ref_and_low_stock_alert ..... 14.60ms DONE   [14] Ran
pending after: 0
```
`orders.conversation_ref` (varchar 64, nullable, indexed) and `product_variants.low_stock_alerted_at` (nullable timestamp). `Schema::hasColumn('product_variants','low_stock_alerted_at')` = 0 before, present after.

## G. Production health after deploy

| Check | Result |
|---|---|
| Storefront `/`, `/products`, `/product-page/hilsa-fish-pickle`, `/login` | 200 |
| Admin (`/admin/dashboard`, `/admin/orders`, order detail) in a real browser, logged in as Joy | renders; figures intact (route cache healthy) |
| 3 assistant routes registered | `route:list` count 3 |
| Assistant endpoints without key | **401** ×3 |
| Assistant endpoints with the existing `CHATBOT_API_KEY` (held in a shell variable, never printed) | promotions 200 (coupons/combos/as_of); availability 200 with `status`, `is_active`, and per variant `sku`, `final_price`, `available_stock`, `orderable`, `reason`; orders for an unused phone → `orders: []`; bad phone → 422 |
| Legacy redirect `/product-category/dry-fish` → 301 `/category/dry-fish` | intact (the `5e955a2c` behaviour) |
| Storefront asset | `app-wt8veaL2.js`, unchanged |
| `failed_jobs` | 11 (baseline), `jobs` 0 after each queue tick, no log file created today |
| Cron | 2 `schedule:run` entries; `webhooks:inventory-low` listed hourly (`0 * * * *`) |

## H. Real order verification

**Order 1 — `LLD-20260906-QFGKLCD9GC` (id 56)**, placed by Joy on the normal storefront at 15:45:39 (+06), *before any subscriber existed*: Dhamua Chapa Shutki 125gm × 1 @ ৳220 + ৳60 Inside-Dhaka shipping = **৳280**, COD, pending/unpaid. From the database: **`source = "checkout"`, `conversation_ref = NULL`**, `phone_verified_at` null (no OTP on this flow), `checkout_token` present (36 chars), stock reserved on the variant. Visible in the admin panel with the customer-history panel and confirmation controls working.

**Order 2 — `LLD-20260906-B9C17FQL` (id 57)**, placed at 17:11:09 (+06) *after* the `order.created` subscriber was active: Royal Essence Ghee 350gm × 3 @ ৳870 = **৳2,610**, free outside-Dhaka delivery, COD, pending. `source = "landing"` (landing-page checkout), `conversation_ref = NULL`.

**On `source`.** docs/38 expected `null` for a browser order. The shop has always written a value: across all 56 orders at the time, `checkout` 37 (ids 1–56), `landing` 13, `admin` 6. The release **added** `assistant` as the only value a request may *supply* and refuses anything else with 422; it changed nothing about the existing defaults. The correct invariant is therefore "`source` is the shop's own channel label and is never `assistant` unless the assistant flow said so" — and both orders satisfy it.

Pricing, shipping-zone charge, stock refusal, idempotency token and the courier panel all behaved as before; none of that code is in the release.

**Closing the test orders.** Both were cancelled through the admin status control — order 56 at 11:16Z, order 57 at 11:21Z — which is also how `order.status_changed` was exercised twice (sections J and O). Nothing else about them was edited; no customer other than Joy was affected.

## I. HQ credential record (`integration_credentials`)

| id | provider | account_ref | type | how it got there |
|---|---|---|---|---|
| 4 | `shop_webhook_secret` | `laladia-com` | custom, 64 chars | generated by `openssl rand -hex 32` into `~/.hq-laladia-webhook-secret` (600) on the shop host; Joy read it there and pasted it into HQ's credential screen |
| 5 | `shop_assistant_key` | `laladia-com` | api_key, 64 chars | the existing `CHATBOT_API_KEY` from `~/laladia.com/.env`, **unchanged** (n8n still depends on it); Joy copied it from his terminal into HQ |
| 6 | `shop_admin_token` | `laladia-com` | token, 51 chars | Sanctum token of a new shop user `hq@bordeguna.com` with role **"HQ Integration"** holding only `order.view` + `customer.view`, random unknown password; the token was written to `~/.hq-laladia-admin-token` (600) and pasted by Joy. Needed because `hq:facts:reconcile` lists orders through the admin API — docs/38 had deferred it |

Two duplicate rows Joy created while learning the form (ids 2 and 3, `account_ref` null) were revoked and soft-deleted. Nothing was rotated. `hq:commerce:probe laladia-com` after all three: `public_products 200, assistant_products 200, admin_orders 200`.

## J. Webhook configuration and end-to-end evidence

**Subscribers on the shop (`webhooks` table), all with the same secret, all `is_active = 1`, all → `https://hq.bordeguna.com/api/webhooks/shop/laladia-com`:**

| id | event | created | exercised live? |
|---|---|---|---|
| 3 | `order.created` | first, alone (shadow) | **yes** — order 57 |
| 4 | `order.status_changed` | after the shadow test | **yes** — order 56 cancelled |
| 5 | `order.payment_updated` | after the shadow test | subscribed; not triggered (would require marking a test order paid) |
| 6 | `shipment.status_updated` | after the shadow test | subscribed; not triggered (needs a courier shipment) |
| 7 | `customer.registered` | after the shadow test | subscribed; not triggered (needs a new account) |
| 8 | `inventory.low` | last, after the shadow test | see section N |

The two placeholder rows (ids 1–2, example.com) remain inactive.

**HQ endpoint state before any delivery:** unsigned POST → `401 {"error":"invalid signature"}` (was 503 "not configured" until the secret existed).

**Event 1 — `order.created`, order 57.** Shop queue drained by the minute cron; HQ recorded it at **11:12:04Z** for `occurred_at 11:12:03Z`: `event_id 4d04b609-…`, `source shop:laladia-com`, `aggregate order / LLD-20260906-B9C17FQL`, `business_id 2`, **`dedupe_key shop:laladia-com:a187ad48-…`** (the shop's new delivery id, so the new envelope reached HQ with `id`, `occurred_at`, `schema_version 1`, `store` and the four `X-Shop-*` headers), HMAC verified, timestamp within the 300 s window. Processed by the minute drain at **11:13:02Z**, 1 attempt, no error. Payload keys as emitted by the shop's listener (order_number, customer name/phone/email, totals, zone, address line, items…) plus HQ's `_store`, `_schema_version`, `_correlation_id`.

**Replay protection, proven against the live endpoint from inside HQ** (same delivery id, body re-signed with the stored secret): `202 {"accepted":true,"event_id":"4d04b609-…","duplicate":true}`; a second replay identical; **bad signature → 401**; **timestamp 2 h old → 401 "stale timestamp"**; `events` count **1 before, 1 after**.

**Event 2 — `order.status_changed`, order 56** (`pending → cancelled` from the admin panel): occurred 11:16:02Z, processed 11:17:02Z, 1 attempt; `facts_orders` for order 56 now `status cancelled`, `cancelled_at 11:16:02Z`, `last_event_id` = that event.

## K. HQ facts and customer identity evidence

After reconciliation (section L) and the two events, `facts_orders` holds 3 rows (orders 57, 56 and yesterday's `LLD-20260905-XVRB7QDLCX`), each with `store_id 1`, `business_id 2`, source label, status, totals, district/thana, and `last_event_id` once an event touched it.

`customers` = **2**, `customer_identities` = **10**, `customer_merges` = **0**:
- customer 1 = Joy (phone canonical `017…308`), `orders_count 2`, `lifetime_value 2,890` — identities: `phone` (1.00), `email` ×2 (he used two addresses; both attach to the same customer through the phone match, no merge needed), `shop_user 11`, `shop_order` ×2.
- customer 2 = yesterday's customer (`019…999`) — `phone`, `email`, `shop_user`, `shop_order`.
Every identity is a deterministic match at confidence 1.00; nothing was forced, and no two customers were joined.

## L. Reconciliation result

`hq:facts:reconcile --store=laladia-com --hours=48` → **"checked 3 orders since 2026-09-04 11:11:15, inserted 3 missing"**. This was the missed-webhook recovery case by construction: order 56 was placed before any subscriber existed, so no webhook ever announced it, and reconciliation recovered it from the shop's admin order list (`listRecentOrders`, 386 ms, logged in `connector_calls`). Source orders were not modified. Re-runs during observation are in section O.

## M. Commerce probe

`hq:commerce:probe laladia-com`: `{"store":"laladia-com","public_products":200,"assistant_products":200,"admin_orders":200}` · capabilities `{search, product, variant, combos, geo, preview, create_order, idempotent_create}` all true. `bionic-garden` still `assistant_products: "no key", admin_orders: "no token"` — by design, Bionic is out of scope. **No order was created through HQ**; `create_order` is a declared capability, not an exercised one (Phase 4).

## N. `inventory.low` rollout

State before enabling: 42 active variants, threshold 5 (`low_stock_threshold` setting), **exactly one** variant at/below it — `PIC-HIL-V2` (stock 9, reserved 4, 5 available) — and it was **already stamped `low_stock_alerted_at = 2026-09-06 15:00:05`** by the hourly run that fired after the deploy while no subscriber existed: the trap docs/38 §E predicted, observed live.

Sequence executed: subscriber id 8 created (active) → **`UPDATE product_variants SET low_stock_alerted_at = NULL`** re-armed **1 row** → 0 stamped. Expected effect, stated before running: the next hourly run (12:00Z) emits **one** `inventory.low` for `PIC-HIL-V2` and nothing else (the next-lowest variant has 127 available). Outcome: section O.

## O. Observation results

Window: 11:12Z (first delivery) to 11:22Z (final pass), with every subscriber active from 11:18Z. Rather than waiting for the 12:00Z cron, `php artisan webhooks:inventory-low` was run by hand at 11:20:47Z (the identical command the scheduler runs): **"emitted 1, re-armed 0 (threshold 5)"**.

**HQ inbox at the end of the window — 6 events, all `processed`, 1 attempt each, 0 pending, 0 failed:**

| id | type | subject | occurred → processed (UTC) | note |
|---|---|---|---|---|
| 1 | `order.created` | `B9C17FQL` (test order 2) | 11:12:03 → 11:13:02 | shadow test |
| 2 | `order.status_changed` | `QFGKLCD9GC` (test order 1) | 11:16:02 → 11:17:02 | `pending → cancelled` |
| 3 | `inventory.low` | `PIC-HIL-V2`, available 5 | 11:21:03 → 11:22:02 | **exactly the one predicted** |
| 4 | `order.created` | **`LLD-20260906-GZPTTHF9`** | 11:21:04 → 11:22:02 | **real production activity, not this session's**: an admin-created order (`source admin`, ৳1,089, confirmed ten seconds after creation) |
| 5 | `order.status_changed` | `B9C17FQL` (test order 2) | 11:21:04 → 11:22:02 | `pending → cancelled` |
| 6 | `order.status_changed` | `GZPTTHF9` | 11:21:04 → 11:22:02 | `pending → confirmed` |

Order 4 is the point of the shadow period: an order nobody in this milestone placed went through the admin path and arrived in HQ with its creation and confirmation, and HQ built **customer 3** from it (`phone`, `shop_user`, `shop_order` identities, confidence 1.00, no merge).

**Consistency at the end of the window:** `facts_orders` 4 (both test orders `cancelled` with `cancelled_at` matching the event times; `XVRB7QDLCX` and `GZPTTHF9` `confirmed`); `customers` 3, `customer_identities` 13, `customer_merges` 0; `connector_calls` 11, **0 failed**; `ai_runs` 0, `tool_calls` 0 (non-AI work touched neither). **`hq:facts:reconcile` re-run: "checked 4 orders, inserted 0 missing"** — the facts and the shop agree, and reconciliation is idempotent. HQ `/api/health` all `ok`; no error or exception in the HQ app log since 08:00Z (the only warnings are the two deliberate signature/staleness rejections from the replay test). Shop: `jobs` 0 after each tick, `failed_jobs` **11** — unchanged from the pre-deploy baseline, so no delivery failed and no retry was needed — `orders` 58, active subscribers 6, no application log file created today.

**Duplicates:** none. Every event carries a distinct shop delivery id; the deliberate replay was answered `duplicate:true` and recorded nothing.

## P. Rollback procedure

- **Code**: `cd ~/laladia-src && git checkout --detach 5e955a2c && umask 022 && git archive HEAD | tar -x -C ~/laladia.com`, then `config:cache`, `route:cache`, `view:cache`, `queue:restart` in `~/laladia.com` — or extract `~/laladia-code-before-2026-09-06-hq.tar.gz`.
- **Schema** (only if required): `php artisan migrate:rollback --step=1` drops the two columns.
- **Data**: `~/laladia-db-before-2026-09-06-hq.sql.gz`.
- **Configuration-only mitigations** that need no rollback: set `is_active = 0` on any `webhooks` row; revoke the HQ credential rows; the shop then behaves exactly as before the integration.

## Q. Anomalies

1. **Local `main` had moved and was dirty** (another session) — handled by deploying the isolated ref; nothing of that work was released or touched.
2. **`source` is `checkout`/`landing`/`admin`, never null** — a wrong expectation in docs/38, not a defect; corrected in section H.
3. **`hq:facts:reconcile` needs the admin token** — docs/38 D.3 had deferred `shop_admin_token`; it was created with a two-permission role.
4. **`inventory.low` first-crossing consumed before subscription** — predicted, observed (`PIC-HIL-V2` stamped at 15:00), re-armed.
5. **One env-dependent test** (`SiblingBrandLeakTest`) fails in any fresh checkout without real `ADMIN_EMAIL`/`ADMIN_PHONE`; passes with them.
6. **Fresh Vite build ≠ committed CSS hash**; not part of this release.
7. **Two accidental duplicate credential rows** created by hand; removed.
8. The cPanel Terminal renderer froze twice mid-command; re-attaching the page restored the persistent shell with no lost state.
9. Joy `cat`ed the admin token on his terminal while a screenshot was being taken; it appeared in that screenshot and nowhere else. Rotate it if that is a concern: revoke on the shop (`$u->tokens()->delete()`), reissue, replace HQ row 6.

## R. Known limitations

- `order.payment_updated`, `shipment.status_updated`, `customer.registered` are subscribed but were **not exercised** by a real event during this milestone; they ride the same `SendWebhookJob → WebhookService` path and the same HQ inbox, with identical HMAC, timestamp and dedupe handling.
- Webhooks leave the shop up to ~60 s after the event (queue drained by cron once a minute), and a deploy mid-queue can drop in-flight jobs; the nightly reconcile is the safety net.
- `~/laladia-src` is **detached** at `4865c109`. The next `main` release must first merge `feature/hq-phase1-api` into `main` (ordinary merge) or the HQ change will be lost from the docroot.
- `shop_admin_token` and the assistant key have no expiry; rotation is manual.
- No `scheduled_jobs` row polls the shop; HQ learns of orders by webhook and by the nightly reconcile only.
- The assistant availability endpoint reports `stock_insufficient` only at 0 available; `PIC-HIL-V2` at 5 is `orderable: true, reason: ok` — consistent with P-1 (status offers, checkout confirms).

## S. Exact next milestone

**Bionic Phase 1.** Because `origin/main` does not yet contain the HQ commit, Bionic's `relaunch` should merge **`upstream/feature/hq-phase1-api`** directly (or `upstream/main` once the HQ commit has been merged there by the owner of the SEO/AEO work), run Bionic's own suite, and deploy per its runbook — its docroot *is* the repository, so `git diff --name-only HEAD..origin/relaunch -- public/assets/ads` must be empty first. Then: `bionic-garden` credentials in HQ (its own `CHATBOT_API_KEY`, a fresh webhook secret, an admin token with the same two-permission role), `order.created` first, verify, widen, and the same `low_stock_alerted_at` re-arm after the last subscriber. **Not started; waiting for instruction.**

---

**Confirmation:** no unrelated local `main` work was released. Production laladia.com = `5e955a2c + 4865c109`. Local `main` (`8616e80d`) and the uncommitted edit were never checked out, merged, pushed, tested or deployed by this session.
