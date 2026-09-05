# 35 - Phase 1 Readiness Review (5 September 2026)

Prepared before any Phase 1 code, from the current repository and a read-only inspection of the ERP, LaLaDia, Bionic and the VPS on 5 Sep 2026. Where a document and the code disagreed, the code wins and the discrepancy is listed in section O. Every item is tagged **ALREADY EXISTS**, **NEEDS MODIFICATION**, **NEW**, **BLOCKED** or **FUTURE**.

## A. Phase 1 relevant architecture (approved)

Phase 1 = identity, data and API foundation (doc 30): the read-first ERP API, the shop assistant API additions and webhook events, the Commerce Platform Interface with the LaLaDia and Bionic adapters (WooCommerce only if Econic has real volume), the event inbox wired to real producers, unified customer identity and its rebuild command, `facts_orders`, and starting Meta App Review. HQ stays a consumer of truth (ADR-007); every write to a store or the ERP goes through a typed connector with an idempotency key (ADR-011); agents, tools with policy, customer AI and marketing are Phases 2 to 5 and are out of scope here.

What Phase 0 delivered that Phase 1 builds on (**ALREADY EXISTS** in `apps/hq`): the `events` inbox/outbox with dedupe, attempts, backoff and dead-lettering (`EventRecorder`, `ProcessDomainEvents`); `integration_credentials` (encrypted, hidden payload); `stores` with an `adapter` column and `capabilities` JSON; `platform_accounts`; `connector_calls`; `audit_logs`; correlation ids on every request and job; `Features` flags; the scheduler that can run tools.

## B. Existing systems discovered (verified against code)

### Bor De Guna ERP (`rhjoyofficial/bordeguna-erp`, live at erp.srv1808022.hstgr.cloud, `52da1c9`)

| Aspect | Finding | Tag |
|---|---|---|
| HTTP surface | Inertia web routes only: `routes/web.php`, `auth.php`, `settings.php` plus eight module `routes/web.php`. **No `routes/api.php`, no API group in `bootstrap/app.php`.** | ALREADY EXISTS (web) / NEW (api) |
| Authentication | Session auth (Laravel Breeze-style controllers). **Neither Sanctum nor Passport is in `composer.json`.** | NEW dependency for API tokens |
| Tenancy | `BusinessContext` singleton (`set/forget/id/hasBusiness`) set from the logged-in user's session by the business switcher; every tenant model uses `BelongsToBusiness` + `BusinessScope`, which **fails closed** when no context is set (queries return nothing). | ALREADY EXISTS; an API must set the context from the token, never from a header the caller controls |
| Authorization | spatie permissions named `module.area.verb` (e.g. `sales.reports.view`, `inventory.stock.adjust`, `hrm.salary.view`, `accounting.chart.manage`). | ALREADY EXISTS; reuse verbatim as token abilities |
| Read gateway | `Foundation\Support\Ai\ReadableData`: a permission-keyed table/column map (products, warehouses, stock_batches, stock_movements, customers, sales_invoices, sales_orders, suppliers, purchase_orders, accounts...) compiled to SQL by the app; the model never writes SQL. | ALREADY EXISTS; the natural core of the read-first API |
| Reports | `ReportDefinition` contract (`key, name, group, permission, parameters, columns, rows, countRows, meta`), 23 definitions across HRM, Accounting, Sales, Foundation; the assistant's `run_report` tool executes them. | ALREADY EXISTS; expose as `POST /reports/{key}/run` |
| Write side | Everything mutating is an Action class (`CreateSalesOrder`, `ApproveSalesOrder`, `RecordCounterSale`, `RecordCustomerReceipt`, `PlanCustomerImport`...) wrapped in the approval workflow engine. | ALREADY EXISTS; Phase 1 exposes none of them; Phase 4+ may expose proposals only |
| Audit | spatie activitylog on 72 models. | ALREADY EXISTS; API calls must set the causer to the token owner |
| Rate limiting | `throttle` only on email-verification routes. | NEW for the API |
| CRM identity | `Crm\Support\PhoneIdentity::normalize()`; `phone_normalized` on `leads`, `contacts`, `crm_tickets`; **`customers` (Sales) has `phone` but no `phone_normalized`**. Tickets: `ticket_number, subject, body, category, priority, status, resolve_by, resolved_at, resolution, assigned_to_user_id, customer_id, contact_id, source`. | ALREADY EXISTS (partly) |
| Data volume | 9 employees, 153 products, 4 customers, 0 sales orders, 0 leads (5 Sep). | fact |

### LaLaDia (`rhjoyofficial/LaLaDia`, live `a6b4ffdc`/local `66def463`) and Bionic (`rhjoyofficial/Bionic.garden`, `relaunch` `da6f826f`)

`git diff upstream/main relaunch` over `routes/`, `Product`, `Order`, `Cart`, `Webhook`, the assistant middleware and `ApiResponse` is **empty**: the two shops have an identical API surface. One adapter class serves both, configured per store (base URL, keys, order prefix `LLD`/`BNC`).

