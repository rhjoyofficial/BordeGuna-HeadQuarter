# 70 - What Joy must have in place before Phases 3 to 10 can run without pausing (13 September 2026)

**Status: a list for Joy, written while gate 6 ran. Nothing here is built. Every item is something only he can sign up for, pay for, verify or register, ordered by how long it takes to get, because the slow ones decide the pace, not the code.**

Joy's direction, 13 Sep: Phases 3 to 10 are the point of HQ, work speed must increase, nothing is left behind,
and almost everything we build is Bangla and Bangladesh-facing. This document answers his question "what
subscription, API, or service do I need to take so you never have to pause?" It is arranged by lead time
because a Meta business verification or a BTRC SMS masking takes weeks and a Claude plan upgrade takes a minute.
The code side of each phase is in `docs/30` and `docs/60`; this is only the outside world.

## A. Why I stop today, and the two things that would remove most of the stops

1. **The deploy and the gate launch.** I do not run `deploy.sh`, `db:seed` on production, or the gate runner because
   this session's safety classifier refuses those commands as "Production Deploy", whatever I type. Reads over ssh
   are allowed; those three are not. That is why they come back to Joy as `!` commands. The fix is on his machine,
   not mine: an explicit allow rule in `~/.claude/settings.json` for exactly those three command shapes on `ssh vps`.
   An explicit allow is honoured before the classifier is asked. I can write the rule when he says so; it is his
   security setting, so I will not write it unasked.
2. **The Claude Code plan.** A long day of building, CI, browser work and gate grading runs into the plan's usage
   window. **Claude Max (the 20x tier)** is the plan for the pace he is asking for. Same account, no code change.

Everything below is per phase. Items marked **SLOW** need weeks and should start now, in parallel, regardless of
which phase they serve.

## B. Already in place (verify, do not buy again)

- Anthropic API key on the VPS (`api.anthropic.com`), used by HQ agents. **Check the console spend limit and the
  usage tier**: gates cost about $2 each today; Phase 3 in shadow will cost tens of dollars a day, and the tier's
  requests-per-minute must not be the ceiling. Raise the monthly limit now; it is a form, not a purchase.
- ChatGPT (the design thread). Keep it; it is the decision layer.
- Hostinger KVM VPS (Docker, Traefik, n8n, ERP, HQ, two shops), GitHub, Chrome with the extension, the domains.
- Meta business portfolio with the Facebook pages and Instagram accounts.

## C. Phase 3, customer AI and the Messenger channel (start these today)

| # | Item | Lead time | What it needs from Joy | Why |
| --- | --- | --- | --- | --- |
| C1 | **Meta Developer app** under the business portfolio, products Messenger + Instagram Messaging + Webhooks | 1 day to create, **SLOW** for advanced access | A developer account, the app, a privacy-policy URL (on bordeguna.com), the pages subscribed | Every inbound message reaches HQ through this app's webhook and every reply leaves through its page token |
| C2 | **Meta Business Verification** | **SLOW, 1 to 4 weeks** | Trade licence, TIN, a utility bill or bank statement in the business name, the business phone | Without it the app stays in development mode: only admins and testers can message it. Advanced access to `pages_messaging` and later WhatsApp both require it |
| C3 | **App Review for `pages_messaging`, `pages_manage_metadata`, `instagram_manage_messages`** | **SLOW, days to weeks after C2** | A screen recording of the flow, which I will produce once the webhook is up | Same reason: without review, the customer AI cannot answer a real customer |
| C4 | **WhatsApp Business Platform (Cloud API)** | **SLOW, after C2** | A phone number NOT currently on any WhatsApp app (a new SIM is simplest), the display name, a payment method on the WABA | WhatsApp is the channel Bangladesh customers actually use; conversation pricing is per 24h window, billed in USD |
| C5 | **A payment method Meta accepts** | Days | An international-endorsed card (dual-currency card with dollar endorsement from his bank), or an agency account | WhatsApp conversations and later ads are billed to it |
| C6 | **Transactional e-mail** (Postmark or Resend; Amazon SES if he prefers AWS) | 1 day | Account, domain verification (SPF, DKIM, DMARC records on bordeguna.com) | Order confirmations, agent handoffs to humans, daily digests. Free tiers cover us for months |
| C7 | **Bangladesh SMS gateway with a masking name** (SSL Wireless, Alpha SMS, BulkSMSBD, or GreenWeb; pick one that gives an HTTP API and a masked sender) | **SLOW: BTRC masking approval, 1 to 3 weeks** | Trade licence, a signed masking application, prepaid credit | OTP for customer identity, order status, and the SMS campaigns the ERP already has a screen for |
| C8 | **Object storage for media** (Cloudflare R2, 10 GB free; or Hostinger object storage) | 1 hour | Account, one bucket, an API token | Customer photos, voice notes, generated images; the VPS disk is not where media belongs |
| C9 | **Cloudflare (free) in front of bordeguna.com and hq.bordeguna.com** | 1 hour, DNS move | Move the nameservers | WAF and rate limiting in front of a public webhook; also the DNS records C6 needs |

