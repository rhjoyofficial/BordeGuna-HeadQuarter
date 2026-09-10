# 30 - Development Roadmap

> ## ⛔ GOVERNED BY THE ARCHITECTURE CHECKPOINT (10 September 2026)
>
> **Read `docs/60-architecture-checkpoint-2026-09-10.md` before implementing any phase below.**
>
> HQ is a business operating system with a ten-to-twenty-year horizon, not a collection of AI
> features. One principle governs every phase: **AI models, AI vendors, tool protocols, external
> providers and infrastructure implementations must remain replaceable; HQ's business and control
> contracts must remain durable.**
>
> Nine invariants and eleven boundaries are set out in `docs/60`. The decisions themselves are ADRs,
> and an ADR is what a change to a phase below has to argue with:
>
> | | |
> | --- | --- |
> | ADR-029 | The Tool Gateway and Capability Registry are canonical. **MCP is an adapter, not the governance layer.** An agent reasons about a capability, never a vendor endpoint. |
> | ADR-030 | Evaluation measures six dimensions, and autonomy consumes dimension-scoped evidence rather than one averaged number. |
> | ADR-031 | Consequential execution is verification-first. **A 200 is not a postcondition.** |
> | ADR-032 | Agents decide what should happen; durable infrastructure decides how it progresses. **No process state lives in a model.** |
> | ADR-033 | Memory is never truth, whatever transport it arrived on. |
> | ADR-034 | Approval-required is a **stage** evidence can end; HUMAN_ONLY is a **decision** it cannot. |
>
> ⚠ Phases 3 to 10 below were written before this checkpoint. Their *sequence* stands; their
> implementation is bound by the boundaries above wherever the two differ.

Phases are adjusted to what actually exists. Sizes are for one senior engineer working with Claude Code, part-time alongside the ERP and shops; "wk" = working weeks of focused effort, not calendar time.

| Phase | Name | Delivers | Size | Hard external dependency |
|---|---|---|---|---|
| **0** | Foundation | Laravel 12 HQ app (Inertia/React/TS) with health endpoint, auth, 2FA, RBAC; business/brand/store/platform-account model (ADR-010); `integration_credentials`; feature flags; audit log foundation; structured JSON logging; cost/event/tracing foundation (`ai_runs`, `tool_calls`, `events` tables and correlation ids, no agents yet); scheduler skeleton (`scheduled_jobs`, tick, run history, no NL yet); Docker stack (app/web/horizon/scheduler/postgres+pgvector/redis/backup) on the **current** VPS after the pre-deploy checklist (doc 27); local PostgreSQL backup (pg_dump -Fc, retention, verification) with a proven restore drill and a documented not-disaster-proof limitation (ADR-014); monitoring and capacity alerts; deployment scripts; CI on Postgres 17; local development instructions. ERP restore drill passed first. **No agents, no social integrations, no marketing APIs, no customer AI.** | 2-3 wk | none |
| **1** | Identity, data, API | ERP `/api/hq/v1` (read-first) in the ERP repo; shop assistant API additions + webhook events (upstream, merged to Bionic); Commerce Platform Interface with `LaLaDiaCommerceAdapter`, `BionicCommerceAdapter`, legacy `WooCommerceAdapter` (if Econic access is obtained); connectors with fixtures; event inbox/outbox; unified customer identity + rebuild; `facts_orders`; Meta App Review **started** | 3 wk | Woo access; Meta App Review clock starts |
| **2** | Command Center | tool gateway + policy engine + approval center; agent runtime with `executive` and `analytics`; model router with cost accounting; "Ask HQ" UI; **scheduler with NL entry** (doc 21); daily briefing skill replacing the ERP-only briefing; observability dashboards | 3 wk | none |
| **2.5** | **Shared Foundation** | **Approved 8 Sep 2026 (docs/53).** The six subsystems every later phase would otherwise build in its own shape: Platform Capability Registry (ADR-022); Consent and Compliance ledger, append-only with a rebuildable projection, enforced at the gateway (ADR-021); Autonomy Control Plane, a separate resolver combining by minimum and failing closed (ADR-020); Evaluation subsystem (ADR-023); Media Asset service with metadata in Postgres and bytes behind a storage driver; Conversation and Memory core. Plus content provenance labelling (ADR-024) and the descriptor fields each subsystem reads (ADR-026). **No customer-facing AI, no R4/R5 enablement, no autonomous execution.** | 2-3 wk | none |
| **3** | Customer AI | conversation engine + handoff state machine + inbox UI; Messenger channel adapter in shadow mode beside the n8n bot, then cutover; Instagram DMs and Facebook comments; customer memory; `support` agent | 3 wk | Meta App Review approved for messaging scopes (else stays Dev Mode: Joy-only) |
| **4** | AI orders + commerce automation | `take_order` skill through the shop checkout API with value-gated approval; order lifecycle workflow (follow-ups, review request); stock protection; ERP tickets from conversations; `operations` and `inventory` agents | 2 wk | none (Woo orders only if Phase 1 chose integration) |
| **5** | Marketing AI | `marketing_experiments`, Meta Ads adapter with a new ads-scoped system-user token, hourly insights sync, campaign guardian workflow with guardrails, contribution reporting (needs ERP cost prices filled); Google Ads/TikTok adapters later behind the same interface | 3 wk | ads token; Meta portfolio consolidation; product feed for Google/TikTok |
| **6** | Content factory + publishing | content items, brand personas, generation tools (text, image via OpenAI, compositor), QA, approval, platform variants, publishing adapters (Facebook, Instagram, LinkedIn first), metrics collection, weekly plan skill; **the Research Engine** (source provenance, freshness, credibility, fact separated from inference, research artifacts), which stays in this phase rather than the shared foundation because it has one real consumer and no safety role; n8n content workflows retired | 3 wk | Page tokens per brand; LinkedIn API access |
| **7** | Voice | voice gateway (Node), telephony provider, COD confirmation calls first, inbound receptionist second | 3 wk + provider lead time | telephony provider + Bangla realtime quality test |
| **8** | Avatar + voice clone | media identities with consent, HeyGen/voice providers as tools, presenter videos in the factory | 2 wk | consent decisions, provider budgets |
| **9** | Autonomous workflows + skills | skill proposal loop; autonomy widened per ADR-023 against **measured agreement rates recorded by the Phase 2.5 evaluation subsystem**, never on judgement; cross-business executive delegation. Permanently human-only tools (ADR-025) are out of scope here by definition | 2 wk | **evaluation history actually accumulated from 2.5 onward** (the old plan assumed data nothing was recording) |
| **10** | Controlled autonomous operations | widen autonomy grants per measured agreement rate; weekly evaluation gates; owner sees only exceptions. A5 remains unreachable (ADR-020) and the permanent human-only class never widens | ongoing | trust earned, not scheduled |

