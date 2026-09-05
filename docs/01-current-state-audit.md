# 01 - Current State Audit

**Method.** Read-only inspection on 5 September 2026: local repositories on disk, SSH to the Hostinger VPS (`ssh vps`), live HTTP against every brand domain, read-only queries against the ERP database, n8n exports, and the session memory of earlier verified work (dated where used). Nothing was modified. Items not verified are marked **UNKNOWN**.

---

## 1. Infrastructure

### 1.1 Hostinger VPS `srv1808022` (200.97.162.40)

| Item | Value |
|---|---|
| Plan | Hostinger KVM 2 |
| OS / kernel | Ubuntu 24.04.4 LTS, 6.8.0-134 |
| CPU / RAM | 2 vCPU, 7.8 GB, **no swap** |
| Disk | 96 GB, 20 GB used (77 GB free) |
| Uptime / load | 59 days, load ~0.5 |
| Docker | Engine 29.6.1, Compose v5.3.1 |
| RAM in use | 2.7 GB used, 5.1 GB available |
| Firewall | ufw active: 22, 80, 443 only |
| SSH | key-only (`passwordauthentication no` effective), `PermitRootLogin yes` (key), fail2ban active |
| Reverse proxy | Traefik (latest) on the **host network**, Let's Encrypt, routes by container labels |
| Host packages | **No php, nginx, apache, mysql, mariadb, caddy or certbot on the host.** Pure Docker host plus Hermes Agent (native Python, systemd user unit). |
| Platform snapshot | Weekly Hostinger snapshot recorded in earlier notes, **UNKNOWN whether currently enabled** (needs hPanel check) |

**Containers (13):**

| Stack | Containers | Notes |
|---|---|---|
| `bordeguna-erp` (compose in repo `deploy/`) | app (PHP-FPM), web (nginx), db (MySQL 8.0), worker (`queue:work`), scheduler (`schedule:work`), backup (nightly mysqldump) | Private bridge `internal`, no route to n8n. Resource limits set. `offsite` service (restic to Backblaze B2) is **defined but not running**. |
| `n8n-vb99` (hPanel one-click, no compose on disk) | n8n 2.29.7 | SQLite storage, 14 workflows, 127.0.0.1:5678 behind Traefik |
| `postgresql-4jjn` (hPanel) | Postgres 17 | DB `n8n_chat_memory`: tables `customers`, `draft_orders`, `human_takeover`, `message_debounce`, `n8n_chat_histories`, `content_calendar` (0 rows), `call_confirmations` (0 rows). No pgvector. |
| `content-render-b7k2` (hPanel) | Node `server.js` | Image composition for the content pipeline, 127.0.0.1:3010, 768 MB cap |
| `docker` (Zeno demo) | app (Node), db (Postgres 16) | Joy's own product, unrelated to Bor De Guna |
| `safor-travels` | nginx static | Azmion client preview |
| `traefik-rsf3` (hPanel) | traefik | owns :80/:443 |

**Host cron:** Zeno reseed 04:00, Zeno backup 02:30, automation-stack backup 02:00 (`/root/scripts/backup-automation.sh`, 14-day retention, last run 5 Sep OK, 26 MB).

**Hermes Agent:** native install, `hermes-gateway.service` running since 16 Jul, Telegram channel, `model.provider: auto` with an OpenRouter `base_url` still present (flagged 17 Aug, never removed), 44 plugins, one daily health-check cron delivering to Joy's Telegram.

### 1.2 Nikolahost shared cPanel (15.235.188.158)

All five brand domains resolve to this one shared IP: laladia.com, bionic.garden, theazmion.com, econic.store, bordeguna.com. PHP 8.3.33. Database engine MariaDB 10.11 (LaLaDia). Known account `sangramn` (`asiacp2000.nikolahost.com:2083`, home `/home/sangramn`) hosts laladia.com, bionic.garden and several unrelated sites (bdg-survey, survey.bordeguna, tdki.org, enterprise.bordeguna, bionic.theazmion, api.cmmoin.academy, nazibullaharif.com). **UNKNOWN which cPanel account holds econic.store, theazmion.com and bordeguna.com.**

