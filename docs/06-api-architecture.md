# 06 - API Architecture

Three API surfaces matter: what HQ **consumes**, what HQ **exposes**, and what must be **added** to existing systems.

## 1. APIs HQ consumes (connectors)

| Connector | Base | Auth | Exists today | Notes |
|---|---|---|---|---|
| `LaLaDiaCommerceAdapter`, `BionicCommerceAdapter` (Commerce Platform Interface, doc 15) | `https://<shop>/api/v1` | assistant key header (`chatbot.key`), Sanctum token for admin routes | yes | products, search, combos, shipping zones, geo, checkout, landing checkout; admin: orders, shipments, customers, coupons |
| `ErpConnector` | `https://erp.../api/hq/v1` | bearer token (Sanctum) with per-business ability | **no, must be built** | see section 3 |
| `WooCommerceAdapter` (Econic, **legacy/temporary**) | `https://econic.store/wp-json/wc/v3` | consumer key/secret | API exists in Woo; **keys UNKNOWN** | products, orders, customers, webhooks; replaced by `FutureEconicCommerceAdapter` when the rebuilt platform exists |
| `MetaAdapter` | Graph API v25 | Page tokens, system-user token, app secret | partial (CAPI token, Page token in n8n) | Pages, Messenger, Instagram, comments, Ads Manager, CAPI |
| `GoogleAdapter` | GA4 Data API, Google Ads API, Merchant API | OAuth service account | no | reporting first, Ads later |
| `TikTokAdapter` | Marketing API | OAuth | no | later |
| `ModelProviders` | OpenAI Responses, Anthropic Messages | API keys | keys exist | via `ModelRouter` |
| `TelegramAdapter` | Bot API | bot token | yes (n8n, Hermes) | alerts and command channel |
| `SmsAdapter` | MimSMS HTTP | token | yes (shops, ERP) | reuse ERP `HttpSmsDriver` contract |

Every commerce adapter implements the `CommercePlatform` interface (doc 15); the ERP connector and platform adapters have their own typed interfaces. Connector contract (every connector):
- typed request/response DTOs (PHP 8.3 readonly classes) validated with JSON schema on the boundary;
- idempotency key parameter on every write;
- retries with backoff only on idempotent operations; circuit breaker per host (open after N failures, half-open probe);
- timeouts: 5 s reads, 15 s writes, 60 s media uploads;
- every call logged to `tool_calls` or `connector_calls` with duration and outcome;
- credentials resolved from `integration_credentials` at call time (AI -> Tool Gateway -> Policy -> Permission -> Credential Resolution -> Connector -> Provider), never from config or prompts;
- writes that create something with a provider identity return `{state: confirmed|uncertain, provider_ref}` and support a `reconcile(logical_key)` lookup (ADR-011).

## 2. APIs HQ exposes

| Surface | Path | Consumers | Auth |
|---|---|---|---|
| Web app | `/` (Inertia) | humans | session + 2FA |
| Command API | `/api/v1/commands` | Telegram bridge, future WhatsApp/voice bridges | Sanctum token per channel |
| Webhook inbox | `/webhooks/{provider}` | shops (`order.created`...), Woo, Meta (Messenger, IG, comments, leadgen), Pathao (if repointed), Telegram | provider signature verification, replay protection via `events.dedupe_key` |
| Tool API | `/api/v1/tools/{tool}` | the voice gateway, n8n during transition | Sanctum token scoped to an agent identity; passes the same policy engine as internal calls |
| Health | `/up`, `/api/health` | Traefik, Uptime Kuma | none |

## 3. New ERP API `/api/hq/v1` (additive change to the ERP repo)

Principles: read-first, business-scoped by token ability, reuses the ERP's existing report definitions and permission map so HQ can never read what the token's owner cannot. Version in the path; JSON:API-style envelopes; ETag on collections.

| Group | Endpoints (v1) | Write? |
|---|---|---|
| Businesses | `GET /businesses` | no |
| People | `GET /employees/summary`, `GET /attendance/today`, `GET /leave/pending` | no |
| Finance | `GET /finance/overview`, `GET /finance/receivables`, `GET /finance/payables`, `GET /finance/cash` | no |
| Inventory | `GET /stock`, `GET /stock/low`, `GET /products/{sku}/cost` | no |
| Purchasing | `GET /purchase-orders`, `POST /purchase-recommendations` (creates a *proposal*, not a PO) | proposal only |
| Sales/CRM | `GET /customers`, `GET /customers/{id}/360`, `GET /tickets`, `POST /tickets` | ticket create |
| Approvals | `GET /approvals/pending`, `POST /approvals/{id}/decide` (acts as the token owner) | yes, mirrors UI |
| Reports | `POST /reports/{key}/run` (the 23 existing report definitions) | no |

Implementation notes for the ERP: a new `routes/api.php` group with `auth:sanctum` + `ability:hq:<business>`, controllers that call existing Actions and report definitions (never raw queries), a `BusinessContext` set from the token, and route registration tests so the route-shadowing defect seen in LaLaDia cannot recur. Sanctum is not yet installed in the ERP; adding it is a small dependency change.

## 4. Additions to the shop API (LaLaDia upstream, merged down to Bionic)

- `GET /api/v1/assistant/orders?phone=` (order status for the customer AI, keyed on normalised phone)
- `GET /api/v1/assistant/products/{slug}/availability` (per-variant stock and active promotions)
- new outbound webhook events: `order.status_changed`, `payment.completed`, `shipment.delivered`, `inventory.low`, `customer.message_received` is **not** a shop event (comes from Meta)
- an `hq` webhook subscriber row on each shop pointing at HQ's inbox, HMAC secret stored in HQ.

## 5. Versioning and compatibility

Path versioning (`/v1`). Breaking changes create `/v2` and keep `/v1` alive for one release cycle of every consumer. Contract tests in HQ run against a recorded fixture of each upstream response so an upstream change fails HQ's CI, not production.