| Aspect | Finding | Tag |
|---|---|---|
| Versioning | `/api/v1` prefix wrapping `routes/public.php` and `routes/admin.php`. | ALREADY EXISTS |
| Assistant API | `/api/v1/assistant/{products, products/search, products/{slug}, categories, combos, combos/{slug}, shipping-zones}`; `throttle:30,1`; middleware `chatbot.key` = constant-time compare of header `X-API-Key` against `services.chatbot.api_key` (`CHATBOT_API_KEY` env). Live on both domains (401 without key). | ALREADY EXISTS |
| Product shape | `ProductResource`: id, name, slug, short_description, note, key_values, description, base_price, referral_rate_percent, free_delivery_min_grams, thumbnail, image_url, gallery, is_active, is_featured, is_trending, meta_*, landing_slug, is_landing_enabled, **status**, category{id,name,slug}, variants[], certifications[]. Variants carry title, sku, price, discount_type/value, sale_ends_at, **stock, reserved_stock**, weight_grams, is_active, gift. | ALREADY EXISTS |
| Availability today | Two mechanisms. (1) Product `status` (`available_now, pre_order, coming_soon, limited_batch, out_of_stock`, `ProductAvailability`) and `is_active`. (2) `CheckoutPricingService` **refuses lines when variant stock is insufficient** ("is currently out of stock", "Insufficient stock for ..."). | ALREADY EXISTS; see discrepancy O-1 |
| Checkout | `POST /api/v1/checkout/preview` and `POST /api/v1/checkout` with items[{variant_id|combo_id, quantity}], customer_name, customer_phone (`BdPhone::RULE` `^01[3-9]\d{8}$`), customer_email, address_line, city, **district, thana** (required), area, zone_id, payment_method (storefront keys), coupon_code, notes, is_gift + recipient fields, ga_client_id, **`checkout_token` (min 32 chars, required for guests)**. Preview returns subtotal, lines/items, coupon, grand_total (server computes every price). Landing: `POST /api/v1/landing/{slug}/preview|checkout` (also accepts `checkout_token`). **Idempotency ALREADY EXISTS:** `CheckoutIdempotency` returns the same order for a repeated token with the same basket (unique index on `orders.checkout_token`, tested in `CheckoutIdempotencyTest`). HQ sends its idempotency key as `checkout_token`. | ALREADY EXISTS |
| Orders | `order_status` values `pending, confirmed, delivered, cancelled, returned` (+ processing/shipped timestamps); `source`, `payment_status`, `fbp/fbc/ga_client_id` captured; `OrderResource` is rich (items, shipments per courier, transactions, timeline). Admin order routes exist under `auth:sanctum` + `admin` + per-route `permission:*`. | ALREADY EXISTS |
| Admin auth | `auth:sanctum`; `User` uses `HasApiTokens`, so **personal access tokens can be issued** to an `hq` admin user with a minimal role. | ALREADY EXISTS |
| Error contract | `App\Helpers\ApiResponse`: `{success, message, data}` / `{success:false, message, errors}` / paginated `{success, data, meta}`; validation errors are Laravel 422 `{message, errors}`. | ALREADY EXISTS |
| Outbound webhooks | `Webhook{event, url, secret(encrypted), is_active}`; `WebhookService::dispatch(event, payload)` posts `{event, data}` JSON with `X-Shop-Signature` = HMAC-SHA256(body, secret) (plus a legacy `X-Laladia-Signature` on the original shop). **Six events wired** (corrected after reading the listeners): `order.created`, `order.status_changed` (old_status/new_status/changed_at), `order.payment_updated`, `shipment.status_updated`, `customer.registered`, `coupon.expired`; `SendWebhookJob` retries 3x with 30/120/300 s backoff. No event id, no timestamp in the envelope. Zero subscribers today. | ALREADY EXISTS / NEEDS MODIFICATION (envelope only) |
| Phone canon | `BdPhone` normalises `+880`, `880`, spaced and hyphenated forms to `01XXXXXXXXX`. | ALREADY EXISTS |
| Customers | shop `users`/orders keyed by phone; ~21 distinct LaLaDia customers, 1 Bionic order. | fact |
| Hosting | Nikolahost shared cPanel, MariaDB 10.11, queue drained by scheduler every minute; webhooks therefore leave the shop within ~60 s, not instantly. | fact; affects event latency |

### Econic (econic.store)

WordPress 7.1 + WooCommerce 10.9.1, same shared host. **No code, dump, REST keys or cPanel access on hand.** Joy's decision: rebuild as a custom multi-seller platform (ADR-009). | BLOCKED (access) / FUTURE (rebuild)

### Automation and channels

n8n Bionic Messenger bot (Anthropic + Postgres memory) calls the Bionic assistant API; Meta app in **Development Mode** (only Joy can message). Bot Postgres holds `customers`, `draft_orders`, `human_takeover`. Joy (Q23/Q24): replace, do not migrate. | ALREADY EXISTS, to be replaced in Phase 3

## C. Required API changes (summary)

