# 11 - Tool Registry

Tools are the **only** way an agent touches the world. The full chain is always: AI -> Tool Gateway -> Policy -> Permission -> Credential Resolution -> Connector -> Provider, with audit at every step. Exposure is filtered per call by role, scope, permission, business, brand, policy and environment (doc 08). Each is a PHP class implementing `Tool` with: `key`, `inputSchema()`, `outputSchema()`, `permission()`, `policyClass()`, `actionClass()` (AUTO / APPROVAL_REQUIRED / HUMAN_ONLY default), `idempotent()`, `execute(ToolContext, array $args)`. The registry is a table plus the class map; the AI receives tool definitions generated from the schemas.

## v1 tools

| Tool | Backed by | Action class | Notes |
|---|---|---|---|
| `search_products(store, query)` | commerce adapter | AUTO | live |
| `get_product(store, slug\|sku)` | commerce adapter | AUTO | includes variants, prices, active promos, status |
| `get_variant(store, variant_id)` | commerce adapter | AUTO | pack size, price, status |
| `get_combo(s)` | commerce adapter | AUTO | |
| `get_price(store, variant, qty, district?)` | adapter preview | AUTO | tiers, free-delivery rules, promotions computed by the store |
| `get_availability(store, variant)` | adapter | AUTO | `{orderable, reason, stock_hint}`; **orderable follows product status, not stock quantity** (business policy, doc 15) |
| `get_inventory(business, sku)` | ERP `/stock` + store stock hint | AUTO | accounting view for the inventory agent, never used to refuse a customer |
| `get_shipping_quote(business, district, thana, lines)` | shop `/checkout/preview` | AUTO | |
| `get_customer(identity)` | HQ identity + shop admin customers + ERP CRM | AUTO (permission-scoped) | |
| `get_customer_history(customer_id)` | HQ facts + shop orders | AUTO | |
| `get_order(business, ref)` | shop admin orders | AUTO | |
| `create_order(store, customer, lines, address, payment)` | commerce adapter (`preview` then `create`) | AUTO under value threshold, else APPROVAL | idempotency key = conversation + draft hash; uncertain provider response -> reconcile by key before any retry (ADR-011) |
| `update_order(business, ref, changes)` | shop admin order routes | APPROVAL_REQUIRED | allowed fields whitelisted |
| `cancel_order` | shop admin | APPROVAL_REQUIRED | |
| `send_message(conversation, text, attachments)` | Meta adapter / WhatsApp / SMS | AUTO for replies in an AI_ACTIVE conversation; APPROVAL for outbound campaigns | consent checked |
| `request_human(conversation, reason)` | HQ conversation engine | AUTO | |
| `resume_ai(conversation)` | HQ | HUMAN_ONLY (button) | |
| `create_notification(target, text, severity)` | Telegram/email/in-app | AUTO | |
| `generate_report(key, params)` | ERP `/reports/{key}/run`, HQ facts | AUTO | |
| `create_ticket(business, customer, subject)` | ERP `/tickets` | AUTO | |
| `create_purchase_recommendation(items)` | ERP proposal endpoint | AUTO (proposal only) | never creates a PO |
| `create_campaign(experiment)` | Meta/Google/TikTok adapter | APPROVAL_REQUIRED | |
| `update_campaign(id, changes)` | adapter | AUTO within guardrails (doc 12), else APPROVAL | |
| `pause_campaign(id, reason)` | adapter | AUTO when a stop-loss rule fires, else APPROVAL | pausing is the safe direction |
| `resume_campaign` | adapter | APPROVAL_REQUIRED | |
| `delete_campaign` | adapter | HUMAN_ONLY | |
| `schedule_content(item, platform, at, tz)` | HQ scheduler | AUTO (creates a pending job); publishing itself follows the content approval state | |
| `publish_content(item, platform)` | publishing adapter | APPROVAL_REQUIRED unless the item is already approved | |
| `generate_image(brief)` / `generate_video(brief)` | media providers | AUTO with cost cap per day | |
| `schedule_job(nl_text \| spec)` | HQ scheduler | AUTO for read-only jobs; inherits the class of the tool the job will run | |

## Gateway guarantees

1. Arguments are validated against the schema **before** policy evaluation; a malformed call never reaches a connector.
2. Every call is written to `tool_calls` with input, output (or error), policy result, approval id and duration.
3. Writes carry an idempotency key passed to the provider where supported. Execution is **at-least-once**: a retried call with the same key returns the first result when HQ recorded it, and otherwise runs the tool's `reconcile()` (look the side effect up by logical key at the provider) before deciding whether to execute again (ADR-011).
4. Per-agent and per-business rate limits (Redis) and a daily cost budget per agent.
5. A tool can be disabled at runtime (kill switch) without a deploy; disabling is audited.
6. Tools are exposed to the model in the model provider's function-calling format, generated from the same schema used for validation (single source).
7. Every tool answers product, price, availability and order questions from the live commerce adapter in the same run; no such fact is ever served from model memory or from a stale retrieved chunk.

## Adding a tool (checklist)

Schema -> permission -> policy -> action class -> connector method with idempotency -> unit tests with recorded fixtures -> registry row -> evaluation case in doc 29 -> ADR if it changes a boundary.