Constraints already learned the hard way: no SSH from this machine (browser session URL only), no `rsync`, no `composer`, Vite cannot build, one persistent Terminal shell, `curl` from the box lies about the box's own sites. LaLaDia's queue is drained by the Laravel scheduler running `queue:work --stop-when-empty` every minute. Mailboxes for `laladia.com` and `bionic.garden` live on this cPanel (support@, admin@, sales@, care@ forwarders). Backups are manual dumps stored on the same account.

### 1.3 Local development machine

Windows 11, PHP 8.3.30, Node 22.20, Composer 2.8, MySQL 8.4 (Laragon), Python 3.13. **No Docker locally.** Two GitHub identities via SSH aliases `github-personal:` (rhjoyofficial) and `github-official:` (bdgsoftware-gif).

---

## 2. Bor De Guna ERP (`D:\WORK\BorDeGuna\bordeguna-erp`, `rhjoyofficial/bordeguna-erp`)

| Item | Value |
|---|---|
| Stack | Laravel 12, Inertia + React 19 + TypeScript, Tailwind, Radix, Pest |
| Size | 117k lines of PHP, 152 models, 154 controllers, 185 React pages, **200 tables**, 317 test files / **3,081 test methods** |
| Modules | Foundation, Hrm, Inventory, Purchasing, Sales (incl. POS, quotations, promotions, targets), Accounting (GL, close, budgets, bank matching, dunning), Assets, Crm (leads, pipeline, tickets, cadences, contacts, messaging) |
| Multi-business | `businesses`: LaLaDia (1), Bionic (2), Azmion (3), Bor de Guna (4). Brands as a product dimension: `LLD`, `BNC`. Every tenant table carries `business_id`; `BusinessScope` fails closed. |
| Auth / RBAC | Laravel session auth, spatie/laravel-permission, spatie/activitylog |
| Approvals | Config-driven multi-stage workflow engine (`approval_workflows`, `approval_decisions`), polymorphic `ChangeRequest`, shared approval inbox |
| Scheduler | Laravel scheduler in its own container: attendance 23:00, amortizations, leave accrual, **morning briefing 07:00**, dormant-customer sweep 07:30, follow-ups 08:30, recurring expenses 08:30, expired-stock write-off proposals 23:30 |
| Queue | `database` driver, single `worker` container, `--tries=1` |
| Events | None. Modules call each other only through Actions. No domain event bus exists. |
| Public API | **None.** Zero API routes; everything is Inertia web routes. |
| Messaging | CRM module: `HttpSmsDriver` (MimSMS-style HTTP), `SmtpMailDriver`, provider registry, contact groups, campaigns. `message_providers` table currently **empty** on production. |
| AI assistant ("Hudhud") | Built in Foundation: `ErpAssistant`, `AssistantTools` (`how_to`, `read_data`, `run_report`, todos, notes), permission-derived table map, structured read requests compiled to SQL by the app (never model-authored SQL), read-only DB user. Providers: Anthropic (active, `claude-sonnet-4-5-20250929`), OpenAI driver present, Gemini/OpenRouter planned. Credentials in `ai_credentials` (1 row). 16 assistant messages so far. |
| Production data | 9 employees, 10 users, 153 products, 4 customers, 0 leads, **0 sales orders**. Payroll and attendance are live monthly; commerce has not moved into the ERP. |
| Deployment | `git pull` + `docker compose build` in `/opt/bordeguna-erp`, runbook `deploy/README.md`, ~100 pre-deploy dumps in `/root`. Local `main` = production `52da1c9`. |
| Backups | Nightly dump in container (14 daily / 8 weekly, on-box). Offsite restic to B2 designed, **not deployed**. Restore drill scripts exist. |
| CI | GitHub Actions: tests, lint, mirror |