| System | Change | Tag | Repo |
|---|---|---|---|
| ERP | add `laravel/sanctum`; `routes/api.php` under `/api/hq/v1`; token abilities = existing permission names; `BusinessContext` set from the token's business; controllers that call `ReadableData`/report definitions/read-only queries only; throttle; correlation id echo; activitylog causer = token owner | NEW | bordeguna-erp |
| Shops (LaLaDia, merged down to Bionic) | assistant endpoints: `orders?phone=`, `products/{slug}/availability`, `promotions`; `Idempotency-Key` header honoured on `checkout` and `landing/{slug}/checkout`; webhook envelope gains `id`, `occurred_at`, `schema_version`; new events `order.status_changed`, `payment.completed`, `shipment.delivered`, `inventory.low`; per-event retry with backoff in `SendWebhookJob` | NEEDS MODIFICATION | LaLaDia then Bionic |
| Shops | an `hq` admin user with a minimal role and a Sanctum personal access token; an `hq` webhook subscriber row per event | NEW rows (admin action, no code) | production admin panels, on approval |
| HQ | `ErpConnector`, `CommercePlatform` interface + `LaravelShopAdapter` (one class, two store configs), webhook inbox endpoints with signature verification, identity resolution, `facts_orders`, rebuild commands | NEW | BordeGuna-HeadQuarter |
| WooCommerce | adapter only if Econic has order volume worth reading; needs REST consumer key/secret | BLOCKED (access, decision Q6b) | |

## D. Proposed ERP API contract (`/api/hq/v1`, read-first)