## D. Phase 4, AI orders (payments and delivery)

| # | Item | Lead time | What it needs from Joy | Why |
| --- | --- | --- | --- | --- |
| D1 | **bKash Merchant account + PGW (payment gateway) API access** | **SLOW, 2 to 4 weeks** | Trade licence, TIN, bank account in the business name, a merchant agreement | The payment Bangladesh customers expect first |
| D2 | **Nagad merchant API** | **SLOW, similar** | Same documents | Second wallet; many customers have one and not the other |
| D3 | **An aggregator instead of, or as well as, D1+D2** (SSLCommerz or aamarPay) | 1 to 2 weeks | Same documents; a settlement bank account | One integration for cards, bKash, Nagad, Rocket, bank transfer; sandbox on day one so I can build before D1 lands |
| D4 | **A courier API** (Pathao Courier, Steadfast, RedX, or Paperfly, whichever the shops already ship with) | Days | Merchant account, API key | Order status the agent can read and the customer can be told; the ERP already models the physical flow |
| D5 | **Bangladesh Bank / VAT note** | none | His accountant confirms how online wallet receipts are recorded | Not a purchase; a decision the accounting side needs before AI orders post money |

## E. Phase 5, marketing and ads

| # | Item | Lead time | What it needs from Joy | Why |
| --- | --- | --- | --- | --- |
| E1 | **Meta Marketing API** access on the same app (`ads_management`, `ads_read`) | **SLOW, App Review after C2** | The ad account attached to the portfolio, the payment method from C5 | Campaign creation and reporting from HQ, behind approval |
| E2 | **The LaLaDia hilsa campaign's blocked fields** (advertiser and payer names) | Minutes | Fill them in Ads Manager | It has been sitting in draft since August for exactly this |
| E3 | **Google Ads API** (developer token, basic access) | 1 to 2 weeks | A Google Ads manager account, the token application | Only if search ads are wanted; optional for the first release |
| E4 | **Meta Conversions API** | none extra | Pixel already on the shops? Confirm | Server-side purchase events so ad reporting is honest |

## F. Phase 6, the content factory and the research engine

| # | Item | Lead time | What it needs from Joy | Why |
| --- | --- | --- | --- | --- |
| F1 | **An image-generation API** instead of the browser: OpenAI Images (`gpt-image-1`) or Google Gemini/Imagen | 1 hour | API key with billing | Today product images come from ChatGPT through the browser by hand (the `chatgpt-image-generate` skill). An API makes it a pipeline step |
| F2 | **A video-generation API** (Google Veo through the Gemini API, or Kling) | 1 hour | API key with billing | Short product clips; optional at first |
| F3 | **A web-search API for the research engine**: Tavily or Brave Search (both have free tiers); SerpAPI if Bangladesh-localised Google results are needed | 1 hour | API key | The research engine is only as good as what it can read |
| F4 | **Anthropic Batch API + prompt caching** | none | Nothing to buy; already on the key | Half-price bulk generation for content, and cheaper long system prompts. I switch the content jobs to it |
| F5 | **Bangla fonts** | none | Nothing; free (Noto Serif Bengali, SolaimanLipi already in the ERP PDFs) | Rendering |
| F6 | **A stock-photo API** (Pexels, free) | minutes | Key | Filler imagery |

## G. Phase 7, voice (Bangla)

