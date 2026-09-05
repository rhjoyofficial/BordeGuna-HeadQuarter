# 36. Phase 1 build record and resume point (5 Sep 2026, late)

Authorised by Joy the same evening: "also complete phase 1 and sync memory then make a resume point for tomorrow."
Scope followed docs/35 (readiness review). This is the record of what exists, where, what runs in production and what does not.

## A. What was built

### HQ (this repo, `main`, all pushed)

| Commit | Content |
|---|---|
| `dedf115` | Commerce Platform Interface (`App\Commerce\Contracts\CommercePlatform`) + DTOs + `LaravelShopAdapter` (one class for laladia and bionic) + `CommerceRegistry` (credentials `shop_assistant_key` / `shop_admin_token` by store slug) + `ConnectorCallLogger`; shop webhook inbox `POST /api/webhooks/shop/{store}` (HMAC over raw body, timestamp window, dedupe on delivery id or body hash, 202); `facts_orders`; unified customer identity (`customers`, `customer_identities`, merge candidates, `PhoneCanonical`); `hq:customers:rebuild`, `hq:facts:reconcile`, `hq:commerce:probe`; customers UI. |
| `f220aa1` | Handlers for the shop's real events `order.payment_updated` and `shipment.status_updated`; the adapter passes the HQ idempotency key as the shop's own `checkout_token`; docs/35 O-2 corrected. |
| `f9457d1` | `App\Erp\ErpConnector` + `ErpRegistry` (credential `erp_api_token`, account_ref = HQ business slug) + DTOs; `facts_erp_snapshots` read model; scheduler tools `erp.poll_overview` and `erp.poll_low_stock`; `hq:erp:probe`; `HQ_ERP_BASE_URL`. |

Tests: **73 Pest, all passing** (Postgres). CI: hq-ci on every push.

### ERP (`bordeguna-erp`, branch `feature/hq-api` = `3fd4be0`, pushed, NOT merged, NOT deployed)

Read-first API at `/api/hq/v1` behind Sanctum tokens:
- `HqApiContext` middleware: `{business}` in the URL must match token ability `hq:business:{id}`; `TenantScope::enter()` from the token, never from a header; correlation id echoed.
- `HqReadController`: `businesses`, `{b}/overview`, `stock`, `stock/low`, `products`, `products/{sku}`, `customers`, `customers/{id}`, `tickets`, `POST tickets` (the one write; CRM numbering + SLA; replay by `crm_tickets.external_ref`), `approvals/pending`, `reports`, `POST reports/{key}/run`.
- Each endpoint needs the ERP permission name as a token ability; cost figures need `inventory.reports.view`.
- `php artisan hq:api:token {email} {business} --abilities=...` issues or `--revoke`s; abilities outside a read allow-list are refused. Payroll, salary, journals, approvals are unreachable.
- Sanctum's SPA `csrf-cookie` route switched off (`sanctum.routes=false`).
- Migrations: `personal_access_tokens`, `crm_tickets.external_ref`.
- Tests: 7 new Pest; **full suite 3,096 passing**.

### LaLaDia shop (`LaLaDia`, branch `feature/hq-phase1-api` = `4865c109`, pushed, NOT merged, NOT deployed; Bionic gets it by `git merge upstream/main` after the LaLaDia merge)

- Webhook envelope: body gains `id`, `occurred_at`, `schema_version`, `store` beside unchanged `event`/`data`; headers `X-Shop-Event`, `X-Shop-Delivery`, `X-Shop-Timestamp`, `X-Shop-Store`. HMAC unchanged in method, still over the exact bytes sent.
- `SendWebhookJob`: 5 tries over ~2.5 h (was 3 over 7.5 min).
- `webhooks:inventory-low` (hourly): `inventory.low` once per crossing below the `low_stock_threshold` setting, re-armed on recovery (`product_variants.low_stock_alerted_at`). Listed in the admin webhook event list.
- Assistant endpoints (same `X-API-Key`): `GET /api/v1/assistant/orders?phone=`, `GET /api/v1/assistant/products/{slug}/availability` (reason = checkout's rule), `GET /api/v1/assistant/promotions`.
- Checkout accepts `source=assistant` + required `conversation_ref` (`orders.conversation_ref`); any other `source` is 422.
- Migration: `orders.conversation_ref`, `product_variants.low_stock_alerted_at`.
- Tests: 8 new; **full suite 1,307 passing**.

## B. Production state

| System | Runs | Notes |
|---|---|---|
| hq.bordeguna.com | Phase 0 + theme image | Phase 1 (`f9457d1`) NOT deployed yet, see section D. Inert until credentials exist. |
| erp.srv1808022.hstgr.cloud | unchanged (`main`) | `feature/hq-api` not merged. Merging + migrating is Joy's call. |
| laladia.com / bionic.garden | unchanged | `feature/hq-phase1-api` not merged. Deploy needs his go and the usual backup-first runbook. |

## C. What only Joy can do (in order)

1. **Review and merge** `bordeguna-erp` `feature/hq-api`, deploy the ERP (migration adds two tables/columns, nothing else changes), then on the ERP: create the `hq` user, run `hq:api:token hq@... 4 --abilities=inventory.view,inventory.reports.view,sales.reports.view,sales.customers.view,crm.tickets.view,crm.tickets.manage` per business (1 LaLaDia, 2 Bionic, 3 Azmion, 4 Bor de Guna). Paste each token into HQ `integration_credentials` (provider `erp_api_token`, account_ref = HQ business slug). Then `hq:erp:probe`.
2. **Review and merge** LaLaDia `feature/hq-phase1-api` into `main`, `git merge upstream/main` into Bionic, both suites green, deploy both (backup first). Then per shop: an admin API token for the `hq` admin user (`shop_admin_token`), the assistant `X-API-Key` (`shop_assistant_key`), and a webhook subscriber row per event pointing at `https://hq.bordeguna.com/api/webhooks/shop/{store-slug}` with a secret stored in HQ as `shop_webhook_secret`. Then `hq:commerce:probe` and `hq:facts:reconcile`.
3. **Decisions P-1..P-6** from docs/35 (P-1 stock refusal vs status rule is the one that changes code).
4. First login at hq.bordeguna.com, 2FA enrolment, password change.

## D. Deploy of HQ Phase 1

**NOT deployed.** hq-ci is green on `f9457d1`, but the release step over SSH was refused by the session's permission
policy, so production still runs the Phase 0 + theme image. The release is one command and adds only the
`facts_erp_snapshots` table:

```
ssh vps 'cd /opt/bordeguna-hq && ./deploy/deploy.sh'
```

Then confirm `https://hq.bordeguna.com/api/health` and the entry in VPS-local `deploy/RELEASES.md`.

## E. Resume point for 6 Sep

Joy: "tomorrow first will share everything about this project and we will decide what you do tomorrow."
Before that conversation: nothing further to build. Read this file, then docs/35 sections D and O for the two branches awaiting his review. Do not merge or deploy the ERP or shop branches without his explicit go.
