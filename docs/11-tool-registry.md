# 11 - Tool Registry

Tools are the **only** way an agent touches the world. Each is a PHP class implementing `Tool` with: `key`, `inputSchema()`, `outputSchema()`, `permission()`, `policyClass()`, `actionClass()` (AUTO / APPROVAL_REQUIRED / HUMAN_ONLY default), `idempotent()`, `execute(ToolContext, array $args)`. The registry is a table plus the class map; the AI receives tool definitions generated from the schemas.

## v1 tools

| Tool | Backed by | Action class | Notes |
|---|---|---|---|
| `get_product(business, slug\|sku)` | shop `/api/v1/assistant/products/{slug}` | AUTO | includes variants, prices, active promos |
| `search_products(business, query)` | shop `/assistant/products/search` | AUTO | |
| `get_combo(s)` | shop `/assistant/combos` | AUTO | |
| `get_inventory(business, sku)` | shop availability endpoint (new) + ERP `/stock` | AUTO | returns both views when both exist |
| `get_shipping_quote(business, district, thana, lines)` | shop `/checkout/preview` | AUTO | |
| `get_customer(identity)` | HQ identity + shop admin customers + ERP CRM | AUTO (permission-scoped) | |
| `get_customer_history(customer_id)` | HQ facts + shop orders | AUTO | |
| `get_order(business, ref)` | shop admin orders | AUTO | |
| `create_order(business, customer, lines, address, payment)` | shop `/api/v1/checkout` or landing checkout | AUTO under value threshold, else APPROVAL | idempotency key = conversation + draft hash |
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
3. Writes carry an idempotency key; a retried call with the same key returns the first result.
4. Per-agent and per-business rate limits (Redis) and a daily cost budget per agent.
5. A tool can be disabled at runtime (kill switch) without a deploy; disabling is audited.
6. Tools are exposed to the model in the model provider's function-calling format, generated from the same schema used for validation (single source).

## Adding a tool (checklist)

Schema -> permission -> policy -> action class -> connector method with idempotency -> unit tests with recorded fixtures -> registry row -> evaluation case in doc 29 -> ADR if it changes a boundary.
