# 37 - Resume Briefing, 6 September 2026

Written for a reader (human or AI) with no prior context on this project. It restates what HQ is, what has been verified true of the wider estate, exactly what has been built and where it stands right now, and what remains, in enough detail that no earlier document needs to be read first. Every fact below was either read from the documents listed at the end or re-verified directly against the three git repositories on 6 Sep 2026 (commit hashes, branch names and file line counts were checked live, not recalled).

This file is `docs/37`. It does not replace `docs/00-36`; it is the entry point that tells you which of them still matter and in what order to read them if you need more depth than this briefing gives.

---

## 1. What this project is

**BordeGuna-HeadQuarter ("HQ")** is a new, separate web application whose job is to become the single place from which the whole Bor De Guna company group is run: one login, one screen that watches every business (LaLaDia, Bionic Garden, Azmion, Econic, and the Bor De Guna ERP), tells the owner what needs attention, lets humans and AI agents act through controlled, audited tools, and remembers why every action happened.

It is explicitly **not** a second ERP and **not** a second shop. Employees, payroll, accounting, inventory truth, procurement stay in the Bor De Guna ERP. Products, prices, stock, promotions and orders for LaLaDia and Bionic stay in the shop platform. HQ integrates with these systems of record; it does not copy their truth into itself. It is also not an unrestricted AI agent: no AI action reaches a customer, a campaign budget, a payment or a record without passing a policy/permission/approval layer, and every action is audited.

This is a **different project from the "Bor De Guna ERP"** that most of this assistant's other working memory concerns. The ERP (`bordeguna-erp`, live at `erp.srv1808022.hstgr.cloud`) is one specific business's day-to-day operating system (9 employees, HRM, payroll, accounting, POS, CRM). HQ is a layer that sits above the ERP and the shops and eventually orchestrates all of them, including AI-driven marketing, content and customer conversation work the ERP was never meant to do. **Nothing described here has touched the ERP's production behaviour except one still-unmerged, unreviewed branch (section 4.2).**

## 2. The people and how they work

- **Joy** is the operator building this with Claude Code, day by day, the same working style used on the ERP: architecture document before code, real verification against live systems (not just green tests), one module/phase at a time, explicit approval before anything touches production or costs money.
- **"The boss"** is C M Moin, referenced for decisions outside Joy's authority (a new official Meta account/portfolio, mail provider, mail migration timing).
- Joy holds **every approval right** in the design (`operator` role with all permissions, doc 13) so nothing about who-can-approve is left undefined.
- Standing rule for whoever picks this up: **read-only inspection before any claim** ("code and live systems win over documents when they disagree" - this discipline already caught and corrected two documentation errors, see section 4.3, item O-2). Nothing destructive, no production migration, no DNS/Meta/payment/credential change without Joy's explicit go-ahead, every time, not just once.

## 3. The wider estate this project watches (verified 5 Sep 2026, re-confirmed live 6 Sep 2026)

| Brand | Domain | What it is | System | Host |
|---|---|---|---|---|
| Bor De Guna | bordeguna.com | Parent company site | Static React/Vite SPA | Nikolahost shared cPanel |
| LaLaDia | laladia.com | Food e-commerce | Laravel 12 shop platform (the "product") | Nikolahost shared cPanel |
| Bionic Garden | bionic.garden | Wellness e-commerce | Fork of the LaLaDia platform | Nikolahost shared cPanel |
| Azmion | theazmion.com | Technical/agency brand | Static React/Vite SPA | Nikolahost shared cPanel |
| Econic | econic.store | Consumer electronics shop | WordPress 7.1 + WooCommerce 10.9.1 | Nikolahost shared cPanel |
| Bor De Guna ERP | erp.srv1808022.hstgr.cloud | ERP + HRM + Accounting + Sales/POS + CRM | Laravel 12 + Inertia/React, Docker | Hostinger VPS |
| Automation | n8n-vb99.srv1808022.hstgr.cloud | Bionic Messenger bot, content pipeline | n8n 2.29.7 + Postgres 17 | Hostinger VPS |
| **HQ (this project)** | **hq.bordeguna.com** | **Central operating/intelligence layer** | **Laravel 12 + Inertia/React/TS, Postgres+pgvector, Redis, Horizon, Docker** | **Same Hostinger VPS** |

