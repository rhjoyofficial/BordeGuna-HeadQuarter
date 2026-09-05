# 34 - Architecture Review (5 September 2026)

Final review of the correction pass before Phase 0 code. Every item below is consistent with docs 00-33 and ADRs 001-013 as of this date.

## A. Decisions now locked

| Decision | Reference |
|---|---|
| HQ is a separate Laravel 12 + Inertia/React/TypeScript application; ERP, stores and finance remain systems of record; HQ owns AI, agents, skills, tools, policies, approvals, scheduling, workflows, events, identity, memory, content, assets, experiments, orchestration, audit, observability, command center | ADR-001, ADR-007, doc 00 |
| PostgreSQL 17 + pgvector + Redis + Horizon; ERP stays on MySQL | ADR-003 |
| Every AI action: Tool Gateway -> Policy -> Permission -> Credential Resolution -> Connector -> Provider, audited; no model-authored SQL, no unrestricted DB access | ADR-004, docs 11, 12, 24 |
| Scheduling is a core product feature: durable `scheduled_jobs`, NL entry, Asia/Dhaka default, pause/resume/edit/cancel/run-now/retry/missed-job policies/history | ADR-005, doc 21 |
| Reliability: one logical slot, unique run record, at-least-once execution, idempotent tools, provider idempotency, reconciliation; **no exactly-once claim** | ADR-011 |
| Commerce Platform Interface; WooCommerce is a temporary legacy adapter; Econic will be a custom, possibly multi-seller platform | ADR-009, doc 15 |
| Domain model: Business, Brand, Store, Product owner, Seller, Channel, Platform account as separate entities | ADR-010, docs 03, 05 |
| Current VPS first, with swap, limits, monitoring, health checks, backups, capacity alerts; second VPS only on evidence | ADR-012, doc 27 |
| Behavior Alignment Layer, no fine-tuning or training | ADR-013, docs 08, 09 |
| Multi-agent registry; tools filtered by role, scope, permission, business, brand, policy, environment | doc 08 |
| Human + AI handoff states AI_ACTIVE, AI_WAITING, HUMAN_REQUIRED, HUMAN_ACTIVE, HUMAN_RESOLVED, AI_RESUMED, CLOSED; humans can take over, edit, resolve, resume, permanently disable AI, mark outcome; feedback feeds alignment | doc 14 |
| Unified customer identity; structured / episodic / semantic memory; transactional truth stays in sources | docs 09, 14 |
| Live commerce tools for every product, price, availability and order fact | docs 11, 15 |
| Availability follows product status, not stock quantity (business policy row, changeable) | doc 15 |
| Platform-neutral marketing core with Meta/Google/TikTok adapters; profit-first contribution metric including refunds and cancellations | docs 16, 23 |
| Platform capability metadata per account; UI and AI see only supported operations | doc 18 |
| Voice (Phase 7+) and avatar/voice cloning (Phase 8+) provider-neutral, consent-gated, not built now | docs 19, 20 |
| Model router with tiers and full cost accounting; provider-neutral business layer (OpenAI and Anthropic first) | doc 26 |
| n8n, Hermes and shop schedulers replaced deliberately: shadow -> compare -> cut over -> deactivate -> observe -> delete only after explicit approval | ADR-006, doc 22 |
| Production safety: no destruction, migration, DNS, Meta, payment or credential changes without explicit approval | doc 24, brief section 23 |
| Repository on the personal GitHub account | Q1 |
| HQ domain `hq.bordeguna.com` (DNS live) | Q2 |
| Local on-VPS PostgreSQL backup; Backblaze B2 removed; no offsite provider; limitation documented | ADR-014, doc 28 |

## B. Decisions still open

| # | Question | Blocks |
|---|---|---|
| Q4 | Which org holds HQ's OpenAI and Anthropic keys and card | Phase 2 (no agents in Phase 0) |
| Q5 | Budget caps and guardrail defaults (doc 12) | Phase 2 |
| Q6b | Rebuilt Econic: fork of the LaLaDia product or new codebase; integrate legacy Woo meanwhile? | Phase 1 adapter list |
| Q7 | Econic as an ERP business row and brand code | Phase 1 mirror |
| Q12-Q16 | Meta portfolio consolidation, single Meta app, privacy pages, Instagram links, WhatsApp WABA | Phases 3, 5, 6 |
| Q25, Q26 | Mail provider after cPanel; CarryBee plaintext credentials | doc 33; security now |
| Others | listed in doc 31 by phase | later phases |

## C. Updated architecture diagram

