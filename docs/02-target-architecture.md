# 02 - Target Architecture

## The decision in one paragraph

HQ is a **new, separate Laravel 12 application** (Inertia + React + TypeScript, the stack every existing system already uses) deployed in Docker on the **current** Hostinger VPS next to the ERP, with swap, resource limits and capacity monitoring added first; a second VPS is a documented scaling option, not a prerequisite (ADR-012). It owns *no business truth*. It owns the control surface, the AI agents, the tool gateway, the policy/approval layer, the durable scheduler and workflow engine, the event bus, customer identity resolution, the asset library, and observability. It reaches the ERP, the two Laravel shops, WooCommerce, Meta, Google and every other system through **typed connectors**. See ADR-001 for why not "extend the ERP" and ADR-002 for why not a Node/Python core.

```
                          +-----------------------------+
   Owner / staff  ----->  |  HQ web (Inertia/React)     |  <----- Telegram / WhatsApp command channel (later)
                          |  Command Center, Approvals, |
                          |  Inbox, Scheduler, Assets   |
                          +--------------+--------------+
                                         |
                          +--------------v--------------+
                          |  HQ core (Laravel 12)       |
                          |  Agents  Skills  ModelRouter|
                          |  ToolGateway  PolicyEngine  |
                          |  Approval  Scheduler  Events|
                          |  Identity  Memory  Audit    |
                          |  Behavior Alignment Layer   |
                          +---+----------+----------+---+
                              |          |          |
              +---------------+          |          +----------------------+
              v                          v                                 v
   +--------------------+   +---------------------------------+   +------------------------+
   | ERP connector      |   | Commerce Platform Interface     |   | Platform adapters      |
   | (new ERP API,      |   |  LaLaDiaCommerceAdapter         |   | Meta, Google, TikTok,  |
   |  read-first)       |   |  BionicCommerceAdapter          |   | LinkedIn, YouTube,     |
   +---------+----------+   |  WooCommerceAdapter (legacy)    |   | Telephony, HeyGen ...  |
             v              |  FutureEconicCommerceAdapter    |   +------------------------+
     Bor De Guna ERP        |  FutureMultiVendorAdapter       |
     (system of record)     +----------------+----------------+
                                             v
                     LaLaDia, Bionic, econic.store (Woo, legacy), the rebuilt Econic platform
                     (commerce truth stays in each store)
```

## Layers

| Layer | Responsibility | Owned by |
|---|---|---|
| Systems of record | employees, ledgers, inventory, procurement, orders, products, prices, customers-as-transactions | ERP, shops, Woo (unchanged) |
| Connectors | typed read/write access to each system, credentials, retries, idempotency keys, rate limits | HQ `app/Connectors/*` |
| Commerce Platform Interface | one contract for every store (LaLaDia, Bionic, Woo as legacy, the future Econic and multi-vendor platforms); WooCommerce is an adapter, never the model (ADR-009) | HQ `app/Commerce/*` |
| Tool Gateway | the only door the AI can use: `get_product`, `create_order`, `pause_campaign`... each a typed class with schema, permission, policy class | HQ `app/Tools/*` |
| Policy + Permission + Approval | AUTO / APPROVAL_REQUIRED / HUMAN_ONLY per tool and per argument thresholds; RBAC per user and per agent; approval queue with SLA | HQ |
| Agents + Skills + Model Router | agent registry (role, prompt, tools, memory scope, KPIs), skill registry, provider-agnostic model calls with cost tracking | HQ |
| Scheduler + Workflow engine | durable jobs (cron, one-time, interval, event-triggered), natural-language to job, per-run history, retry, pause/resume | HQ |
| Event bus | canonical events received from connectors and emitted internally; outbox table; Redis for fan-out | HQ |
| Identity + Memory | unified customer identity, structured memory, episodic conversation memory, semantic retrieval (pgvector) | HQ |
| Behavior Alignment Layer | preferences, decision memory, SOPs, approved/rejected actions, edits, outcome evaluation; how the AI learns the owner's way of working without any model training (ADR-013) | HQ |
| Observability + Audit | every AI call, tool call, approval and outcome; cost per unit; dashboards | HQ |
| Channels | web UI first; Telegram command channel (already in use); Messenger/Instagram/WhatsApp as customer channels via Meta adapters | HQ |