Everything runs on one Hostinger KVM 2 VPS (`srv1808022`, 200.97.162.40, Ubuntu 24.04, 2 vCPU, 7.8 GB RAM, **no swap as of 5 Sep**) behind a single Traefik reverse proxy, alongside n8n, two Postgres instances, a content-render service, an unrelated personal project ("Zeno"), and a client preview site ("safor-travels" - explicitly not Joy's own project, do not touch). The shops and the static sites live on Nikolahost shared cPanel with no SSH access. Full inventory (containers, cron jobs, accounts, credentials by name, security observations) is in `docs/01-current-state-audit.md`; nothing in this briefing repeats it beyond what the resume decision needs.

The ERP itself, as of this audit: **all seven of its planned modules are live** (Foundation, HRM, Inventory, Purchasing, Sales/POS, Accounting, CRM), 117k lines of PHP, 200 tables, 3,081+ test methods, an AI assistant named Hudhud already built inside it. It has **no public API** (Inertia web routes only) and **no domain events** - both of those gaps are exactly what HQ's Phase 1 closes.

## 4. What has actually been built and shipped so far

Work proceeded in phases, each documented before being coded, each ending with the feature exercised against a real system rather than trusted on a green test suite alone (this discipline is stated as principle 5 in `docs/00` and has already justified itself: it is how the two documentation-vs-code discrepancies in section 4.3 were caught).

### 4.1 Phase 0 - Foundation (accepted and deployed 5 Sep 2026)

Delivered: the base Laravel 12 + Inertia/React/TypeScript application; authentication with mandatory 2FA; RBAC; the core domain model (businesses, brands, stores, platform accounts); `integration_credentials` (encrypted secret storage); feature flags; an append-only audit log; structured JSON logging with correlation ids; the cost/event/tracing foundation (`ai_runs`, `tool_calls`, `events` inbox/outbox with dedupe and dead-lettering, `connector_calls`); a scheduler skeleton (`scheduled_jobs`, `job_runs`, tick, run history - no natural-language entry yet, that is Phase 2); the Docker stack (app, web/nginx, horizon, scheduler, Postgres 17 + pgvector, Redis, backup) on the existing VPS behind Traefik.

Explicitly **not** built in Phase 0, on purpose: no AI agents, no model calls, no social integrations, no marketing APIs, no customer-facing AI. Phase 0 is infrastructure only.

Before any HQ container was started, the pre-deploy safety checklist ran: an actual **restore drill** was performed - the ERP's nightly backup dump was restored into a scratch MySQL instance and the ERP was booted against it (`deploy/RESTORE-DRILL.md`), proving the one existing production system's backups are real, not just present. Deployed and verified at `hq.bordeguna.com` on 5 Sep 2026.

### 4.2 Phase 1 - Identity, data and API foundation (built and partly deployed 5 Sep 2026, evening)

Phase 1's job: give HQ a safe way to read the ERP and the two live shops, and start building one unified idea of "who is this customer" across all of them, without HQ becoming a second copy of anyone's truth. A full readiness review (`docs/35`, 333 lines) was written and reviewed **before** any Phase 1 code, verifying every architectural assumption directly against the ERP and shop source code (not against older design docs) and flagging every place a doc had assumed something the code did differently (section 4.3 below).

**Built in the HQ repository itself** (`BordeGuna-HeadQuarter`, branch `main`, all pushed, HEAD `61470ba`):
- `App\Commerce\Contracts\CommercePlatform` - a single interface (with DTOs: Product, Variant, Price, Availability, Order, etc.) that any e-commerce platform can implement, so HQ code never needs to know whether it is talking to LaLaDia, Bionic, a future rebuilt Econic, or a future multi-vendor platform.
- `LaravelShopAdapter` - one class that serves **both** LaLaDia and Bionic, because a live diff of their APIs came back byte-identical; only store-level configuration (base URL, keys, order prefix `LLD`/`BNC`) differs.
- `CommerceRegistry` - resolves per-store credentials (`shop_assistant_key`, `shop_admin_token`) from `integration_credentials`.
- `ConnectorCallLogger` - every outbound call to a shop or the ERP is logged.
- A webhook inbox: `POST /api/webhooks/shop/{store}`, verifying an HMAC signature over the raw request body, checking a timestamp window, and deduplicating by delivery id (or a body hash as fallback for older event types that carry no id).
- `facts_orders` - a read model of orders as HQ understands them, built from webhook events, never the source of truth for an order.
- Unified customer identity tables: `customers`, `customer_identities` (one customer can be known by phone, email, a shop's internal user id, or - later - a Messenger/Instagram/WhatsApp id, each with a confidence score), merge candidates, and a `PhoneCanonical` normaliser that gives HQ, the ERP and both shops one shared idea of what a Bangladeshi phone number looks like.
- Console commands: `hq:customers:rebuild`, `hq:facts:reconcile` (nightly catch-up for any webhook that never arrived), `hq:commerce:probe` (health-check the shop connectors).
- A customers UI screen.
- Handlers for the shop's **real** webhook event names (`order.payment_updated`, `shipment.status_updated` - not the names an earlier design doc had guessed, see 4.3).
- `App\Erp\ErpConnector` + `ErpRegistry` (credential `erp_api_token`) + DTOs; a `facts_erp_snapshots` read model; two scheduler tools, `erp.poll_overview` and `erp.poll_low_stock`, that will let HQ poll the ERP on a schedule once a token exists (the ERP itself emits no events yet, so Phase 1 polls rather than subscribes); `hq:erp:probe`.
- **73 Pest tests, all passing, on Postgres, in CI (`hq-ci` on every push).**

**Built on a branch in the ERP repository** (`bordeguna-erp`, branch `feature/hq-api`, HEAD `3fd4be0`, **pushed but NOT merged into `master` and NOT deployed** - confirmed live on 6 Sep 2026 by checking the branch list directly):
- A new, read-first API under `/api/hq/v1`, authenticated with Laravel Sanctum tokens (a brand-new dependency for the ERP - it previously had no API of any kind, only session-based web routes).
- `HqApiContext` middleware: the `{business}` segment in the URL must match a token ability `hq:business:{id}`; the ERP's existing multi-tenant "which business am I in" context (`BusinessContext`) is set **from the verified token**, never from a header the caller could forge; a correlation id is echoed on every response.
- `HqReadController` exposing: business list, per-business overview, stock levels, low-stock list, products, customers, tickets (plus the **one write endpoint in the whole API**: creating a support ticket), pending approvals, and the ability to run any of the ERP's existing 23 report definitions by key.
- Every endpoint requires the caller's token to hold the exact same permission name (`sales.reports.view`, `inventory.reports.view`, etc.) the ERP already uses internally for that data - nothing new to authorize, the existing permission model is reused verbatim as the API's access control.
- A command to issue or revoke a scoped token per business (`php artisan hq:api:token {email} {business} --abilities=...`), refusing any ability outside a read-only allow-list. **Payroll, salary, journals and approval decisions themselves are structurally unreachable through this API** - the allow-list simply does not include them.
- Two new migrations: `personal_access_tokens` (from Sanctum) and one new column, `crm_tickets.external_ref` (so a ticket created via the API can be matched back to whatever created it, to avoid duplicates).
- 7 new tests; **the ERP's full suite (now 3,096 tests) still passes.**

**Built on a branch in the LaLaDia repository** (`LaLaDia`, branch `feature/hq-phase1-api`, HEAD `4865c109`, **pushed but NOT merged and NOT deployed**; Bionic will receive the same change later by a plain `git merge upstream/main`, since the two codebases are still identical at the API level):
- The outbound webhook envelope gained an event id, an occurred-at timestamp, a schema version and a store identifier (previously it carried none of these, which is what made deduplication and replay reliable at all).
- The retry policy for a failed webhook delivery was extended from 3 attempts over 7.5 minutes to 5 attempts over about 2.5 hours.
- A new hourly command, `webhooks:inventory-low`, fires an `inventory.low` event once per crossing below a per-product threshold, and re-arms itself once stock recovers (so it does not fire every hour forever).
- Three new read endpoints under the existing `/api/v1/assistant/*` surface (guarded by the same `X-API-Key` mechanism already used by the n8n Messenger bot): a customer's recent orders by phone, a single product's real-time availability (with the *reason* a variant is or isn't orderable), and active promotions.
- Checkout now accepts `source=assistant` with a required `conversation_ref`, so an order placed by an AI conversation can always be traced back to the conversation that created it.
- 8 new tests; the shop's full suite (now 1,307 tests) still passes.

**Deployed to production, 5 Sep 2026, 15:27 UTC, on Joy's explicit instruction ("deploy hq now"):** HQ itself, at `hq.bordeguna.com`, running commit `6bb9f06` (Phase 0 + a theme pass + all of Phase 1's HQ-side code). Verified after release: the health endpoint reported the database, Redis, Horizon and the scheduler tick all healthy; the new database table existed; the tool registry listed the two new ERP-polling tools; login worked; the shop webhook endpoint correctly answered "not configured for this store" (expected, since no shop has been wired up yet); zero errors appeared in the application log. A rollback script and a previous-release pointer exist if anything needs undoing.

**What is deliberately still NOT deployed, and why:** the ERP branch and the LaLaDia branch. HQ's own code went live because it is a brand-new, currently-inert application - going live with it changes nothing for anyone. The ERP and shop branches, by contrast, change **production systems that already run the real business** (payroll data sits one permission-check away from the new ERP API; live customer checkout sits behind the shop change). Both are held for Joy's explicit review and go-ahead, exactly as the ERP's own long-standing rule requires (never deploy a production system without an explicit ask, backups first, one system at a time).

### 4.3 Two things a design document got wrong, caught by reading the real code before Phase 1 build (worth carrying forward as a working lesson)

1. **O-1, still an open decision:** the design assumed "availability follows a product's status field only, never its stock quantity" (this was in fact Joy's own explicit answer, Q9: "inactive product -> unavailable; otherwise always in stock; never depends on quantity"). But the shop's actual checkout code (`CheckoutPricingService`) **does** refuse an order line when a variant's live stock is insufficient, regardless of that policy. So an AI that tells a customer "yes, that's available" purely from the status field could still have the order rejected two steps later at checkout. This is decision **P-1** in section 6 below: nobody has yet decided whether to change the shop's checkout behaviour to match the stated policy, or to keep checkout's stock check and simply teach the AI to say "available, confirmed at checkout" instead of a flat "available."
2. **O-2, already corrected:** an earlier design document assumed the shops would emit webhook events named `payment.completed` and `shipment.delivered`. Reading the actual listener code showed the real event names are `order.payment_updated` and `shipment.status_updated` (alongside `order.created`, `order.status_changed`, `customer.registered`, `coupon.expired`), and that checkout idempotency (returning the same order for a repeated request) **already existed** in the shop code, which the same earlier document had listed as something still to be built. Both corrections were folded into the actual Phase 1 code the same evening (5 Sep) before anything shipped - the documents were fixed to match reality rather than the code being built to match a wrong document.

The standing lesson, consistent with how the ERP side of this work has always operated: **when a document and the running code disagree, the code wins, and the disagreement gets written down rather than quietly overwritten.** A third such gap remains open and unresolved: the ERP's `customers` table has no phone-normalisation column (only leads, contacts and support tickets do), so HQ has to normalise ERP customer phone numbers itself at match time rather than relying on the ERP already having done it (item O-4).

## 5. Exact state of every repository, verified live on 6 Sep 2026

| Repository | Branch | HEAD | Status |
|---|---|---|---|
| `BordeGuna-HeadQuarter` | `main` | `61470ba` | Deployed to production (`hq.bordeguna.com`, release `6bb9f06` plus two documentation-only commits on top). Working tree clean. |
| `bordeguna-erp` | `feature/hq-api` (checked out locally; `master` is production) | `3fd4be0` on the feature branch; production remains on its own unchanged `master` HEAD | Feature branch pushed to origin, **not merged, not deployed**. Awaiting Joy's review. |
| `LaLaDia` | `feature/hq-phase1-api` (checked out locally; `main` is closest to production) | `4865c109` on the feature branch | Feature branch pushed to origin, **not merged, not deployed**. Bionic (`Bionic.garden`, branch `relaunch`) has not yet received this change and will get it by merging LaLaDia's `upstream/main` after LaLaDia itself merges and deploys. |

No repository has uncommitted local changes as of this check. All three branch states match what `docs/36` (the build record) claimed - this was independently re-verified with `git log`/`git branch`, not assumed from the document.

## 6. Exactly what only Joy (or the boss) can decide or click next, in order

This is copied and lightly reordered from `docs/36` section C and `docs/35` sections O/P, cross-checked for consistency, because it is the actual critical path for anything past this point:

1. **Review and merge `bordeguna-erp` `feature/hq-api`.** The change adds a token-authenticated, read-only API and two small schema additions; it does not touch any existing business logic, screen, or posting rule. Deploy it (migration + the usual pre-deploy-backup runbook). Then, on the ERP, create a dedicated `hq` user and issue one Sanctum token per business (LaLaDia=1, Bionic=2, Azmion=3, Bor de Guna=4) scoped to read-only abilities such as `inventory.view`, `inventory.reports.view`, `sales.reports.view`, `sales.customers.view`, `crm.tickets.view`, `crm.tickets.manage`. Paste each resulting token into HQ's `integration_credentials` screen. Run `hq:erp:probe` to confirm connectivity.
2. **Review and merge LaLaDia `feature/hq-phase1-api`** into LaLaDia's `main`, then `git merge upstream/main` into Bionic's `relaunch`, run both test suites, deploy both (backup first, per each shop's own runbook - LaLaDia and Bionic deploy differently, see `docs/01` section 3). Then, per shop: create an `hq` admin user with a minimal role and issue it a Sanctum token (for the few order/customer read-write endpoints HQ needs); note the existing assistant `X-API-Key`; add a webhook subscriber row for each event, pointing at `https://hq.bordeguna.com/api/webhooks/shop/{store-slug}` with a secret. Store all three values in HQ. Then run `hq:commerce:probe` and `hq:facts:reconcile`.
3. **Decide P-1 through P-6** (the open-decisions table in `docs/35` section P) - the one with real code consequences is **P-1**: whether to change the shop's checkout behaviour so it matches the stated "status decides availability, not quantity" policy, or to keep the current quantity check and have the AI phrase availability more carefully. The rest (who holds the API tokens, deploy cadence, Econic/WooCommerce access, privacy-policy sign-off) are process decisions with a stated safe default if Joy stays silent.
4. **Log into `hq.bordeguna.com` for the first time**, enrol two-factor authentication, and change the password.

Until step 1 and 2 happen, HQ is **fully built for Phase 1 but functionally inert** - every connector exists and is tested, but there are no live credentials for it to use yet, exactly as the deploy verification proved ("No business has an active erp_api_token credential yet" is the expected, correct answer right now).

## 7. What is explicitly not built yet, and why that is correct at this stage

Nothing past Phase 1 has been started. Specifically **not yet built, on purpose**, per the Phase 0 architecture review's "will not be built yet" list and the roadmap: any AI agent or model call from HQ; any Messenger, Instagram, WhatsApp or Facebook-comment handling; any marketing/ads adapter or campaign automation; any content generation or publishing; natural-language scheduling (the scheduler exists but only accepts structured jobs so far); customer identity *resolution logic beyond the deterministic phone/email match* (Phase 1 builds the tables and the simple matches; smarter matching is later); any change to ERP business logic; commerce *write* adapters beyond ticket creation; voice, avatars, or voice cloning; any fine-tuning (this is a permanent architectural decision, not a phase gate - ADR-013 rules it out entirely); and no migration, deletion or reconfiguration of the existing n8n workflows, the Hermes personal-assistant agent, or any ERP functionality - those get replaced deliberately later (shadow -> compare -> cutover -> deactivate -> delete only after explicit approval), never silently.

The roadmap ahead (`docs/30`, sizes are focused working weeks for one engineer with Claude Code, not calendar time):

| Phase | Delivers | Size | Depends on |
|---|---|---|---|
| 2 - Command Center | Tool gateway, policy engine, approval center, first two AI agents ("executive", "analytics"), model router with cost accounting, an "Ask HQ" chat UI, natural-language scheduling, a daily briefing skill, observability dashboards | 3 wk | Phase 1 complete |
| 3 - Customer AI | Conversation engine, human/AI handoff state machine, Messenger channel running in shadow mode next to the existing n8n bot before cutover, then Instagram DMs and Facebook comments, customer memory, a "support" agent | 3 wk | Meta App Review approved for messaging (until then, stays developer-mode / Joy-only, same as the current n8n bot) |
| 4 - AI orders | An order-taking skill through the real shop checkout API with value-gated human approval, order-lifecycle workflows, stock protection, tickets auto-created from conversations | 2 wk | none new |
| 5 - Marketing AI | Meta Ads adapter, campaign guardrails, true profit-based contribution reporting (needs Joy to fill in product cost prices first), Google/TikTok later behind the same interface | 3 wk | new ads-scoped Meta token, portfolio consolidation, a product feed |
| 6 - Content factory | Content generation (text/image), brand personas, approval flow, multi-platform publishing, retiring the current n8n content pipeline | 3 wk | Page tokens per brand, LinkedIn API access |
| 7 - Voice | Telephony-based COD confirmation calls, then an inbound receptionist | 3 wk + provider lead time | a telephony provider and a Bangla speech-quality test |
| 8 - Avatar/voice clone | Consent-gated synthetic presenter video for the content factory | 2 wk | consent + provider budget decisions |
| 9-10 - Autonomy | Widening which action classes can run unsupervised, based on measured agreement rates over time, never on a fixed calendar | ongoing | evaluation history from Phases 2-6 |

Two structural facts worth remembering when planning further: **Phase 3 (customer AI) is the phase most likely to stall**, because it depends on Meta's App Review process (typically 1-3 weeks, and gated on an official Meta business account that does not exist yet - see the open question in section 8); that is exactly why Meta preparation work (privacy pages, the review package) starts inside Phase 1, not Phase 3. And a **hosting-capacity decision runs alongside every phase, not before any of them**: the current VPS has no swap and is already running MySQL, two Postgres instances, PHP, Node, n8n and a personal agent on an 8 GB box; a second VPS is explicitly "only on evidence" from monitoring, not a prerequisite guess (`docs/33`, ADR-012).

## 8. Decisions already made that should never be re-asked

Joy answered a full list of architecture and business questions on 5 Sep 2026 (`docs/31`); re-asking any of these wastes his time and should not happen:

- GitHub: personal account (`rhjoyofficial`). Domain: `hq.bordeguna.com` for now. Capacity: same VPS for now, upgrade only when actually needed.
- AI provider keys live under the Bor De Guna org, changeable from the UI, no budget cap for now ("as much as needs, no barrier for now" - though cost is still tracked on every run regardless).
- Econic: a full rebuild as a custom, possibly multi-vendor platform - **not** an ERP business row, **not** an ERP brand; HQ tracks it as an HQ-only business/store.
- Availability policy: an inactive product is unavailable; an active one is always treated as in stock and never depends on live quantity (subject to the still-open checkout conflict in section 4.3/6, P-1).
- The Pathao delivery-tracking webhook currently points at a third party ("bizmation") that should be removed - the callback must point at Bor De Guna's own systems.
- All Meta (Facebook/Instagram/WhatsApp) access for LaLaDia and Bionic is moving to a **new official account belonging to the boss**, not Joy's personal one; one Meta app will serve every Page; privacy/terms pages get written once and hosted per brand domain.
- Approval authority: Joy holds every approval right so nothing about "who can approve what" is ever undefined.
- The existing Hermes personal-assistant agent and the existing n8n Bionic-bot chat state are **not** being migrated into HQ - both get rebuilt fresh inside HQ when their moment comes, and the old ones are retired only after Joy explicitly approves it, never silently.
- Backups: Backblaze B2 (off-site storage) is **not available and not part of the plan** - HQ's backup is a local, on-VPS Postgres dump with this limitation written down rather than hidden, matching the same honest-about-gaps approach the ERP's own backup story took.
- No model fine-tuning, ever, anywhere in this plan - alignment happens through the "Behavior Alignment Layer" (structured preferences, decision records, evaluations), never by training a model on the business's data.

Still genuinely open, blocking specific later phases only (not blocking anything on the current critical path in section 6): whether the rebuilt Econic is a fork of the LaLaDia codebase or an entirely new one, and whether HQ should read the *current* WooCommerce store at all in the meantime (needs access nobody currently has); when the boss's new official Meta profile will actually exist and who runs it day to day; and whether the Hostinger VPS's weekly snapshot feature is actually turned on (the one thing that would survive total loss of the VPS's disk - this has never been confirmed one way or the other and should be checked in hPanel, not assumed).

## 9. How to resume this conversation with no other document open

If you are an AI picking this project back up with only this file loaded: HQ is fully built and deployed through Phase 1 on its own side; two production changes (the ERP's new read API, one shop's new webhook/assistant endpoints) are written, tested and pushed but waiting on Joy's review and go-ahead before anything is merged or deployed; nothing has agents, conversations, marketing or content capability yet, correctly, because that is Phase 2 onward and none of its prerequisites (a live ERP token, live shop credentials, Meta review) exist yet. The very next concrete actions are the four numbered items in section 6, all of which are Joy's clicks and decisions, not more code. Do not build ahead of them without asking - Phase 2 has real architecture decisions of its own (the policy engine, the approval center, agent scopes) that deserve the same document-before-code discipline every phase so far has used, and the current state gives no urgent reason to skip that.

## 10. Source documents, for anything this briefing compressed away

All paths relative to `D:\WORK\BorDeGuna\BordeGuna-HeadQuarter\`.

- `README.md` - repository layout and the recommended reading order.
- `docs/00-executive-vision.md` - the one-paragraph mission, what HQ is/is not, guiding principles.
- `docs/01-current-state-audit.md` - the full, dated inventory of every server, container, credential, and business system this project watches (188 lines; this briefing's section 3 is a small extract).
- `docs/02` through `docs/29` - one subsystem design document each (data architecture, AI agents, memory, skills, tool registry, policy engine, approval system, commerce, marketing, content, social integrations, voice, scheduling, automation, security, observability, cost control, deployment, backup/recovery, testing) - read the one relevant to whatever is being built next, not all of them up front.
- `docs/30-development-roadmap.md` - the phase table and dependency graph (this briefing's section 7 restates it in full).
- `docs/31-open-questions.md` - every decision Joy or the boss has made or still owes, with his exact recorded answers (this briefing's section 8 restates the closed ones and section 8's tail restates the open ones).
- `docs/32-architecture-decisions.md` and `docs/adr/` - the numbered ADRs (001-014) that back every "locked decision" in `docs/34`.
- `docs/33-nikolahost-to-hostinger-migration.md` - the future hosting consolidation, explicitly not a Phase 0/1 prerequisite.
- `docs/34-architecture-review-2026-09-05.md` - the pre-Phase-0 locked-decisions review, the Phase 0 implementation checklist, and the risk register.
- `docs/35-phase-1-readiness-review-2026-09-05.md` - the 333-line readiness review this briefing draws most heavily on: full API contracts for both the ERP and the shops, the commerce interface contract, the event/webhook contract, the customer-identity matching rules, the security model, the data-ownership matrix, sequence diagrams for four key flows, and every risk/discrepancy/open-decision table (sections O, P, Q, R).
- `docs/36-phase-1-build-record-2026-09-05.md` - exactly what was built, where, and what is deployed vs. pending (this briefing's section 4.2 restates it with added live-verification).
- `deploy/README.md`, `deploy/CAPACITY.md`, `deploy/RESTORE-DRILL.md` - the operational runbooks for HQ's own infrastructure.

No memory of this project existed in this assistant's persistent memory store before 6 Sep 2026 (it is tracked separately from the Bor De Guna ERP's own extensive memory trail); this briefing and a short pointer entry are being added to that memory store alongside this file so future sessions do not have to rediscover the project from scratch.
