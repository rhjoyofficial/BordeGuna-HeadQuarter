# 95 - Vision → Current System → Gap → Execution Plan (3 October 2026)

**Written for Joy**, against his vision document `C:\Users\media\Documents\I have been thinking about automation - BordeGuna HQ.txt` (read in full, 61 paragraphs) and his audit brief of 3 October. Nothing was modified: no code, no commit, no migration, no deploy. Production was read with read-only commands only.

**Evidence tags used throughout**
- **[REPO]** confirmed by reading code, migrations, routes, config or tests on `main` = `70f957e`.
- **[PROD]** confirmed on the VPS on 3 Oct (git HEAD, `docker ps`, `hq:credentials:health`, `hq:ai:provider`, `hq:ai:cost`, `hq:autonomy-policy:show`, `hq:shadow:evidence`). A row-level read of `feature_flags`/`agents` was blocked by the session's safety classifier; those values are taken from `docs/68` §4 (27 Sep) and tagged **[DOC-27SEP]**.
- **[DOC]** documented (docs/ADR) but not implemented.
- **[INFERRED]** my reading of the architecture.
- **[UNKNOWN - OWNER TO VERIFY]** cannot be established from the repo or production.

Audit method: five parallel read-only inspections of the repository (control plane and AI runtime; customer AI, channels and voice; commerce, ERP and shops; data model and platform primitives; ads/research/content/infra), each reporting file paths per claim, cross-checked against each other and against production. Paths below are relative to `apps/hq/` unless they start with `docs/` or `deploy/`.

---

## 0. Executive summary (answers to your ten questions; detail in the sections named)

1. **What is BordeGuna HQ today?** A production control plane around *reads*: identity, event inbox/outbox, durable scheduler, Tool Gateway, hash-bound approvals, consent ledger, evaluation and autonomy-evidence machinery, audit, backups-first releases. On top of it sits an AI runtime with three agents (Executive, Analytics, Support) that have passed their safety exams and are **switched off**. Every path that would touch a customer, money, an ad account or a publication is built up to the last step and then held inert by structure: empty provider registries, empty store allow-list, flags seeded OFF, no enabling user, no credentials. [REPO][PROD]
2. **How much of the vision is built?** By domain (§4): foundation and control plane are complete; customer AI is mostly complete but not activatable; order automation is partially complete; ads/research/content are foundation-only; image/video generation, proactive sales, follow-up, next-best-action, outcome tracking, experiments, AI-to-AI collaboration, business twin are **not started**. Averaged crudely across the 18 vision domains: roughly a third built, **zero activated**.
3. **Biggest missing capabilities:** (a) nothing wakes an agent on a real customer message, (b) no outreach/follow-up/lead/opportunity concept at all, (c) no business-outcome tracking (action → order → revenue), (d) no learning loop (the decision corpus is written, never read), (e) no image/video generation provider, (f) no workflow engine for multi-step automation, (g) no orchestration between agents.
4. **Biggest blockers:** Meta Business Verification + App Review + a WhatsApp number (not started, weeks of lead time); both shop admin tokens rejected since 22 Sep; the shop→HQ network block since 13 Sep; the docs/92 language fix unbuilt (last code commit 27 Sep); CI not run since 1 Oct; no second order approver named; no ERP write token; no payment gateway or courier API path for AI orders.
5. **Prepare immediately:** §7 checklist, in this order: shop admin tokens (5 min), Meta Business Verification (start today), WhatsApp SIM, ERP write-ability decision and second approver, Hostinger firewall ticket, KVM 4 upgrade, OpenAI billing to the business card, Meta system user + ad accounts per brand, image-generation provider decision, search-provider key, claims policy per product line.
6. **Claude can build now without you:** the docs/92 language contract; run CI on main; the agent wake-up path on a live message (inert behind the existing flag); the hand-over Telegram ping; the delivery reconciliation job; event-kind scheduled jobs; a generic workflow/step engine; Customer 360 page; follow-up and opportunity entities (inert); outcome/attribution linkage; a decision-corpus reader; a ConnectorRegistry; the briefing schedule. All testable with simulated providers.
7. **Do NOT build yet:** §17: autonomous outbound to customers, autonomous ad spend, autonomous publishing, AI-to-AI delegation, self-modifying playbooks, phone calls, avatar/cloned voice, digital twin, unrestricted browsing, any grant above A0, a second AI provider switch.
8. **Shortest safe path:** §18: finish the language fix → P3-G2 attempt 5 → Executive shadow week (owner briefing live in ~10 days) → in parallel start Meta verification and build the inert customer-AI activation path → P3-G3 shadow beside the n8n bot → Support at A0 on Messenger → order proposals into the ERP inbox → ads read side → content drafts. Build broadly, activate narrowly.
9. **Major architectural risk:** the business-domain layer is thin and the vision's biggest value (sales conversations, follow-up, revenue loop) has no entities, no outcome linkage and no learning loop; if those are bolted on per feature, the platform will fork into seven approval mechanisms and five registries (already happening, §10). Second risk: production and the n8n bot both answer customers, with HQ blind to the shops (tokens rejected, network block).
10. **The single most important next step:** build docs/92, release, run P3-G2 attempt 5, and open the Executive shadow week, while you submit Meta Business Verification the same day. The first is the only path to anything live; the second is the slowest external clock for everything customer-facing.

---

## 1. The vision, fully understood

### 1.1 Explicit ideas stated in the document (with paragraph)

| # | Capability (your words, condensed) | ¶ |
|---|---|---|
| V1 | One central intelligence and operating layer above ERP, shops, brands, social, marketplaces, Google, ads, couriers, channels; ERP stays source of truth; "What is happening in my company?" | 2-3 |
| V2 | Customer-to-order automation: understand intent, business, brand, language; product and SKU; price and availability; eligibility; **fraud/risk checks through permitted external sources**; delivery; prepare, execute via the proper system, verify, reconcile, follow the order afterward | 5 |
| V3 | Support automation end to end: "Where is my order?" from the authoritative system; change requests judged for approval/money/fulfilment/another department/human | 7 |
| V4 | **Real-time one-to-one AI sales department**: phone calls, WhatsApp, Messenger, Instagram; listen in real time, recommend, discuss offers, handle objections, follow up, move to outcome; feel like a knowledgeable representative | 9 |
| V5 | Multilingual: Bangla, English, Banglish, **Hindi**, others; adapt to the customer; stay focused | 11 |
| V6 | Customer 360: first interaction, interests, products discussed, purchases, orders, problems, conversations, recommendations, promises made/avoided, follow-ups scheduled, outcomes, human involvement; one relationship across Facebook → WhatsApp → phone → website | 13 |
| V7 | Proactive follow-up: reorder timing, unfinished quotations, "next month", wholesale interest, abandoned after delivery-charge question; detect, decide, initiate where policy permits | 15 |
| V8 | Next-best-action engine per relationship: answer, ask, quote, recommend, order proposal, follow up tomorrow, stop contacting, hand to human | 17 |
| V9 | Natural language → structured business data ("Bionic-এর সরিষার তেল ১২ বোতল…") | 19 |
| V10 | Human + AI: hand-over with a complete context package; AI resumes routine follow-up afterwards | 21 |
| V11 | AI workforce: Sales Rep, Support Rep, Marketing Manager, Content Creator, Research Analyst, Social Media Manager, Advertising Analyst, Operations Coordinator, Executive Assistant; each with purpose, knowledge, skills, tools, permissions, authority; **collaborate through governed capabilities** (Sales → Order workflow, Marketing uses Research evidence, Content uses approved product info, Advertising reads performance, Executive summarises) | 23 |
| V12 | Marketing chain: understand product/brand/objective → research → concept → copy → brief → **image → video** → platform versions → captions → ready to publish | 25 |
| V13 | Generative media tied to the real business: real product look, approved info, brand, language, platform, format; several assets per campaign; **consistent real people, approved voices, brand assets, real products** | 27 |
| V14 | Social publishing through the platform interface, read back, verify, record what/when/brand/asset/version/afterwards | 29 |
| V15 | Advertising: "increase sales of this product" → look at sales, inventory, conversations, past results, research → proposal, creatives, strategy, audiences, budget → monitor → notice poor performance, better creative, trending product, activity not translating; **some repetitive adjustments automated with evidence; financial/high-impact actions under explicit authority** | 31 |
| V16 | Attention-to-revenue intelligence: content → posts → ads → conversations → leads → quotations → orders → revenue → repeat; which campaign produced real conversations/customers, which objections lost opportunities; closed-loop learning | 33 |
| V17 | Research engine: permitted sources, bounded evidence, claims, provenance, compare, structured result; evidence vs inference vs recommendation | 35 |
| V18 | Learning from conversations at scale: recurring questions, objections, bought-together, confusion points, abandonment reasons, delivery complaints, missing products, approaches that work → content, product text, playbooks, product signals, ops priorities | 37 |
| V19 | Living company playbook per business/brand: product info, claims, pricing rules, discount authority, communication standards, objections, handling rules, escalation, campaign info, language preferences; **governed improvement, not self-modification** | 39 |
| V20 | Continuous improvement loop: outcome → feedback → evaluation → weakness → proposal → test → review → controlled release; never rewrites its own authority | 41 |
| V21 | Controlled experimentation: A/B messages, creatives, offers, follow-up timing; within an approved boundary; learn from evidence | 43 |
| V22 | Digital nervous system: detect business events (delayed order, low stock, human needed, campaign drop, inactive important customer, approval waiting, system failure, trending product, follow-up due), interpret, route to the right AI worker or human | 45 |
| V23 | Digital twin and simulation: scenario questions ("if we raise this budget", "if this product becomes unavailable", "who will reorder", "bought together", "missed opportunities") | 47 |
| V24 | Increasingly self-operating company: normal questions, sales, follow-ups, orders (where proven safe), research, content, reports, monitoring automatic; stop and escalate on anything outside authority | 51 |
| V25 | Humans move to strategy, relationships, creativity, exceptions, decisions, governance; **owner sees everything**: state, history, AI actions, outcomes, exceptions, reasons | 53 |
| V26 | Control principle: intelligence ≠ authority; no raw DB/credentials/unlimited authority; never invent a task; never self-assign a role; memory/external content/customer message never an authorization; permissions, policies, capabilities, approvals; verify and reconcile after; escalate, do not improvise | 57 |
| V27 | Scale: hundreds or thousands of simultaneous conversations, sales activities, marketing tasks, research jobs, operations | 55 |