Total to the end of Phase 6: roughly **21 to 22 focused weeks** with Phase 2.5 included; calendar time depends on how much of Joy's week HQ gets. Phases 5 and 6 can run in either order; Phase 3 is the one most likely to stall on Meta review, so its review starts in Phase 1.

## Dependency graph

```
P0 Foundation
 |
 +-- P1 Identity/Data/API -- P2 Command Center (M1..M7) -- P2.5 SHARED FOUNDATION --+
 |        |                                                                         |
 |        |     P2.5 delivers, in this order:                                       |
 |        |       1 Platform Capability Registry   (ADR-022)                        |
 |        |       2 Consent and Compliance ledger  (ADR-021)                        |
 |        |       3 Autonomy Control Plane         (ADR-020)                        |
 |        |       4 Evaluation subsystem           (ADR-023)                        |
 |        |       5 Media Asset service                                             |
 |        |       6 Conversation and Memory core                                    |
 |        |                                                                         |
 |        |                       every phase below consumes all six ---------------+
 |        |                                                                         |
 |        |    +--> P3 Customer AI --> P4 AI Orders ----------------------+         |
 |        |    |                                                          |         |
 |        +----+--> P5 Marketing AI --------------------------+           |         |
 |        |    |                                              |           |         |
 |        |    +--> P6 Content + Publishing + RESEARCH ENGINE +-----------+--> P9 --+--> P10
 |        |    |                                              |           |
 |        |    +--> P7 Voice (after P3, needs telephony and   |           |
 |        |    |             recording consent from P2.5)     |           |
 |        |    |                                              |           |
 |        |    +--> P8 Avatar (after P6; BLOCKED until         |           |
 |        |                   synthetic identity consent       |           |
 |        |                   records exist in P2.5's ledger)  |           |
 |        |                                                                |
 |        +-- Meta App Review (starts P1, gates P3 channels and P6 scopes) +
 |
 +-- Hosting migration / second VPS (doc 33, ADR-012): optional track, triggered by
     capacity monitoring, not a prerequisite for any phase

 P9 and P10 widen autonomy ONLY against evaluation evidence recorded from P2.5 onward (ADR-023).
```

## Per-phase exit criteria

Every phase ends with: tests green in CI on Postgres; the feature exercised against the real ERP/shop/platform in a browser or a real channel; backups verified; a runbook entry; the dashboards showing the new activity; an ADR if a boundary moved. Nothing is "done" on a green suite alone.

## Immediate next implementation step

**Approved sequence, 8 September 2026.** This replaces the Phase 0 text that stood here until Phase 0
completed on 5 September.

1. **Phase 2 M4 merge.** M4 is code-complete and green. PR #6 carries the model router, provider
   drivers, fallback, circuit breaker, structured output, AI run and cost accounting, budget
   enforcement, the provider probe, and ADR-025's human-only split.
2. **Production deployment, on Joy's explicit authorization only.** The order is fixed: backup,
   deploy, `migrate --force`, `ModelTierSeeder`, install the Anthropic credential, verify
   `ai.runtime` is still controlled, `hq:ai:probe --dry`, then a deliberate live safety gate, then
   verify the `ai_runs` row and `hq:ai:cost`.
3. **The real provider safety gate**, which is the M4 exit criterion and needs the API key from Q4.
4. **Phase 2.5 Shared Foundation**, design then implementation. Not started.
5. **M5 agents.** Not started.
6. **Then** the customer, commerce, marketing, content and voice phases in the order above.

Standing constraints while that sequence runs: no customer-facing AI, no R4 or R5 tool enabled, no
autonomous execution, and no real business write capability. Nothing is enabled at R4 or above today
and no tool is agent-executable.

Decisions already taken and not reopened: personal GitHub account, current VPS first (ADR-012),
Econic rebuilt later behind the Commerce Platform Interface (ADR-009), no fine-tuning ever
(ADR-013), and the six ADRs recorded on 8 September (ADR-020 to ADR-026, minus ADR-025 which is
already implemented). Still open: Q4's provider key installation, and the questions in doc 31.
