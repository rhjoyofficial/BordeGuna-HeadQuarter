# 43 - Production Reconciliation and HQ Phase 1 Restoration (6 September 2026)

> **STATUS: IN PROGRESS — paused end of 6 Sep, resuming 7 Sep.** Sections A–H are complete and verified. Both shops are restored and stable. Sections I onward wait on Joy: one real LaLaDia storefront order, then the three Bionic credential pastes and the admin-user line, then the Bionic `order.created` subscriber, one real Bionic order, the remaining five subscribers, cross-shop isolation checks, observation, the admin-token rotation, and the final report.

Both shops restored to "the other session's 6 September release **plus** the approved HQ Phase 1 commit", after that release had been deployed over the top of the LaLaDia HQ integration and before Bionic had received it. This supersedes the Bionic-only milestone (docs/42 was never written; its candidate `68a18789` was discarded) and completes what docs/41 started.

**Scope respected.** No rollback of either shop; the newer application code is the baseline throughout. LaLaDia's local uncommitted Bangla-digit edit was not touched. Econic, Meta, Google/TikTok, n8n, payments, DNS, customer AI, AI order-taking, Content Factory, Voice, Avatar and Phase 2 untouched. No secret value appears here or in chat.

## A. Why the previous release was superseded

| UTC | What happened |
|---|---|
| 08:23 | LaLaDia released from the isolated ref: `5e955a2c + 4865c109` (docs/41) |
| 11:12–11:43 | Live HQ path proven: three real orders, six events, replay/HMAC/staleness tests |
| **11:56:08** | Another authorised session, working through the same cPanel session, ran `git checkout main` in `~/laladia-src` (leaving the detached `4865c109`), fast-forwarded to `46b70eba` and released it — its own "Deploy — 6 September 2026" runbook (`docs/DEPLOY-2026-09-06.md`, AEO/GEO/SXO, legacy URL recovery, gallery, nine migrations) |
| **11:57:47** | Same session fast-forwarded `~/bionic-garden-app` `b30b4b7c → d7bdf7c2` and released it |
| 12:02 | Detected: laladia.com assistant endpoints 404, old webhook envelope, `inventory.low` unscheduled, `source=assistant` rejected — the HQ code overwritten by `git archive`, the HQ schema and subscriber rows intact. Bionic candidate `68a18789` (built on `b30b4b7c`) obsolete. Halted and reported |

Neither `main` (`46b70eba`) nor `relaunch` (`d7bdf7c2`) contained `4865c109`. Joy's decision: treat the new code as the baseline and restore HQ on top of it; no rollback.

**Freeze evidence before restoration (12:09Z):** `origin/main` and `origin/relaunch` unchanged since 11:19Z; the uncommitted LaLaDia file's mtime 13:50 +06 (hours earlier); server reflogs quiet since 11:57:47Z. Re-checked immediately before each push (fast-forward guards would have refused otherwise).

## B. Starting commits, HQ commit, resulting release commits

| Shop | Starting (live) | HQ commit | Release candidate | Now live |
|---|---|---|---|---|
| LaLaDia | `46b70eba` (`main`) | `4865c109` | **`702b8b60`** = merge of `4865c109` onto `46b70eba` | `702b8b60` since 12:26:29Z |
| Bionic | `d7bdf7c2` (`relaunch`) | `4865c109` | **`f95bd912`** = merge of `4865c109` onto `d7bdf7c2` | `f95bd912` since 12:33:30Z |

Both merges were built in fresh clones from GitHub (`scratchpad/laladia-restore-gate`, `scratchpad/bionic-restore-gate`), never in the working checkouts. **Dry-run merges reported zero conflicts and no file overlap** between the HQ commit and either 6 September release (`comm -12` of the two changed-file lists was empty on both shops). Both merges were ordinary `--no-ff` merge commits — the minimal strategy that preserves both histories unaltered.

**Blast-radius proof (identical on both shops):**
```
git diff --stat <base>..<candidate>     -> 12 files changed, 501 insertions(+), 6 deletions(-)
diff <(files base..candidate) <(files 5e955a2c..4865c109)   -> IDENTICAL file set
git log --no-merges base..candidate     -> exactly 4865c109
```
`origin/main` `46b70eba → 702b8b60` and `origin/relaunch` `d7bdf7c2 → f95bd912`, both pushed as fast-forwards after re-fetching and asserting the remote had not moved.

## C. Release-gate results

| | LaLaDia `702b8b60` | Bionic `f95bd912` |
|---|---|---|
| Full suite (sqlite in-memory, `ADMIN_EMAIL`/`ADMIN_PHONE` production-shaped) | **1,341 passed, 1 skipped, 0 failed** (4,877 assertions, 387 s) | 1,340 passed, 1 skipped, **1 environment-only failure** → 6/6 pass with `SHOP_BRAND_KEY=bionic`; effective **1,341 / 1 / 0** |
| The Bionic env failure | — | `SiblingBrandLeakTest › core storefront pages carry no other brand name`: the test derives "my brand" from `config('shop.brand_key')` and **defaults to LaLaDia when the key is empty**, so a fresh clone with `.env.example` (`SHOP_BRAND_KEY=`) treats Bionic's own name as a leak. Fails identically on the untouched baseline `d7bdf7c2`; production `.env` carries `SHOP_BRAND_KEY=bionic` (verified on the server, `brandkey=1`). Not touched by the release |
| `npm run typecheck` | exit 0 | exit 0 |
| `npm run build` | built | built |
| `npm run verify:bundles` | storefront no React; admin 3/3 sentinels | same |
| Residue in the clones | `vendor/composer/*` regenerated (this repo tracks `vendor/`); rebuilt assets discarded; **nothing committed beyond the merge** | same |