```
 YOU (owner, staff) ── HQ web + Telegram ──> HQ core (Laravel 12)
                                             Agents · Skills · ModelRouter · Behavior Alignment Layer
                                             ToolGateway -> PolicyEngine -> Permission -> CredentialResolution
                                             ApprovalCenter · Scheduler · Workflows · Events · Identity · Memory · Audit
            ┌──────────────────────────┬─────────────────────────────┬──────────────────────────┐
            v                          v                             v                          v
   ERP connector             Commerce Platform Interface     Platform adapters            Model providers
   /api/hq/v1 (new)          LaLaDia · Bionic · Woo(legacy)  Meta · Google · TikTok       OpenAI · Anthropic
            v                FutureEconic · FutureMultiVendor LinkedIn · YouTube · Telephony
   Bor De Guna ERP (MySQL)           v                             v
   system of record          laladia.com · bionic.garden ·  Facebook/IG/WhatsApp, ads,
                             econic.store · future platform  GA4, phone, media providers
                                     v                             v
                             real-world outcome ──> measurement (facts, events) ──> learning (decision records, evaluations)
```
Infrastructure: one Hostinger KVM 2 today; Docker stack app/web/horizon/scheduler/postgres+pgvector/redis/backup on a private bridge behind the existing Traefik; swap, limits, monitoring first (ADR-012).

## D. Updated domain model

`businesses` 1-n `brands`; `businesses` 1-n `stores`; `stores` n-m `businesses` as product owners; `stores` 1-n `sellers`; `brands` 1-n `platform_accounts`; `channels` as a fixed list; `customers` per business with n `customer_identities`; `conversations` carry business, brand, channel, customer, state and `ai_disabled`; orders (read model) carry store, channel, and per-line seller and product owner. Full DDL sketch in doc 05.

## E. Updated commerce model

`CommercePlatform` interface + DTOs (`Store`, `Product`, `Variant`, `Price`, `Promotion`, `Combo`, `Availability`, `Customer`, `Order`, `Shipment`, `PaymentState`, `Return`); adapters LaLaDia, Bionic, WooCommerce (legacy), FutureEconic, FutureMultiVendor, plus `ErpSalesGateway`; `capabilities()` per adapter; idempotency key on every write with `reconcile()`; prices computed by the store (`preview` then `create`); availability = `{orderable, reason, stock_hint}` under the status-based policy; every order line attributed to seller and product owner; `business_id` derived from order prefix where a merchant is shared.

## F. Updated scheduler model

`scheduled_jobs` (kind once|cron|interval|event, expression, run_at, timezone default Asia/Dhaka, payload, tool_key, agent, owner, approval_state, retry_policy, missed_policy catch_up|skip|run_latest_only, state active|paused|cancelled|done, next_run_at) -> `schedule:work` tick with a Redis lock -> `job_runs` unique per `(job_id, scheduled_for)` (one logical slot) -> Horizon job -> ToolGateway (policy applies) -> run status/output/error, retries with backoff, dead-letter with notification. Natural language -> strict JSON `JobSpec` -> resolver -> preview card -> persisted row or a clarifying question. Reliability per ADR-011: at-least-once, idempotent tools, provider idempotency, reconciliation; watchdog verifies the tick every 10 minutes.

## G. Updated AI / tool / policy model

Agents are rows (role, prompt version, allowed tools, memory scope, model tier, KPIs). Per call the effective tool set = role ∩ scope ∩ enabling human's permission ∩ business ∩ brand ∩ policy ∩ environment. `ToolGateway.execute`: schema validation -> `PolicyEngine` (kill switches, action class, permission, guardrail rules, budgets, consent; default `require_approval`; fails closed) -> approval with the exact stored payload when required -> credential resolution -> connector with idempotency key and `reconcile()` -> `tool_calls` + `audit_log` -> `ai_runs` cost row. Behavior Alignment Layer: `owner_preferences`, `decision_records`, `knowledge_documents`, `evaluations`; no training. Model router: LOW / MEDIUM / HIGH / SPECIALISED tiers, provider-neutral, tracking input/output/cached tokens, latency, model, estimated cost, rolled up to workflow, conversation, customer, order, content, campaign and brand.

## H. Updated deployment topology

Current VPS `srv1808022` (KVM 2). New compose project `bordeguna-hq`: `app` (PHP-FPM, 768 MB), `web` (nginx, Traefik labels for the HQ host), `horizon` (768 MB, worker memory restart at 256 MB), `scheduler` (128 MB), `db` (pgvector/pgvector:pg17, 1 GB), `redis` (320 MB, AOF), `backup` (local pg_dump -Fc into /root/backups/bordeguna-hq, not disaster-proof, ADR-014), optional `uptime-kuma`; private `internal` bridge; only `web` visible to Traefik; `.env` 600 outside git. Host prerequisites: 4 GB swap, monitoring and capacity alerts, a proven ERP restore drill. Staging as a second compose project with `dry_run` connectors. Second VPS (doc 33, ADR-008) only when monitoring says so.

## I. Phase 0 implementation checklist

Host safety (before any HQ container):
- [ ] 4 GB swap file, `vm.swappiness=10`, verified with `swapon --show`
- [ ] Record `free -h`, `docker stats`, `df -h` baseline in `deploy/CAPACITY.md`
- [ ] Verify Docker health, ufw (22/80/443), fail2ban, Traefik certificate resolver
- [ ] Verify ERP, automation and Zeno backups are fresh
- [x] ERP restore drill: **restored the nightly dump into a scratch MySQL and booted the ERP against it** (5 Sep, deploy/RESTORE-DRILL.md). The ERP's inactive offsite definition stays untouched; B2 is not part of the plan (ADR-014)
- [ ] Monitoring and Telegram alerts: RAM available, swap %, disk %, OOM kills, container restarts, health probes

