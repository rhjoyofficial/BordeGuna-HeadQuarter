# 40 - ERP Phase 1 Integration Complete (6 September 2026)

The production record of the ERP half of Phase 1: the read-first HQ API released to the live Bor de Guna ERP, a scoped integration credential issued, and HQ reading the ERP for the first time. Authorised by Joy on 6 Sep 2026 as one end-to-end milestone with a hard stop before any shop work. Every figure below was read from the live systems as the step ran, not recalled.

**Scope respected.** LaLaDia, Bionic, Econic, Meta, Google, TikTok, n8n, payments, DNS, checkout behaviour, Phase 2, AI agents and model calls were not touched. No token or secret value appears in this document or in any chat transcript: the token travelled from the ERP's console into HQ's encrypted store through one process environment and was never written to stdout or disk.

## A. Released commit

**ERP `master` = `17d595c9e42d8c3057e7deb89d5c149b503fabf3`** (`17d595c`), a linear history of two commits on top of the previous production commit:

| Commit | What |
|---|---|
| `3fd4be0` | The HQ read-first API behind Sanctum tokens scoped to one business (the Phase 1 branch, fast-forwarded — no merge commit, no squash, no rebase) |
| `17d595c` | Test-only: restores `use Illuminate\Support\Facades\DB;` in `tests/Feature/Accounting/MasterExpenseChartTest.php` (see P-1 below) |

Release diff against production: **14 files, +792 / -6.** The only pre-existing files the application executes that changed: `bootstrap/app.php` (registers `routes/api.php`), `app/Models/User.php` (adds the `HasApiTokens` trait) and `TicketController::nextNumber()` (private → public, logic untouched). Everything else is new surface or test.

## B. Previous production commit

**`be052d0c0f850a4e0a7f4c9121e4482bf4171203`** (`be052d0`) — "Other income: the document 4900 never had", 5 Sep 2026. Re-confirmed on the VPS immediately before the backup and again immediately before the deploy: detached HEAD, no modified tracked files.

## C. Push result

```
To github-personal:rhjoyofficial/bordeguna-erp.git
   be052d0..17d595c  master -> master
origin/master = 17d595c9e42d8c3057e7deb89d5c149b503fabf3
```

Pre-push gates, all run on the merged tree: full suite **3,096 passed / 14,823 assertions** (169 s, 8 processes), `npx tsc --noEmit` exit 0, working tree clean, release diff contained only the intended 14 files. **No CI ran on this push and none was expected**: both workflows trigger on `develop`/`main`, and this repository's branch is `master`.

## D. Backup result

| | |
|---|---|
| File | `/root/predeploy-backup-20260906-hqapi.sql.gz` |
| Taken | 2026-09-06 07:30:37 → 07:30:39 UTC, production HEAD `be052d0` at the time |
| Method | `mysqldump --single-transaction --routines --triggers` via the `db` service, password through `MYSQL_PWD`, never on a command line |
| Size | **129 KB** (consistent with the 124–127 KB nightly dumps) |
| Integrity | `gzip -t` OK; final line `-- Dump completed on 2026-09-06  7:30:39` |
| Completeness | **202 `CREATE TABLE` statements = 202 tables live** in `information_schema`; `employees` INSERT present |
| Nightly backups | fresh: `erp-20260906-010000.sql.gz` (01:00 UTC, 127 KB) |

## E. Migration result

Guard held: the deploy script refused to migrate unless exactly two migrations were pending, and exactly two were.

```
2026_09_05_202000_create_personal_access_tokens_table ........ 143.42ms DONE   [batch 84] Ran
2026_09_05_203000_add_external_ref_to_crm_tickets ............ 213.96ms DONE   [batch 84] Ran
pending after: 0
```

`crm_tickets` held **0 rows** at the moment of the ALTER (confirmed), so the unique index was added to an empty table.

## F. Deployment result