## D. Backup evidence

| Shop | Code | Database | Rollback |
|---|---|---|---|
| LaLaDia | `~/laladia-code-before-2026-09-06-restore.tar.gz` **150 MB** | `~/laladia-db-before-2026-09-06-restore.sql.gz` **320 KB**, `Dump completed 2026-09-06 12:23:58`, **79 tables** | **`46b70eba`** (not the old `5e955a2c`) |
| Bionic | `~/bionic-code-before-2026-09-06-hq.tar.gz` **166 MB** (taken 12:01Z, after the other release — it captures `d7bdf7c2`, which is the correct point) | `~/bionic-db-before-2026-09-06-hq.sql.gz` **124 KB**, `Dump completed 2026-09-06 12:01:44`, **79 tables** | **`d7bdf7c2`** |

Credentials for `mysqldump` were read from each shop's `.env` into shell variables and never echoed.

## E. Deployment and migration state

**LaLaDia (12:26:29Z):** `~/laladia-src`: `git fetch` → `git merge --ff-only origin/main` (server `main` `46b70eba 702b8b60`) → guard `HEAD == 702b8b60` → `umask 022` → `git archive HEAD | tar -x -C ~/laladia.com` → `migrate --force` → **"Nothing to migrate"** (the HQ columns survived the overwrite; the other release's nine migrations were already applied) → `config:cache`, `route:cache`, `view:cache`, `cache:clear` (the other runbook's sitemap requirement), `queue:restart`. After: `pending=0`, **`assistant_routes=3`, `inv_low_scheduled=1`**, `public/index.php` 644.

**Bionic (12:33:30Z), docroot = repository:** guard chain — `HEAD == d7bdf7c2`, tracked modifications 0, untracked 0, **`git diff --name-only HEAD..origin/relaunch -- public/assets/ads` empty**, 12 incoming files — then `git merge --ff-only origin/relaunch` → `f95bd912` → `migrate --force` → **`2026_09_05_210000_add_hq_conversation_ref_and_low_stock_alert … 13.62ms DONE`** (Bionic's first HQ migration) → caches + `cache:clear` + `queue:restart`. After: `pending=0`, `assistant_routes=3`, `inv_low=1`, `public/assets/ads` still clean (11 tracked files, no diff). The "permanently dirty ads" condition described in older notes no longer exists: the directory is tracked and clean.

**External verification, both shops:** storefront, `/products`, `/blog`, `/faq`, product/landing pages and `/admin/login` all 200; the three assistant endpoints **401 without a key** on both; the other release's legacy redirects intact (`/product-category/dry-fish` → 301, `/home` → 301).

## F. Credential reconciliation

**LaLaDia — reused, not recreated:** HQ rows 4 `shop_webhook_secret`, 5 `shop_assistant_key`, 6 `shop_admin_token`, all `account_ref laladia-com`, `active`, payload present (64/64/51 chars). None rotated: all three still authenticate (`hq:commerce:probe laladia-com` → `assistant_products 200, admin_orders 200`). The stale duplicate rows 2–3 were already removed in docs/41.

**Bionic — new rows, `account_ref bionic-garden`:** _(filled in section K)_

HQ resolves every shop credential through `CommerceRegistry`/`IntegrationCredential` by `(provider, account_ref, status=active)`; nothing is hard-coded in config or env (the probe's "no key / no token" answers before the rows existed prove the lookup path).

## G. Webhook reconciliation (LaLaDia)

Inspected, not recreated: `webhooks` rows 3–8 (`order.created`, `order.status_changed`, `order.payment_updated`, `shipment.status_updated`, `customer.registered`, `inventory.low`), all `is_active = 1`, all → `https://hq.bordeguna.com/api/webhooks/shop/laladia-com`, one shared secret; rows 1–2 (example.com placeholders) still inactive. Exactly six intended types, no duplicates. During the 30-minute overwrite window the shop would have sent the **legacy** envelope (no delivery id) had any event fired; none did (HQ events stayed at 7), so no legacy-keyed rows exist.

`low_stock_alerted_at` state after restore: 1 stamped (`PIC-HIL-V2`, already delivered to HQ as event 3) and it is still the only variant at/below threshold — **no re-arm needed**; the emitter's schedule simply resumed.

## H. HQ data continuity (LaLaDia, before any new order)

`hq:facts:reconcile --store=laladia-com --hours=48` → **"checked 5 orders, inserted 0 missing"**. Events 7, pending 0, failed 0; `facts_orders` 5 (all `store_id 1`); customers 4; identities 17; merges 0; connector calls 0 failed. The three restored assistant reads answer 200 with the stored key (promotions, availability with `orderable`/`reason`/`available_stock`, orders-by-phone with no address fields).

## I. Real LaLaDia order through the restored path

_(filled in when placed)_

## J. Bionic credentials, subscriber and real order

_(filled in as the steps complete)_

## K–P. _(continued below as the milestone ran)_