### 1.2 Capabilities implied by the document (not stated in these words)
- A **Lead / Opportunity / Follow-up** entity, because V7, V8 and V16 cannot exist without something that records "this customer was interested in X on date Y and nobody closed it".
- An **outcome record per AI action** (V16, V20): a reply, order, post or campaign change must be linkable to the order or revenue that followed.
- A **generic workflow/step engine with waits** (V2 "follow the order afterward", V7 "follow up later", V14 publish-then-read-back): multi-step processes that survive restarts.
- A **business-event vocabulary and router** (V22): events with a type, an interpreter and a destination (AI worker or person).
- **AI-to-AI delegation through the gateway** (V11): one agent starting governed work for another.
- A **knowledge/playbook store with retrieval** (V19, V11 "knowledge") that agents read at run time and that changes only by governed release.
- **Telephony** and real-time audio (V4 "phone calls … listening in real time").
- **Media identity consent** for real people/voices (V13).
- **Attribution** from campaign → conversation → order (V16) across Meta click ids, UTMs, phone numbers.
- **Experiment and variant assignment** machinery (V21).
- **Scale primitives**: queue partitioning, rate limits per provider, cost ceilings per conversation (V27).

### 1.3 Things NOT in the document (so I do not present them as yours)
- Marketplace selling (Daraz etc.) is named only as a system HQ should *understand* (¶2), not as a sales channel to build.
- TikTok/YouTube appear as platforms to publish to (¶2, ¶29); no TikTok *ads* ask.
- No request for HR, payroll, finance posting, procurement or manufacturing automation (those come from `docs/89` §8 proposals, not from your document).
- No request to replace the ERP website module, the shops or n8n; the document keeps them as they are.
- No numbers: no budgets, dates, autonomy thresholds, SLA targets, or which brand goes first.
- No mention of Econic, Azmion, Hindi beyond one word, or a specific AI vendor.

---

## 2. The current system, audited

### 2.1 Production state [PROD, 3 Oct]
- `/opt/bordeguna-hq` HEAD `531d59b` (27 Sep 18:54 +06). Seven containers up and healthy: app, web, horizon, scheduler, backup, redis, db (5 days / 3 weeks). Compose also defines `uptime-kuma` and a staging compose exists under `deploy/staging/` (not running). [PROD][REPO]
- Credential health: ERP token verified (last OK 2 Oct 22:05 UTC via a tool call, so the ERP poll runs); both shop **assistant** keys verified (15 Sep); both shop **admin** tokens `credential_invalid` since 22 Sep 20:00/20:15 UTC; Econic and ERP-counter `unsupported` by design; OpenAI key verified 27 Sep. 2 incidents open. [PROD]
- Model provider: OpenAI serving (`gpt-5.6-luna` low, `gpt-5.6-terra` medium, `gpt-5.6-sol` high; reasoning effort low); four Anthropic rows off. **AI spend October: $0.00, 0 runs.** [PROD]
- No autonomy evidence policy active; no shadow period. [PROD]
- Runtime OFF, three agents paused at A0, Support has no enabling user, 0 grants, 0 consent events, Meta inbound off, no outbound provider, `take_order` admits no store. [DOC-27SEP; consistent with code defaults, REPO]

### 2.2 Repository state [REPO]
- `main` = `70f957e` (27 Sep 19:10, docs only after the `531d59b` release). No commits anywhere since. Working tree: two docs staged for deletion, `docs/94` untracked, none committed. Eight worktrees at old stream heads.
- 84 tables (53 migrations), 71 models, 37 tool classes (28 registered in `config/hq.php`, 9 defined but unregistered: `content.create_draft`, `publishing.publish_post`, `research.search`, `research.fetch_page`, `research.file_artifact`, `ads.read_account`, `ads.read_campaigns`, `ads.read_insights`, `ads.sync_ad_spend`), 3 agent rows, 2 AI providers, **0 messaging/publishing/research/voice/ads providers registered** (`PlatformRegistry` and `SpeechProviders` bound empty in `app/Providers/AppServiceProvider.php`).
- Tests: 142 files, 1,351 `it/test` definitions, 1,682 Pest passed last recorded (`531d59b`), on real Postgres. CI workflow `.github/workflows/hq-ci.yml` (Postgres 17 service, PHP 8.3, Node 22, migrate fresh/rollback/fresh, Pest, tsc, Vite, docker build, deploy-safety suite) has **not run since 1 Oct**; the ten runs of 22-27 Sep were quota-refused within seconds. **Last green run: 16 Sep on `1cbef42`. Production `531d59b` has never had a green CI run** (`gh run list`, read-only). The deploy-safety suite self-skips its mode-600 and flock checks on Windows, so it has not demonstrably run on Linux since 16 Sep. Restore drill last run 5 Sep (PASS) with 40+ migrations since; the docs ask for monthly or after each schema change.
- Feature flags read in code: `ai.runtime`, `ai.freeze`, `scheduler.gateway`, `ask_hq`, `scheduler.nl`, `briefing.narration`, `channels.meta.inbound` (seeded OFF), `channels.meta.forwarded` (seeded OFF). A missing row reads as OFF.
- Scheduled on the host clock: scheduler tick and events drain every minute; approvals sweep and alerts every 5 min; shadow ticks 09:00/17:00 Dhaka; shadow evidence hourly check + 00:15 store; compare-sweep 01:00; retention 08:15; Horizon snapshot. Business jobs (`erp.poll_*`, `commerce.reconcile_facts`, `commerce.check_fact_integrity`) live in the `scheduled_jobs` table [REPO for mechanism; rows on production UNKNOWN - OWNER TO VERIFY].

### 2.3 What exists, by layer (one line each; detail in §3)
- **Gateway** `app/Gateway/ToolGateway.php`: 10-step order (availability → argument schema → scope/EEC → policy+permission → consent → idempotency/replay → credential pre-flight → execute in vault scope → record → audit). Redaction deny-list, correlation ids, kill switches (8 distinct: config list, `tools` table, `ai.freeze`, `ai.runtime`, agent status, business active, shadow stop, provider lever).
- **Policy** `app/Gateway/Policy/PolicyEngine.php`: kill switches → human-only → role boundary → environment → permission intersection → tier baseline (R0-R3 auto, R4-R5 approval) → per-tool policy class (tighten only) → versioned `policies` rows (**none seeded, no screen**) → budgets → default deny.
- **Approvals**: hash-bound, single-use, 48 h expiry, 4 h SLA, delegation, approve-with-edit as a new approval, requester/enabler/approver separation; absolute self-approval block only on `take_order`/`cancel_sales_order`.
- **EEC** (ADR-047): requester ∩ enabler ∩ agent row ∩ tool permission, re-read before every call.
- **Autonomy**: A0-A4 (A5 unreachable), grants (none), evidence policy tables (no version, no numbers by design), shadow week command with hard stops (Executive only by config; never opened).
- **Model router**: tiers not ids, cost per call, $50/month soft cap, circuit breaker, structured output + one repair, raw replies kept. OpenAI/Anthropic; adding a third = one provider class + rows.
- **Agents**: rows not code; one runtime (`AgentRunner`, 3 turns, 8 tool calls); five deterministic pre-model guards (security intent, prohibited capability, role boundary, task grounding, task scope); no delegation, no skills, no knowledge retrieval.
- **Evaluation**: suites `safety.core` 80, `behaviour.executive` 15, `behaviour.support` 43, `regression.role_boundary` ~23, `regression.runtime_boundaries` 46, `regression.execution_integrity` 12; deterministic grader with 24 checks; results immutable by convention/retention, not trigger.
- **Conversation core**: threads, messages with provenance, 7-state machine, deterministic escalation word-lists (EN/BN/Banglish), support workflow contracts, inbox UI with take-over/decide/remember, customer memory labelled as memory, consent gate at the gateway.
- **Channels**: Meta webhook (flag OFF), Messenger/IG/comment/WhatsApp normalisers, n8n copy-forward (flag OFF), Graph send adapters with idempotent `ChannelSender`, delivery ledger. **No provider registered; no IG/WhatsApp platform-account rows seeded; no reconciliation job for uncertain sends.**
- **Identity**: customers per business, identities per channel, BD phone canonical, deterministic resolver, reversible merges; strangers from Messenger never auto-merge; ERP customers not ingested.
- **Commerce**: ERP connector with 24 endpoints (reads + 3 writes), Phase 4 tools registered inert; ERP side on production (see §19 disagreement 2); shop adapter (reads; writes present but unreachable); 8 webhook events accepted, `inventory.low` and `coupon.expired` have no handler; `facts_orders` read model, nightly reconcile, integrity checker.
- **Ads**: Meta reader, read models (`facts_ad_accounts`, `facts_ad_spend`), budget guard, contribution report (cost source always null) — tools unregistered, no credential.
- **Research**: Brave/Tavily providers, bounded tasks, append-only artifacts, untrusted-content rules — tools unregistered, no key.
- **Content**: briefs (append-only), drafts (frozen words), claims policy class, live product facts, Facebook/Instagram publishers, read-back, Bangla browser card renderer — tools unregistered, `content-render` service defined not started.
- **Media**: `media_assets` + local storage driver. `MediaGenerator` interface has **no implementation** → no image generation. No video generation at all.
- **Voice**: STT (Google bn-BD), TTS (ElevenLabs, Azure), voice-note ingestion, cloned-voice refusal — registry empty, audio fetch from Meta not built, telephony interface has zero implementations.
- **Observability**: 5 dashboards (AI health, control plane, cost, platform, posture), briefings with evidence bundle, 15 alert rules → Telegram + in-app audit row, retention runner.
- **Deploy**: backup-first (`backup-first-lib.sh`), host lock, `--verify-only`, release record, health must name the released commit, rollback script, restore drill doc; backups 14 daily / 8 weekly on the same box, no off-site copy.

---

## 3. Vision-to-implementation matrix

States: COMPLETE / MOSTLY COMPLETE / PARTIALLY COMPLETE / FOUNDATION ONLY / DESIGNED BUT NOT BUILT / NOT STARTED / BLOCKED / INTENTIONALLY DEFERRED / NOT YET SAFE TO ACTIVATE. Each row also gives the finer ladder **D**esigned → **I**mplemented → **In**tegrated → **T**ested → **P**roduction-ready → **A**ctivated.

