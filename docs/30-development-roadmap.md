# 30 - Development Roadmap

Phases are adjusted to what actually exists. Sizes are for one senior engineer working with Claude Code, part-time alongside the ERP and shops; "wk" = working weeks of focused effort, not calendar time.

| Phase | Name | Delivers | Size | Hard external dependency |
|---|---|---|---|---|
| **0** | Foundation | Laravel 12 HQ app (Inertia/React/TS) with health endpoint, auth, 2FA, RBAC; business/brand/store/platform-account model (ADR-010); `integration_credentials`; feature flags; audit log foundation; structured JSON logging; cost/event/tracing foundation (`ai_runs`, `tool_calls`, `events` tables and correlation ids, no agents yet); scheduler skeleton (`scheduled_jobs`, tick, run history, no NL yet); Docker stack (app/web/horizon/scheduler/postgres+pgvector/redis/backup) on the **current** VPS after the pre-deploy checklist (doc 27); local PostgreSQL backup (pg_dump -Fc, retention, verification) with a proven restore drill and a documented not-disaster-proof limitation (ADR-014); monitoring and capacity alerts; deployment scripts; CI on Postgres 17; local development instructions. ERP restore drill passed first. **No agents, no social integrations, no marketing APIs, no customer AI.** | 2-3 wk | none |
| **1** | Identity, data, API | ERP `/api/hq/v1` (read-first) in the ERP repo; shop assistant API additions + webhook events (upstream, merged to Bionic); Commerce Platform Interface with `LaLaDiaCommerceAdapter`, `BionicCommerceAdapter`, legacy `WooCommerceAdapter` (if Econic access is obtained); connectors with fixtures; event inbox/outbox; unified customer identity + rebuild; `facts_orders`; Meta App Review **started** | 3 wk | Woo access; Meta App Review clock starts |
| **2** | Command Center | tool gateway + policy engine + approval center; agent runtime with `executive` and `analytics`; model router with cost accounting; "Ask HQ" UI; **scheduler with NL entry** (doc 21); daily briefing skill replacing the ERP-only briefing; observability dashboards | 3 wk | none |
| **3** | Customer AI | conversation engine + handoff state machine + inbox UI; Messenger channel adapter in shadow mode beside the n8n bot, then cutover; Instagram DMs and Facebook comments; customer memory; `support` agent | 3 wk | Meta App Review approved for messaging scopes (else stays Dev Mode: Joy-only) |
| **4** | AI orders + commerce automation | `take_order` skill through the shop checkout API with value-gated approval; order lifecycle workflow (follow-ups, review request); stock protection; ERP tickets from conversations; `operations` and `inventory` agents | 2 wk | none (Woo orders only if Phase 1 chose integration) |
| **5** | Marketing AI | `marketing_experiments`, Meta Ads adapter with a new ads-scoped system-user token, hourly insights sync, campaign guardian workflow with guardrails, contribution reporting (needs ERP cost prices filled); Google Ads/TikTok adapters later behind the same interface | 3 wk | ads token; Meta portfolio consolidation; product feed for Google/TikTok |
| **6** | Content factory + publishing | content items, brand personas, generation tools (text, image via OpenAI, compositor), QA, approval, platform variants, publishing adapters (Facebook, Instagram, LinkedIn first), metrics collection, weekly plan skill; n8n content workflows retired | 3 wk | Page tokens per brand; LinkedIn API access |
| **7** | Voice | voice gateway (Node), telephony provider, COD confirmation calls first, inbound receptionist second | 3 wk + provider lead time | telephony provider + Bangla realtime quality test |
| **8** | Avatar + voice clone | media identities with consent, HeyGen/voice providers as tools, presenter videos in the factory | 2 wk | consent decisions, provider budgets |
| **9** | Autonomous workflows + skills | skill proposal loop, more AUTO classes earned by evaluation history, cross-business executive delegation | 2 wk | evaluation data from Phases 2-6 |
| **10** | Controlled autonomous operations | widen AUTO thresholds per measured agreement rates; weekly evaluation gates; owner sees only exceptions | ongoing | trust earned, not scheduled |

Total to the end of Phase 6: roughly **19 focused weeks**; calendar time depends on how much of Joy's week HQ gets. Phases 5 and 6 can run in either order; Phase 3 is the one most likely to stall on Meta review, so its review starts in Phase 1.

## Dependency graph

```
P0 Foundation
 ├── P1 Identity/Data/API ──┬── P2 Command Center ──┬── P3 Customer AI ── P4 AI Orders ──┐
 │        │                  │                       ├── P5 Marketing AI ─────────────────┤
 │        │                  │                       └── P6 Content + Publishing ─────────┼── P9 Skills/Autonomy ── P10
 │        └── Meta App Review (starts P1, gates P3 channels, P6 publishing scopes)        │
 │                                                                                        │
 └── Hosting migration / second VPS (doc 33, ADR-012) ── optional track, triggered by     │
            capacity monitoring, not a prerequisite for any phase                          │
 P7 Voice (after P3, needs telephony)  ── P8 Avatar (after P6) ─────────────────────────┘
```

## Per-phase exit criteria

Every phase ends with: tests green in CI on Postgres; the feature exercised against the real ERP/shop/platform in a browser or a real channel; backups verified; a runbook entry; the dashboards showing the new activity; an ADR if a boundary moved. Nothing is "done" on a green suite alone.

## Immediate next implementation step

**Phase 0, step 1: capacity and safety before code.** Run the pre-deploy checklist in doc 27 on the current VPS: swap, usage, Docker health, firewall, backups with a real restore test of the ERP dump, reverse proxy, disk, RAM headroom, monitoring. Then scaffold `apps/hq` (Laravel 12, Inertia/React/TS, Pest, Horizon, Postgres 17 + pgvector, Redis) with the `deploy/` stack copied from the ERP's proven compose, get `/up` answering at `hq.bordeguna.com` behind Traefik, and CI green on an empty app. Only then does Phase 1 begin with the ERP API.

Decisions already taken for Phase 0: personal GitHub account (done), current VPS first (ADR-012), Econic rebuilt later behind the Commerce Platform Interface (ADR-009). Still open before code: the HQ domain name (Q2) and which provider org holds HQ's AI keys (Q4); see doc 31.