Application scaffold (`apps/hq`):
- [ ] Laravel 12, Inertia + React 19 + TypeScript, Pest, Pint, tsc strict, Vite
- [ ] Postgres 17 + pgvector, Redis, Horizon, `schedule:work`
- [ ] `/up` and `/api/health` with dependency status
- [ ] Authentication, mandatory TOTP 2FA, session and device management
- [ ] RBAC (spatie) with roles mirrored from the ERP where names overlap
- [ ] Domain model migrations: businesses, brands, stores, store_product_owners, sellers, channels, platform_accounts (ADR-010), seeded with the four ERP businesses, LLD/BNC brands and the three current stores
- [ ] `integration_credentials` (encrypted, present/absent UI, expiry) and `OAuthManager` skeleton
- [ ] Feature flags table + middleware/helper
- [ ] Audit log foundation (append-only, actor human|agent|system, before/after, reason)
- [ ] Structured JSON logging with request/run correlation ids
- [ ] Cost/event/tracing foundation: `ai_runs`, `tool_calls`, `connector_calls`, `events` (inbox/outbox with dedupe), `owner_preferences`, `decision_records` tables and their repositories, no agents wired
- [ ] Scheduler skeleton: `scheduled_jobs`, `job_runs` (unique slot), tick, run history UI, pause/resume/cancel/run-now; NL entry deferred to Phase 2
- [ ] Docker stack `deploy/` per section H, image build, entrypoint, health checks, limits
- [ ] Backup container (nightly `pg_dump -Fc`, local, verified, 14 daily / 8 weekly) and a restore drill into a scratch database, run once and recorded; limitation documented: not disaster-proof, no offsite provider (ADR-014)
- [ ] Deployment scripts and `deploy/README.md` runbook (pre-deploy checklist, release, rollback)
- [ ] CI: Pest on Postgres 17 + Redis services, Pint, tsc, Vite build, route-registration test
- [ ] `apps/hq/README.md` local development instructions (WSL2/Docker or Laragon services, `dry_run` connectors)
- [ ] Exit: `/up` 200 behind Traefik on the HQ host, login with 2FA in a real browser, one scheduled no-op job executed and visible in run history, backup verified, alerts firing on a test condition

## J. Risks

| Risk | Mitigation |
|---|---|
| Host memory pressure with HQ added to an 8 GB box without swap | swap + per-container limits + alerts before deploy (ADR-012); scale on evidence |
| No backup on this VPS survives loss of the VPS (all local; B2 not available) | documented limitation (ADR-014, doc 28); Hostinger snapshot setting to be confirmed; `OFFSITE_HOOK` extension point for a future provider |
| Meta App Review latency gates customer AI and publishing | start in Phase 1; Dev Mode keeps Joy-only testing possible |
| Econic unknowns (account, keys, volume) | Woo adapter optional; interface protects HQ either way |
| Shared SSLCommerz merchant and Pathao account across brands | `business_id` from order prefix, documented in finance |
| Secrets sprawl (n8n store, `.env` files, OneDrive plaintext CarryBee file) | `integration_credentials` as the one store; CarryBee file removal this week (Q26) |
| Prompt injection via customer text | typed tools, no free-text SQL/URLs/shell, permission-derived exposure, output filters |
| Duplicate external side effects on retries | ADR-011 reconciliation; uncertain state alerts a human |
| Over-building before value | Phase 0 is foundation only; agents and integrations arrive per phase with shadow mode |

## K. External dependencies

Hostinger VPS (current), GitHub (personal account), Traefik (existing), Meta App Review and tokens (Phases 3, 5, 6), OpenAI and Anthropic accounts in an agreed org (Phase 2), WooCommerce REST keys if Econic legacy integration is chosen (Phase 1), Google Cloud / Ads / Merchant (Phase 5+), TikTok (later), telephony provider (Phase 7), HeyGen and voice providers (Phase 8), a mail provider when cPanel mail moves (doc 33).

## L. What will NOT be built yet

No AI agents, skills or model calls in Phase 0. No Messenger, Instagram, WhatsApp or comment handling. No marketing adapters or campaign automation. No content generation or publishing. No natural-language scheduling (Phase 2). No customer identity resolution or memory (Phase 1). No ERP API changes (Phase 1). No commerce adapters (Phase 1). No voice, avatar or voice cloning. No fine-tuning ever in the current plan. No migration of shops, DNS, Meta configuration, payments or credentials. No deletion of n8n workflows, Hermes or ERP functionality.

---

**Stop point.** Phase 0 implementation was authorised on 5 Sep 2026 with `hq.bordeguna.com` as the production hostname.