**Reusable for HQ:** multi-business model, RBAC and approval engine patterns, the permission-derived AI tool design, the Anthropic/OpenAI driver abstraction, messaging driver registry, the scheduler/worker container pattern, the deploy runbook discipline.

**Gaps relative to HQ:** no API for other systems to call, no events, no Redis, no vector search, no outbound integrations (Meta, Google, payments, couriers), commerce not live.

---

## 3. Shop platform: LaLaDia (`D:\WORK\LALADIA\LaLaDia`, `rhjoyofficial/LaLaDia`)

| Item | Value |
|---|---|
| Stack | Laravel 12.53, Blade storefront (SEO-critical), React/Inertia admin (61 screens), Sanctum |
| Size | 111 migrations, 60 models, 110 PHPUnit files (~1,270 tests), 49 Playwright specs |
| Public API `/api/v1` | auth, categories, products (+search, recommendations), combos, cart, wishlist, checkout (+preview, phone OTP), shipping zones, coupons, geo (64 districts / 737 thanas / 5,744 areas), landing pages, Pathao webhook |
| Assistant API `/api/v1/assistant/*` | products, search, categories, combos, shipping zones, gated by `chatbot.key` header, throttled 30/min. **Live on both shops (401 without key).** Used by the n8n Messenger bot. |
| Admin API | 281 routes: orders, shipments, products, combos, customers, coupons, landing pages, storefront, integrations, courier, SMS, webhooks, roles, failed jobs, health |
| Integrations | Couriers: Pathao (live creds, store 393838), Steadfast, RedX, CarryBee (speculative, never run), Manual. Payments: SSLCommerz (live merchant shared with Bionic), COD, partial advance. SMS: MimSMS via `SMSService`. WhatsApp Cloud API service (env keys, unconfigured). Meta CAPI (system-user token, verified healthy, daily `meta:verify`). GTM `GTM-5W9MN2VS`, GA4 config present but unused server-side. |
| Outbound webhooks | Admin-managed subscribers, HMAC-signed, events `order.created`, `customer.registered`, `coupon.expired` |
| Events / jobs | 7 events, 6 jobs, listeners for Meta purchase/delivered conversions |
| Scheduler | coupons daily, cart abandon hourly, unpaid-order expiry hourly, **courier sync every 15 min**, cart recovery every 15 min, `meta:verify` 04:30, queue drain every minute |
| Settings-driven | Pixel, CAPI token, GTM id, domain verification, order prefix, courier and SMS credentials are admin-editable rows (blank = read `.env`). This is what makes the fork viable. |
| Production | Nikolahost cPanel `sangramn`, docroot `~/laladia.com`, DB `sangramn_laladia_db` (MariaDB 10.11), release = `git archive | tar -x` from `~/laladia-src`. Live `a6b4ffdc` (4 Sep); local `main` `66def463` is ahead (hilsa tracking work, 5 Sep). |
| Business data | ~48 orders, ~21 distinct customers, COD cancellation ~33% overall |

### Bionic Garden (`D:\WORK\BIONIC\Bionic-Garden-v2`, `rhjoyofficial/Bionic.garden`, branch `relaunch`)

Fork with shared history; `upstream` = LaLaDia. 119 commits ahead of `upstream/main`, 204 files diverged (brand content, palette, identity migration, catalogue). Live `da6f826f`. Pixel `1773890070472625`, GTM `GTM-5DH52W8X`, own domain verification. COD only, wishlist off, order prefix `BNC`. **Deployed by `git merge --ff-only` inside the live docroot** (`~/bionic-garden-app`), tree permanently dirty with server-only ad images. One order in its life.

---

## 4. Econic (`econic.store`)

WordPress 7.1 + WooCommerce 10.9.1 on the same Nikolahost IP, PHP 8.3. **No code, database dump or credentials exist on disk** (`D:\WORK\ECONIC` is empty; `D:\Econic` holds product photos of a solar rechargeable fan cap). Facebook Page "Econic Ventures" exists (Joy has full control). **UNKNOWN:** cPanel account, hosting plan, payment gateways, order volume, customer count, plugins, WooCommerce REST API keys, theme. This is the least-known system in the estate.

