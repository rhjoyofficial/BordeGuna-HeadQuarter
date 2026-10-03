# 97 - External Capability & Integration Strategy (3 October 2026)

**Required by** `docs/96` §8, §26 (ChatGPT's standing instruction, adopted by Joy 3 Oct). **Status: PROPOSED, for ChatGPT's ruling and Joy's decisions.** No external provider named here is approved; nothing new is integrated until this matrix is ruled on (ChatGPT, 3 Oct 19:00).

**Evidence.** Three research appendices, current web research of 3 Oct 2026 with a source for every price and every claim marked UNVERIFIED where a primary page could not be read:
- `97-research/A-media-and-voice-2026-10-03.md` (image, editing, video, avatar, STT, TTS, telephony, card rendering)
- `97-research/B-communication-and-business-operations-2026-10-03.md` (WhatsApp, Messenger/IG, email, SMS, payment, courier + phone-history, notifications, CRM)
- `97-research/C-ai-research-automation-ads-infra-2026-10-03.md` (LLM, embeddings, search, OCR, translation, Meta/Google/TikTok ads, analytics, workflow, MCP, storage/monitoring/email)

HQ's current state per capability is from the repository/production audit `docs/95`.

**Classes** (`docs/96` §8): **USE** buy/use as-is · **INTEGRATE** vendor API behind a thin HQ adapter · **ADAPT** extend something we already own (ERP, shops, HQ service) · **BUILD** HQ writes it (only HQ-owned business/control logic) · **WAIT** do nothing yet.
**Layers** (`docs/96` §5): Capability (HQ contract) → Adapter (HQ class) → Provider → Transport.

---

## 1. One-page answer

**Nothing in the vision requires HQ to build a model, an engine, a messaging system, a payment system, a courier system, a search engine or a CRM.** Every external capability below is USE, INTEGRATE, ADAPT or WAIT. The only BUILD items are HQ-owned control logic the standing instruction already assigns to HQ: the thin workflow layer on an adopted MIT package, the notification router, and the capability contracts/adapters themselves.

**Three things gate most of the customer-facing value, and they are owner actions, not code:**
1. **Meta Business Verification** of portfolio `3967588683468735` (up to 14 business days). It gates WhatsApp (250 recipients/day until done), Messenger/IG at scale, Lead Ads, the ads write tier, and publishing.
2. **A new +880 SIM in the company's name** for WhatsApp Cloud API.
3. **A dual-currency company card** with prepaid USD top-ups in ≤ $300 slices (Bangladesh Bank cap per transaction; IT-expense exemptions apply). No vendor in this matrix accepts bKash/Nagad except local BD providers (SMS aggregators, On Cloud API, payment gateways).

**Cost at modest volume (all external capabilities together): roughly $100-250 a month**, dominated by LLM inference, WhatsApp marketing templates and image/video generation; most others are free tiers or cents.

---

## 2. The matrix (five fields ChatGPT asked for, per capability)

Columns: **Avail.** = capability available on the market and usable from Bangladesh? · **Path** = API / MCP / n8n / internal · **BD** = Bangladesh viability · **HQ owns** = the HQ-owned portion · **Recommendation** (primary / fallback, class) · **Today in HQ** (from docs/95) · **Decision needed from Joy**.

### 2.1 AI

| Capability (contract) | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| LLM inference `ai.complete` | Yes | Direct API (router) | Card OK; OpenAI/Anthropic list BD | Router, tiers, budgets, structured-output validation, audit | **USE** OpenAI gpt-5.6-terra/luna; fallback Anthropic Sonnet 5.5 / Haiku 4.5; Gemini paid third; OpenRouter open-weight for English-only outage fallback | Live; OpenAI serving, Anthropic off; $50 soft cap | Raise OpenAI org limit with company card; request OpenAI ZDR (customer text in prompts)? ; approve a 200-prompt Bangla/Banglish eval set before tier changes |
| Embeddings + vector search `knowledge.embed` / `knowledge.search` | Yes | Direct API; pgvector internal | Card OK | Playbook/knowledge store, chunking, scope filters, re-embed jobs | **USE** text-embedding-3-large in **pgvector** (already provisioned); fallback 3-small; bge-m3 self-hosted only under a data-residency rule | pgvector extension present, no vector column | Approve pgvector (no Pinecone/Qdrant) |
| Translation / Banglish | LLM does it | Internal | — | Glossary of brand/product terms | **WAIT** (no dedicated API; DeepL has no Bengali text) | Language detection exists; docs/92 contract in build | None |

### 2.2 Media

| Capability | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| Image generation with real product references `media.image.generate` | Yes | Direct API; n8n Gemini/OpenAI nodes for batch | Google bills BD (+15% VAT); OpenAI BD supported | Creative brief, product reference library, brand kit, provenance/rights metadata, approval of exact asset | **INTEGRATE** Gemini Nano Banana 2 (volume) + Nano Banana Pro (hero/Bangla text); fallback OpenAI gpt-image-1.5 (already billed); FLUX.2 third. Exclude Midjourney (no API, ToS), Imagen 4 (reported sunset) | `MediaGenerator` interface, no implementation | Google Cloud billing account + paid-tier key; product reference photos + brand kit; Bangla in-image text must be A/B-tested on real labels |
| Image editing / background removal / resize `media.image.edit` | Yes | Direct API; self-hosted container | Card | Platform size presets | **INTEGRATE** Photoroom Basic ($0.02/img); **USE** self-hosted `rembg` as free fallback; resize stays internal | Chromium/Imagick render service defined, not started | Photoroom key |
| Short product video `media.video.generate` | Yes | Direct API, async job + poll | Google billing | Async job table, provenance, approval | **INTEGRATE small** Veo 3.1 Fast ($0.10/s 720p); fallback Kling via fal.ai, Runway Gen-4 Turbo; **WAIT on scale** until a clip beats a phone video on cost per purchase. Exclude Sora (API removed 24 Sep 2026) | Nothing | Same Google key; fal.ai account if fallback wanted |
| Consented avatar presenter `media.presenter.generate` | Yes | Direct API + webhooks | Card | Media-identity consent record, revocation | **WAIT** until a presenter records consent on camera and the hilsa sourcing story is settled; then **INTEGRATE** HeyGen (Bangla BD listed, documented consent flow); fallback Synthesia | Cloned voice refused in code; consent record designed only | Whose face/voice; signed likeness consent |
| Branded Bangla product cards `media.card.render` | Yes (own service) | Internal | — | Templates, Bangla font stack, size presets | **ADAPT** own Chromium HTML→image service (shapes Bengali correctly); fallback Templated/Bannerbear only if a non-developer must edit templates | Service defined, not in release compose | Approve starting the render container |

### 2.3 Voice and telephony

| Capability | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| Bangla speech-to-text (voice notes) `voice.transcribe` | Yes (quality to prove) | Direct API | Card / Google billing | Audio fetch from Meta, ffmpeg conversion, transcript provenance | **INTEGRATE after a 50-note bake-off** (ElevenLabs Scribe v2, Google chirp_3 `bn-BD`, gpt-4o-transcribe, Gemini Transcribe); provisional primary ElevenLabs Scribe v2, fallback Google chirp_3 `bn-BD` | Google/ElevenLabs/Azure adapters exist, unregistered; audio fetch missing | 50 real WhatsApp voice notes (clean, noisy, Banglish) for the test |
| Natural Bangla text-to-speech `voice.speak` | Yes | Direct API | Card | Voice choice per brand, consent for any clone | **INTEGRATE** Azure `bn-BD` Nabanita/Pradeep (only Bangladesh-locale voices on the market; 500k chars/mo free); fallback ElevenLabs v3 for expressive ads. Exclude OpenAI TTS (no Bengali), PlayHT (reported discontinued) | Azure/ElevenLabs adapters exist, unregistered | Azure subscription |
| Phone calls / AI phone agent `voice.call` | Partly | — | **Weak**: inbound BD numbers need KYC or $250/mo; foreign CLI rejected by customers; BTRC rules on automated calls unclear; no proven streaming Bangla STT | Call session, transcript, outcome, recording consent (design only) | **WAIT**; design the contracts inert. If piloted later: outbound order-confirmation only, Retell or Vapi + Twilio, Azure bn-BD voice, legal opinion first | `TelephonyProvider` interface, zero implementations | Legal read of BTRC rules before any pilot |

### 2.4 Customer communication

| Capability | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| WhatsApp conversations `messaging.whatsapp` | Yes | Direct Cloud API + webhooks; Meta's WhatsApp Business Tools MCP for setup only; n8n for side flows | **Yes**; BD rates from 1 Oct 2026: marketing $0.0732, utility/service $0.0037, first 1,000 service replies per number free | Conversation, identity, consent, workflow, templates registry, audit | **INTEGRATE** Meta Cloud API direct (no BSP fee); fallback On Cloud API (BD local, BDT via bKash/Nagad, $9.99/mo) or 360dialog | Normaliser + send adapter + ledger built, unregistered, no account row | New SIM; Business Verification; card on the WABA; one WABA with two numbers (one per shop)? |
| Messenger + Instagram DM + comments `messaging.meta` | Yes | Direct Graph API + webhooks; no official runtime MCP | Yes, $0 messaging fees | Same as above | **INTEGRATE** direct on the existing app with a system-user Page token (Standard Access for own Pages; App Review ~20 days only if Meta demands Advanced). Not ManyChat/Chatfuel (data outside HQ) | Built, unregistered; flag OFF | Business Verification; IG Professional + linked + message access on |
| Email `messaging.email` | Yes | Direct (Laravel transport) | Card | Templates, consent for marketing | **USE** Postmark Basic ($15/10k); fallback Resend free tier | No email channel | DNS records (DKIM/SPF/DMARC) on three domains; a real From mailbox |
| SMS with masking `messaging.sms` | Yes, local only | Internal (ERP driver) / HTTP | **Local aggregators only**; international routes ~100× cost and no masking | Routing (masking for OTP/order, non-masking promo within hours) | **ADAPT** the aggregator the ERP SMS driver already uses + masked sender ID per brand; fallback a second local aggregator | ERP has a driver; HQ none | Which aggregator the ERP uses; trade licence/TIN/NID for masking (2-7 working days) |
| Staff/owner notifications `notify.person` | Yes | Direct Bot API | Free | **Router**: event → role → channel, quiet hours, dedupe, escalation | **BUILD** the thin router; **USE** Telegram (live), email + in-app as fallback | One Telegram chat; no per-person routing | Chat IDs per role/person |

### 2.5 Commerce operations

| Capability | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| Payment link for an AI-taken order `payment.request` | Yes | Direct API + IPN into the shop | **Yes**; SSLCommerz live; Stripe not available in BD | Order intent, workflow state; the **shop/ERP stays system of record** | **INTEGRATE** SSLCommerz Invoice API (link + QR + optional SMS/email + IPN + refund API); fallback bKash Tokenized Checkout direct | None in HQ; SSLCommerz live in shops | Confirm Invoice API enabled on the store ID; bKash merchant onboarding later? |
| Courier booking + tracking `fulfilment.ship` / `.track` | Yes | Direct API + webhooks | Yes | Delivery state in context, "where is my order" | **INTEGRATE** Pathao (live in shops); fallback Steadfast | Shops only; HQ none | Steadfast merchant account + keys if fallback wanted |
| Phone delivery-history / COD risk `risk.check_phone` | Yes (couriers' own endpoints) | Direct API | **Legal caution**: Personal Data Protection Act 2026 makes mobile numbers personal data; cross-merchant history is profiling | Decision record only (never store raw history); human decides | **INTEGRATE** only first-party courier endpoints (Steadfast `fraud_check`, eCourier `fraud-status-check`) with a privacy-notice line, flag-only; **WAIT** on all aggregators (FraudChecker BD, SMSoft etc.) | Nothing | Legal read; privacy-notice wording; flag-only vs auto-reject (recommend flag-only) |
| CRM / leads `crm.lead` | Yes (ERP) | Direct webhook into HQ → ERP | — | Lead routing, follow-up | **ADAPT** the ERP's own CRM (leads, pipeline, tickets, SMS campaigns exist) + **INTEGRATE** Meta Lead Ads webhook; external CRM **WAIT**; enrichment **WAIT** | ERP CRM not exposed to HQ | Lead Ads permissions after BV |

### 2.6 Research, documents, marketing data

| Capability | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| Web search / retrieval with citations `research.search` / `.fetch` | Yes | Direct API via the Gateway; Brave MCP only for ad-hoc analyst sessions | Card | Citation record (URL, fetched_at, hash), source allow-list, budgets | **USE** Brave Search ($5/1k, $5 free monthly) + Jina Reader; fallback Tavily; Exa for semantic discovery; Perplexity only for human-read memos. Do not adopt Google CSE (closing 1 Jan 2027) | Brave/Tavily adapters built, unregistered | Brave + Jina accounts; source allow-list per brand |
| OCR / document extraction `document.extract` | Yes | Direct API | Card | Schema per document, human approval of amounts | **USE** Mistral OCR 4 (Bengali named as a strength) + gpt-5.6-luna for field extraction; fallback Google Enterprise OCR | Nothing | 30 real documents for a blind accuracy test |
| Meta Ads read + governed writes `ads.read` / `ads.change` | Yes | **Direct Marketing API** for reads and approval-gated writes; **official Meta Ads MCP as an adapter** for human analyst sessions and paused drafts only (per-user OAuth, not HQ authority) | Yes | Budget guard, approvals, activity log reasons, contribution profit | **INTEGRATE** (API) + **ADAPT** (MCP); no community MCP servers | Reader built, unregistered; no write code (ADR-050) | Add `ads_management` to the app; system user on both ad accounts; token; BV for Standard tier |
| Google Ads / TikTok Ads | Partly | — | Billing fragile; shops invisible in Google (SEO first); TikTok BC empty, currency question open | — | **WAIT** | None | None now |
| Analytics / owner dashboards | Yes | Direct API pulls; Metabase internal | Free | Data pulls into HQ Postgres; governed panels only | **USE** GA4 Data API + Meta Insights pulled nightly + **Metabase OSS** on a read-only role for owner dashboards; HQ does not become a BI tool | HQ dashboards are about HQ itself | GA4 service-account Viewer; VPS RAM (2 GB) |

### 2.7 Automation and protocols

| Capability | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| Durable business workflows `workflow.*` | Yes | Internal | — | **Workflow state, authority, audit** (`docs/96` §10) | **BUILD-thin** on the MIT package `durable-workflow/workflow` (embedded on Laravel queues/DB); write only the activities. Temporal: WAIT | Scheduler + state machine; no workflow engine | Approve adopting the package |
| n8n | Yes (self-hosted, 14 workflows) | Signed webhooks both ways | — | HQ owns state; n8n is a stateless executor | **USE** n8n for integration steps (Messenger bot, content pipeline, polling). Contract: HQ → n8n signed webhook with `run_id` + idempotency key; n8n → HQ callback records the result; n8n holds no business state and no money-capable credentials. Never rewrite a working n8n flow for purity | Copy-forward receiver built, flag OFF | Agree the contract; keep the Bionic bot running |
| MCP `mcp.*` adapters | Yes | MCP client inside HQ | — | Every MCP call enters as a Gateway capability with its own contract, scope, audit | **ADAPT only**: `laravel/mcp` client (official, 1.0); allowlisted servers (Meta Ads official, Brave, GitHub, Google Workspace when needed); deny write tools by default; no spend-capable tokens in MCP servers | Zero MCP code (ADR-029) | Approve the allowlist |

### 2.8 Infrastructure

| Capability | Avail. | Path | BD | HQ owns | Recommendation | Today in HQ | Decision needed |
|---|---|---|---|---|---|---|---|
| Object storage + off-server backup copy | Yes | S3 API | Card | Media assets, backup copies | **USE** Cloudflare R2 (10 GB free, free egress); fallback Hetzner. ⛔ Backblaze B2 excluded by owner decision | Local disk only; no off-site copy | Cloudflare account + scoped R2 token |
| CDN / DNS / webhook protection | Yes | — | Free | — | **USE** Cloudflare Free | None | DNS move decision |
| Error tracking | Yes | SDK | Free | — | **USE** GlitchTip self-hosted (Sentry-compatible); fallback Sentry Developer free | stderr logs only | ~1 GB RAM |
| System email | Yes | — | Card | — | **USE** Resend free (or Postmark, shared with 2.4) | None | Same DNS work |
| Secrets | Yes | — | — | In-app encrypted vault | **WAIT** (keep the vault; Infisical later) | Vault live | None |

---

## 3. What HQ builds (and nothing else)

Only HQ-owned control/business logic (`docs/96` §3), each a thin layer over the providers above:
1. **Capability contracts + adapters** for each INTEGRATE row (`media.image.generate` → `GeminiImageAdapter`, `OpenAiImageAdapter`; `voice.transcribe` → `ElevenLabsScribeAdapter`, `GoogleChirpAdapter`; etc.), registered through one **ConnectorRegistry** (replacing today's five registry shapes, `docs/95` §10).
2. **Workflow layer** on `durable-workflow/workflow` (adopted, not written).
3. **Notification router** (role → channel).
4. **Business entities the vision needs** (Customer 360, follow-up/opportunity/task, outcome/attribution, playbook) — HQ-owned by definition.
5. **Async media job table + provenance** for generated assets.

Explicitly **not** built: any model, image/video/voice engine, search engine, messaging transport, payment or courier system, CRM, BI tool, secrets manager, or replacement for working n8n flows.

---

## 4. Sequence (dependency order, build broadly / activate narrowly)

1. **Now, owner side:** Business Verification; WhatsApp SIM; company card on OpenAI/Google/Meta; Cloudflare account; GA4 service account.
2. **Now, engineering, inert:** ConnectorRegistry; adapters for Gemini/OpenAI image, Photoroom, Azure TTS, the STT bake-off harness, Brave/Jina, Mistral OCR, Postmark, SSLCommerz Invoice, Pathao; the n8n contract; the workflow package; all behind flags OFF and tested with faked HTTP.
3. **First activations (after their gates):** research + content drafts (keys + claims policy) → image generation for drafts (approval of exact asset) → WhatsApp/Messenger reply proposals at A0 (after BV) → payment links for approved orders → ads reads → governed ads writes.
4. **Later:** video at scale, avatar, telephony, Google/TikTok ads.

---

## 5. Exact decisions requested

**From ChatGPT (architecture):**
- D1. Approve the classification of every row above (especially: Meta Ads = direct API for authority + official MCP as analyst adapter; n8n contract; `durable-workflow/workflow` as the BUILD-thin workflow base; pgvector over a managed vector DB).
- D2. Approve one ConnectorRegistry as the single provider-resolution path before new adapters are added.
- D3. Approve "phone-history checks only via first-party courier endpoints, flag-only, with notice; aggregators WAIT" pending a legal read.

**From Joy (owner):**
- J1. Submit Meta Business Verification (gates WhatsApp, Messenger/IG scale, Lead Ads, ads writes, publishing).
- J2. Buy the WhatsApp SIM in the company's name; decide one WABA with two numbers.
- J3. Company dual-currency card on OpenAI, Google Cloud, Meta, Photoroom, Brave, Mistral, Postmark, Cloudflare (each ≤ $300 per top-up).
- J4. Product reference photos + brand kit per brand (for image generation).
- J5. 50 real WhatsApp voice notes, 50 Banglish messages, 30 real documents (for the STT, language and OCR tests).
- J6. Legal read: PDPA 2026 (phone-history checks, consent text) and BTRC (telephony).
- J7. Which SMS aggregator the ERP uses; masking documents.
- J8. Approve starting the content-render container and Metabase on the VPS (RAM) or the KVM 4 upgrade first.

---

## 6. RULING (ChatGPT, HQ thread, 3 Oct 2026 ~23:55 Dhaka) — status: APPROVED

Recorded from https://chatgpt.com/c/6a9baf11-c2c8-83ee-a43b-6bc7a76498c3. This section supersedes "PROPOSED" at the top.

- **D1 External Capability Matrix: APPROVED.** USE / INTEGRATE / ADAPT / BUILD / WAIT is the official HQ build-vs-integrate framework; every row in §2 approved at architecture level. Provider selections are **replaceable implementation choices, not vendor lock-in**; the durable object is the capability contract, not the vendor API.
- **D2 ConnectorRegistry: APPROVED AND MANDATORY for new integrations.** Capability → ConnectorRegistry → Provider/Adapter → Direct API / MCP / n8n / Internal → Tool Gateway. No blind rewrite of legacy integrations now, but every NEW external adapter enters through the registry; document the migration path for the five legacy registry shapes and converge over time.
- **D3 Phone history / COD risk: APPROVED WITH LEGAL AND ACTIVATION GATE.** First-party courier endpoints only; signal → evidence/flag → governed decision; never an automatic rejection unless a separately approved policy permits it; aggregator cross-merchant profiling WAIT; no production activation before the legal gate.
- **D4 Script validation ordering: MOVE IT.** Script validation runs **after the Gateway's authorization decision and before execution**, never after execution. Required order: model output → parsing/normalisation → runtime task/scope/security guards → Tool Gateway → availability → arguments → scope/EEC → policy + permission → consent → idempotency/replay → credential preflight → **ScriptPolicyValidator** → EXECUTE → record/audit → verification/reconciliation. Preferred shape: `Gateway.authorize()` → `pre_execution_safety_checks()` → `Gateway.execute()`. Authorization (may the caller do this?) and content/script validity (does this output meet the language rule?) stay separate concerns; a valid script can never cause an unauthorized call. **Supersedes docs/92 A.6's ordering note**; docs/92 and the implementation are updated accordingly.
- **n8n contract: APPROVED** (signed request with run_id + idempotency key → n8n executes → callback → HQ records). n8n never owns business state, autonomy authority or money-capable credentials; working flows are replaced only for a deliberate capability/reliability/security/cost/maintainability reason.
- **MCP contract: APPROVED.** Interoperability adapter only, behind the Tool Gateway and registry; MCP-provided content never grants authority; allowlisted servers; write-capable MCP actions denied by default until governed; no community MCP where a first-party direct API is the authoritative integration.
- **Workflow foundation:** `durable-workflow/workflow` **approved as the thin current base**, not blessed as the 20-year technology. Before depending on it deeply, verify durability, retry behaviour, idempotency compatibility, wait/resume semantics and Laravel/PostgreSQL fit; keep the workflow abstraction isolated so the package can be replaced. Temporal WAIT.
- **Rule for every future capability:** before implementing any new external capability, document: business capability; mature external solutions; direct API / MCP / n8n availability; Bangladesh/commercial viability; privacy/consent; HQ-owned responsibility; adapter/connector; fallback; cost model; state/credential ownership; verification/reconciliation; classification; exact owner decision.
- **Continue** the inert work already approved (docs/92, wake-up path, hand-over ping, event-kind jobs, delivery reconcile, seed/config fixes, credential kinds, briefing row). No consequential external behaviour activated.
- **Required next report** (back to the thread): exact commits/releases, docs changed, test results, D4 ordering proof/test, ConnectorRegistry status, workflow-base status, n8n contract status, MCP boundary status, any architectural inconsistency found, any new owner decision. Do not start unrelated new external integrations. ChatGPT's expectation: first D4 + ConnectorRegistry + docs/92 correction, then capability implementation.