## Technology stack (recommended)

| Concern | Choice | Reason |
|---|---|---|
| Application | Laravel 12 + Inertia + React 19 + TypeScript | Identical to ERP and shop admin; Claude Code and Joy are fluent; the ERP's approval, RBAC and AI-driver patterns are copy-and-adapt |
| Database | PostgreSQL 17 + `pgvector`, HQ's own container | JSONB for traces and memory, pgvector avoids a separate vector DB; the ERP stays on MySQL untouched |
| Cache / queues / locks | Redis 7 | Horizon queues, rate limits, scheduler locks, streams |
| Queue runtime | Laravel Horizon | Supervised workers, retries, tags, dashboard; the `database` driver used elsewhere cannot carry this |
| Scheduler | `schedule:work` tick driving an HQ `scheduled_jobs` table | Durable, timezone-aware, per-run history; cron is only the tick |
| Workflows | HQ `workflow_runs` state machine over queued steps (saga pattern) | Long multi-step automations with checkpoints; n8n kept for tactical flows during transition |
| Object storage | none in Phase 0 (local disk); an S3-compatible store (Hostinger Object Storage or similar) is a later phase when media volume needs it. Backblaze B2 is not available and not part of the plan | Media and generated content, later |
| AI providers | OpenAI Responses API + Anthropic Messages API behind `ModelRouter`; drivers modelled on the ERP's `AssistantDriver` | Provider-agnostic business layer; per-task tier routing |
| Realtime voice (Phase 7) | Small Node "voice gateway" between telephony and a realtime model, calling HQ tools over HTTP | PHP is the wrong runtime for bidirectional audio; isolate it |
| Reverse proxy / TLS | Existing Traefik | Labels only |
| Observability | Structured JSON logs, `ai_runs` / `tool_calls` tables, Horizon dashboard, optional Uptime Kuma | Enough for one operator, on-box |

## What is reused as-is

- ERP: multi-business model, approval engine design, `PhoneIdentity`, permission-derived tool exposure, AI driver classes, messaging drivers, deploy pattern.
- Shops: `/api/v1/assistant/*` read tools, checkout API for order creation, admin API for order management, outbound webhooks, Meta CAPI, GTM.
- n8n: Messenger handling and the Bionic bot as the **transitional** customer AI until HQ's channel adapter replaces it; content-render; Telegram alerting.
- Existing Meta assets, pixels, GTM containers, ad account, domain verification (all portable, none code-bound).

## What must change in existing systems (small, additive)

1. **ERP gains an HQ-facing API** (`/api/hq/v1/*`, token-authenticated, read-first). Design in `06-api-architecture.md`.
2. **Shops extend the assistant API** with variant stock, promotions and order-lookup-by-phone, and add outbound webhook events (`order.status_changed`, `shipment.delivered`, `payment.completed`).
3. **Econic** is integrated today through a legacy `WooCommerceAdapter` and will be rebuilt as a custom, potentially multi-seller commerce platform; HQ reaches it only through the Commerce Platform Interface, so the rebuild changes an adapter, not HQ (ADR-009).
4. **n8n bot** switches its order tools to call HQ so every AI order passes the policy layer. Until then it stays as it is.

## What is missing entirely and must be built

Command center, agent registry, skills registry, tool gateway, policy engine, approval center, durable scheduler with NL entry, workflow engine, event bus, unified customer identity, conversation/handoff state machine, marketing core + adapters, content factory, publishing adapters, voice gateway, media identity/consent, asset library, model router with cost accounting, observability, secrets handling, HQ deployment stack.

## Non-goals for the first year

Replacing the ERP or the shop platform; a data warehouse before there is volume to justify it; fine-tuning or training models; any autonomous action that touches money, legal or employees without a human.