## 5. Azmion (`theazmion.com`) and Bor De Guna (`bordeguna.com`)

Both static React/Vite single-page sites on Nikolahost. Azmion source: `bdgsoftware-gif/AZMION` (office account). bordeguna.com source: **UNKNOWN** (candidates: `D:\WORK\BorDeGuna\Bor-De-Guna`, `BordeGuna-Brand-of-Wellness`). No backend, no data. Trivial to move.

---

## 6. Automation and AI already running

### n8n (14 workflows, all on the VPS)

| Group | Workflows | State |
|---|---|---|
| Bionic Messenger bot | `Messanger-Auto-Reply-Bot` (52 nodes: Facebook Graph v25 webhook, LangChain agent on **Anthropic**, Postgres chat memory, debounce), tools: Order Draft, Confirm Order, Escalate To Human, Resume From Human, Error Alerts (Telegram) | Live, but the Facebook app is in **Dev Mode**: only Joy can message it. Calls `bionic.garden/api/v1/assistant/*`. One human-takeover row open since 9 Jul. |
| Content pipeline | Lookup Product, Generate AI Creative (OpenAI image), Compose Product Image (content-render), Publish Facebook Post, Trigger Web Form, Avatar Video (HeyGen), Video Status, Publish Facebook Video | Each stage works alone; **never wired end to end**. `content_calendar` empty. OpenAI billing limit hit 22 Jul, **UNKNOWN whether lifted**. HeyGen key present, inactive. |

n8n credentials on file (names only): Anthropic, OpenAI, Bionic Facebook Page token, X-API-Key (shop assistant key), Telegram x2, HeyGen, Postgres, generate-webhook secret.

### Hermes Agent
Personal brain on Telegram; one useful cron (daily health check, no-agent mode). Not pointed at business tools.

### Laravel schedulers
ERP (briefing, sweeps, attendance) and both shops (courier sync, cart recovery, Meta verify) already run recurring business jobs, all as Laravel `Schedule::` entries with no UI, no natural-language entry and no per-run history beyond logs.

---

## 7. Accounts and platform assets

| Platform | What exists | Verified |
|---|---|---|
| Meta business portfolios | End Brackets `582031730230465` (Joy's; holds WhatsApp Business registration, ad accounts, **and the new `BordeGuna Ads (BDT)` account `28164410319885213`**). Bor de Guna `3967588683468735` (boss's; owns LaLaDia dataset). | 1-3 Sep |
| Meta Pages | La La Dia `100085015853889`, Bionic Garden `321936947659712` (transfer into a portfolio **blocked** by dataset link), Econic Ventures, Azmion, Bôr dé Güna, End Brackets. Joy has full control of all. | 15 Aug, 2 Sep |
| Meta datasets/pixels | LaLaDia `1260982165256142` (CAPI healthy), Bionic `1773890070472625`, Bionic Page dataset `689321244257515` | 31 Aug, 2 Sep |
| Meta apps | `LaLaDia Conversions API` `1804360750550977` (system-user token, never expires). Messenger bot app: **Dev Mode, `pages_messaging` not reviewed.** Instagram messaging, WhatsApp Cloud API: **not set up.** | 27 Aug, 17 Aug |
| Meta ad accounts | `28164410319885213` BordeGuna Ads (BDT, card, phone verified), `506034876943542` personal (BDT), `1345773583335115` End Brackets (USD). Two campaigns built (beef pickle trial closed at 0 purchases; hilsa paused awaiting review). | 3 Sep |
| Google | GTM containers for both shops, GA4 properties (ids in shop settings). **Google Ads: none. Search Console, Merchant Center, YouTube channel: UNKNOWN.** No product feed exists on any shop. | 2 Sep |
| TikTok | Nothing (footer link only) | 2 Sep |
| LinkedIn | Company pages End Brackets, Bôr dé Güna, Bionic Garden (Joy admin) | Aug |
| X | UNKNOWN | - |
| Telegram | Bot(s) used by n8n alerts and Hermes | 5 Sep |
| Anthropic | Org "C M Moin's Individual Org", one key `hermes-hostinger-vps-temp-rakib` (no expiry), workspace `Hermes-Hostinger-VPS`, on credits, plus the ERP's own stored key (Anthropic, active) | 30 Aug |
| OpenAI | Key in n8n, billing limit hit 22 Jul, **status UNKNOWN** | 17 Aug |
| HeyGen | Key in n8n | 5 Sep |
| Payments | SSLCommerz (one live merchant, both shops), COD, MimSMS balance ~Tk 2,300 (Aug) | Aug |
| Couriers | Pathao merchant (single callback URL points at bizmation), Steadfast/RedX/CarryBee credentials partially held | Aug/Sep |
| Backblaze B2 | Bucket `bordeguna-erp-backup` configured for restic, **replication never ran** | 5 Sep |

