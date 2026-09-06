# 38 - Phase 1 Production Readiness (6 September 2026)

> **ACCEPTED by Joy, 6 Sep 2026, with two decisions that supersede the open questions below.**
> **P-1 = CONFIRMED, Option B**: the checkout stock refusal stays, `CheckoutPricingService` is not modified, and no separate checkout release is created. The four wording rules he set are recorded verbatim in `docs/39` section J.
> **B.6 = resolved by Step 0**: the SEO/canonical/404 and hilsa QA work is **already live**, so the LaLaDia release would carry the HQ commit alone. Section B.6's option (b) no longer applies. Verified production state, evidence and blast radius: **`docs/39-phase-1-step-0-production-state-2026-09-06.md`**.
> Section G's swap and disk figures in `docs/01` were also stale and are corrected there; the host has a 4 GB swapfile.

**This is a review document. Nothing in it has been executed.** No branch was merged, nothing was deployed, no production token was issued, no webhook subscriber was created, no checkout behaviour was changed, nothing Meta, payment or DNS was touched, and Phase 2 has not been started.

Everything below was read out of the actual branches today, not recalled from docs/35 or docs/36. Where an earlier document disagrees with the code, the code is written here and the disagreement is named. Two things earlier documents said are already out of date and are corrected in place (LaLaDia's `main` has moved; the ERP report-permission picture is narrower than "23 reports" implied).

## Verification performed today, 6 Sep 2026

| Check | Result |
|---|---|
| ERP branch `feature/hq-api` | `3fd4be0`, branched from `master` = `be052d0` — **merge-base equals master HEAD, so it fast-forwards** |
| LaLaDia branch `feature/hq-phase1-api` | `4865c109`, branched from `main` = `5e955a2c` — **merge-base equals main HEAD, so it fast-forwards** |
| Bionic `relaunch` | `b30b4b7c`, already contains LaLaDia `5e955a2c` — after the LaLaDia merge it needs exactly one new commit |
| ERP targeted suite (`tests/Feature/HqApi/HqApiTest.php`) | **7 passed, 66 assertions, run today** |
| LaLaDia targeted suite (`tests/Feature/HqPhase1ShopApiTest.php`) | **8 passed, 56 assertions, run today** |
| Full suites | **NOT run today.** Green when the branches were built (ERP 3,096 / LaLaDia 1,307). Re-running both is a merge gate, section L. |
| Production versions of the ERP and the two shops | **Not verifiable from this machine** (no shell on cPanel; the ERP VPS was not touched today). Confirming them is step 0 of section L. |

### Status vocabulary used throughout

- **READY** - code is complete, tested, and needs no decision; it still waits for an explicit go before it moves.
- **REQUIRES JOY** - a decision, a credential, a click, or an approval only you can give.
- **BLOCKED** - cannot proceed at all until something outside this work exists.
- **SAFE TO AUTOMATE** - I can do it unattended once you have said go, because it is reversible and touches nothing live on its own.

---

## A. ERP production change list (`bordeguna-erp`, branch `feature/hq-api`)

One commit, `3fd4be0`. **13 files, +791 / -5.** Status: **READY**, awaiting **REQUIRES JOY** review and go.

### A.1 Exactly what enters production

| File | Change | Risk to existing behaviour |
|---|---|---|
| `composer.json` / `composer.lock` | adds `laravel/sanctum ^4.3` | New dependency; no existing code path calls it |
| `config/sanctum.php` | new file; **`'routes' => false`** | Sanctum's own `/sanctum/csrf-cookie` route is deliberately never registered |
| `bootstrap/app.php` | registers `routes/api.php` in `withRouting()` | Adds an `/api` route group that did not exist; web routes untouched |
| `routes/api.php` | new; 12 routes under `/api/hq/v1` | New surface only |
| `app/Http/Middleware/HqApiContext.php` | new; tenant context from the token | Used only by the new routes |
| `app/Http/Controllers/Api/Hq/HqReadController.php` | new; 288 lines, all endpoints | New surface only |
| `app/Console/Commands/HqApiToken.php` | new; issues/revokes tokens | Console only, run by hand |
| `app/Models/User.php` | adds the `HasApiTokens` trait | Adds a `tokens()` relation; changes nothing existing |
| `app/Modules/Crm/Http/Controllers/TicketController.php` | `nextNumber()` **private → public** + an import reorder | Visibility only. No logic changed, so the CRM screen numbers tickets exactly as before |
| `database/migrations/2026_09_05_202000_create_personal_access_tokens_table.php` | new table | See A.4 |
| `database/migrations/2026_09_05_203000_add_external_ref_to_crm_tickets.php` | one column + one unique index | See A.4 |
| `tests/Feature/HqApi/HqApiTest.php` | 7 tests | Test-only |

**The only change to a file that production already executes is `TicketController::nextNumber()` becoming public**, plus two framework wiring lines (`bootstrap/app.php`, `User.php`). Nothing else in the ERP is edited: no screen, no Action, no posting rule, no seeder, no existing route.

### A.2 Read-first and least-privilege: confirmed, with three caveats you should see

**Confirmed read-first.** Twelve routes: eleven `GET`, one `POST` (`tickets`). The single write inserts a `crm_tickets` row with the CRM screen's own numbering and SLA clock, and replays safely on a repeated `idempotency_key` (unique per business). No Action, no journal, no stock movement, no approval decision is reachable.

**Confirmed least-privilege in three independent layers:**

1. **Issuance allow-list.** `HqApiToken::READ_ABILITIES` refuses any ability outside these thirteen: `inventory.view`, `inventory.reports.view`, `sales.view`, `sales.reports.view`, `sales.customers.view`, `purchasing.view`, `purchasing.reports.view`, `accounting.view`, `hrm.employees.view`, `hrm.attendance.view`, `crm.tickets.view`, `crm.tickets.manage`, `crm.contacts.view`. A token carrying `hrm.payroll.manage` or `accounting.journal.post` **cannot be minted by this command at all.**
2. **Per-business ability.** Every token also carries `hq:business:{id}` for exactly one business. `HqApiContext` compares that against the `{business}` segment in the URL and returns 403 otherwise, then enters `TenantScope` from the token — never from a caller-supplied header — so `BusinessScope` filters every query for the rest of the request. Proven by test: a token for business A gets 403 and no data on business B's overview, stock and customers.
3. **Per-endpoint ability.** Each method calls `requireAbility()` and returns 403 rather than a thinner answer. `overview` is the one composite: each tile is `null` unless its ability is present. Product cost (`avg_unit_cost`) appears only with `inventory.reports.view`.

**Caveat 1 — the token's ability list is the whole gate; the user's ERP roles are not re-checked.** `requireAbility()` calls `$request->user()->tokenCan($ability)`. If the `hq` user holds no roles at all (which is what I recommend), a token minted with `sales.reports.view` still reads sales reports. This is a deliberate, defensible design — the allow-list is the boundary and it excludes everything sensitive — but it means **the ability list you type at issuance time is the real security decision**, not the user's role. **REQUIRES JOY:** accept as-is (recommended for Phase 1) or ask for a follow-up commit that requires *both* the token ability and the user's own permission.

**Caveat 2 — `hq:api:token` does not check that the user belongs to the business.** It checks the user exists and the business exists. In practice you will attach the `hq` user to the businesses anyway (the `/businesses` endpoint does filter by real membership), so this only matters if a token is minted carelessly. Worth knowing, not worth blocking on.

**Caveat 3 — three of the thirteen allowed abilities unlock nothing today.** `sales.view`, `purchasing.view`, `hrm.employees.view`, `hrm.attendance.view` and `crm.contacts.view` are not used by any endpoint and match no report. Do not issue them; see section D.

### A.3 Payroll, salary, journals and approval decisions: confirmed unreachable

| Concern | Why it cannot be reached |
|---|---|
| Payroll runs, payslips, salary structures, salary figures | No endpoint exposes them. The only indirect route would be a report, and **every payroll report requires `hrm.payroll.manage`**, which the issuance allow-list refuses |
| Attendance rows and corrections | Same: **every attendance report requires `hrm.attendance.manage`**, refused by the allow-list. (The allow-list's `hrm.attendance.view` matches no report and no endpoint) |
| Journals, GL, trial balance, period close | No endpoint. No report definition in the registry serves them |
| Approval decisions | `approvals/pending` is read-only and returns only `id`, `type` (class basename), requester name, current stage and created-at. **There is no approve/reject path in the API at all** |
| Stock adjustments, PO approvals, user/role management | Not exposed |

**The complete list of registry reports an HQ token could ever run**, verified by reading every `permission()` in the registry (HRM base set + Accounting + Purchasing + Sales registrations):

| Report key | Permission required | Reachable by an HQ token? |
|---|---|---|
| `sales_by_brand` | `sales.reports.view` | Yes, if you grant that ability |
| `purchasing_expense_register` | `purchasing.reports.view` | Yes, if granted |
| `purchasing_standing_costs` | `purchasing.reports.view` | Yes, if granted |
| `accounting_budget_vs_actual` | `accounting.view` | Yes, if granted — **this is the one that reads journal-derived actuals; see D.2** |
| `payroll_*` (11 definitions incl. salary sheet, line detail, loan balance, settlement worksheet) | `hrm.payroll.manage` | **No — ability cannot be issued** |
| `attendance_*` (10 definitions incl. daily, monthly register, lateness, overtime) | `hrm.attendance.manage` | **No — ability cannot be issued** |

This corrects a looser earlier statement: it is not "the 23-report engine behind permissions", it is **four reports at most**, and only those you deliberately grant.

### A.4 Exact migration impact

Two migrations, both additive, both with working `down()`.

**`2026_09_05_202000_create_personal_access_tokens_table`**
```
CREATE TABLE personal_access_tokens (
  id, tokenable_type + tokenable_id (morphs, indexed), name TEXT,
  token VARCHAR(64) UNIQUE, abilities TEXT NULL,
  last_used_at NULL, expires_at NULL (indexed), timestamps
)
```
New, empty, referenced by nothing existing. `down()` drops it.

**`2026_09_05_203000_add_external_ref_to_crm_tickets`**
```
ALTER TABLE crm_tickets
  ADD external_ref VARCHAR(160) NULL AFTER created_by,
  ADD UNIQUE KEY crm_tickets_business_external_ref_unique (business_id, external_ref)
```
Nullable, so every existing row stays valid, and a MySQL unique index treats NULLs as distinct, so existing rows cannot collide. **`crm_tickets` held 0 rows on production as of the 5 Sep verification**, so this ALTER is effectively instantaneous — but confirm the count at deploy time rather than trusting that (`SELECT COUNT(*) FROM crm_tickets`). `down()` drops the index then the column.

**No data migration, no backfill, no seeder, no change to any existing column.**

### A.5 Exact endpoints and abilities

Base: `https://erp.srv1808022.hstgr.cloud/api/hq/v1` · group middleware `auth:sanctum` + `throttle:120,1` · scoped routes additionally `HqApiContext`.

| Method + path | Ability required | Returns |
|---|---|---|
| `GET /businesses` | any `hq:business:*` on the token | businesses the token names **and** the user belongs to |
| `GET /{business}/overview` | per tile: `sales.reports.view`, `inventory.reports.view`, `purchasing.reports.view`, `crm.tickets.view` | invoiced-this-month, invoices past due date, customer count, stock value, low-stock count, active products, open POs, pending-approval count, open/overdue tickets. Missing ability = `null` tile |
| `GET /{business}/stock` | `inventory.reports.view` | on-hand per product per warehouse (`filter.sku`, `filter.warehouse`, paginated, max 500/page) |
| `GET /{business}/stock/low` | `inventory.reports.view` | products under their own threshold |
| `GET /{business}/products` | `inventory.view` | active catalogue; `avg_unit_cost` **only** with `inventory.reports.view` |
| `GET /{business}/products/{sku}` | `inventory.view` | one product + on-hand |
| `GET /{business}/customers` | `sales.customers.view` | active customers (`filter.phone`) |
| `GET /{business}/customers/{id}` | `sales.customers.view` | one customer + last 20 invoices + last 20 tickets |
| `GET /{business}/tickets` | `crm.tickets.view` | tickets, open by default |
| `POST /{business}/tickets` | `crm.tickets.manage` | **the one write**; CRM numbering + SLA; replays on `idempotency_key` |
| `GET /{business}/approvals/pending` | any of the three `*.reports.view` | pending approval metadata, max 100 |
| `GET /{business}/reports` | — (filters by the token's own abilities) | the reports this token may run |
| `POST /{business}/reports/{key}/run` | the report definition's own permission | columns + rows, max 5,000, `truncated` flag |

Every scoped response echoes `X-Correlation-Id` and sets `X-Business-Id`.

### A.6 Safest merge / deploy / rollback sequence (ERP)

**Do not run any of this without an explicit go.** Steps 1-3 are **SAFE TO AUTOMATE** after your go; steps 4-6 touch production and I will run them only on your word, one at a time, reporting between each.

```
0. CONFIRM production HEAD:            ssh vps 'git -C /opt/bordeguna-erp rev-parse --short HEAD'   # expect be052d0
1. Local:  git checkout master && git pull --ff-only
2. Local:  php artisan test            # full suite must be green (expect ~3,096)
3. Local:  git merge --ff-only feature/hq-api && npx tsc --noEmit   # tsc unaffected but it is the standing rule
   (push only after your go; nothing deploys itself)
4. VPS:    BACKUP FIRST - section F.1, verify size and the "Dump completed" tail
5. VPS:    git fetch origin master
           git -C /opt/bordeguna-erp checkout --detach origin/master
           cd /opt/bordeguna-erp/deploy && docker compose build && docker compose up -d
           docker compose exec app php artisan migrate --force      # expect exactly 2 migrations
6. VERIFY: section H.1 before touching anything else
```

**Rollback (ERP)** — code and schema roll back independently:

```
Code only (the usual case):
  ssh vps 'git -C /opt/bordeguna-erp checkout --detach be052d0'
  cd /opt/bordeguna-erp/deploy && docker compose build && docker compose up -d
  # The two new tables/columns can stay: nothing on be052d0 reads them.

Schema too (only if a migration itself misbehaves):
  docker compose exec app php artisan migrate:rollback --step=2
  # drops personal_access_tokens (any issued token dies with it) and crm_tickets.external_ref

Data (only if something wrote wrongly):
  restore the pre-deploy dump per deploy/README.md "Restore"
```

There is no state to unwind beyond that: the API writes nothing until a token exists, and no token exists until you create one.

---

## B. LaLaDia production change list (`LaLaDia`, branch `feature/hq-phase1-api`)

One commit, `4865c109`. **12 files, +501 / -6.** Status: **READY**, awaiting **REQUIRES JOY** review, a blast-radius decision (B.6) and a go.

### B.1 Exactly what enters production

| File | Change |
|---|---|
| `app/Infrastructure/Webhook/WebhookService.php` | envelope gains 4 fields + 4 headers; `SCHEMA_VERSION = 1`; `storeKey()` helper |
| `app/Jobs/SendWebhookJob.php` | `tries` 3 → 5; `backoff` `[30,120,300]` → `[60,300,900,3600,3600]` |
| `app/Console/Commands/EmitInventoryLowWebhooks.php` | new hourly command |
| `routes/console.php` | schedules that command hourly, `withoutOverlapping()` |
| `app/Domains/Webhook/Controllers/AdminWebhookController.php` | `inventory.low` added to the selectable event list |
| `app/Http/Controllers/Assistant/AssistantController.php` | new; 3 read endpoints |
| `routes/public.php` | 3 routes added **inside the existing `throttle:30,1` + `chatbot.key` assistant group** |
| `app/Domains/Order/Requests/CheckoutRequest.php` | 2 new optional rules |
| `app/Domains/Order/Models/Order.php` | `conversation_ref` added to `$fillable` |
| `app/Domains/Product/Models/ProductVariant.php` | `low_stock_alerted_at` added to `$fillable` |
| `database/migrations/2026_09_05_210000_add_hq_conversation_ref_and_low_stock_alert.php` | 2 columns |
| `tests/Feature/HqPhase1ShopApiTest.php` | 8 tests |

### B.2 Webhook envelope changes: confirmed

Before: `{"event": …, "data": …}` with one header, `X-Shop-Signature`.

After: `{"event": …, "data": …, "id": <uuid>, "occurred_at": <ISO8601>, "schema_version": 1, "store": <key>}` plus headers `X-Shop-Event`, `X-Shop-Delivery` (= `id`), `X-Shop-Timestamp` (= `occurred_at`), `X-Shop-Store`.

- `event` and `data` keep their position, names and payloads. **A subscriber that ignores the new fields is unaffected.**
- The HMAC is still computed over the exact bytes transmitted (`hash_hmac('sha256', $body, $hook->secret)`), so signature verification is unchanged in method.
- The legacy `X-Laladia-Signature` header behaviour is untouched.
- **Subscribers today: zero** (docs/01, to be re-confirmed in the shop admin before deploy). So this change is observable by nobody until HQ subscribes.
- `store` = `config('shop.brand_key')` if set, else the host of `APP_URL`. **`SHOP_BRAND_KEY` defaults to empty in both repos**, so unless you set it, LaLaDia will send `laladia.com` and Bionic `bionic.garden`. HQ does not depend on this — it identifies the store from the `{store}` slug in the URL it was called on, never from the payload — so this is informational only. **REQUIRES JOY (optional):** set `SHOP_BRAND_KEY=laladia-com` / `bionic-garden` if you want the payload to match HQ's slugs.

Retry change: a receiver that is down now has ~1 h 45 m of retries (60 s, 5 m, 15 m, 1 h, 1 h) instead of 7.5 minutes. Duplicate deliveries are safe because HQ dedupes on the delivery id.

### B.3 New assistant endpoints: confirmed

All three sit inside the existing group, so they inherit `chatbot.key` (constant-time compare of `X-API-Key`) and `throttle:30,1`. Proven by test: without the key they are refused.

| Endpoint | Returns | Notes |
|---|---|---|
| `GET /api/v1/assistant/orders?phone=01XXXXXXXXX&limit=` | that phone's own orders: number, placed-at, order + payment status, method, grand total, source, items, first shipment | Phone normalised through `BdPhone`; 422 on a non-Bangladeshi shape; limit capped at 25; **no addresses returned** |
| `GET /api/v1/assistant/products/{slug}/availability` | per variant: `orderable`, `reason`, `available_stock`, `final_price`, `pre_order`; plus product `status` and `is_active` | `reason` ∈ `product_inactive`, `status:*`, `variant_inactive`, `stock_insufficient`, `ok`. **Reports both the status rule and the stock rule** — this is what makes P-1 decidable without code |
| `GET /api/v1/assistant/promotions` | public active coupons (code, type, value, min purchase, free delivery, ends-at) and active combos | Read-only, computed by existing services |

All three are read-only. Nothing computes a price for checkout, nothing writes.

**Minor note, not a defect:** `orders` carries a second `throttle:60,1`; the group's stricter `throttle:30,1` governs in practice.

### B.4 `source=assistant` + `conversation_ref`: confirmed

`CheckoutRequest` gains:
```php
'source'           => ['nullable', Rule::in(['assistant'])],
'conversation_ref' => ['nullable','string','max:64','regex:/^[A-Za-z0-9._:-]+$/','required_with:source'],
```
Both are optional. `OrderService` creates the order with `Order::create([...$data, …])` and both columns are `$fillable`, so the values persist when sent and stay null otherwise.

**The one real behaviour change on the public checkout:** previously `source` was not in the rules, so a browser sending it was ignored and the value never persisted. Now `source=assistant` is accepted and stored, and **any other value returns 422** where it used to be silently dropped. No storefront code sends `source`, so no real customer path changes — but that is the sentence to hold in mind when watching the first day of orders.

### B.5 Checkout behaviour impact: nothing else changes

- Pricing, stock refusal, coupons, shipping, idempotency (`checkout_token`), OTP, advance policy, Pathao derivation: **untouched**. No file in the pricing or stock path is in this diff.
- The existing `CheckoutPricingService` stock refusal is **still in force**. That is exactly the P-1 conflict, and this branch deliberately does not resolve it (section I).
- Migration: `orders.conversation_ref VARCHAR(64) NULL` + index, `product_variants.low_stock_alerted_at TIMESTAMP NULL`. Both nullable and additive; LaLaDia holds ~48 orders and a small variant table, so both ALTERs are instant. `down()` drops the index and both columns (losing only values HQ wrote).

### B.6 Safest merge / deploy / rollback sequence (LaLaDia)

**REQUIRES JOY first — the blast-radius question (P-4).** `main` is `5e955a2c` and carries work that is not part of this integration (SEO/canonical-host and 404 recovery, the hilsa landing QA section). I cannot see what is live on laladia.com from here. Merging the HQ branch into `main` and releasing `main` therefore ships that work too. Options:

- **(a) Confirm those commits are already live** — then this release carries the HQ change alone. Cleanest. Requires you to check the deployed version.
- **(b) They are not live** — then either release them first as their own step and the HQ change after (my recommendation, smallest blast radius), or accept one combined release with both.

Once decided, the release follows the shop's own runbook conventions verbatim (`docs/DEPLOY-2026-09-01.md`), including the traps that have bitten before:

```
1. Local:  git checkout main && git pull --ff-only
2. Local:  php artisan test        # full suite (expect ~1,307) + Playwright where a page changed
3. Local:  git merge --ff-only feature/hq-phase1-api      # push only on your go
4. BACKUP: section F.2 (code tarball AND database dump, both verified)
5. Server (cPanel Terminal — HTTPS to github fails there, only the SSH alias works):
       cd ~/laladia-src && git fetch origin && git merge --ff-only origin/main && git log --oneline -1
       umask 022                                  # ⚠ NOT optional - a 077 umask once 403'd the whole site
       git archive HEAD | tar -x -C ~/laladia.com
       cd ~/laladia.com
       php artisan migrate --force                # expect exactly 1 migration
       php artisan config:cache
       php artisan route:cache                    # ⚠ NOT optional - a stale route cache 500'd the admin on 30 Aug
       php artisan view:cache
       php artisan queue:restart
6. VERIFY: section H.2 - and place one real test order before anything else
```

**Rollback (LaLaDia):**
```
Code:   cd ~/laladia-src && git checkout <previous sha> && umask 022 && git archive HEAD | tar -x -C ~/laladia.com
        cd ~/laladia.com && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart
        (or extract the pre-deploy tarball from section F.2 over the docroot)
Schema: php artisan migrate:rollback --step=1     # drops the two columns; storefront never reads them
Data:   restore the pre-deploy SQL dump (phpMyAdmin / cPanel), only if an order write went wrong
Fast mitigation without any rollback: deactivate the webhook subscriber rows in the shop admin,
and/or `php artisan schedule:` the hourly command off by reverting routes/console.php.
```

---

## C. Bionic merge plan (merge path only — **do not deploy**)

Status: **READY to plan, deliberately not to execute.** Bionic must not move until LaLaDia is merged, deployed and verified.

**Current position, verified today:** Bionic `relaunch` = `b30b4b7c`, which already merged LaLaDia `5e955a2c`. So after LaLaDia merges the HQ commit into `main`, Bionic's incoming diff is **exactly that one commit** — no unrelated backlog rides along.

```
# Only after LaLaDia is live and verified, and only on your go:
cd D:\WORK\BIONIC\Bionic-Garden-v2
git checkout relaunch
git fetch upstream                                  # upstream = rhjoyofficial/LaLaDia
git diff --name-only HEAD..upstream/main            # review; expect only the 12 HQ files
git merge upstream/main                             # ordinary merge, as every previous sync
php artisan test                                    # Bionic's own suite must be green
# push on your go; deploy is a SEPARATE later decision
```

**Deploy traps to respect when that day comes** (from the shop's own runbook — recorded here so they are not rediscovered): `~/bionic-garden-app` **is both the docroot and the git repo**, so a release is a fast-forward merge in place, and its tree is permanently dirty with live ad images that exist nowhere in git:

```
cd ~/bionic-garden-app
git fetch origin
git diff --name-only HEAD..origin/relaunch -- public/assets/ads     # MUST be empty
git merge --ff-only origin/relaunch
php artisan migrate --force                                          # the same 2 columns
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Bionic has had one order in its life, so its risk is lower than LaLaDia's — but it shares the checkout code, so it inherits the same P-1 answer and the same first-order verification.

---

## D. Token and credential plan (**nothing issued yet**)

Status: **REQUIRES JOY** — every item here is a production credential.

### D.1 ERP tokens

```
# On the ERP, after deploy:
#   1. create a user hq@bordeguna.com  (no roles, active) - via the ERP's own user screen
#   2. attach it to the businesses it should list (Settings -> Users)
#   3. per business:
docker compose exec app php artisan hq:api:token hq@bordeguna.com 4 \
  --abilities=inventory.reports.view,sales.reports.view,crm.tickets.view
# token is printed ONCE; paste straight into HQ -> integration_credentials
```

| HQ business slug | ERP business id | Recommended abilities | Why |
|---|---|---|---|
| `bor-de-guna` | 4 | `inventory.reports.view`, `sales.reports.view`, `crm.tickets.view` | Exactly what `erp.poll_overview` and `erp.poll_low_stock` consume today |
| `laladia` | 1 | — **defer** | Business 1 holds no operating data; a token would read empty |
| `bionic` | 2 | — **defer** | as above |
| `azmion` | 3 | — **defer** | as above |

Credential row in HQ: provider `erp_api_token`, `account_ref` = the **HQ business slug** (not the ERP id).

**D.2 Two abilities to withhold deliberately:**
- `accounting.view` — unlocks `accounting_budget_vs_actual`, whose actuals come from journal lines. Not needed by anything HQ runs today. Grant it only when a finance screen in HQ actually needs it (this is docs/35 **P-2**, and withholding it is the conservative reading).
- `crm.tickets.manage` — the only write in the API. Grant it when HQ genuinely opens tickets (Phase 3/4), not now.
- Do not issue `sales.view`, `purchasing.view`, `hrm.employees.view`, `hrm.attendance.view`, `crm.contacts.view` at all: **they unlock nothing.**

**Rotation:** issue new → paste into HQ → `hq:api:token … --revoke` for the old one. Suggested cadence: quarterly, or immediately if a token is ever pasted anywhere but HQ.

### D.3 Shop credentials

| Credential | HQ provider | `account_ref` | Source | Recommendation |
|---|---|---|---|---|
| Assistant read key | `shop_assistant_key` | `laladia-com` / `bionic-garden` | the shop's existing `CHATBOT_API_KEY` | **Note the constraint:** this key is shared with the n8n Messenger bot. Rotating it for HQ rotates it for the bot too. Phase 1 can reuse it; a per-consumer key is a future shop change, not a Phase 1 blocker |
| Admin token | `shop_admin_token` | same | Sanctum PAT of a new `hq` admin user | **Defer.** No Phase 1 code path needs it — order *creation* is Phase 4. If you prefer to create it now, give the role only `order.view`, `customer.view`, `shipment.view` |
| Webhook secret | `shop_webhook_secret` | same | generated when you create the subscriber row | One per store; HQ verifies every delivery against it |

---

## E. Webhook setup plan (**nothing created yet**)

Status: **REQUIRES JOY** (production rows), sequencing **READY**.

**Endpoints HQ already serves** (deployed, currently answering 503 "webhook not configured for this store" because no secret exists — verified at the 5 Sep release):

```
https://hq.bordeguna.com/api/webhooks/shop/laladia-com
https://hq.bordeguna.com/api/webhooks/shop/bionic-garden
```

**Recommended order — shadow first, widen later:**

1. Create the subscriber for **`order.created` only**, with a fresh secret; store the same secret in HQ as `shop_webhook_secret` / `account_ref=laladia-com`.
2. Place or wait for one real order. Confirm the HQ event row, then `facts_orders`, then the customer identity row.
3. Compare `facts_orders` against the shop's own admin order list for a few days (docs/35 step 4 calls this the shadow week).
4. Then add `order.status_changed`, `order.payment_updated`, `shipment.status_updated`, `customer.registered`, and last `inventory.low`.
5. Repeat for Bionic only after its own deploy.

**⚠ A verified trap in the `inventory.low` rollout, found reading the code today.** `EmitInventoryLowWebhooks` stamps `low_stock_alerted_at` **whether or not any subscriber exists** (with zero subscribers, `WebhookService::dispatch` simply loops over nothing). So if the hourly command runs before HQ is subscribed — which it will, from the first hour after deploy — every variant that is *already* below threshold has its crossing consumed silently, and HQ will never hear about those variants until they climb back above the threshold and fall again. **Mitigation when you switch the subscriber on:**

```sql
UPDATE product_variants SET low_stock_alerted_at = NULL;   -- re-arm every variant, once
```
Run it once, immediately after the `inventory.low` subscriber is active, and expect one burst of alerts for genuinely-low variants. (Or accept the gap knowingly.)

---

## F. Backup-before-deploy checklist

Status: **SAFE TO AUTOMATE** on your go for F.1; **REQUIRES JOY** for F.2/F.3 (browser-only cPanel access).

### F.1 ERP (VPS)
```
ssh vps
cd /opt/bordeguna-erp/deploy
docker compose exec -T db mysqldump -uroot -p"$DB_ROOT_PASSWORD" bordeguna_erp \
  | gzip > /root/predeploy-backup-20260906-hqapi.sql.gz
ls -lh /root/predeploy-backup-20260906-hqapi.sql.gz          # ⚠ a wrong service name writes a 20-byte
zcat /root/predeploy-backup-20260906-hqapi.sql.gz | tail -3  #   EMPTY gzip that still passes `gzip -t`
                                                             #   - verify SIZE and "Dump completed"
ls -la /root/backups/bordeguna-erp/daily | tail -3            # nightly backup is also fresh
docker ps                                                     # all stacks up before we start
```
Also record: `git -C /opt/bordeguna-erp rev-parse --short HEAD` (the rollback target) and `SELECT COUNT(*) FROM crm_tickets`.

### F.2 LaLaDia (cPanel)
```
cd ~ && tar -czf laladia-code-before-2026-09-06.tar.gz laladia.com && ls -lh laladia-code-before-*.tar.gz
# database: export sangramn_laladia_db via phpMyAdmin / cPanel Backup, download it OFF the account,
# and check the file size before continuing (a truncated export is worse than none)
cd ~/laladia-src && git log --oneline -1        # record the rollback target
```

### F.3 Bionic (when its day comes)
Same shape: `tar -czf bionic-code-before-<date>.tar.gz bionic-garden-app`, database export of `sangramn_bgarden`, plus the `public/assets/ads` diff check from section C.

---

## G. Rollback plan per repository

| Repo | Code rollback | Schema rollback | Data rollback | Blast radius if it goes wrong |
|---|---|---|---|---|
| `bordeguna-erp` | detach to `be052d0`, rebuild, `up -d` | `migrate:rollback --step=2` (optional; the new objects are inert on the old code) | restore the F.1 dump | **Highest.** This host runs payroll and the books. The change adds surface rather than altering behaviour, but the rollback must still be rehearsed mentally before step 5 |
| `LaLaDia` | re-extract the previous commit (or the F.2 tarball) over the docroot + re-cache + `queue:restart` | `migrate:rollback --step=1` | restore the F.2 SQL dump | **Live storefront.** Mitigation short of rollback: deactivate the webhook subscriber rows |
| `Bionic.garden` | `git reset --hard <sha>` in `~/bionic-garden-app` (⚠ check `public/assets/ads` first) + re-cache | `migrate:rollback --step=1` | restore its own dump | Lower (one order in its life), same code path |
| `BordeGuna-HeadQuarter` | **no change in this step.** HQ production stays `6bb9f06` | — | — | None. `./deploy/rollback.sh` exists (previous release `4fd857a`) if ever needed |

---

## H. Post-deploy verification checklist

### H.1 ERP (immediately after step 5)
```
docker compose exec app php artisan migrate:status | tail -5     # the 2 new migrations show Ran
docker compose exec app php artisan route:list | grep hq         # 12 routes
curl -s -o /dev/null -w '%{http_code}\n' https://erp.srv1808022.hstgr.cloud/api/hq/v1/4/overview   # 401
curl -s -o /dev/null -w '%{http_code}\n' https://erp.srv1808022.hstgr.cloud/                        # 200
docker compose exec app php artisan tinker --execute="echo \Illuminate\Support\Facades\DB::table('crm_tickets')->count();"
docker compose logs --tail=30 app | grep -i error                # expect nothing
docker ps                                                        # all six ERP containers + the four other stacks
curl -s -o /dev/null -w '%{http_code}\n' https://n8n-vb99.srv1808022.hstgr.cloud    # neighbours unharmed
```
Then in a browser as a normal user: open **CRM → Tickets** and create one ticket. This is the only existing screen whose code was touched (`nextNumber()` visibility) and the numbering must be unchanged.

After a token exists: `GET /api/hq/v1/businesses` with it (200), the same call for a business the token does not name (403), and `GET /{business}/overview` (200, `X-Business-Id` and `X-Correlation-Id` present).

### H.2 LaLaDia (immediately after step 5)
```
php artisan migrate:status | tail -3          # 1 new migration Ran
php artisan route:list | grep assistant       # the 3 new routes present
curl -si https://laladia.com/api/v1/assistant/promotions | head -1                    # 401 without the key
curl -si -H "X-API-Key: <key>" https://laladia.com/api/v1/assistant/promotions | head -1   # 200
curl -si -H "X-API-Key: <key>" "https://laladia.com/api/v1/assistant/orders?phone=<a real one>" | head -1
curl -si https://laladia.com/api/v1/assistant/products/<slug>/availability | head -1  # 401 without the key
```
Then, in this order, in a browser: **(1) place one real end-to-end order on the storefront** — this is the single most important check of the whole release; **(2)** confirm the admin panel loads (route cache); **(3)** confirm the order appears with `source` null and `conversation_ref` null; **(4)** the admin webhook screen lists `inventory.low` as a selectable event.

Once the HQ subscriber exists: send a test delivery, then confirm in HQ that the event row carries the delivery id, that `facts_orders` gained the row, and that the identity resolved.

### H.3 HQ (no deploy, but confirm it sees the new world)
```
ssh vps 'docker exec bordeguna-hq-app-1 php artisan hq:erp:probe'        # stops saying "no credential"
ssh vps 'docker exec bordeguna-hq-app-1 php artisan hq:commerce:probe'
ssh vps 'docker exec bordeguna-hq-app-1 php artisan hq:facts:reconcile --store=laladia-com'
curl -s https://hq.bordeguna.com/api/health
```

---

## I. P-1 recommendation

**The conflict, stated exactly.**

- **The business policy you set (Q9, 5 Sep):** an inactive product is unavailable; otherwise a product is always treated as in stock. Availability follows **product status**, never quantity.
- **What the shop's code actually does:** `CheckoutPricingService` refuses an order line when the variant's sellable stock is insufficient ("is currently out of stock", "Insufficient stock for …"), and the product also carries its own `status` enum including `out_of_stock`. **Both rules are live, and the stock one is the one that can reject a real order at the last step.**
- **Why it matters:** an assistant that reads availability from status alone can tell a customer "yes, available", take the whole conversation to checkout, and be refused there. The customer experiences it as the shop changing its mind.

**Recommendation: Option B — keep the checkout exactly as it is, and let the AI phrase availability honestly.** Do not change checkout now.

Reasons, in order of weight:

1. **Removing the stock check lets the shop sell what it does not have.** With COD as the dominant payment method, an oversell is not a refund problem, it is a courier cost, a cancelled delivery, and a customer who was promised something. The current refusal is a safety rail that has been working.
2. **The safer option is reversible; the risky one is not.** Option B is a wording/policy choice inside HQ (`commerce.availability_rule`) that can be changed in a minute. Changing checkout is a code change to the single most important path in a live shop, on a branch that has nothing else to do with it.
3. **The work to support Option B is already done and shipping in this very branch.** `GET /assistant/products/{slug}/availability` returns *both* facts: the merchant's `status` and the concrete `reason` (`ok`, `stock_insufficient`, `status:*`, `product_inactive`, `variant_inactive`), plus `available_stock`. So HQ can say "available — we confirm the exact quantity at checkout" for `stock_insufficient`, and a flat "not available" for the status cases, with **no shop code change at all**.
4. It keeps your stated policy intact where it belongs: **status decides what the shop offers; checkout decides what the shop can actually deliver today.** That is a defensible sentence to a customer, and it is the sentence the AI will be taught.

**What I am NOT doing:** not changing `CheckoutPricingService`, not changing the availability endpoint, not setting an HQ policy row. If you prefer Option A (checkout stops refusing on quantity for active products), that is a separate, deliberate shop release with its own oversell-handling plan, and it should not ride along with this integration.

---

## J. Risks

| # | Risk | Likelihood / impact | Handling |
|---|---|---|---|
| J-1 | The ERP is the payroll and accounting system; any deploy there carries the estate's least replaceable data | Low / severe | Additive change only, no existing logic touched; backup verified first; deployed alone, not alongside anything else; rollback rehearsed (G) |
| J-2 | Token abilities, not user roles, are the sole gate on API reads | Certain / moderate | The issuance allow-list refuses everything sensitive; issue the three abilities in D.1 and nothing more. Optional hardening commit offered (A.2 caveat 1) |
| J-3 | Granting `accounting.view` would expose journal-derived actuals via one report | Only if granted / moderate | Withhold it (D.2) |
| J-4 | LaLaDia `main` carries undeployed unrelated work; releasing `main` ships it too | Certain unless checked / moderate | B.6 decision (a) or (b) before any merge |
| J-5 | `source=assistant` can be claimed by any caller of the public checkout | Low / low | Attribution only; grants no privilege, changes no price. Worth knowing before trusting `source` as evidence in a dispute |
| J-6 | The assistant `X-API-Key` is shared with the live n8n Messenger bot | Certain / low | Reuse in Phase 1; rotating it breaks the bot, so rotate both together or add a per-consumer key later |
| J-7 | `inventory.low` consumes the first crossing before HQ subscribes | **Certain** / moderate | The one-line re-arm in section E, run once after subscribing |
| J-8 | Shop queue is drained once a minute by the cPanel scheduler; a deploy mid-queue can drop in-flight jobs | Moderate / low | Deploy at a quiet hour; `queue:restart` is already in the runbook; HQ's nightly `hq:facts:reconcile` closes any gap |
| J-9 | Webhook envelope change could break an existing subscriber | Very low / moderate | Subscribers are believed to be zero — **confirm in the shop admin before deploy**; the change is additive and the signature method is unchanged |
| J-10 | Every backup on the VPS is on the same disk as the data | Certain / severe if the VPS is lost | Known and documented (ADR-014). Not introduced by this work; do not let this release be the moment it is discovered |
| J-11 | Bionic's live ad images exist only on the server | Certain / moderate | The `public/assets/ads` diff check in section C, before any merge lands there |
| J-12 | ERP API throttle is 120 req/min per token | Low / low | HQ polls hourly; nowhere near it. Worth remembering when Phase 2 agents start asking questions |

---

## K. What requires your explicit approval

Nothing below happens without you saying so, each one separately:

1. **Merging** `feature/hq-api` into ERP `master`, and **deploying** the ERP (backup first).
2. **The B.6 decision** on LaLaDia's undeployed `main` work, then **merging** `feature/hq-phase1-api` and **deploying** LaLaDia.
3. **Merging** into Bionic, and — as a separate decision later — **deploying** Bionic.
4. **Creating the `hq` ERP user** and **issuing tokens** (which abilities, which businesses).
5. **Creating the `hq` shop admin user / token** if you want it now rather than at Phase 4.
6. **Creating webhook subscriber rows** and their secrets on either shop.
7. **The `inventory.low` re-arm SQL** (section E) once subscribed.
8. **P-1**: confirming Option B, or choosing Option A as its own separate release.
9. **Optionally** setting `SHOP_BRAND_KEY` on either shop.
10. Any decision to run the full test suites on your machines vs mine, and when.

## L. Exact order of operations

Each step ends with a report back to you. Nothing starts before the step above it is verified.

| # | Step | Status | Who |
|---|---|---|---|
| 0 | Confirm the three production versions: ERP HEAD on the VPS, laladia.com's deployed commit, bionic.garden's deployed commit | REQUIRES JOY (shops) / SAFE TO AUTOMATE (ERP, read-only) | mixed |
| 1 | Decide **B.6** (LaLaDia blast radius) and **P-1** (record Option B) | REQUIRES JOY | Joy |
| 2 | Run both full suites locally (ERP ~3,096, LaLaDia ~1,307) + `npx tsc --noEmit` on the ERP | SAFE TO AUTOMATE | me, on your go |
| 3 | **ERP first, alone.** Backup (F.1) → merge → deploy → migrate → verify (H.1) | REQUIRES JOY to start | me, step by step, reporting between each |
| 4 | Create the ERP `hq` user, issue the business-4 token with the three abilities from D.1, paste into HQ, run `hq:erp:probe` | REQUIRES JOY | Joy (I can stand by) |
| 5 | Watch the ERP for one working day: logs, `/health`, the CRM tickets screen, the nightly scheduler | SAFE TO AUTOMATE | me |
| 6 | **LaLaDia.** Backup (F.2) → merge → release → migrate → verify (H.2), **including one real end-to-end order** | REQUIRES JOY to start | Joy on the server; me preparing and verifying |
| 7 | Create the LaLaDia webhook subscriber for `order.created` only + the secret in HQ; watch one real order flow into `facts_orders` | REQUIRES JOY | Joy |
| 8 | Shadow-compare `facts_orders` against the shop's admin order list for a few days | SAFE TO AUTOMATE | me |
| 9 | Widen to the remaining five events; then `inventory.low` **plus the re-arm SQL** (E) | REQUIRES JOY | Joy |
| 10 | **Bionic merge only** (section C), suite green, pushed; deploy remains a separate later decision | REQUIRES JOY to start | me, then Joy for deploy |
| 11 | Econic / WooCommerce adapter | **BLOCKED** — no cPanel account, no REST keys, and Q6b undecided | — |
| 12 | Anything Meta beyond development mode (privacy pages can be drafted safely; App Review cannot start) | **BLOCKED** — the boss's official Meta account does not exist yet (Q12b) | — |
| 13 | Phase 2 (Command Center) | **NOT STARTED, deliberately** — begins only when you say Phase 1 is closed, and with a design document first | — |

---

**Stop point.** This document is the deliverable. No merge, no deploy, no token, no subscriber, no checkout change, no Meta, no payments, no DNS, no Phase 2, no agents. The next action is yours: answer B.6 and P-1, and say which of the numbered steps in section L may begin.