Timeline (UTC): 07:32:17 fetch → 07:32:20 `checkout --detach origin/master` = `17d595c` → `docker compose up -d --build` on the `bordeguna-erp` project only → 07:33:00 app ready → 07:33:04 migrate → **07:33:06 DEPLOY_DONE**. Total 49 seconds. Containers recreated: `app`, `worker`, `scheduler` (and `web` restarted shortly after); `db` untouched (up 5 weeks, healthy); `backup` untouched. No other compose project or container on the host was restarted. `laravel/sanctum v4.3.3` present in the built image; `/sanctum/csrf-cookie` is not a registered route (`sanctum.routes=false`).

**Live ERP commit: `17d595c`.**

## G. Verification result (docs/38 H.1, all run)

| Check | Result |
|---|---|
| Both migrations `Ran`, 0 pending | ✅ |
| HQ API routes registered | ✅ **13** — 11 `GET|HEAD` + 2 `POST` (see P-2: docs/38 said 12) |
| Unauthenticated API with `Accept: application/json` | ✅ **401** `{"message":"Unauthenticated."}` (`/4/overview` and `/businesses`) |
| Unauthenticated API without a JSON accept | 302 → `/login` — Laravel's standard non-JSON redirect; HQ's connector always sends `acceptJson()`, so it sees the 401 (P-3) |
| ERP root / login / `/up` | ✅ 302 → `/login`, 200, 200 |
| All six ERP containers | ✅ up; `db` healthy |
| Application log since deploy (app, worker, scheduler) | ✅ **no error, exception, fatal or warning lines** |
| `failed_jobs` / queued `jobs` | ✅ 0 / 0; worker and scheduler restarted clean, scheduler ticking |
| n8n | ✅ 200 |
| Traefik | ✅ up 2 months; ERP and HQ both served over HTTPS through it |
| HQ `/api/health` | ✅ all `ok` (db 8 ms, redis, 1 Horizon supervisor, scheduler tick 8 s) |
| Nightly ERP backup | ✅ fresh at 01:00 UTC |
| **CRM → Tickets in a real browser** (logged in as Joy) | ✅ page renders, list empty before, form works |
| **One real ticket created via the screen** | ✅ **`TK-2026-27-0001`** — subject "HQ Phase 1 release check - ticket numbering after 17d595c", walk-in caller "HQ integration check", complaint / normal, due 2026-09-09 13:37 (72 h SLA), toast "Ticket opened; the clock is running." Row confirmed: `business_id 4`, `external_ref NULL`, `created_by 16`. **Safe to close.** |
| **Ticket numbering unchanged** | ✅ `TK-{fiscal 2026-27}-{0001}`, the CRM's own format, first ticket in the system |
| Browser console (tickets page, after reload; dashboard) | ✅ no errors, no warnings |
| Dashboard | ✅ renders; figures consistent (expenses 4,900; pending approvals 1) |

**Unreachability, verified three ways:** (1) the route table contains no payroll, salary, journal, approval-decision, stock-adjustment, PO-approval, user or role path — only the 13 listed; (2) with the real token, `POST /4/reports/payroll_salary_sheet/run` → **403 "Token lacks ability hrm.payroll.manage"** and `POST /4/reports/accounting_budget_vs_actual/run` → **403 "Token lacks ability accounting.view"**; (3) `GET /4/reports` lists exactly `["sales_by_brand"]` — no `payroll_*`, `attendance_*` or `accounting_*` key is visible to this token. `approvals/pending` returns metadata only (id, type, requester, stage, created-at); there is no decide route.

## H. HQ user created

`hq@bordeguna.com` — ERP user **id 19**, name "HQ Integration", **active, not super-admin, no roles, no direct permissions**, attached to **business 4 only** (`business_user`). Password is a random 48-character string hashed at creation and known to nobody; the account authenticates by Sanctum token only. No ordinary role was needed for token authentication, so none was given.

## I. Token scope

Exactly **one** token, ERP `personal_access_tokens` id 1, name `hq:business:4`, `expires_at` null, issued 2026-09-06 13:38:35 (+06):

```
inventory.reports.view · sales.reports.view · crm.tickets.view · hq:business:4
```