---

## 8. Customer data today

| Source | Where | Volume |
|---|---|---|
| LaLaDia orders/customers | `sangramn_laladia_db` | ~48 orders, ~21 customers, phone-keyed carts |
| Bionic | `sangramn_bgarden` | 1 order |
| ERP customers/leads | `bordeguna_erp` | 4 customers, 0 leads, `phone_normalized` identity already designed |
| Messenger bot | `n8n_chat_memory.customers`, `draft_orders`, `human_takeover` | small, UNKNOWN exact counts |
| Econic | WooCommerce | UNKNOWN |

No unified customer identity exists across these. The ERP's `PhoneIdentity` normaliser (four Bangladeshi phone shapes to one key) is the natural seed for identity resolution.

---

## 9. Security observations

1. CarryBee live credentials sit in a plaintext file inside cloud-synced OneDrive (flagged 2 Sep, still open).
2. The single Anthropic key powering Hermes is named `temp`, never rotates, and belongs to the boss's org; the ERP holds a second key. No secrets manager anywhere; secrets live in `.env` files, n8n's encrypted store, and Laravel encrypted settings rows.
3. `PermitRootLogin yes` on the VPS (key-only, so acceptable, but a dedicated deploy user would be better).
4. No swap on a 7.8 GB host running MySQL, Postgres x2, PHP, Node, n8n and Hermes. An OOM kill is the realistic failure mode when load grows.
5. ERP offsite backup is designed but not running; every backup on the VPS is on the same disk as the data. Nikolahost backups are on the same account as the sites.
6. The Meta Messenger app is in Dev Mode; going live requires App Review and a privacy policy URL per brand.
7. Two Meta portfolios split ownership oddly: the Bor De Guna ad account lives in Joy's personal End Brackets portfolio. A portfolio consolidation is owed before scaling ad spend (documented 2 Sep in the marketing migration guide).
8. LaLaDia and Bionic share one SSLCommerz merchant and one Pathao account; the order prefix is the only thing that says whose money it is.

---

## 10. What this audit changes about earlier assumptions

- The ERP is far larger and more complete than the working notes said: all seven planned modules are live, including POS, accounting close, CRM pipeline and an AI assistant. HQ must build on it, not around it.
- The shops already expose exactly the read-only "commerce tools" the customer AI needs (`/api/v1/assistant/*`), and already emit `order.created` webhooks. The commerce API layer is mostly an extension job, not a build.
- Econic is a fourth technology (WordPress) with zero local knowledge. It needs its own discovery before any integration promise.
- The VPS is comfortable now (2.7 GB used) but cannot also absorb three Laravel shops, a WordPress site and their databases on 8 GB without swap. The hosting migration and the HQ build compete for the same box; see `33-nikolahost-to-hostinger-migration.md` and `27-deployment.md`.