| # | Item | Lead time | What it needs from Joy | Why |
| --- | --- | --- | --- | --- |
| G1 | **Speech-to-text with `bn-BD`**: Google Cloud Speech-to-Text (Chirp) or Azure AI Speech | 1 day | A Google Cloud or Azure account with billing (both accept the card from C5) | Bangla recognition quality is the whole product; both have real Bangladeshi Bangla models, Claude does the understanding |
| G2 | **Text-to-speech with a natural Bangla voice**: ElevenLabs (multilingual, Bengali) for quality; Azure `bn-BD` neural voices (Nabanita, Pradeep) as the cheap fallback | 1 day | ElevenLabs Creator or Pro plan | Customers will hear this voice |
| G3 | **Telephony**: a Bangladesh virtual number with SIP or an IVR API (a local provider; Twilio does not sell BD numbers) | **SLOW: BTRC-licensed provider onboarding** | Trade licence, provider agreement | Only when voice moves from WhatsApp voice notes to phone calls; not needed for the first voice release |

## H. Phase 8, avatar

| # | Item | Lead time | What it needs from Joy | Why |
| --- | --- | --- | --- | --- |
| H1 | **HeyGen API** (or D-ID) | 1 day | Plan with API credits; a consented likeness (his own, or a stock presenter) | Lip-synced Bangla video from G2 audio. Decide whose face before anything is generated |
| H2 | **A YouTube channel + Data API** | 1 hour | Channel under the business Google account, OAuth consent | Publishing the avatar content |

## I. Phases 9 and 10, measured autonomy (nothing to buy)

Autonomy is earned from the evaluation evidence HQ already records (ADR-023, ADR-030, ADR-034). What these phases
need from Joy is decisions, not subscriptions: the activation decision after each gate, and the per-dimension
thresholds a grant may consume. The only spend is model calls, covered by B.

## J. Infrastructure, growing with the phases

| # | Item | When | Why |
| --- | --- | --- | --- |
| J1 | **VPS upgrade** (one step up the KVM ladder, or a second VPS for the AI workers) | Before Phase 3 goes live to customers | Webhooks, queue workers, voice transcoding and the existing stacks on one 4-core box will contend. Hostinger upgrades in place |
| J2 | **Off-box backups** to the R2 bucket from C8 | Now | The 9 Sep consolidation deleted the HQ backup history once; a second copy off the box is the fix |
| J3 | **Sentry (free tier)** for HQ and the shops | Now | Errors with stack traces instead of grepping container logs |
| J4 | **A staging HQ** (same compose, `staging.hq.bordeguna.com`) | Before Phase 3 | Every gate so far has been run on production because there is nowhere else; customer-facing AI must not be tested there |

## K. What is deliberately NOT on the list

- **MCP servers**: nothing to buy. HQ's Tool Gateway is the tool layer, and every outside system reaches the agents
  through it (invariant I1). Third-party MCP servers would be a second door.
- **A vector database**: Postgres with pgvector is already running (`bordeguna-hq-db-1`).
- **An orchestration or observability SaaS** (LangSmith and the like): HQ records every model call, tool call and
  evaluation itself, and that record is the safety evidence. A second copy elsewhere adds nothing.
- **A second LLM provider for the agents**: the runtime is provider-neutral by design (ADR-038), and one provider is
  simpler to reason about at gate time. Revisit only if a Bangla-specific model beats Claude on our own corpus.

## L. The order to do them in

1. **Today**: Claude Max; raise the Anthropic spend limit; the settings allow-rule (say the word); R2 bucket; Cloudflare.
2. **This week, all SLOW items in parallel**: Meta app + Business Verification (C1, C2); WhatsApp number (buy the SIM);
   SMS masking application (C7); bKash/Nagad/aggregator merchant applications (D1 to D3); the dual-currency card (C5).
3. **When Phase 3 is directed**: e-mail provider (C6), courier API (D4), Sentry, staging HQ, VPS upgrade.
4. **When Phases 6 to 8 are directed**: the generation, search, speech and avatar keys, each an hour.

Everything Joy signs up for goes into HQ as a credential through the Credentials screen (one per identity, never in
a `.env` I write from a default), and every one is reached through the gateway.

## M. Readiness, as HQ could verify it on 16 September

ChatGPT's instruction of 16 September (`docs/77` §A, point 14) asks for this list to be tracked and for
nothing to be called available until it has been checked. The table says what was actually checked, and
how. "Not verified" means HQ has no way to see it from here; it does not mean the item is missing. Joy
confirms those, and this table changes only when something is checked.