**Authentication.** Sanctum personal access token issued to a dedicated ERP user `hq@bordeguna.com` (role: none; abilities only), one token per business: abilities `hq:business:{id}` plus the ERP permission names the token may exercise (`sales.reports.view`, `inventory.reports.view`, `hrm.attendance.view`...). Middleware order: `auth:sanctum` -> `ability:hq:business:{id}` (from the route's `{business}` segment) -> `SetBusinessContextFromToken` (calls `BusinessContext::set`; never reads a caller-supplied header) -> `throttle:hq-api` (120/min per token) -> controller. Every response echoes `X-Correlation-Id` (taken from the request or generated) and sets `X-Request-Id`.

**Envelope.** `{ "data": ..., "meta": { "business_id", "as_of", "page", "per_page", "total" }, "links": {...} }`; errors `{ "error": { "code", "message", "details" } }` with 401/403/404/409/422/429. Cursor pagination on large tables (`stock_movements`), page pagination elsewhere; `filter[field]=`, `sort=`, `fields=` whitelisted per endpoint; `If-None-Match`/ETag on collections.

| Method + path | Returns | Permission (ability) | Source in ERP |
|---|---|---|---|
| `GET /businesses` | businesses the token may see | any hq ability | `Business` |
| `GET /{business}/overview` | today: cash, receivables, payables, open POs, low-stock count, pending approvals, attendance present/late | `sales.reports.view` + `accounting.view` + `inventory.reports.view` (each tile hidden without its permission, mirroring `DashboardController`) | existing dashboard queries |
| `GET /{business}/employees/summary` | headcount, on leave today, joined/left this month (no salary) | `hrm.employees.view` | Hrm |
| `GET /{business}/attendance/today` | present/late/absent counts, exceptions count | `hrm.attendance.view` | `daily_attendance` |
| `GET /{business}/finance/overview` `/receivables` `/payables` `/cash` | balances and ageing buckets | `accounting.view` | Accounting report definitions |
| `GET /{business}/stock` `?filter[sku]=&filter[warehouse]=` | on-hand by product/warehouse (batch totals) | `inventory.reports.view` | `stock_batches` via `ReadableData` |
| `GET /{business}/stock/low` | products under their threshold | `inventory.reports.view` | existing low-stock query |
| `GET /{business}/products` `/{sku}` | catalogue with cost fields **only when** `inventory.cost.view` (new permission or existing equivalent) | `inventory.view` | `products`, `product_prices` |
| `GET /{business}/purchase-orders` | open/recent POs | `purchasing.view` | `purchase_orders` |
| `GET /{business}/customers` `/{id}/360` | customers, exposure, receipts, tickets, contacts | `sales.customers.view` / `crm.contacts.view` | Customer 360 (exists in UI) |
| `GET /{business}/tickets` | open tickets | `crm.tickets.view` | `crm_tickets` |
| `POST /{business}/tickets` | creates a ticket (source `hq`) | `crm.tickets.manage` | existing ticket Action |
| `POST /{business}/reports/{key}/run` | runs a `ReportDefinition` with parameters; async when `estimatedRows` is large (returns a job id, poll `GET /reports/runs/{id}`) | the definition's own `permission()` | `ReportDefinition` |
| `GET /{business}/approvals/pending` | pending approval requests visible to the token owner | module approve permissions | `ApprovalInbox` |

**Writes in Phase 1: only `POST /tickets`.** Every future write (purchase recommendation, journal proposal, approval decision) requires `Idempotency-Key` (stored per token for 24 h; a replay returns the first response) and creates the ERP's own document through its Action, never a direct row.

**What stays ERP-owned (never reached by HQ):** payroll and salary data beyond headcount, attendance corrections, leave decisions, journals and closes, stock adjustments, PO approvals, user/role management, the approval decision itself. HQ may *propose*; the ERP's workflow decides.

**Read models/DTOs in HQ:** `ErpBusinessOverview`, `ErpStockLevel`, `ErpProductCost`, `ErpCustomer360`, `ErpTicket`, `ErpReportRun`, all readonly PHP classes built from the JSON with schema validation at the boundary; unknown fields ignored, missing required fields fail loudly.

## E. Proposed Shop API contract (LaLaDia upstream, Bionic by merge)

**Authentication.** Read endpoints: existing `X-API-Key` (`chatbot.key`), one key per store, stored in HQ `integration_credentials`. Order and admin endpoints: Sanctum bearer token of a dedicated `hq` admin user whose role holds only `order.view`, `order.create`, `order.update`, `customer.view`, `shipment.view` (exact names from `routes/admin.php`). Both keys rotate from the shop admin panel; HQ stores the new value; nothing in code.

**Additions (all under `/api/v1/assistant`, same middleware):**

| Endpoint | Purpose | Notes |
|---|---|---|
| `GET /orders?phone=01XXXXXXXXX` | recent orders for a customer (number, status, placed_at, grand_total, items, shipment status) | phone normalised with `BdPhone`; max 10; no addresses returned |
| `GET /orders/{order_number}` | one order, same shape | 404 outside the phone match unless the admin token is used |
| `GET /products/{slug}/availability` | per variant: `orderable`, `reason` (`inactive`, `status:out_of_stock`, `stock_insufficient`, `ok`), `stock_hint`, active promotions, free-delivery rule | the shop computes `orderable` from **its own** rule; HQ policy (`commerce.availability_rule`) decides how the AI phrases it |
| `GET /promotions` | active coupons flagged public, combo savings, tier ladders | already computed by existing services |
| `GET /geo/*` | districts, thanas, areas | ALREADY EXISTS publicly; keep |

**Checkout idempotency (ALREADY EXISTS):** HQ sends its key as `checkout_token` (64-char hash); the shop's `CheckoutIdempotency` returns the same order for the same basket and mints a fresh token when the basket differs. Small addition (NEEDS MODIFICATION): accept `source=hq_ai` and `conversation_ref` so attribution and reconciliation do not rely on a note marker.

**Webhooks (NEEDS MODIFICATION, smaller than first written):** the six existing events keep their names and payloads (`order.status_changed`, `order.payment_updated` and `shipment.status_updated` carry `old_status`/`new_status`/`changed_at`; HQ already accepts them). Add to the envelope `id` (uuid), `occurred_at`, `schema_version`, `store`, and the headers `X-Shop-Event`, `X-Shop-Delivery` (= id), `X-Shop-Timestamp`; extend `SendWebhookJob` backoff to 30 s, 2 min, 10 min, 1 h, 6 h and record permanent failures visibly; add one new event `inventory.low {sku, variant_id, stock, threshold}`. The legacy `X-Laladia-Signature` stays on the original shop.

**Error contract:** unchanged `ApiResponse` shapes; validation 422 `{message, errors}`; the adapter maps `success:false` + `message` to typed exceptions (`Unavailable`, `Validation`, `Refused`, `Uncertain`).

## F. Commerce Platform Interface contract (HQ, `app/Commerce`)

```php
interface CommercePlatform {
    public function store(): StoreRef;                         // id, slug, adapter, base_url, business_id
    public function capabilities(): Capabilities;              // search, product, variant, combos, promotions, geo, preview, create_order,
                                                               // order_lookup_by_phone, order_status, idempotent_create, webhooks: [...]
    public function searchProducts(string $query, int $limit = 10): ProductCollection;
    public function getProduct(string $slugOrSku): Product;    // includes variants + status
    public function getVariant(int $variantId): Variant;
    public function getCombos(): ComboCollection;
    public function getPrice(PriceRequest $r): PriceQuote;     // = preview without customer: lines, discounts, shipping band, free-delivery rule
    public function getAvailability(int $variantId): Availability; // orderable, reason, stock_hint, promotions
    public function previewOrder(OrderDraft $d): OrderQuote;   // server-priced totals; never trust HQ math
    public function createOrder(OrderDraft $d, IdempotencyKey $k): OrderResult; // confirmed|uncertain
    public function getOrder(string $orderRef): Order;
    public function findOrdersByPhone(string $phoneCanonical, int $limit = 10): OrderCollection;
    public function reconcileOrder(IdempotencyKey $k, OrderDraft $d): ?Order; // look up by key, else by (phone, lines, window)
    public function updateOrder(string $orderRef, OrderChange $c, IdempotencyKey $k): OrderResult;   // Phase 4, approval-gated
}
```

DTOs (readonly): `Product{id, slug, name, status, is_active, base_price, category, variants[], images[], free_delivery_min_grams}`, `Variant{id, title, sku, price, discount, sale_ends_at, stock_hint, is_active, weight_grams}`, `Combo`, `PriceQuote{lines[], subtotal, discount, shipping, total, rules[]}`, `Availability{orderable, reason, stock_hint, promotions[]}`, `OrderDraft{customer{name, phone, email}, address{line, district, thana, area}, lines[{variant_id|combo_id, qty}], payment_method, coupon, notes, is_gift, recipient, source, conversation_ref}`, `OrderResult{state: confirmed|uncertain|refused, order?: Order, provider_ref?, error?}`, `Order{ref, status, payment_status, placed_at, totals, lines[], shipments[]}`.

Rules: the store computes prices (`previewOrder` then `createOrder`); `createOrder` always sends `Idempotency-Key`; on timeout or 5xx the result is `uncertain` and `reconcileOrder` runs before any retry (ADR-011); `capabilities()` is populated from the store row and verified by a `probe()` on first use; every call writes `connector_calls`; credentials come from `integration_credentials` at call time.

Adapters: `LaravelShopAdapter` (one class; `laladia` and `bionic` are two `stores` rows) - NEW; `WooCommerceAdapter` - FUTURE unless Q6b says otherwise (maps `/wp-json/wc/v3/products`, `/orders`, `/customers`; no assistant-style availability endpoint exists in Woo, so `getAvailability` derives from `stock_status`); `EconicPlatformAdapter`, `MultiVendorAdapter` - FUTURE: the interface already carries `seller` on lines and `store` on orders so no signature change is expected.

Availability policy: `getAvailability()` returns the store's own verdict; HQ's `commerce.availability_rule` (default `status_based`, Joy Q9) decides whether `stock_insufficient` is surfaced to a customer as "unavailable" or as "orderable, confirm at checkout". See O-1 for the conflict with the shop's checkout stock refusal.

## G. Event and webhook contract (shop -> HQ, ERP -> HQ)

**Shop -> HQ.** HQ exposes `POST /webhooks/shop/{store}`. Verification: `X-Shop-Signature` HMAC over the raw body with the per-store secret from `integration_credentials`; `X-Shop-Timestamp` within 5 minutes; `X-Shop-Delivery` used as `events.dedupe_key` (`shop:{store}:{delivery id}`; for the three legacy events without an id, the fallback hash of type+order_number+occurred_at). Response 202 as soon as the row is written; processing is asynchronous via `hq:events:process`.

Canonical HQ event types produced from shop webhooks: `order.created`, `order.status_changed`, `payment.completed`, `shipment.delivered`, `inventory.low`, `customer.registered`. Payload schema versioned by `schema_version`; the HQ handler validates against `resources/schemas/events/{type}.v1.json` and rejects to `failed` on schema mismatch (never silently).

Handlers in Phase 1 (idempotent): `UpsertFactsOrder`, `ResolveCustomerIdentityFromOrder`, `RecordCustomerRegistration`. Ordering is not guaranteed; `order.status_changed` before `order.created` parks (status `failed`, retry) until the order fact exists or 24 h passes.

**ERP -> HQ.** The ERP has no events. Phase 1 polls: `overview` and `stock/low` on a schedule (`scheduled_jobs`, tool `erp.poll_overview` / `erp.poll_low_stock`, hourly), diffs against the last snapshot, and emits `inventory.low` / `finance.snapshot` events internally. True ERP webhooks are FUTURE (an additive `HqEventPublisher` listener on a few Actions).

**Replay and reconciliation.** Any inbox row can be re-dispatched from the admin UI; a nightly `hq:facts:reconcile --store=` pulls the last 48 h of orders through the adapter and inserts missing facts (catching lost webhooks, which the cPanel scheduler-drained queue makes plausible).

## H. Customer identity architecture (HQ)

Tables (Phase 0 designed them in doc 05; Phase 1 creates them): `customers(id, business_id, primary_phone_canonical, name, email, first_seen_at, last_seen_at, consent jsonb, attributes jsonb, merged_into_id)`, `customer_identities(id, customer_id, channel, external_id, source_store_id, confidence, verified_at, last_seen_at)` with unique `(channel, external_id, source_store_id)`, `customer_merges(id, kept_id, absorbed_id, reason, merged_by, undone_at)`.

Channels in Phase 1: `shop_order` (order_number), `shop_user` (shop user id), `phone`, `email`, `erp_customer` (ERP customer id + business). Phase 3 adds `messenger_psid`, `instagram_igsid`, `whatsapp`.

Matching (deterministic first, in this order):
1. `phone` canonical (`01XXXXXXXXX`, the same normaliser the ERP `PhoneIdentity` and the shop `BdPhone` implement; HQ ports one copy and tests it against both) -> confidence 1.0, auto-link.
2. `email` lower-cased -> 0.9, auto-link when no phone conflict.
3. existing channel id -> 1.0.
4. name + district + an order within 30 days -> 0.6, **queued for human review**, never auto-merged.

Merge rules: two `customers` are merged only by a human (Phase 1 UI: candidate list + merge button) or by a phone match of confidence 1.0 discovered later; merges are recorded and reversible (`merged_into_id` redirect, `customer_merges` undo). Phone is never unique-constrained (one household, one number: ERP C-CRM-12/13). Conflicts (same phone, different names) keep both names in `attributes.aliases` and flag for review.

Privacy and consent: `consent` holds `{marketing_sms, marketing_whatsapp, marketing_messenger, source, at}`; defaults false; only an explicit opt-in recorded by a channel event sets true. The profile is a read model: `hq:customers:rebuild --business=` re-derives it from `facts_orders` and identities. Export and erase per customer in one command (removes profile, identities, memory; facts keep an anonymised customer key). Payroll/employee data never enters this model.

## I. Meta readiness and dependencies

| Item | State (verified dates) | Tag |
|---|---|---|
| Pages | La La Dia `100085015853889`, Bionic Garden `321936947659712`, Econic Ventures, Azmion, Bôr dé Güna (Joy full control, 15 Aug / 2 Sep) | ALREADY EXISTS |
| Pixels/datasets + CAPI | LaLaDia `1260982165256142`, Bionic `1773890070472625`, CAPI healthy (31 Aug) | ALREADY EXISTS |
| Ad account | BordeGuna Ads `28164410319885213` (BDT) inside Joy's End Brackets portfolio | ALREADY EXISTS; moving per Q12 |
| Developer app | Messenger bot app in **Development Mode**; `LaLaDia Conversions API` app `1804360750550977` (events scope only) | ALREADY EXISTS, insufficient |
| Ownership | Joy (Q12/Q13): everything moves to, or is created under, the **boss's new official Meta account/portfolio**; that account does not exist yet | BLOCKED on the boss |
| Required app structure | one business-owned app under that portfolio, Business Verification (needs the company's trade documents), products: Messenger, Instagram Graph API, Webhooks, Marketing API; a system user with `ads_management`, `pages_manage_posts`, `pages_manage_engagement`, `pages_messaging`, `pages_read_engagement`, `instagram_basic`, `instagram_manage_messages`, `instagram_manage_comments`, `instagram_content_publish`, `business_management` | NEW |
| App Review | required for every scope above beyond the app's own admins/testers; needs privacy policy + terms URLs per brand domain (Q14), screencasts, a live Page; typical 1-3 weeks per submission | BLOCKED until the app exists |
| Development Mode allows | building and testing the Messenger/Instagram webhooks and Send API for **app roles only** (admins, developers, testers), publishing to Pages the app admin manages, reading own Pages' data; the current n8n bot runs exactly like this | possible in Phase 1 (dev/staging) |
| Must wait for App Review | messages from the public, comment replies at scale, Instagram DMs from customers, Marketing API writes from a system user for a business the app does not own | Phase 3/5 gate |

Phase 1 Meta work therefore is: write the privacy/terms pages (NEW, safe), prepare the review package (scope list, screencast script), and register HQ's webhook receiver in Development Mode on a **test Page** only. No production Meta configuration changes (Joy's rule).

## J. Security model (Phase 1)

| Boundary | Mechanism |
|---|---|
| HQ -> ERP | Sanctum token per business with ability list; ERP sets `BusinessContext` from the token; HTTPS only; 120 req/min; token stored encrypted in HQ; rotation = issue new, swap row, revoke old (ERP UI); every call logged in `connector_calls` and audited in the ERP with causer = `hq@bordeguna.com` |
| HQ -> shops | `X-API-Key` per store for reads; Sanctum token of an `hq` admin user with a minimal role for orders; same storage, rotation and logging |
| Shops -> HQ | HMAC-SHA256 signature over raw body with a per-store secret, timestamp window 5 min, delivery id dedupe (replay protection), 202-then-process, rate limit per store |
| Secrets | `integration_credentials` (encrypted, hidden from serialisation) in HQ; `.env`/settings rows in the shops and ERP; nothing in prompts, logs (redaction processor) or git |
| Least privilege | ERP token abilities = exactly the permissions the endpoint needs, per business; shop `hq` role = the five order/customer permissions; HQ RBAC gates who may see ERP finance tiles (`finance_viewer`) |
| Isolation | every HQ read model row carries `business_id` and `store_id`; the ERP fails closed without context; shop order prefixes `LLD`/`BNC` map to business ids in `stores`; brand-level data (platform accounts) hangs off `brands` |
| Correlation | HQ sends `X-Correlation-Id`; ERP and shops echo it; audit rows on all three sides carry it |

## K. Data ownership matrix

| Data | Owner (write) | HQ holds | Phase 1 access |
|---|---|---|---|
| Products, variants, prices, promotions, combos, sellable stock | shop (LaLaDia/Bionic) | nothing persistent; cached reads | read via adapter |
| Product cost, ERP stock, warehouses | ERP | nothing | read via ERP API (cost behind its own permission) |
| Orders (online) | shop | `facts_orders` read model | read + create via adapter (create is Phase 4) |
| Orders (counter/dealer) | ERP | `facts_orders` (later) | read |
| Customers (shop buyers) | shop | identities + profile read model | read |
| Customers, leads, tickets | ERP CRM | identities + ticket refs | read; create ticket |
| Employees, attendance, payroll, ledgers | ERP | summaries only, never rows | read summaries |
| Platform accounts, credentials, flags, jobs, events, audit | **HQ** | master | own |
| Customer identity graph, merges, consent | **HQ** | master | own |
| Conversations, memory, decisions | **HQ** (Phase 3) | master | none in Phase 1 |

## L. Sequence diagrams for key flows

**L1. Order created on a shop -> HQ fact and identity**
```
Shop checkout ──OrderCreated event──> SendWebhookJob (queue drained ≤60s on cPanel)
   ──POST {id,event,occurred_at,data}+X-Shop-Signature──> HQ /webhooks/shop/laladia
HQ: verify HMAC + timestamp ─> EventRecorder.record(dedupe=shop:laladia:{id}) ─> 202
HQ scheduler (every minute): ProcessDomainEvents ─> UpsertFactsOrder ─> ResolveCustomerIdentityFromOrder
   ─> customers/customer_identities (phone canonical) ─> audit ─> events.status=processed
Nightly: hq:facts:reconcile pulls last 48h orders via adapter, inserts any missing fact, logs the gap
```

**L2. Read a product for a customer question (Phase 1 builds the path; Phase 3 uses it)**
```
HQ ─> LaravelShopAdapter.getProduct(slug) ─X-API-Key─> shop /api/v1/assistant/products/{slug} ─> Product DTO
HQ ─> getAvailability(variant) ─> shop /assistant/products/{slug}/availability ─> {orderable, reason, stock_hint}
HQ policy commerce.availability_rule decides the wording ─> connector_calls row with correlation id
```

**L3. Create an order through the adapter (Phase 4 uses it; Phase 1 implements and tests it in dry_run against staging)**
```
HQ builds OrderDraft ─> previewOrder ─> shop /checkout/preview ─> OrderQuote (server prices)
HQ ─> createOrder(draft, Idempotency-Key=K) ─> shop /checkout (+K)
  200 ─> confirmed{order_number} ─> facts_orders ─> audit
  timeout/5xx ─> uncertain ─> reconcileOrder(K): shop /assistant/orders?phone= within 10 min matching lines
       found ─> adopt order_number ─> confirmed ; not found ─> retry createOrder with the same K (shop replays if it did see K)
```

**L4. ERP overview for the daily briefing (Phase 2 consumer)**
```
HQ scheduler ─> tool erp.poll_overview ─> ErpConnector ─Bearer token (abilities, business)─> ERP /api/hq/v1/{business}/overview
ERP: auth:sanctum ─> ability check ─> BusinessContext.set(token business) ─> existing dashboard queries per permission ─> JSON + X-Correlation-Id
HQ: DTO validated ─> snapshot row ─> diff ─> internal events (inventory.low, finance.snapshot) ─> connector_calls
```

## M. Phase 1 dependency graph

```
[1] Shop API additions (LaLaDia upstream) ──merge──> [1b] Bionic
        │                                                  │
        ▼                                                  ▼
[2] hq admin user + token + X-API-Key per store (admin panel, on approval)
        │
        ▼
[3] HQ CommercePlatform interface + LaravelShopAdapter (recorded fixtures first, then live reads against production READ endpoints)
        │
        ├──> [4] Webhook inbox endpoint + signature verification + event schemas
        │          │
        │          ▼
        │      [5] hq webhook subscriber rows on both shops (on approval)  ──> [6] facts_orders + identity handlers + rebuild + nightly reconcile
        │
[7] ERP: sanctum + /api/hq/v1 read endpoints + token issuance (ERP repo, its own CI/deploy)
        │
        ▼
[8] HQ ErpConnector + DTOs + poll tools (scheduler) + overview snapshot
        │
[9] Identity UI (candidates, merge, undo) and Businesses screen wiring ─ needs [6]
[10] Meta: privacy/terms pages per domain; review package; dev-mode webhook receiver on a test Page ─ independent, BLOCKED on the boss's account for anything real
[11] WooCommerce adapter ─ BLOCKED (access + Q6b)
```

## N. Phase 1 implementation order (each step ends with the feature exercised against the real system, then committed)

1. **HQ Commerce contract + fixtures** (NEW, no external dependency): interface, DTOs, exceptions, `LaravelShopAdapter` reads against recorded responses from the *live* public/assistant endpoints (captured once with the existing key, secrets excluded), `capabilities()` from `stores`, `connector_calls`. Tests: contract tests per DTO, adapter tests on fixtures.
2. **Shop upstream changes in LaLaDia** (NEEDS MODIFICATION): availability, orders-by-phone, promotions endpoints; webhook envelope + four events + retry; `Idempotency-Key` on checkout. Tests in the shop suite (1,270 today) plus Playwright where a page changes. Merge down to Bionic, run the fork suite. Deploy on Joy's go, per the shops' own runbooks.
3. **Credentials and subscribers** (admin actions, on approval): create the `hq` admin user + minimal role + Sanctum token on each shop; store `X-API-Key`, token and webhook secret in HQ `integration_credentials`; add the `hq` webhook subscriber rows.
4. **HQ webhook inbox + handlers + facts + identity** (NEW): endpoint, signature and replay checks, JSON schemas, `UpsertFactsOrder`, `ResolveCustomerIdentityFromOrder`, `customers`/`customer_identities`/`customer_merges` migrations, rebuild and reconcile commands, identity review screen. Shadow first: subscribe, process, compare `facts_orders` with the shop's admin order list for a week.
5. **ERP API** (NEW, in the ERP repo): add Sanctum, `routes/api.php`, `SetBusinessContextFromToken`, `hq-api` throttle, controllers over `ReadableData`/reports/existing queries, token issuance command, route-registration test; deploy per the ERP runbook (pre-deploy dump, `docker compose build`, migrate). No business logic touched.
6. **HQ ErpConnector + poll tools** (NEW): DTOs, `erp.poll_overview`, `erp.poll_low_stock` as scheduler tools, snapshot table, internal events.
7. **Meta preparation** (NEW/BLOCKED): privacy and terms pages on each brand domain (shop repos, Blade pages); review package document; dev-mode receiver against a test Page.
8. **Documentation and ADRs**: shop API v1 additions (ADR), ERP API (ADR), identity rules (ADR); doc 06/07/14/15 updated to match code.

Estimated effort: 3 focused weeks as in doc 30, with steps 2 and 5 touching production systems only on Joy's explicit go each time.

## O. Risks and discrepancies found

| # | Finding | Impact | Proposed handling |
|---|---|---|---|
| O-1 | **Docs vs code:** doc 15 says availability follows product status, not quantity (Joy Q9). The shop's `CheckoutPricingService` **refuses** an order line when variant stock is insufficient, and the shop also has a product `status` enum including `out_of_stock`. | An AI that promises "orderable" from status alone can be refused at checkout. | Decision needed (P-1): either the shop stops enforcing quantity for active products, or HQ's rule becomes "status decides the *offer*, checkout decides the *order*" and the AI is taught to say so. Until decided, `getAvailability()` returns both facts. |
| O-2 | **Docs vs code (corrected twice):** doc 06 assumed events `payment.completed` and `shipment.delivered`; the shop actually emits `order.payment_updated` and `shipment.status_updated` (plus `order.created`, `order.status_changed`, `customer.registered`, `coupon.expired`), with 3 retries, but without an event id or timestamp in the envelope. Checkout idempotency also already exists (`checkout_token`), which doc 06 had listed as missing. | Less shop work than planned; HQ accepts the real names; dedupe of the legacy envelope falls back to a body hash until the id lands. | HQ inbox and handlers updated (5 Sep evening); envelope id/timestamp remains the one webhook change. |
| O-3 | **Docs vs code:** doc 06 assumed the ERP could get Sanctum "as a small dependency change". True, but the ERP has **no API layer at all** and its `BusinessContext` is session-driven; a token-driven context setter is new code on the tenancy boundary. | Highest-risk change of Phase 1 (payroll data behind it). | Read-only endpoints only; abilities = permission names; tests that prove a token for business A returns nothing for business B; deploy alone. |
| O-4 | ERP `customers.phone` has no `phone_normalized` (only leads/contacts/tickets do). | Identity match to ERP customers needs normalisation on the HQ side. | HQ normalises at match time; an ERP migration is optional later. |
| O-5 | Shop queue is drained by the scheduler once a minute on cPanel. | Webhooks arrive with up to 60 s delay; a shop outage loses none (queued) but a deploy mid-queue can. | Nightly reconcile pulls missing orders (section G). |
| O-6 | Two shops share one SSLCommerz merchant and one Pathao account. | Misattribution risk in facts. | `store_id` on every fact from the receiving endpoint, never from payload text. |
| O-7 | Meta ownership is moving to an account that does not exist yet. | Every Meta step beyond dev-mode is blocked. | Phase 1 prepares; Phase 3 waits. |
| O-8 | Econic: no access of any kind. | No adapter can be built or tested. | BLOCKED; not on the Phase 1 critical path. |
| O-9 | LaLaDia `main` (`66def463`) is ahead of production (`a6b4ffdc`); Bionic tracks upstream by merge. | Shop API work lands on top of undeployed commits. | Ship step 2 as its own release after the current pending work, or together on Joy's call. |

## P. Open decisions (only Joy / the boss)

| # | Decision | Default if silent |
|---|---|---|
| P-1 | Availability conflict O-1: change the shop checkout to stop refusing on quantity for active products, or keep it and let the AI say "confirmed at checkout"? | keep checkout as is; AI offers, checkout decides |
| P-2 | Which ERP data may HQ read in Phase 1: finance overview yes/no, product cost yes/no, employee headcount yes/no? | all read-only summaries yes; salary/payroll rows never |
| P-3 | Who issues and holds the shop `hq` admin tokens and the ERP tokens (Joy or the boss)? | Joy, stored in HQ credentials, rotated quarterly |
| P-4 | Deploy cadence for the shop API changes: with the pending hilsa work or separately? | separately, smaller blast radius |
| P-5 | Should Econic/WooCommerce be read at all before the rebuild (needs REST keys)? | no; skip until the rebuild |
| P-6 | Privacy policy and terms wording per brand (needed for Meta review): who signs off? | Joy drafts, boss approves |

## Q. What can be built immediately (no approval, no production touch)

- HQ Commerce interface, DTOs, `LaravelShopAdapter` against recorded fixtures and the **live read-only** assistant endpoints (existing key, staging credentials row).
- HQ webhook inbox endpoint, signature verification, JSON schemas, handlers, `facts_orders`, identity tables, rebuild/reconcile commands, identity review screen, all tested with synthetic events.
- ERP API code in the ERP repo on a branch with its tests (no deploy).
- ErpConnector + DTOs against fixtures.
- Privacy/terms page drafts; Meta review package document.
- Shop upstream code (availability/orders/promotions endpoints, envelope, idempotency) on a branch with tests (no deploy).

## R. What must wait for approval or access

- Deploying any shop or ERP change (Joy's go, per runbook, backups first).
- Creating the `hq` users, tokens, keys and webhook subscriber rows on production shops.
- Issuing ERP tokens and switching on the ERP API in production.
- Anything Meta beyond development mode on a test Page: the boss's new account, Business Verification, App Review.
- WooCommerce/Econic: REST keys and the Q6b decision.
- Repointing the Pathao callback away from bizmation (Q11): separate approval, shop side.

**Phase 1 code has not been started. This review is documentation only.**