**Not issued**, per the security decision: `accounting.view`, `crm.tickets.manage`, `sales.view`, `purchasing.view`, `hrm.employees.view`, `hrm.attendance.view`, `crm.contacts.view`. `last_used_at` populated by the probe at 07:39:21 UTC.

## J. HQ credential stored

`integration_credentials` id 1 on HQ: provider **`erp_api_token`**, account_ref **`bor-de-guna`**, business_id 1 (HQ's Bor de Guna, `erp_business_id 4`), credential_type `token`, status `active`, `payload` encrypted with APP_KEY (verified present, 50 characters, never displayed), `scopes` mirrors the four abilities, `meta` records issuance on the ERP for docs/40. **Exactly one row** for this provider/account — no duplicate. Total credentials on HQ: 1.

## K. HQ probe result

```
hq:erp:probe bor-de-guna --overview
[bor-de-guna] {"ok":true,"business_id":4,"visible_businesses":1,"ms":120}
```

The previous "No business has an active erp_api_token credential yet" state is gone. A second probe during observation answered `ok` in 51 ms. The overview it fetched (aggregate figures only): sales invoiced this month 0, invoices past due 0, customers 1; inventory stock value 0, low-stock count 0, active products 118; purchasing **null** (ability deliberately withheld); approvals pending 1; tickets open 1 (the verification ticket), overdue 0.

## L. Polling result

Both tools executed through `ToolRegistry` — the same `execute()` path `RunScheduledJob` uses — with a `ToolContext` for business `bor-de-guna`, directly rather than via a `scheduled_jobs` row, so nothing is left scheduled behind (scheduling the hourly polls is a configuration decision for the next milestone).

```
erp.poll_overview  => {"status":"succeeded","output":{"business":"bor-de-guna","kind":"overview","as_of":"2026-09-06T13:39:16+06:00"}}
erp.poll_low_stock => {"status":"succeeded","output":{"business":"bor-de-guna","kind":"low_stock","as_of":"2026-09-06T07:39:17+00:00","items":0}}
```

Effects: `facts_erp_snapshots` 0 → **2** (business 1, kinds `overview` and `low_stock`); `connector_calls` **5** rows for `erp:bor-de-guna` (`businesses`, `overview` ×3, `lowStock`), all `succeeded`, 51–120 ms; **`ai_runs` = 0 and `tool_calls` = 0 throughout** — non-AI operations touched neither. The snapshot payload carries the same keys as the API response and nothing more.

## M. Cross-business security result

All with the single business-4 token, from inside the HQ container; denied responses carried **only** `{"error":{"code","message"}}` and no data:

| Request | Result |
|---|---|
| `GET /4/overview` | **200**, `X-Correlation-Id` present, `X-Business-Id: 4` |
| `GET /1/overview`, `/2/overview`, `/3/overview` | **403** "This token is not issued for that business." |
| `GET /1/stock/low`, `/1/tickets` | **403**, same |
| `GET /businesses` | 200, lists exactly one business |
| `GET /4/stock`, `/4/stock/low` (inventory.reports.view) | 200 |
| `GET /4/tickets` (crm.tickets.view) | 200 |
| `GET /4/approvals/pending` | 200, metadata only |
| `GET /4/products` (needs inventory.view) | **403** "Token lacks ability inventory.view." |
| `GET /4/customers` (needs sales.customers.view) | **403** "Token lacks ability sales.customers.view." |
| supplied `X-Correlation-Id: hq-milestone-check-0001` | echoed back |
| no token, JSON accept | **401** |
| sensitive words (salary, payslip, journal, ledger) in any 200 body | **none** |

Read-first held: the only write endpoint, `POST /{business}/tickets`, was **never called by HQ** during this milestone. The one ticket was created by Joy's browser session through the CRM screen.

## N. Browser CRM verification

Done, logged in as Joy in Chrome, before any credential was created: CRM → Tickets rendered post-deploy; ticket `TK-2026-27-0001` opened through the form; list showed it with status Open and due date; reload clean; dashboard clean. Detail in section G.

## O. Rollback target

**Code: `be052d0`** (`git -C /opt/bordeguna-erp checkout --detach be052d0`, then `docker compose up -d --build` in `deploy/`). The two new schema objects are inert to that code and may stay. **Schema** (only if ever required): `migrate:rollback --step=2`. **Data**: `/root/predeploy-backup-20260906-hqapi.sql.gz`. Nothing in this release wrote to a pre-existing table except the one ticket row Joy's session created.

## P. Anomalies

1. **Pre-deploy gate failure, pre-existing (P-1).** The first full-suite run aborted at load time: `use IlluminateSupportFacadesDB;` in `tests/Feature/Accounting/MasterExpenseChartTest.php`, backslashes stripped, committed in `1131bba` on 5 Sep — unrelated to the HQ branch, zero production impact (test file), but it meant the suite could not run to completion since that commit. Fixed as its own test-only commit `17d595c` after the fast-forward, per Joy's Option 2. The `3,096` count recorded in docs/36 is now genuinely reproducible.
2. **Route count is 13, not 12 (P-2).** docs/38 miscounted: there are two `POST`s — `tickets` (the one write) and `reports/{key}/run` (a POST that reads). Corrected in docs/38 in place. The "read-first, one write" statement stands.
3. **302 vs 401 (P-3).** Without an `Accept: application/json` header, an unauthenticated API call is redirected to `/login` — standard Laravel behaviour for non-JSON requests; with the header it is a 401. HQ's connector sends `acceptJson()`. Separately, *every* unknown URL on the ERP, JSON or not, redirects a guest to `/login` (a pre-existing fallback), so nonexistent API paths answer 302 rather than 404; their absence is proven by the route table, not by status codes.
4. **Correlation and business headers are absent on wrong-business 403s and on `/businesses`.** `HqApiContext` returns before it sets the headers when the ability check fails, and `/businesses` sits outside the middleware. All 200s and all in-scope 403s carry both. Worth a small follow-up; not a security issue.
5. **`connector_calls.response_status` is null on successes.** The logger records an HTTP status only from an exception's code. Durations and statuses are correct. Cosmetic.
6. **The verification ticket's category stayed "complaint"** — the Enter that submitted the form landed before the category change. Cosmetic; the ticket is labelled and safe to close.
7. **Pint and ESLint drift** (~200 files, 5 errors) is pre-existing on `be052d0`, touches nothing in this release, and was left alone as instructed.

## Q. Remaining limitations

- The token has **no expiry**; rotation is by hand (`hq:api:token … --revoke`, then reissue). Recommended cadence: quarterly, or immediately on any suspicion.
- Token abilities, not the user's ERP roles, are the effective boundary (reviewed and accepted for Phase 1). The `hq` user holds no roles, so tightening later would need a deliberate change.
- Only **business 4** is connected. Businesses 1–3 hold no operating data; no tokens were issued for them.
- The ERP emits no events; HQ can only **poll**. No `scheduled_jobs` row exists yet for the hourly polls — they ran once, by hand, for this record.
- Each `PollErpTool` run replaces the snapshot row; there is no history of snapshots.
- The API's `purchasing` tile and the two purchasing reports need `purchasing.reports.view`, which was not granted.

## R. Exact next recommended milestone

**The LaLaDia Phase 1 release** — docs/38 section B and docs/39 section D: one commit (`4865c109`), 12 files, 1 migration, blast radius already established as the HQ change alone. Before it starts, two read-only confirmations only Joy can make (docs/39 section H): `git log --oneline -1` in `~/laladia-src` (expect `5e955a2c`) and `~/bionic-garden-app` (expect `b30b4b7c`), and the `webhooks` table query on both shops. After LaLaDia is live and verified: the `order.created` subscriber first, then the rest, then Bionic's merge. **Not started. Waiting for instruction.**

---

**Milestone status: COMPLETE. Stopped.** Live ERP `17d595c`; HQ connected to the ERP for business 4 with three read abilities; no shop, Meta, payment, DNS, n8n, Phase 2 or agent change of any kind.