| Item | State on 16 Sep | How it was checked |
| --- | --- | --- |
| **Anthropic API credit** (the key HQ uses, credential #14: `hermes-hostinger-vps-temp-rakib`, workspace Hermes-Hostinger-VPS) | **Blocked twice over**, read on the billing page 17 Sep: balance minus $0.06, and the organisation's $20 monthly spend limit 100% used ($20.15, resets 1 Oct). Needs $25 of credit AND the limit raised to $50 (`docs/runbooks/anthropic-billing.md`). The server-side check waits for Joy's go-ahead because it uses the stored key | The gate's own 8-token call from inside the app container, with nothing switched on, 16 Sep. On the release after gate 11 the check records its answer, and the table shows this row as `provider_blocked_by_credit` (an incident) instead of *unverified, executable* (docs/77 E.1) |
| **OpenAI API account, credit and key** (the owner's decision of 21 Sep: HQ uses OpenAI for now, Anthropic later; `docs/81` C.7, ADR-049) | **Not set up yet; Joy's.** HQ side built on branch `ai/openai-provider`, not released: an OpenAI driver, the rows seeded switched off, `hq:ai:provider` to move traffic. Needs: prepaid $25, auto recharge off, a $50 monthly hard spend limit with an alert, a project "BordeGuna HQ" and its key stored on /credentials as `openai_api_key` / `hq` (`docs/runbooks/openai-billing.md`). ChatGPT Plus does not pay for the API | Not visible from HQ until the key is stored; then `hq:credentials:health --verify-model` (one tiny call) records `verified`, `provider_blocked_by_credit` or `credential_invalid` on the OpenAI row |
| **GitHub Actions** (CI for every HQ change) | **Paused until the 1 October reset, by Joy's choice** (16 Sep): the account's free minutes for September are used, and he chose to wait rather than pay | PR #35's jobs were refused before starting ("recent account payments have failed or your spending limit needs to be increased"). Until 1 October every merge rests on local verification, recorded as such in docs/78 C; on 1 October CI runs on main and anything it finds is fixed first |
| Anthropic monthly spend limit | Raised on 14 Sep (gate 7 ran after it) | Gates 7 to 10 ran without the limit error |
| Shop tokens, LaLaDia and Bionic | Ready | `hq:credentials:health`: both admin tokens and both assistant keys accepted by the shops on 15 Sep |
| ERP token | Ready | Same table: accepted on 15 Sep 22:05 UTC by the nightly low-stock poll |
| Econic and the ERP counter | Unsupported by design, no credential | Same table. Per Joy's 15 Sep direction, shops reach HQ through the ERP once it is ready; no new per-shop credentials are made |
| Meta Business Verification (C2) | Not verified | Not visible from HQ |
| Meta messaging App Review (C3) | Not verified | Not visible from HQ |
| Meta inbound in HQ | Off | Flag `channels.meta.inbound` shipped off; `hq:shadow:open` refuses while it is on |
| Messaging send provider | None registered | `PlatformRegistry` is empty in the release; the reply tool answers "No messaging provider is registered" |
| WhatsApp Cloud API (C4) | Not verified | Not visible from HQ |
| SMS masking, BTRC (C7) | Not verified | Not visible from HQ |
| bKash, Nagad or an aggregator (D1 to D3) | Not verified | Not visible from HQ |
| Object storage (C8) | Not configured in HQ | No storage credential in HQ's readiness table |
| Cloudflare in front of the webhook (C9) | **Not in place** | `bordeguna.com` nameservers are `ns20/ns21.nameserveit.com`, not Cloudflare (DNS lookup, 16 Sep) |
| Staging HQ (J4) | **Does not exist** | `staging.hq.bordeguna.com` does not resolve; no staging container on the VPS (16 Sep) |
| VPS capacity (J1) | 2 vCPU, 7.8 GB RAM (4.6 GB available), disk 31% of 96 GB, load 0.3, 22 containers | Read on the VPS, 16 Sep. ⚠ Two cores, not four as §J assumed: the step up matters before Phase 3 takes real traffic |

P3-G3 needs, at least: the Meta app in development mode with the page subscribed (C1), the app's
webhook secret in HQ's vault, Cloudflare in front (C9), and a staging HQ (J4). HQ's vault holds no Meta
credential, Cloudflare is not in front and no staging HQ exists; whether the Meta app itself exists is
not visible from here. So P3-G3 does not start in this milestone, which is also ChatGPT's instruction.