| Vision item | State | Ladder | What exists today / where | Still missing | Activation | Depends on / blocker | Next |
|---|---|---|---|---|---|---|---|
| V1 Central layer, "what needs attention" | MOSTLY COMPLETE (read side); NOT YET SAFE TO ACTIVATE (AI narration) | D I In T P, A=no | Facts, ERP snapshots, dashboards, alerts live; Executive briefing + Ask HQ built, inert (`app/Briefing`, `app/Ask`) | Shadow week; narration flag; briefing not scheduled (`ComposeBriefingTool` "no scheduled job runs it"); shop reads blind (tokens) | Runtime OFF | docs/92 → P3-G2 att.5 → `hq:shadow:open --by` (you) | Build docs/92; new shop tokens |
| V2 Customer → order automation | PARTIALLY COMPLETE | D I In T, P=partial | ERP draft→record→cancel path with read-back and reconciliation (`app/Tools/Commerce/TakeOrderTool.php`, ERP `ConfirmHqSalesOrderDraft`); quote/status/cancel; NL→structured via agent schema | `take_order_stores=[]`; no ERP write token; no second approver; COD only; no payment link; no courier; no fraud check; no "follow the order afterward" workflow; no agent holds the tool; no store on the ERP website module | Inert by config | Second approver (you/boss), write token (you), ERP website store, S6 gateway, S7 courier | Decide approver + admit one store in a test business |
| V2 Fraud/risk via external services | NOT STARTED | — | Nothing in HQ, ERP or shops (only SSLCommerz's own risk flag logged) | Courier fraud APIs (Steadfast/Pathao/eCourier) adapter; COD-risk flag on `facts_orders`; the ChatGPT research of 26 Sep is a design input only | — | Courier API accounts (you), PDPA 2026 review (owner) | Design an R2 read tool `risk.check_phone` behind a courier adapter; needs owner's legal read first |
| V3 "Where is my order?" | PARTIALLY COMPLETE | D I | `commerce.get_order_status` reads only ERP orders HQ placed; shop orders by phone exist in the adapter (`findOrdersByPhone`) but no tool exposes them; shop `/assistant/orders?phone=` endpoint exists and is uncalled | A read tool over shop orders by customer identity; courier state (no API) | Inert | Shop admin tokens; courier API (S7) | Build `commerce.find_customer_orders` (R2) over the assistant endpoint |
| V3 Change-request judgement | FOUNDATION ONLY | D I | Escalation word-lists route refund/payment/complaint to a person before the agent; cancel tool exists for HQ-placed ERP orders | Any "modify order" capability; approval routing per domain (docs/13 approvers/SLAs not in code) | Inert | ERP order edit API (none) | Later |
| V4 Real-time 1:1 AI sales (text) | FOUNDATION ONLY | D I T | Conversation core, Support agent at A0 proposes replies from live facts; product/price/availability reads | **No wake-up on a live message** (`ConversationEventHandler` is a no-op); no sales role, no recommendation logic, no offers/objection playbook, no outreach, no provider registered | Inert | Meta verification S1/S2, WhatsApp S3, platform-account rows, provider registration (code), P3-G3/G4 | Build the inert wake-up path now; start S1 today |
| V4 Phone calls / real-time voice | NOT STARTED | D only | `TelephonyProvider` contract, zero implementations; STT/TTS adapters exist unregistered | Everything: telephony vendor, BTRC-licensed number, call table (docs/19), audio streaming, consent for recording | — | Licensed number (slow), vendor choice, PDPA recording consent | Later (Phase 7) |
| V5 Multilingual | PARTIALLY COMPLETE | I T | Bangla/English/Banglish detection, prompts, escalation vocabularies; operator locale | Mixed-script rule enforcement (docs/92); customer preferred-language field (only a memory key); **Hindi: nothing** | — | Your language policy choice | Build docs/92 with a versioned script policy that can later add `hi` |
| V6 Customer 360 | PARTIALLY COMPLETE | I | `customers`, identities, orders, LTV, merges; `/customers/{id}` shows identities + last 50 orders + legacy consent blob | Conversations, memories, consent ledger, hand-overs, promises, follow-ups, outcomes on the page; cross-business person (one phone = two customer rows); auto-merge when a stranger reveals a phone; ERP customers not ingested; no AOV/segment/RFM | Live (read) | Shop tokens; identity design decision for cross-business | Build the 360 read model + page (safe, no provider) |
| V7 Proactive follow-up | NOT STARTED | — | Nothing (grep confirms; `RoleBoundary` *refuses* "follow up with" as outbound) | Follow-up entity, scheduler kind=event (dead code today), consent purpose marketing, outbound provider, policy | — | Consent model for marketing; S1-S3; your rules on who may be contacted | Design inert entities + jobs (§18 W4) |
| V8 Next-best-action | NOT STARTED | — | Nothing | NBA = a scheduled/event-driven evaluator over Customer 360 producing *proposals* (never model-invented tasks) | — | V6, V7, outcome data | After V6/V7 |
| V9 NL → structured data | MOSTLY COMPLETE | I T | Agent structured-output schema + one repair; `TakeOrderShape`; ERP re-prices | Product disambiguation across stores; multi-item/bundle parsing (ERP contract has no bundles/coupons) | Inert | — | Covered by V2 activation |
| V10 Human + AI hand-over | MOSTLY COMPLETE | I In T | 7-state machine, deterministic triggers, inbox take-over/return-to-agent, context on the thread page | **No Telegram/notification on hand-over** (docs/94 was wrong); order facts and hand-over reason not on the inbox page; docs/14 anger/threshold/confidence triggers absent | Inert | — | Add the ping + reason + order card (small, safe) |
| V11 AI workforce (roles) | FOUNDATION ONLY | D I | Agents as rows, role profiles v2 (3 roles), tool sets per agent, EEC | 11 of 14 roles absent; **no AI-to-AI delegation, no workflow start, no shared skills, no knowledge store**; `agents.kpis` absent | Inert | A workflow/task primitive first | Do not add agents until one works live |
| V12 Marketing chain (research → copy → brief → image → video → variants) | FOUNDATION ONLY | D I T (partial) | Research engine, briefs, drafts, claims check, Bangla card renderer, publishers — all unregistered | Image generation (no `MediaGenerator` impl), video (none), campaign concept/objective entity, platform variants, `content-render` service not started, search key, claims policy content (empty) | Inert, unregistered | Image provider decision + key; search key; claims policy (you); Page tokens; S1 | Register research+content tools behind a flag; pick an image provider |
| V13 Media tied to the real business (products, people, voices) | NOT STARTED beyond refusal | D | `media_assets` with provenance/rights/consent id; cloned voice refused; avatar consent record designed (docs/20) | `media_identities` table, consent workflow, product-image reference library, brand kit, any generator | — | Written consent policy (owner), provider | Later (Phase 8) |
| V14 Social publishing with read-back | PARTIALLY COMPLETE | I T | `FacebookPagePublisher`, `InstagramPublisher`, `PostReadBack`, `content_publications` with logical key and read-back, ADR-051 fingerprint approval | Not registered; no Page tokens; LinkedIn/YouTube/TikTok absent; scheduling of a post = a scheduled job (exists) + publish tool (unregistered) | Inert | S1 (verification), O-9 Page tokens, IG professional accounts | After S1 |
| V15 Advertising automation | FOUNDATION ONLY (read side) | I T | `MetaAdsReader`, read models, `AdBudgetGuard`, `AdsHumanOnly`, contribution report; ADR-050 accepted | Tools unregistered; no system-user token; no account/currency per brand; **no write side at all** (prepare/pause/budget); no waste flags; no guardian; cost prices null so contribution is always blank | Inert | O-3 system user token, O-5 accounts/currency, O-6 limits, O-8 ERP cost prices (data entry) | Register reads behind flag once token exists |
| V16 Attention → revenue loop | NOT STARTED (data partially present) | — | `facts_orders.campaign_ref` (fbc/utm) and `facts_ad_spend` would allow campaign→order; nothing links conversation→order or reply→outcome | Outcome entity on actions; attribution join; conversation outcome column; KPI time series | — | Ads read side live; conversations live | Design the outcome/attribution primitive now (§10) |
| V17 Research engine | MOSTLY COMPLETE (code) | I T | `app/Research/*`, bounded tasks, provenance, append-only artifacts, untrusted-content guard | Unregistered; no provider key; no standing briefs; no UI | Inert | Brave/Tavily key (you), source allow-list (you) | Register behind a flag with a key |
| V18 Learning from conversations at scale | NOT STARTED | — | `decision_records` (human edits of AI proposals with deltas) written by 3 places; **no reader** | Pattern mining, recurring-question/objection reports, feed into content/playbook | — | Live conversations | After customer AI is live and has volume |
| V19 Living playbook | DESIGNED BUT NOT BUILT | D | Prompts (versioned md), `content_briefs.claims_policy`, `owner_preferences` (no writer) | `knowledge_documents`/playbook store with retrieval and governed versioning; per-brand rules (pricing, discount authority, escalation) as data | — | Your policies written down | Build a playbook table + loader into context (safe) |
| V20 Continuous improvement loop | FOUNDATION ONLY | I (eval) | Evaluation + autonomy evidence feed *widening*; prompt versions by digest | Nothing proposes prompt/policy/playbook changes from outcomes; no controlled release of knowledge | — | V16, V18 | Later |
| V21 Controlled experiments | NOT STARTED | — | 0 hits for "experiment" in app | Experiment entity, variant assignment, outcome measurement, approved boundary | — | V16 | Later |
| V22 Digital nervous system (events → route) | PARTIALLY COMPLETE | I T | `events` inbox/outbox with handlers, 15 alert rules, low-stock events arrive | **Event-kind scheduled jobs never fire** (`NextRunCalculator` returns null); `inventory.low`/`coupon.expired` have no handler; no router to an AI worker; no "important customer inactive" or "campaign dropped" detectors | Live (alerts only) | — | Wire event-triggered jobs + handlers (safe) |
| V23 Digital twin / simulation | NOT STARTED | — | Nothing | Everything | — | Years of data | Do not build |
| V24 Self-operating company | INTENTIONALLY DEFERRED | — | Autonomy ladder exists; A5 unreachable | Evidence policy numbers (yours), grants, every live phase | — | Everything above | By evidence only |
| V25 Owner sees everything | MOSTLY COMPLETE | I In P | Audit (append-only), tool calls, agent runs, approvals, briefings, dashboards, posture page | Business outcomes of AI actions; phone-friendly view (none); per-user notifications (Telegram single chat + audit row only) | Live | — | Add outcome view later; PWA later |
| V26 Control principle | COMPLETE | I In T P | Gateway, EEC, no-implicit-task, untrusted content never authorises, vault-only secrets, approvals, verify+reconcile on writes | HUMAN_ONLY permanent list is empty in code (no such tool exists yet); `policies` rows absent; R3 tier on customer-facing send (ADR-017 says R5) | Live | — | Fix the tier label; add the permanent list when such tools appear |
| V27 Scale (thousands of simultaneous) | FOUNDATION ONLY | I | Horizon queues, locks, rate limiter per Meta account, cost caps | KVM 2 shared box; no per-provider limiter at the gateway; no load test; Postgres-bound | — | N3 KVM 4 | Upgrade before Phase 3 live |

---

## 4. Big picture by domain

| Domain | Assessment | Basis |
|---|---|---|
| Core HQ foundation (entities, auth, 2FA, vault, audit, scheduler, backups, deploy) | **Complete and live** | 84 tables; append-only triggers; backup-first deploy; 7 healthy containers [PROD] |
| Control plane (gateway, policy, approvals, EEC, consent, kill switches) | **Complete and live; exercised only by reads and demo** | 10-step gateway; approvals hash-bound; `policies` table empty; no real R4 approval has ever been executed on production [INFERRED from demo-only tool] |
| AI runtime (router, agents, guards, evaluation, autonomy) | **Complete, exam-passed, not activated** | Gate 13 PASS, Executive integrity 12/12, Support 42/43 + 80/80; runtime OFF; $0 in Oct [PROD] |
| Customer AI (inbox, channels, support agent) | **Mostly built, not activatable** | No provider registered; no wake-up path; no IG/WhatsApp account rows; Meta S1/S2/S3 not started; docs/92 unbuilt |
| Real-time communication (phone, voice) | **Foundation only (adapters), telephony not started** | STT/TTS classes unregistered; audio fetch missing; `TelephonyProvider` zero impls |
| Sales/order automation | **Partially built (ERP path), zero activation** | Tools registered inert; ERP side on production; no approver/token/store; no payment/courier/fraud |
| Marketing (research, content, briefs) | **Foundation only** | Code exists unregistered; no keys; claims policy empty; render service not started |
| Advertising | **Read side built, unregistered; write side not built** | ADR-050 accepted, no code for prepare/pause; no token |
| Research | **Mostly built, unregistered** | Brave/Tavily impls; no key; no UI |
| Content | **Foundation only** | Drafts/briefs/checks; no generator; publishers unregistered |
| Image generation | **Not started** | `MediaGenerator` interface, no implementation |
| Video generation | **Not started** | Nothing |
| Social publishing | **Partially built, unregistered** | FB/IG publishers + read-back; no tokens; no other platforms |
| Voice | **Foundation only** | See real-time comms |
| AI workforce (multi-role, delegation, skills, knowledge) | **Foundation only** | 3 roles; no delegation; no skills; no knowledge store |
| Executive intelligence (briefing, Ask HQ) | **Built, inert; one week from live** | Shadow week preconditions: 2 of 4 met |
| Business intelligence (KPIs, attribution, outcomes) | **Foundation only** | Dashboards are about HQ itself; `ContributionReport` blank; no outcome linkage |
| Autonomous operations | **Intentionally deferred** | No evidence policy numbers; 0 grants |

Defensible overall statement: **the platform (control plane around reads) is complete; the business-domain layer the vision needs is roughly one third built and nothing AI-driven is live.**

---

## 5. What is still missing (specific)

**Customer AI**
- Wake-up: no production code path runs the Support agent when a message arrives. `app/Support/Events/Handlers/ConversationEventHandler.php` is deliberately empty; `conversation.reply_proposed` is registered as an event but never emitted. The only caller that binds a thread to a run is the gate (`AgentEvaluationSubject`).
- Providers: `PlatformRegistry` is bound empty; nothing calls `register()`. Even with Meta verification done, zero code path sends.
- Platform accounts: only two `facebook_page` rows seeded (`config/hq-seed.php`); no `instagram_account`, no `whatsapp_number`. Inbound IG/WhatsApp would dead-letter as "unknown platform account".
- Credentials: no `meta_app_secret`, `meta_webhook_verify_token`, `meta_page_token`, `meta_instagram_token`, `whatsapp_access_token`, `meta_forward_secret` stored.
- Delivery: no reconciliation job for `sending`/`uncertain` deliveries; only the shadow-comparison sweep is scheduled.
- Hand-over: no notification to a person (Telegram or otherwise) on `human_required`; reason and order facts not shown on the inbox page.
- Language: docs/92 contract absent; mixed-script rule unenforced; Hindi absent; customer preferred-language is a free-text memory key.
- Consent: only inbound service consent (24 h) is ever written; no UI/command for withdrawal, do-not-contact, marketing opt-in, operator-recorded consent; shop registrations do not write to the ledger.
- Identity: a Messenger stranger who later gives a phone is never auto-merged; same phone across LaLaDia and Bionic = two customers; ERP customers not ingested.
- Support agent: `enabling_user_id = null`; not in `hq.shadow.agents`; `send_whatsapp_reply` held by nobody; comment replies have zero implementations (`CommentChannel`).
- Voice notes: `wa-media:<id>` reference kept, bytes never fetched; `SpeechProviders` empty; Google project id empty.

**Sales/orders**
- `hq.orders.take_order_stores = []`; no agent holds `commerce.take_order`; no ERP token with `sales.orders.*`; no second approver; recipient = buyer only (no gift); COD only; no payment link; no courier booking/tracking; no fraud/COD-risk check; no `order_lifecycle_followup`; no quotation persistence (ERP has quotations, not exposed); no "find this customer's shop orders" tool.
- Unknown: whether the two ERP HQ migrations ran on ERP production (UNKNOWN - OWNER TO VERIFY with `php artisan migrate:status` on the ERP).

**Marketing/ads/content/research**
- 9 tools unregistered; no flags for them; no credentials (`AdsCredential` expects a system-user token; research expects Brave/Tavily; publishing expects Page tokens); `content-render` service not started; claims policy has no content; `CostPriceSource` bound to `UnavailableCostPrices`; ads write side has no code; no campaign/creative/experiment entities; no image/video generator.

**Platform**
- Event-kind scheduled jobs are dead code; `inventory.low`/`coupon.expired` unhandled; `scheduler.gateway` flag has no seeded row (production said ON per docs/50, UNKNOWN); no generic workflow engine (ADR-032 "orchestration" = state machine + scheduler only); no policies screen or rows; roles beyond owner/operator unused; no per-user notifications; no PWA/mobile view; staging compose exists but no staging host.

**Data/outcomes**
- No Lead, Opportunity, Follow-up, Sales Activity, Quote, Campaign, Creative, Experiment, KPI, Task (human work), Playbook entities; `decision_records` has no consumer; no conversation outcome column; no action→order linkage.

**Operations**
- Shop admin tokens rejected 11 days; shop→HQ network block 20 days (not recorded in any HQ doc); CI dark since 1 Oct and never green for production; deploy-safety suite unproven on Linux since 16 Sep; restore drill stale (5 Sep); backups daily 01:00 UTC, verified, 14 daily/8 weekly, **on the same box**, freshness watched only by the host cron; Hostinger snapshot status UNKNOWN; KVM 2 shared with ERP/n8n/others; staging is a definition only.
- Credentials screen: the provider field is free text and its hint names kinds (`meta`, `telegram`, `mimsms`) that no code reads; a row stored under a wrong name is silently useless. The vault slots the code actually reads are: `shop_admin_token`, `shop_assistant_key`, `shop_webhook_secret`, `erp_api_token`, `openai_api_key`, `anthropic_api_key`, `meta_page_token`, `meta_instagram_token`, `whatsapp_access_token`, `meta_app_secret`, `meta_webhook_verify_token`, `meta_forward_secret`, `meta_ads_token`, `brave_search_api_key`, `tavily_api_key`, `google_speech_access_token`, `elevenlabs_api_key`, `azure_speech_key`. Telegram is env, not vault. No slot exists for an image provider, SMS, Google Ads, TikTok or HeyGen.
- `config/hq-seed.php` (5 Sep) still lists the old Bionic pixel `1773890070472625` and one ad account; the live Bionic dataset is `689321244257515` and the Bionic ad account `547593411643381` since 21 Sep. The seed must be corrected before the ads read side is registered.
- Flags `research.engine`, `content.drafting`, `publishing.write` have no rows; permissions `hq.tools.research.read`, `hq.tools.content.write`, `hq.tools.publishing.write` are referenced by tools but not seeded (deliberate until registration). The ads budget guard refuses everything until an `ads.monthly_budget` policy row exists. `content-render` is a separate compose project not in the release compose; `HQ_CONTENT_RENDER_URL` is null. `media_assets` has no size cap, no mime allow-list and no retention target.

---

## 6. Dependency chains (T = technical, J = Joy/owner, X = external company)

**Customer AI (Messenger first)**
docs/92 language contract (T) → P3-G2 attempt 5 PASS (T, your release line J) → agent wake-up path + Telegram ping + delivery reconcile, inert (T) → Meta Business Verification (J/X, up to 14 business days) → Meta app with Messenger/IG/Webhooks + App Review (J/X) → `meta_app_secret`/verify token/Page token stored (J) → `channels.meta.inbound` ON (T, a deploy) → P3-G3 week of paired proposals beside the n8n bot (T+J verdicts) → Support enabling user named (J) → Support at A0 on real threads (J opens) → evidence → A1/A2 grant (J signs) → provider registered + sends under approval (T+J).
WhatsApp adds: new SIM (J/X) → WhatsApp Cloud API number on the app (J/X) → `whatsapp_number` platform-account row (T) → `whatsapp_access_token` (J) → templates allow-list (J) → same gates.
Instagram adds: professional account linked to the Page (J) → `instagram_account` row (T) → token (J).

**Sales/orders**
Product/SKU/price/availability reads (built) → eligibility (built) → quote (built) → second approver named (J) → ERP token with `sales.orders.draft/record` issued (J, `hq:api-token --write`) → one store admitted in `take_order_stores` (T deploy) → a store on the ERP website module (ERP programme, J) → P4-G1/G2 operator-taken order checked by hand (J) → agent holds `take_order` at A0 (T) → P4-G3 → payment link (S6 gateway, J/X) → courier API (S7, J/X) → tracking replies → follow-up workflow (T, consent J).

**Marketing**
Search key (J) + source allow-list (J) → research tools registered behind flag (T) → claims policy per product line (J) → content drafts registered (T) → image provider decision + key (J) → `MediaGenerator` impl (T) → `content-render` started (T, host) → Page tokens (J, after S1) → publish tool registered (T) → P6 gates → ads system-user token + accounts/currency/limits (J) → ads reads registered (T) → ERP cost prices entered (staff, J) → contribution profit → P5 gates → ads writes (T, after ADR-050 gate).

**Learning loop**
Live conversations and orders (all above) → outcome entity + attribution (T) → decision-corpus reader (T) → pattern reports (T) → playbook store (T) + your policies (J) → governed release (T).

---

## 7. WHAT I NEED TO PREPARE FOR YOU (owner checklist)

Legend: NOW = blocks the next 30 days; SOON = needed within the next phase; LATER = can be postponed safely. "Company-owned" = must sit on a Bor de Guna account/card, never a personal one.

| # | What | Why | Needed by | When | Who provides | Registration / verification / payment / documents | What to collect / credentials | Company-owned? | Postponable? |
|---|---|---|---|---|---|---|---|---|---|
| 1 | New LaLaDia + Bionic **admin tokens** stored on the HQ Credentials screen | HQ's shop reads have been blind since 22 Sep; nightly reconcile cannot run | V1, V3, V6 | NOW (5 min) | You (shop admin → Sanctum token) | none | Token string, store slug | yes | no |
| 2 | **Shop → HQ network block** fixed (hPanel VPS firewall or Hostinger support) | Live order events from 15.235.229.40 to the VPS fail since 13 Sep | V1, V22 | NOW | You / Hostinger | support ticket | Confirmation that the IP reaches 200.97.162.40:443 | — | no |
| 3 | **Release line** for the docs/92 candidate, then **P3-G2 attempt 5**, then **`hq:shadow:open executive --by=<you>`** | The only path to any AI being live | V1 | NOW (after I build) | You | none | Your `--by` identity | — | no |
| 4 | **Meta Business Verification** (S1) on portfolio `3967588683468735` | Without it only admins can message the app: no customers, no publishing, no ads API | V4, V14, V15 | NOW (up to 14 business days) | You / boss | trade licence, TIN, utility bill or bank statement in the business name, business phone, domain already verified | Verification status, business id | yes | no |
| 5 | **Meta developer app** with Messenger, Instagram, Webhooks + **App Review** (S2) (`BDG | Marketing Automation` `1446749247331336` exists for CAPI; confirm it can carry messaging or make a second one) | The only door to real customer messages | V4 | NOW (after 4) | You | App Review screencasts, privacy policy URL, data-use statement | App id, app secret, webhook verify token, Page access tokens per Page (long-lived), IG professional account ids | yes | no |
| 6 | **New SIM never used on WhatsApp**, registered to WhatsApp Cloud API (S3) | WhatsApp is the dominant channel; Cloud API needs its own number | V4 (WhatsApp) | NOW (days) | You / boss | business verification (4), display name approval | Phone number id, WABA id, permanent token, template names | yes | partly (Messenger first) |
| 7 | **Second order approver** named (a person other than the one who asks) | With nobody named, no AI order can ever be approved; the rule is absolute in code | V2 | NOW (decision) | Boss | none | Name, HQ user, `hq.approvals.decide` | — | no |
| 8 | **ERP write token** for HQ with `sales.orders.draft` + `sales.orders.record` (+ `cancel`) for one business | Order proposals cannot be written without it | V2 | SOON | You (`php artisan hq:api-token … --write`) | none | Token; confirm the two HQ migrations ran on ERP prod (`migrate:status`) | yes | until P4 |
| 9 | **A store on the ERP website module** (ADR-046 long-term shape) or a decision to admit a standalone shop to `take_order_stores` | `take_order` admits no store today | V2 | SOON (decision) | You | none | Store slug | — | no |
| 10 | **Hostinger KVM 4 upgrade** (N3, $12.99/mo) | One KVM 2 box runs HQ + ERP + n8n + others; customer AI and a staging copy need room | V4, V27 | SOON | Boss (payment) | billing | — | yes | until Phase 3 live |
| 11 | **Dual-currency company card** (S4) on OpenAI (`Bor de Guna` org, key #19), Meta, future providers | Dollar bills in the business name; today's OpenAI org limit $100 | all | SOON | Boss / bank | bank KYC | Card on each provider | yes | partly |
| 12 | **Meta Marketing API**: system user token with `ads_read`, ad account id + currency per brand, monthly ads budget per business, spending limit per account (O-3, O-5, O-6) | Ads read side refuses an account whose brand/currency is unknown | V15, V16 | SOON | You | system user in the portfolio | Account ids (`act_…`), currency, budget numbers | yes | until P5 |
| 13 | **ERP cost prices** entered for every sold product (O-8) | Contribution profit is blank without them (`cost_price` blank today) | V15, V16 | SOON | Staff | none | — | — | until P5 |
| 14 | **Search provider key** (Brave or Tavily; your choice on cost) + the **source allow-list** per brand | Research engine reads only approved sources | V12, V17 | SOON | You | account on the provider | API key | yes | until P6 |
| 15 | **Claims policy per product line** (mustard oil, honey, pickles, ghee, salt): what may / may not be said, in Bangla and English | Content drafts are checked against it; HQ never writes it | V12, V19 | SOON | You / boss (legal read, CRPA 2009 s.44) | none | A written list per product | — | until P6 |
| 16 | **Image-generation provider decision** (OpenAI images on the existing org, or another) + key; **product photo reference library** and **brand kit** (logos, colours, fonts) | No `MediaGenerator` exists; the vision wants real-product consistency | V12, V13 | SOON (decision) | You | provider account | Key; approved product photos; brand assets | yes | until P6 |
| 17 | **Page tokens and Instagram professional accounts per brand** (O-9, O-10); **who approves posts per brand** | Publishing needs both; approval of the exact post | V14 | after 4/5 | You | after S1/S2 | Long-lived Page tokens, IG ids, approver names | yes | until P6 |
| 18 | **Communication policies**: who may be contacted proactively, quiet hours (config has defaults), reply window, marketing opt-in wording, mixed-language script rule (Bangla script vs romanised), tone per brand | Follow-up and outreach are refused without consent and policy | V5, V7 | SOON (decisions) | You / boss | PDPA 2026 review recommended | A one-page policy per brand | — | no for V7 |
| 19 | **Sales rules**: discount authority (none for AI is the safe default), price-group behaviour (ERP already enforces), gift delivery yes/no, COD-only yes/no, max order value for an AI proposal | Shapes the conversation script and the order policy class | V2, V4 | SOON | You / boss | none | Written rules | — | partly |
| 20 | **Escalation policy**: who gets the Telegram ping for `human_required`, per brand, per hour of day; the agent roster and shifts | The hand-over ping I will build needs a destination | V10 | SOON | You | none | Telegram chat ids / HQ users | — | no |
| 21 | **SMS masking name** (BTRC, 1-3 weeks) and an SMS provider (S5) | Order updates/OTP by SMS; the ERP already has an SMS driver | V3, V7 | LATER (start early) | Boss | BTRC approval, trade licence | API key, masking name | yes | yes |
| 22 | **Payment gateway** for AI-taken orders (SSLCommerz merchant exists for the shops; bKash/Nagad merchant accounts, S6) | Online payment for an AI order | V2 | LATER (start early, 1-4 weeks) | Boss | merchant KYC | Store id/password per store | yes | yes (COD works) |
| 23 | **Courier API accounts** (Pathao is live in the shops; Steadfast/RedX/CarryBee keys; CarryBee needs 5 things from the boss) (S7) | "Where is my order", booking, and courier fraud checks | V2, V3 | LATER (start early) | Boss | merchant accounts | API keys, webhook secrets; move CarryBee creds out of OneDrive plaintext | yes | yes |
| 24 | **Google Cloud project + Speech-to-Text key** (bn-BD) and a **Bangla TTS plan** (ElevenLabs or Azure) | Voice notes and spoken replies | V4 voice | LATER | You | provider accounts | Project id, keys | yes | yes |
| 25 | **BTRC-licensed number / telephony vendor** for calls; **recording consent policy** | Phone sales and COD confirmation calls | V4 phone | LATER (slow) | Boss | BTRC | — | yes | yes |
| 26 | **Written consent policy for real faces/voices**; whose face and voice the presenter uses | Avatar/consistent-person media | V13 | LATER | Boss | signed consent | — | — | yes |
| 27 | **Autonomy numbers** (pass rate, sample size, evidence age per level) | Nothing can step above A0 without them; HQ ships none by design | V24 | after the shadow week | You | none | Numbers per agent/level | — | until evidence exists |
| 28 | **Your own HQ plan** (promised 22 Sep) and a **priority order** (owner briefing / customer replies / orders / ads / watchers) | The build has followed the roadmap, not your value order | all | NOW | You | none | Any shape, Bangla or English | — | no |
| 29 | **Cloudflare free plan** in front of `bordeguna.com`/`hq.` + **R2** (or any off-server copy) (N5/N6; ⛔ not B2) | Public webhook protection; first backup copy off the box | ops | SOON | You | DNS change | — | yes | partly |
| 30 | **GitHub Actions minutes/budget** (N4) | CI has not run since 1 Oct; local merge gate is not CI | ops | NOW | You | billing | — | yes | no |
| 31 | Confirm **Hostinger snapshot** is enabled; confirm production `scheduled_jobs` rows (Bionic reconcile) and `scheduler.gateway` flag; **run the restore drill** (`deploy/restore.sh` to the scratch DB, on the VPS) | Unknowns from this audit; the drill is a month and 40 migrations stale | ops | SOON | You (`! ssh …`) | none | Drill result into `deploy/RESTORE-DRILL.md` | — | no |
| 32 | **Legal/PDPA 2026 read** before any phone-number-based profiling, fraud lookup or cross-merchant data use | Mobile number is personal data; profiling defined in the Act | V2 fraud, V6, V7 | before those phases | Boss / lawyer | — | — | — | until then |

Items marked UNKNOWN - OWNER TO VERIFY: whether S1 was ever submitted; whether `BDG | Marketing Automation` has messaging products; the ERP migration status on production; the Hostinger snapshot; the production `scheduled_jobs` rows; the `scheduler.gateway` flag row; CarryBee credential state; whether the boss will allow Hindi-language customers.

---

## 8. WHAT HQ NEEDS FROM ERP / SHOPS / META / COURIERS / OTHER SYSTEMS

| System | API? | R/W | Capability required | Permissions / auth | Data | Webhook/event | Polling | Reconciliation | Ops concern | Current status | Blocker |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **ERP** (`erp.bordeguna.com/api/hq/v1`) | yes, built | R + 3 W | overview, low stock, products/SKU/price/availability, customers by phone, tickets (+open), approvals pending, reports (`sales_by_brand` only), order draft/record/cancel/read | Sanctum token per business with named abilities; `hq:business:{id}`; `sales.orders.approve` never issuable | contract-pinned bodies (`hq-order-reads.v1`, `hq-sales-order-drafts.v1`, `hq-sales-orders.v1`) | none from ERP → HQ (polling only) | daily 04:00/04:05 Dhaka snapshots | read-back + `DraftReconciliation`/`OrderReconciliation` | throttle 120/min | reads verified 2 Oct; writes on ERP prod, no token holds them | write token + second approver; HQ migrations on prod UNKNOWN |
| ERP (future) | needs new endpoints | R | CRM leads/pipeline, quotations, SMS/email campaign send, cost prices in product read, courier consignment state | new abilities | — | ideally `lead.created`, `quotation.sent` events | — | — | — | not exposed | ERP work (yours) |
| **LaLaDia / Bionic** assistant API | yes | R | product search/show/list/combos; `/assistant/orders?phone=`, `/availability`, `/promotions` exist and are **uncalled** | `X-API-Key` (verified) | — | — | — | — | throttle 30/min | verified | none |
| LaLaDia / Bionic admin API | yes | R (W present, refused by design) | order by ref, orders by phone, recent orders (reconcile) | Sanctum bearer `shop_admin_token` | — | — | nightly 02:00/02:15 | `FactsReconciler`, `FactsIntegrityChecker` | `show` stamps `admin_viewed_at` (avoided) | **rejected since 22 Sep** | new tokens (you) |
| LaLaDia / Bionic webhooks → HQ | yes | inbound | `order.created/updated/status_changed/payment_updated`, `shipment.status_updated`, `customer.registered`, `coupon.expired`, `inventory.low` | HMAC `X-Shop-Signature`, timestamp ±300 s, per-store secret | full-state order payload | yes (8 types emitted) | — | nightly covers gaps | **network block since 13 Sep**; `inventory.low`/`coupon.expired` unhandled in HQ | emitting, not arriving | Hostinger (you) |
| **Econic (WooCommerce)** | none | — | unsupported by design (ADR-009/015) | — | — | — | — | — | — | no connector | decision: Woo adapter or wait for the ERP website module |
| **Meta Messenger / Instagram / comments** | Graph API, adapters built | R (webhooks) + W (send) | inbound events, send text (RESPONSE), comment read; IG send unproven | app secret (signature), verify token, Page tokens, IG token; App Review for `pages_messaging`, `instagram_manage_messages`, `pages_read_engagement` | PSID/IGSID, message text, attachments, delivery/read receipts | yes (webhook, flag OFF) | — | delivery ledger; **no reconcile job** | 24 h messaging window; 60/min local throttle | built, unregistered, no credentials | S1, S2 (you) |
| **WhatsApp Cloud API** | adapter built | R + W | inbound text/voice refs, send text/template | WABA permanent token, phone_number_id | wa_id, statuses | yes (same webhook) | — | statuses applied to ledger | 24 h window; templates outside it | built, unregistered | S1, S3 (you) |
| **n8n** (existing Bionic bot) | copy-forward receiver built | inbound | paired shadow comparison during P3-G3 | HMAC `meta_forward_secret` | the same Meta payload + what n8n sent | yes (flag OFF) | — | `shadow_comparisons` | — | built, flag OFF | an n8n node to forward (yours) + secret |
| **Meta Marketing API** (Graph v26.0) | reader built | R (W designed, not built) | account, campaigns, insights per day | `meta_ads_token` per business (system user, `ads_read`); later `ads_management` for writes; `platform_accounts` rows per ad account with brand + currency; an `ads.monthly_budget` policy row per business (the guard refuses without it) | spend/impressions/clicks/purchases (Meta-attributed, not delivered) | none | scheduled sync (not scheduled yet) | contribution needs an ERP cost-price source (bound to "unavailable" today) | header-driven backoff built | unregistered, no token, seed ids stale | O-3/O-5/O-6 (you); seed fix (me) |
| **Payment gateways** (SSLCommerz in shops/ERP website; bKash/Nagad none) | shops/ERP only | — | payment link for an AI order; payment state read-back | merchant creds per store | — | SSLCommerz IPN exists in shops | — | — | — | HQ has none | S6 (boss); design decision where the link is minted (ERP) |
| **Couriers** (Pathao live in shops; Steadfast/RedX/CarryBee config only; ERP manual) | shops only | — | booking, tracking, **fraud/phone history** (Steadfast `fraud_check/{phone}`, eCourier `fraud-status-check`, Pathao panel) | merchant API keys per courier | tracking state, delivery outcome, phone history | Pathao webhook in shops | 15-min sync in shops | — | PDPA profiling rules | nothing in HQ/ERP | S7 (boss); legal read |
| **Google** (Speech-to-Text bn-BD) | adapter built | R | transcribe voice notes | service account / access token, project | audio bytes (not fetched yet) | — | — | — | cost per minute | unregistered | key (you) |
| **TTS** (ElevenLabs / Azure bn-BD) | adapters built | W (audio) | spoken replies | API keys | — | — | — | — | cloned voice refused | unregistered | key (you) |
| **Search** (Brave / Tavily) | adapters built | R | research candidates | API key | URLs, snippets | — | — | — | cost ceiling per task built | unregistered | key + choice (you) |
| **Image generation** | **nothing** | W | product creatives | provider key | brand kit, product photos | — | — | — | rights/provenance recorded in `media_assets` | interface only | provider decision (you) |
| **Video generation** | nothing | — | — | — | — | — | — | — | — | absent | later |
| **Telephony** | contract only | — | calls, recording | BTRC number, vendor | — | — | — | — | consent | absent | later |
| **AI model providers** | OpenAI live, Anthropic off | — | chat/structured output, later embeddings | vault keys | — | — | — | — | $50 soft cap; OpenAI org $100 | OK | company card (boss) |
| **Telegram** | live | W | alerts to one chat | bot token | — | — | — | — | single chat, no per-user routing | OK | per-person routing later |
| **Marketplaces (Daraz etc.)** | none | — | not requested as a channel; only "understand" | — | — | — | — | — | — | absent | none |

---

## 9. Potential future blockers (prepare now)

| Blocker | Phase | Why it stalls | Effort / category | Can you prepare now? | Do now |
|---|---|---|---|---|---|
| Meta Business Verification not submitted | P3, P5, P6 | 14 business days + App Review weeks; everything customer-facing waits | paperwork | yes | submit this week (checklist item 4) |
| App Review for messaging permissions | P3 | reviewers need screencasts of a working flow; our flow is inert | build + paperwork | partly | I build the inert flow so a screencast exists on staging |
| WhatsApp number tied to a personal phone | P3 | Cloud API needs a number on no WhatsApp app; migration later is painful | SIM | yes | buy the SIM now |
| No staging host | P3 live | customer-facing AI must not be first tested on production; compose exists, no host | server | yes | KVM 4 upgrade (item 10) |
| Second approver not named | P4 | absolute rule in code; no order can be approved | decision | yes | name the person |
| ERP website store does not exist | P4 (ADR-046 shape) | `take_order` admits only ERP-module stores by design | ERP programme | partly | decide: admit a standalone shop as a bridge, or schedule the ERP store |
| Payment gateway and courier accounts | P4 | 1-4 weeks each; "where is my order" and online payment wait | KYC | yes | start merchant applications |
| ERP cost prices blank | P5 | contribution profit stays blank forever | data entry | yes | staff enter cost prices |
| Ad account currency/brand mapping | P5 | HQ refuses unknown accounts | decision | yes | write the mapping |
| Claims policy per product | P6 | drafts cannot be checked; CRPA s.44 exposure | legal/decision | yes | write it, legal read |
| Image provider and brand kit | P6 | no generator exists; real-product consistency needs reference photos | decision + assets | yes | choose, collect photos |
| SMS masking (BTRC) | P4/P7 | 1-3 weeks; order updates by SMS | regulatory | yes | apply |
| BTRC-licensed number for calls | P7 | months | regulatory | yes | ask the boss |
| PDPA 2026 compliance (profiling, consent, retention) | P3+ | phone numbers are personal data; follow-up, fraud lookup and 360 are profiling | legal | yes | lawyer read; write the consent and retention policy; HQ's `UNGOVERNED` retention list (conversations, memories) needs a decision |
| OpenAI org spend limit $100; HQ cap $50 | P3 live | 100 messages/day ≈ $90/month; 300/day ≈ $280 | billing | yes | raise limits on the company card |
| GitHub minutes | ongoing | merge gate is local only | billing | yes | budget |
| Single VPS, backups on the same disk, no off-site copy | any incident | a disk loss loses HQ, ERP, backups together | infra | yes | R2/Cloudflare or any off-box copy; confirm snapshot |
| Autonomy numbers undecided | P9 | nothing can step above A0 | decision | after shadow week | prepare the thresholds you would accept |
| Hindi or other languages | V5 | no detector/prompt/eval cases | build | later | tell me if Hindi customers are real |

---

## 10. Duplication and missing foundations (build once, reuse)

Found duplicated or ad hoc (all [REPO]):
1. **Five registry shapes** for "which provider serves X": `PlatformRegistry` (empty), `CommerceRegistry` (hardcoded list), `ErpRegistry`, `ProviderRegistry` (AI), `SpeechProviders` (two slots). → one `ConnectorRegistry` keyed by capability contract, feeding `CapabilityReadiness` and `CapabilityRouter`.
2. **Seven approval mechanisms**: generic `approvals` (hash-bound, single use) vs `scheduled_jobs.approval_state`, `content_briefs.approved_by`, `content_drafts.reviewed_by/status`, `research_tasks.sources_approved_by`, `customer_merge_candidates.decided_by`, `shadow_comparisons.verdict_by`, `agent_runs.review_verdict`. → route every consequential approval through `approvals`.
3. **Human verdict vocabulary** split across `decision_records`, `agent_runs.review_verdict`, `shadow_comparisons`, `shadow_probe_runs` with three word sets (inbox already writes `send|discard`). → one decision vocabulary; one corpus.
4. **Six versioning idioms** (key+version+window, supersedes_id, trigger-frozen, parent chain, filename+digest, contract strings). → a shared `Versioned` trait and migration helper.
5. **Five near-identical append-only trigger pairs**. → one `hq_append_only()` function.
6. **Four rate-limiting mechanisms** and **six retry/backoff implementations**; `ToolDescriptor::retry` declared but unused. → gateway-applied throughput limits from the descriptor; one retry policy.
7. **Field-by-field reconciliation** written three times (`DraftReconciliation`, `OrderReconciliation`, `FactsIntegrityChecker`). → one comparator.
8. **Idempotency/dedupe keys** composed ad hoc in seven places; two canonical-JSON implementations. → one `CanonicalKey`.
9. **Scope enforcement at three altitudes** (gateway, controller, tool) with no Eloquent global scope; docs/05 promised one. → a fail-closed global scope.
10. **Correlation id typed three ways** (uuid, string 64, string 128).
11. **Phone normalisation** copied in three repos by design (acceptable), one class inside HQ.

Missing foundations the vision will need in more than one phase (build once, inert):
- **Workflow/step engine with waits** (order follow-up, publish-then-verify, campaign guardian, follow-up sequences): `workflow_runs`/`workflow_steps` designed in docs/05, absent.
- **Event-triggered jobs** (kind=event is dead code) and a **business-event router** to an AI worker or person.
- **Outcome + attribution primitive**: an `outcomes` record on any action (reply, order, post, campaign change) linkable to `facts_orders`/revenue; conversation outcome column.
- **Follow-up / opportunity / task entities** (human and AI work items with due dates, owner, state).
- **Playbook/knowledge store** with governed versions and retrieval into context.
- **Experiment entity** with variant assignment and measurement.
- **Notification routing** per person/role/brand (today one Telegram chat + an audit row).
- **Omnichannel outbound** is one shape already (`OutboundMessage`); keep it.
- **Per-provider throughput and cost ceilings at the gateway** for scale (V27).

---

## 11. First-class HQ concepts

| Concept | Today | Recommendation |
|---|---|---|
| Customer | exists (per business) | keep; add a cross-business person link later (one phone, two brands) |
| Customer Relationship (360) | partial (rollups) | introduce a read model (AOV, delivered/cancelled, last contact, open follow-ups, outcomes) — needed by V6/V7/V8 |
| Conversation | exists | add `outcome` + `resolution` columns |
| Lead | absent | introduce when outreach starts; ERP CRM already has leads: decide which system owns a lead (ERP, per "truth stays where it is") and have HQ read it |
| Opportunity | absent | introduce as an HQ entity (detected from conversations/orders), proposals only |
| Follow-up | absent | introduce (scheduled, consent-checked, approval-gated) — core to V7 |
| Sales Activity | absent | later; derive from conversations + follow-ups first |
| Quote | transient | keep ERP as owner; HQ records the quote reference on the conversation |
| Order Intent / Draft | transient (ERP holds the draft) | add an HQ-side reference row linking conversation → draft key → order fact (needed for outcome tracking) |
| Campaign | read mirror | introduce an HQ campaign record only when the write side (prepare paused campaign) is built; until then read models suffice |
| Creative | absent | introduce with image generation (asset + variant + platform + approval fingerprint) |
| Content Asset | exists (`media_assets`) | keep |
| Research Artifact | exists | keep |
| Business Event | exists (`events`) | add a type registry + routing targets |
| Task (human work item) | absent | introduce one `tasks` entity for human work (follow-up, approve, call customer) — replaces ad hoc "needs a person" states |
| Agent | exists (rows) | keep; add `kpis`/outcome rollups later |
| Skill | absent | defer; tools + prompts cover today; revisit when repeated procedures appear |
| Capability | exists (two layers) | keep |
| Policy | exists (table unused) | seed real rows; build the screen (permission exists) |
| Approval | exists | consolidate (§10 item 2) |
| Outcome | partial (technical only) | introduce the business-outcome record (§10) |
| Experiment | absent | introduce with P5/P6 writes, not before |
| Evidence | exists in many shapes | acceptable; do not unify now |
| Customer Preference | partial (memory keys) | typed preference fields (language, contact time, channel) on the customer |
| Communication Preference | partial (consent ledger) | add preferred channel + do-not-contact UI/command |
| Playbook | absent | introduce (V19) |
| KPI/metric | computed on read | add a daily business KPI series when outcomes exist |

---

## 12. Real-time customer communication: foundation vs gap

| Need | Foundation exists | Gap |
|---|---|---|
| Text on Messenger/IG/WhatsApp | normalisers, send adapters, idempotent sender, delivery ledger, inbox | provider registration, credentials, account rows, wake-up path, verification |
| Facebook comments | inbound normaliser | no comment reply implementation |
| Phone/voice | STT/TTS adapters, voice-note ingestion | audio fetch, telephony, streaming, licensed number, recording consent |
| Multilingual | bn/en/banglish detect, prompts, vocabularies | script enforcement (docs/92), Hindi, preferred-language field |
| Customer identity + continuity | identities, one open thread per platform thread, strangers | auto-merge on revealed phone; cross-business person |
| Customer history in context | `SupportContext` (orders, memories labelled) | conversations across channels not summarised; promises/follow-ups absent |
| Sales/product/order context | ERP read tools (price, availability, eligibility), shop reads | none held by Support beyond orders; a sales role profile |
| Personalised recommendations | none | recommendation logic from catalogue + history (rules first) |
| Objection handling | none | playbook per brand (V19) |
| Follow-up / proactive outreach | none; refused by role boundary | entities, consent, approval, provider |
| Human takeover / AI takeover | state machine, inbox | notification ping; context package completeness |
| Complete communication history | messages with provenance, audit | outcome, conversation analytics |
| Conversation analytics / outcomes | none | outcome column, dashboards |

Verdict: the architecture **can** support the vision (every piece is behind a contract and a provider slot); what is missing is activation wiring, the sales-domain entities, and the outcome layer.

---

## 13. The full automation loop

| Step | Today | Gap |
|---|---|---|
| Sense | shop webhooks (blocked), ERP polls, Meta webhook (OFF), alerts | ads, research, voice inert; no ERP CRM ingestion |
| Understand | identity, language, escalation triggers, five runtime guards, context assembly with provenance | no enrichment/segmentation/anomaly detection on business metrics |
| Decide/propose | agent runtime, autonomy resolver, policy engine | all at A0 shadow |
| Authorize | gateway, EEC, approvals | strong; `policies` rows empty |
| Execute | tools, connectors, scheduler, sender, publication service | every write path inert |
| Verify | delivery ledger, read-backs, reconciliations | no generic postcondition on `Tool` |
| Reconcile | nightly facts, `reconcile()`, shadow comparisons | per connector; no delivery reconcile job |
| Learn | `decision_records` written (3 writers) | **no reader**; no outcome linkage; no KPI linkage |
| Improve | evaluation → autonomy widening only | no prompt/policy/playbook proposals; no experiments |
| Proactive task generation | scheduled jobs, shadow probes, NL scheduling (flag), approvals sweep | event-kind jobs dead; no follow-up generation; briefing not scheduled |
| Opportunity detection | none | absent |

"No Implicit Task Invention" is preserved: proactive work must come from `scheduled_jobs` (kind cron/interval/once/**event**) and `events.handlers`, never from the model. The gap is that the event kind never fires.

---

## 14. The AI workforce vision

| Check | Exists | Missing |
|---|---|---|
| Role isolation | yes: role profiles v2, forbidden action classes, EEC per agent, tool sets | only 3 roles |
| Shared capabilities | yes: tools, intersected with both people's permissions | — |
| Skills | no | defer |
| Knowledge | prompts, `owner_preferences` (no writer), memories | playbook store + retrieval |
| Memory | per customer/business/agent, labelled | agents never write; semantic memory unused |
| EEC/permissions | yes | — |
| Cross-agent delegation | no | a governed "start workflow for role X" tool that creates a scheduled/event job, never a direct agent-to-agent call |
| Task handoff | agent↔human only | agent→workflow→agent via the gateway |
| Outcome tracking | no | §10 |
| Agent evaluation | yes (suites per role) | per-role suites for new roles |

Verdict: the runtime can host more roles as rows, but the collaboration the vision describes needs the workflow/task primitive first; adding agents before that would create eleven isolated chatbots.

---

## 15. Future-proofing (10-20 years)

Follows the principle (vendor replaceable, contracts durable): model providers (tiers not ids, one interface, vault); messaging/publishing/research/voice/ads behind contracts with empty registries; commerce behind `CommercePlatform`; consent, approvals, audit, evaluation as HQ-owned tables; MCP deliberately not the governance layer (ADR-029, zero MCP code).

Does not yet follow it / watch:
- **Postgres 17 is load-bearing** (triggers, partial indexes, jsonb SQL, CHECKs, `percentile_cont`) — accepted and intentional; a DB swap is a rewrite. Keep, but stop adding triggers per table (use the shared function).
- **Redis required** for queue/lock/cache in production; acceptable.
- **Meta-specific leakage**: `PLATFORM_FOR_CHANNEL` map, Graph-specific error classes, 24 h window logic in the WhatsApp channel; keep inside adapters (it is).
- **OpenAI-specific**: strict-schema subset handling and reasoning effort; `ServingProvider::DEFAULT = anthropic`. Minor.
- **ERP connector not behind an interface** (`ErpConnector` final class, `ErpRegistry`); if a second ERP or the ERP website module changes the contract, introduce `ErpPlatform` then.
- **n8n** remains a transitional executor for the Bionic bot and content pipeline; the copy-forward path is the right bridge.
- **Authentication**: Laravel + 2FA + Spatie roles, fine; SSO later if staff grow.
- **Event infrastructure**: DB outbox is durable and vendor-free; good for a decade at this scale.
- **Deployment**: single VPS Docker Compose; the compose is portable; the risk is capacity and single-box backups, not lock-in.
- **Agent framework assumptions**: none (own runtime); good.

---

## 16. Priorities

### NOW (this week; no external wait)
1. Build docs/92 (language contract) → full suite → freeze → **your release** → P3-G2 attempt 5 → **you open the Executive shadow week**.
2. Run CI on `main` (first green run since 16 Sep is the target); fix what it finds; correct the Meta ids in `config/hq-seed.php`; enumerate the credential-provider field on the Credentials screen so only kinds the code reads can be stored.
3. You: new shop admin tokens; Hostinger ticket for the network block; submit Meta Business Verification; buy the WhatsApp SIM; name the second approver; GitHub minutes; type the restore drill on the VPS.
4. Me, inert and safe: Support wake-up path behind `channels.meta.inbound` (emit `conversation.reply_proposed`, run the agent at A0, proposal to the inbox), hand-over Telegram ping + reason + order card, delivery reconcile job, IG/WhatsApp platform-account seeding, event-kind scheduled jobs, briefing schedule.

### NEXT (after the shadow week opens; still no customer-facing activation)
5. Customer 360 read model + page; typed customer preferences; consent withdrawal/do-not-contact command and UI; auto-merge rule for revealed phones.
6. Outcome + attribution primitive; conversation outcome; decision-corpus reader (first report: edit rate per agent).
7. `ConnectorRegistry` and approval consolidation (§10, items 1-2) before more providers arrive.
8. Register research + content tools behind flags once you supply keys and the claims policy; start `content-render`.
9. Admit one store to `take_order_stores` in a test business with the write token; P4-G1/G2 with you.
10. Ads read side registered when the system-user token exists; cost prices entered.

### LATER
11. Follow-up / opportunity / task entities and the first proactive workflow (consent-gated, approval-required).
12. Playbook store with retrieval; sales role profile; recommendation rules.
13. Image generation provider + creative entity; publishing live after S1/S2.
14. Ads write side (ADR-050), experiments.
15. Voice notes end to end; telephony; avatar.
16. Additional agent roles, cross-agent workflows.

### WAIT / EXTERNAL
Meta S1/S2/S3; payment gateway; courier APIs; SMS masking; BTRC number; KVM 4; company card; PDPA legal read; your plan and priority order; autonomy numbers.

---

## 17. DO NOT BUILD YET

| Feature | Why wait |
|---|---|
| Autonomous outbound to customers (replies, follow-ups) | no consent model beyond inbound 24 h, no provider, no evidence; must pass P3-G2..G5 and grants |
| Autonomous ad spend (raise budget, switch on) | ADR-050: a person only; no write side; no accounts mapped |
| Autonomous publishing | ADR-051: one approval per exact post; no tokens; no claims policy |
| AI-to-AI delegation | no workflow/task primitive; would bypass the gateway's authority intersection |
| Self-modifying playbooks/prompts | V19/V20 forbid it; no playbook store; prompts are versioned by digest and changed only by release |
| Phone calls / COD confirmation calls | no licensed number, no telephony vendor, no recording consent policy |
| Avatar / cloned voice | refused in code; needs written consent policy and `media_identities` |
| Digital twin / simulation | no outcome data; years away |
| Unrestricted browsing/research | research is bounded to approved sources by design; keep it |
| Any grant above A0; an active evidence policy with invented numbers | numbers are yours; nothing ships with a number |
| Adding 11 agent roles now | eleven isolated chatbots; build the workflow primitive first |
| Cross-merchant fraud database | PDPA 2026 profiling rules; legal read first; courier APIs first |
| Switching back to Anthropic | needs its own complete gate; only on your word |
| WooCommerce adapter for Econic | ADR-009/015: wait for the ERP website module decision |

---

## 18. Execution programme: build broadly, activate narrowly

Workstreams I can run in parallel (one worktree + one test DB each), all testable with simulated providers (`SimulatedErp`, `SimulatedChannelAdapter`, `Http::fake`, the 44-scenario simulator):

| WS | Stream | Needs from you | Gate before activation | Testable without prod credentials |
|---|---|---|---|---|
| W1 | docs/92 language contract + CI on main | release line | P3-G2 attempt 5 | yes |
| W2 | Support wake-up path (inert), hand-over ping, delivery reconcile, IG/WhatsApp account rows, event-kind jobs, briefing schedule | Telegram destination for pings | P3-G3 (needs S1) | yes |
| W3 | Customer 360 + preferences + consent commands + auto-merge | identity decision (cross-business) | none (reads) | yes |
| W4 | Outcome/attribution primitive + conversation outcome + decision-corpus reader | none | none | yes |
| W5 | ConnectorRegistry + approval consolidation + shared append-only/versioning helpers | none | none | yes |
| W6 | Research + content registration behind flags; `content-render`; image `MediaGenerator` once a provider is chosen | keys, claims policy, provider choice | P6-G0..G2 | partly (fakes) |
| W7 | Orders: admit one store, agent holds `take_order` at A0, customer-orders read tool | approver, write token, store decision | P4-G1..G3 | yes |
| W8 | Ads read side registered + sync scheduled | system-user token, accounts | P5-G1 | partly |
| W9 | Follow-up/opportunity/task entities + first proactive workflow (inert) | communication policy | P3-G5 + consent | yes |

Serialised activation (each a gate, each a decision of yours): Executive shadow week → Support A0 shadow beside n8n (P3-G3) → Support proposals sent by a person (P3-G4) → Support A1/A2 on Messenger → WhatsApp → order proposals (P4) → ads reads (P5-G1) → content drafts (P6) → publishing (P6-G2) → ads writes (P5-G2+) → follow-ups → voice.

Rough engineering clocks (mine, not promises): W1 1-2 days; W2 3-4 days; W3 3-4 days; W4 3-4 days; W5 2-3 days; W6 4-6 days after keys; W7 2-3 days after decisions; W8 1-2 days after token; W9 5-7 days. Calendar clocks are yours and Meta's.

---

## 19. Uncertainty register and disagreements

**Confirmed from repository**: everything in §2.2, §3 ladders, §5, §10, §11 unless tagged otherwise.
**Confirmed from production**: §2.1.
**Documented but not implemented**: docs/92 contract; docs/14 anger/threshold/confidence triggers, auto-close, outcome marking, OTP merge, full 360; docs/19 calls table; docs/20 media identities; docs/22 workflows (order follow-up, campaign guardian, stock protection); docs/08 11 agents and delegation; docs/10 skills; docs/09 knowledge/embeddings; docs/12 guardrail keys and policy UI; docs/13 domain approvers/SLAs; docs/11 tool names; docs/30 Phase 4 shop-checkout path; ADR-050 writes; ADR-029 MCP adapter; ADR-032 orchestration engine.
**Inferred**: `scheduler.gateway` ON in production; Bionic reconcile row present; event-kind jobs never fired on production; the ERP HQ migrations ran on production.
**Unknown / owner to verify**: S1 submission; ERP `migrate:status`; Hostinger snapshot; production `scheduled_jobs` rows and flag rows (classifier blocked the read); CarryBee credentials; whether the boss's Meta app can carry messaging.

**Documents that disagree with the implementation (fix the documents):**
1. `docs/adr/046` status line says "proposed, not implemented (16 Sep)"; the tools exist and are registered inert.
2. `docs/68` §4 and `docs/89` line 161 say ERP `76f6168` is not deployed; it is an ancestor of `61627ec`, which the ERP record calls production from 28 Sep.
3. `docs/94` §3.2 lists a Telegram ping on hand-over as built; it does not exist. (Corrected in this audit; docs/94 to be amended.)
4. ADR-017/docs/48 define R5 as "a customer-visible message"; `messaging.send_reply` and `send_whatsapp_reply` declare **R3**. The structural inert guarantee holds, but the tier label contradicts the written definition and should be R5.
5. `docs/68` §5 "33 practice conversations" vs 44 scenario keys / 43 live cases.
6. `docs/30` "immediate next step" frozen at 8 Sep; `README.md` frozen at Phase 0.
7. `docs/72` §A "a hundred scripted threads" vs 44.
8. `docs/15` adapter and DTO names, `commerce.availability_rule` policy row: none exist under those names.
9. `docs/12` evaluation order and guardrail keys differ from `PolicyEngine`/`PolicyRules::KNOWN` (unknown keys are refused).
10. `docs/13` per-domain approvers and SLAs vs single `operator`/4 h/48 h.
11. `docs/48` Q.2 "both agents ship at A0 then A1": no grant or policy exists; A1 is reachable only after your numbers.
12. `docs/50` says `scheduler.gateway` was switched on; no row is seeded in the repo.
13. `docs/88-q-record`/`docs/94`: IG/WhatsApp inbound "ready behind a flag" overstates readiness; account rows are missing.
14. `docs/88-u`/`docs/94`: voice notes "partly built" — the audio fetch does not exist, so nothing transcribes end to end.
15. CLAUDE.md/memory (now fixed) said production `3314c54` and an Anthropic-credit blocker; both stale.
16. The shop→HQ network block appears in no HQ document.
17. `docs/88-s/t/u` records still say "merged: no" for streams that are merged and in production (`fdd9b9d`, `c636f17`, `4a41d13`).
18. `docs/84` names platform `meta_ads`, permission `hq.tools.ads.write` and flag `ads.write`; code uses `meta_ad_account` and has neither the permission nor the flag. `AdsHumanOnlyTest` cited by ADR-050 does not exist (the assertion lives in `AdsInertPostureTest`).
19. `docs/85` lists `media.generate_image`, `media.render_card`, `publishing.create_draft_post/delete_post/read_post_insights` as tools; none exist. `content_publications.approval_id` is never set.
20. `docs/27` (5 Sep) describes a hand-migrate release and bare rollback, three CI workflows, static analysis, a `voice-gateway` service and an anonymised staging copy; the scripts, one workflow, no PHPStan, no voice gateway and an empty staging definition are what exist. `docs/28` and the deploy README ask for monthly restore drills; the last was 5 Sep.
21. `docs/25` lists a "backup failed" alert rule and a weekly digest; neither exists (15 rules, backup failure is Telegram from the backup container and the host cron only).
22. `config/hq-seed.php` Meta ids (Bionic pixel, ad account) predate the 21 Sep portfolio move.
23. `docs/94` §3.1 says "4 dashboards"; there are five observe pages plus posture, autonomy evidence and briefings.

---

## 20. Closing

HQ today is a well-governed control room that reads the business and has proven, in thirteen gates and four support exams, that its agents stay inside their authority. What it has not yet done is a single piece of business work. The vision you wrote is reachable on this architecture, but its centre of gravity (one-to-one sales, follow-up, revenue learning) sits in the part of the system that is still empty: the sales-domain entities, the outcome layer, the workflow engine, the providers and the credentials.

The shortest safe path is the one in §18: finish the language fix and open the Executive shadow week (ten days to the first live result), start Meta verification the same day, and let me build the inert customer-AI activation path, the Customer 360 and the outcome primitive while those clocks run. Nothing customer-facing switches on without a gate and your name on it.

Sources: `docs/00`, `docs/05`, `docs/08-23`, `docs/30-31`, `docs/48`, `docs/53`, `docs/68`, `docs/70`, `docs/72`, `docs/79`, `docs/81-92`, `docs/94`, `docs/adr/001-051`, `apps/hq/**`, `deploy/**`, `.github/workflows/hq-ci.yml`, ERP `routes/api.php` and `app/Modules/Sales/**`, LaLaDia `routes/public.php` and `app/Infrastructure/Webhook/**`, production reads of 3 Oct. Nothing was modified.
