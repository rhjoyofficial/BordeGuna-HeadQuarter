# 84 - Phase 5 design and readiness packet: marketing and ads (21 September 2026)

**Status: DESIGN AND EXTERNAL READINESS ONLY, stream O of `docs/83` (point 4 of the instruction,
verbatim in `docs/83` section A).** Nothing here is built. No tool is registered, no adapter exists,
no flag, migration or scheduled job is created, and nothing touches a prompt, the runner, the
detectors or the gateway's decisions. The decision it rests on is ADR-050 (proposed with this
document).

**Read first:** `docs/83` section A point 4 (the instruction), `docs/60` (the invariants and the
boundaries every design keeps), `docs/16` (the marketing core as first written, 5 September),
`docs/23` (analytics and attribution), `docs/18` (social integrations and the Meta scope list),
`docs/79` (the Phase 4 design, whose shape this document follows), ADR-011, ADR-021, ADR-022,
ADR-024, ADR-029, ADR-031, ADR-034, and Joy's V.1 decision (`docs/50` line 153: R0 to R3 allow, R4
and R5 require approval, no value threshold).

An analogy first. Advertising is the one part of the business where a machine can spend money
without anybody writing a cheque. A shop assistant who may take orders is a small risk; a shop
assistant who may also decide how much to pay a billboard company every hour is a different
employee. This packet keeps the two apart. HQ may read everything the ad accounts say, may prepare
campaigns that do nothing until a person switches them on, and may propose changes with the numbers
behind them. Money starts moving only when a named person approves the exact change, and the outer
ceiling on money, the ad account's own spending limit, is set by the owner in Meta and never by HQ.

## A. What already exists (do not build twice)

1. **The platform contract.** `apps/hq/app/Platform/Contracts/AdsPlatform.php:6` declares the
   interface Phase 5 implements, with six operations: `read_insights` (`:8`), `read_campaigns`
   (`:10`), `create_draft_campaign` (`:12`), `change_budget` (`:14`), `pause_campaign` (`:16`) and
   `publish_campaign` (`:18`). Its docblock says it in one line: "Reading spend and changing spend
   are deliberately far apart." It extends `PlatformProvider`, whose `ceiling()` is the most the
   code can ever do and whose `requiresApproval()` names what the platform itself must approve.
2. **The capability router.** `apps/hq/app/Platform/CapabilityRouter.php:46` resolves a capability
   to exactly one connected account, refuses when HQ has no provider for the platform (`:53`), and
   refuses two equally valid candidates instead of choosing (`:101`, ADR-022, ADR-029). Two ad
   accounts that could both run a LaLaDia campaign are therefore a refusal and a question, never a
   guess. `platform_accounts` already carries `capabilities`, `approval_status` (unknown,
   not_requested, pending, approved, rejected) and `restrictions`
   (`database/migrations/2026_09_05_100000_create_domain_tables.php:106-110`), and the provenance
   of who verified them (`2026_09_09_350000_add_capability_provenance_to_platform_accounts.php`).
3. **The gateway and its vocabulary.** Every descriptor field this packet needs exists in
   `apps/hq/app/Gateway/Descriptor/ToolDescriptor.php:30`: `riskTier`, `permission`,
   `agentExecutable`, `permanentlyHumanOnly`, `credentials`, `idempotencyKeyFrom`,
   `monetaryImpact` (none, low, medium, high), `consentRequired`, `customerFacing`,
   `reversibility` (no effect, reversible, compensable, irreversible), `compensatingTool` and
   `dataClass`. The tiers are in `RiskTier.php`: R2 is "external read", R4 "external side effect,
   recoverable", R5 "material or irreversible", and `baseline()` makes R4 and R5 approval-required
   with no threshold (V.1).
4. **Approvals, execution context and budgets.** `Gateway/Approval/ApprovalService.php:53` parks an
   approval bound to a canonical payload hash (ADR-018), with requester and enabler recorded apart
   (`docs/81` C.2); the Effective Execution Context resolves an agent's authority as the
   intersection of requester, enabler, agent, business and brand (ADR-047); `Gateway/Policy/
   BudgetLedger.php:27` already counts calls per tool and business and checks an agent's budget.
5. **Consent.** `app/Consent/ConsentPurpose.php` separates `Service` from `Marketing`, and
   `permittedWithoutRecord()` is false for every purpose: no recorded consent means no. The ledger
   is append-only and enforced at the gateway (ADR-021).
6. **Provenance.** `app/Context/Provenance.php:42`: only `System` and `Business` are trusted.
   Whatever an ad platform returns (a report, a comment, a rejection reason) is `ThirdParty` and can
   never raise autonomy (ADR-024).
7. **The design already written.** `docs/16` holds the platform-neutral core (`MarketingExperiment`,
   campaign mirrors, the `MarketingPlatform` adapter verbs, the hourly loop, the rules learned from
   real spend) and `docs/23` the contribution-profit joins. This packet keeps both and corrects one
   line of `docs/16` (section C.12).
8. **What happened in the real accounts.** Two hand-built campaigns before HQ (`docs/16`: a beef
   pickle trial, BDT 212 spent, 137 clicks, no purchase; a hilsa campaign, paused). On 10 September
   a LaLaDia hilsa campaign with five ads was built in Ads Manager and **never published**, because
   the ad set's **Advertiser** and **Payer** fields were empty: those are the business's legal
   identity and only Joy may fill them. Building it also showed three traps a machine must never
   repeat: a Facebook-post ad gets a link only by editing the live post (a one-way change when the
   post already had a button); a dropdown pick in Ads Manager silently flipped a "browser add-on" on
   four of five ads; and Ads Manager drafts belong to the Facebook profile that made them.

## B. What this packet decides, in one paragraph

Phase 5 is a new capability family on an old spine. HQ's unit of thought is the **experiment**, a
hypothesis with a budget and a success measure owned by one brand of one business; Meta's
campaigns, ad sets and ads are mirrors of it, never the model. HQ reads insights hourly into its own
read model and judges campaigns by delivered contribution profit, not by the platform's ROAS. HQ
writes to an ad account only through the gateway, as a small set of capabilities: preparing a
paused campaign (R4), pausing (R4), changing a budget (R5) and switching a campaign on (R5), each
approval-required, each idempotent by a logical key, each read back before it is called done.
Billing, payment methods, the ad account's spending limit and deleting anything are not HQ tools at
all: they are permanently human (ADR-050). A marketing agent starts at A0, proposing on real
numbers while people keep deciding, and earns nothing except by measured evidence; spending more
money is never earned automatically.

## C. The design, point by point (in the order of the instruction)

### C.1 Marketing domain model

`docs/16` stands. The durable nouns are the owner's, not Meta's: **objective** (purchase, lead,
traffic, awareness), **audience** (a specification in HQ's words), **offer**, **creative** (media
assets and copy), **budget** (daily and total, in the ad account's currency), **schedule**,
**hypothesis**, **KPI** (name and target), **experiment**, **result**, **decision** (scale, hold,
kill). `marketing_experiments` is the owner-facing record; `campaign_mirrors` hold each platform
object's external id, status, budget and last sync, and exist only because an experiment asked for
them.

Two additions to `docs/16`. First, every experiment names its **product lines** (ERP SKUs), because
stock protection and contribution profit both need them. Second, every experiment carries a
**special ad category** declaration from the start (C.3), made by a person, even when the answer is
"none".

### C.2 Business, brand, store and platform separation

ADR-010 applies unchanged: an experiment belongs to one **brand** of one **business**; it may
promote products sold in one or more **stores**; it runs on one or more **platform accounts** (an ad
account is a `platform_accounts` row with `platform = meta_ads` and `account_ref = act_<id>`). The
gateway's scope check means a person or agent without that business in their Effective Execution
Context cannot see the account, its spend or its experiments.

The real accounts are not tidy yet, and the design refuses rather than guesses (the router's
ambiguity rule). Read from the project's own records: `docs/16` names ad account `28164410319885213`
as BDT; the LaLaDia hilsa campaign of 10 September lives in ad account `37981153204862266` ("La La
Dia Ad Account") with a budget shown as $10.00 a day; a second account `1535878336983845` is empty.
Which account belongs to which brand, and in which currency each is billed, is for Joy to confirm in
Ads Manager before any account row is marked usable (F, item O-5). A brand with two usable accounts
is refused until one is chosen.

### C.3 Campaign, ad set, ad and creative concepts

Meta's own hierarchy, read on 21 September 2026 (`developers.facebook.com/docs/marketing-api/
overview`): "A campaign is the highest-level organizational structure within an ad account and
should represent a single objective"; "Ad sets are groups of ads that configure the budget and
period the ads should run for"; the objective sits on the campaign, schedule, budget, bidding and
audience on the ad set, and the ad creative on the ad. HQ maps them as follows: an experiment's
objective becomes the campaign's; each audience hypothesis becomes an ad set; each creative variant
becomes an ad; the creative itself (image, video, copy) is an HQ media asset uploaded to the
account only when an ad needs it.

**Special ad categories.** Meta's documentation (read 21 September,
`developers.facebook.com/docs/marketing-api/audiences/special-ad-category`): "All campaign
creations require the `special_ad_categories` field"; the accepted values are `HOUSING`,
`FINANCIAL_PRODUCTS_SERVICES`, `EMPLOYMENT`, `ISSUES_ELECTIONS_POLITICS` and `NONE`; choosing any
category also requires `special_ad_category_country`. The group's food and wellness products are
`NONE` in the ordinary case, but the value is never defaulted by code: an experiment without a
person's declaration cannot be prepared. The targeting restrictions Meta lists for the regulated
categories (fixed age range, no gender, radius rules, no lookalikes) are stated for campaigns
targeting the US, Canada or Europe; whether any apply to a Bangladesh-only campaign was not
verified here.

### C.4 Provider abstraction

`AdsPlatform` is the provider contract (A.1); `docs/16`'s `MarketingPlatform` verbs map onto it one
to one, with `delete` removed (C.10). A Google Ads or TikTok adapter later is another implementation
of the same interface with its own `ceiling()`; nothing above the adapter names Meta. The model
never sees an endpoint, an API version or an account id: it reasons about "prepare a paused campaign
for experiment 12", and the router picks the account (ADR-029). The Graph API version is
configuration read by the adapter, never a literal in business code: Meta's changelog (read 21
September, `developers.facebook.com/docs/graph-api/changelog`) lists Marketing API v26.0 (29 July
2026, sunset to be announced), v25.0 (18 February 2026) and v24.0, available until 6 October 2026.
`docs/16` and the Bionic bot use v25.0 today; the adapter starts on the newest version whose sunset
is furthest away, and a version change is a configuration change with a test run, not a code change.

MCP, if Meta or anyone else offers an ads MCP server later, is an adapter behind this contract and
the gateway (ADR-029, `docs/60` 2.5): a tool discovered over a protocol is not a tool HQ has until
somebody registers a descriptor for it.

### C.5 Meta integration boundaries

What HQ does through Meta, and what it deliberately does not:

- **Does:** read campaigns, ad sets, ads and insights of the accounts the business portfolio grants
  it; upload a creative the owner approved; create a campaign, ad set and ad **paused**, with a
  campaign spending limit equal to the experiment's total budget; pause; change a budget within
  Meta's limits; switch a prepared campaign on.
- **Does not, ever:** add, remove or change a payment method; pay a bill; change the ad account's
  spending limit; create or close an ad account; change portfolio users, roles, system users or
  partner access; delete a campaign, ad set, ad or creative; edit a live organic post to add a
  button (the 10 September trap: a Facebook-post ad gets its link only by editing the post, which
  Meta warns about and which can be one-way); upload customer lists without the consent rules of
  C.9.
- **Rate discipline, from Meta's own limits** (read 21 September,
  `developers.facebook.com/docs/marketing-api/overview/rate-limiting`): the `ads_management`
  business-use-case budget per ad account per hour is "(100000 if your app is in the Marketing API
  Full access or 300 if your app is in the Dev tier) + 40 * Num of Active ads", and `ads_insights`
  "(190000 ... or 600 if your app is in the Dev tier) + 400 * Number of Active ads"; and "For each
  ad set, the budget is only allowed to change 4 times per hour". At the group's scale (a handful of
  active ads) even the development tier allows several hundred management calls and a few thousand
  insight calls an hour per account, far above an hourly sync. HQ's own policy allows at most one
  budget change per ad set per hour, well inside Meta's four, so HQ can never be the reason a
  budget change is refused. The adapter reads the `X-Ad-Account-Usage` and
  `X-Business-Use-Case` headers and backs off before the limit, and a throttling error (codes 17,
  613, 80000 to 80014, as the page lists) is `unavailable`, never `failed`.

### C.6 Capability registry requirements

The advertising family is already one of the eight in `docs/60` 2.3. Phase 5 adds:

- a provider row for `meta_ads` whose `ceiling()` holds the six `AdsPlatform` operations and whose
  `requiresApproval()` names the ones Meta gates behind access tiers or review (`create_draft_campaign`,
  `change_budget`, `pause_campaign`, `publish_campaign`: every write);
- one `platform_accounts` row per ad account, with `capabilities` verified by a person
  (`capabilities_verified_by`), `approval_status` taken from the app's real access state, and
  `restrictions` holding the account's currency, time zone and the owner's spending limit as read
  back from Meta (read-only facts, never set by HQ);
- the router's refusal for two candidate accounts per brand, unchanged.

A capability the account does not hold is not offered to the model at all (`docs/18`: the UI and
the AI are shown only the operations that account currently supports).

### C.7 Tool Gateway contracts

Every effect is a registered tool behind the gateway (I1). The descriptor sketches are in section D;
the contract rules that all of them keep:

1. **Business scope from the experiment.** `scope: ToolScope::Business`, `scopeFrom:
   'business:args.business_slug'`, and the experiment's brand must belong to that business.
2. **One account, resolved by the router,** never named by the caller.
3. **A payload the approval can bind.** Every write's arguments are the full intended state (the
   experiment id, the target object's external id, the new status or budget, the currency), so the
   canonical payload hash an approval binds (ADR-018) is exactly what will be sent.
4. **Read back before success** (ADR-031): after a write, the adapter reads the object and compares
   status and budget with the intent; a mismatch or an unreadable object is `uncertain`, never
   `succeeded`.
5. **No prose.** A `ToolRequest` has nowhere to put a sentence (`docs/60` 2.4); ad copy travels as a
   media asset id and a copy record the owner approved, not as free text from the model.

### C.8 Credential and connection model

The connection is the business's, never a person's. Following `docs/18`: a Meta app under the
**boss's official business portfolio** (Joy's Q12 and Q13 decisions, 5 September), a **system
user** in that portfolio with `ads_management` and `ads_read` on the named ad accounts, and a
system-user token stored in `integration_credentials` as provider `meta_ads_token` per business,
read by `CredentialVault` only inside the adapter, never shown to a model, never logged. A personal
user token is refused as a credential kind for ads.

Access levels, read on 21 September (`developers.facebook.com/docs/marketing-api/get-started/
authorization`): Meta has renamed "Ads Management Standard Access" to the Marketing API access tier,
"Standard Access" to **Limited Access** and "Advanced Access" to **Full Access**. Limited is the
default: "Heavily rate-limited per ad account. For development only. Not for production apps running
for live advertisers", with one system user and one admin system user. Full comes after App Review:
"Lightly rate limited per ad account", ten system users. To qualify for advanced access an app must
"Have successfully made at least 500 Marketing API calls in the last 15 days" with "an error rate of
less than 15% in the last 500 calls". On permissions the same page says: "If your app is only
managing your ad account, standard access to the `ads_read` and `ads_management` permissions are
sufficient." So the first release manages the group's own accounts on standard permission access,
and moves to Full access once the read-only sync has made its 500 clean calls; the numbers above
make that a natural consequence of gate P5-G1, not a separate project.

The readiness states of `docs/81` C.7 apply unchanged: a billing or spend block is
`provider_blocked_by_credit` and never condemns the token; a revoked or expired token is
`credential_invalid`.

### C.9 Consent and authorization boundaries

Two different questions, kept apart as `docs/60` 2.6 requires.

**Authorization** is the Effective Execution Context: requester, enabler, agent, business and brand,
intersected; plus the tool permission `hq.tools.ads.write` (new) for any write and
`hq.tools.ads.read` for reads. A person who may see LaLaDia's orders may not therefore see its ad
spend.

**Consent** matters in two places only. Ads shown to Meta's audience need no per-person consent from
HQ: Meta decides who sees them. But:

1. **Customer lists** (a Custom Audience built from customers' phones or emails) send personal data
   out of HQ to target those people with promotion. That is `ConsentPurpose::Marketing` per person,
   and the ledger says no record means no. The tool that would upload a list is R5, carries
   `consentRequired: Marketing`, `dataClass: Personal`, and is not built in Phase 5's first release
   (the group has about 21 customers, `docs/16`, far below any useful audience).
2. **Server-side conversion events** (Conversions API) send hashed customer data to Meta for
   measurement. The shops already send them (`docs/16`: pixels and CAPI healthy on both shops).
   Whether delivered-purchase events should also be sent, and with which customer fields, is a
   decision for Joy (section I), because Bangladesh's data-protection position on this was not
   verified here.

### C.10 Human-only decisions

Permanent (`permanentlyHumanOnly: true`, never registered as agent tools, `docs/60` 2.11 "moving
money"): adding or changing a payment method; paying or disputing a bill; setting, raising,
resetting or removing the **ad account spending limit**; creating or closing ad accounts; granting
or removing people, partners or system users; filling the **Advertiser** and **Payer** identity
fields; declaring a special ad category; deleting any ad object; accepting Meta's terms or policies;
appealing a rejected ad or a restricted account; editing a live organic post for an ad.

Not permanent, but human today (approval-required, agent not cleared): switching a campaign on,
raising a budget, pausing, preparing a campaign.

### C.11 Budget and spend controls

Money is bounded four times, from the outside in:

1. **The ad account spending limit, set by the owner in Meta.** Meta, read 21 September
   (`facebook.com/business/help/141820733085330`): "An ad account spending limit is a flexible
   lifetime cap on the total money your ad account can spend across all ad campaigns"; "when
   spending reaches the limit, your ads pause, and the account stops spending"; "If your ad account
   uses available funds to pay for ads, you can't set an ad account spending limit." This is the
   one control HQ cannot override, which is exactly why HQ never writes it (ADR-050). HQ reads it
   and refuses to prepare or switch on a campaign whose budget cannot fit under what remains.
2. **A campaign spending limit per experiment,** set by HQ at preparation to the experiment's
   approved total. Meta (`facebook.com/business/help/481733105308636`): campaign spending limits
   "ensure that all the ad sets and ads in your campaign stop running once they've reached your
   spending limit"; and "Campaign lifetime budgets take the place of campaign spending limits when
   Advantage+ campaign budget ... is enabled", in which case HQ sets the lifetime budget instead.
3. **HQ's own budget policy,** a per-business monthly advertising budget in BDT that the owner sets
   on HQ (a policy row, audited), which every preparation and budget change is checked against.
4. **The approval itself,** bound to the exact budget and currency.

Daily spend is paced against the experiment's budget from the hourly sync; overspend against plan,
zero delivery and spend on an out-of-stock product (read from the ERP) raise an HQ alert, which the
owner resolves.

### C.12 Approval requirements

Every write is R4 or R5, so every write is approval-required under V.1, **with no value threshold**.
This corrects `docs/16`'s "Guardrail example": "raising daily budget by <= 20% ... -> AUTO" and
"`pause_campaign` when CPA over 48 h > BDT 900 -> AUTO" were written before V.1 (8 September) and
before ADR-023. They describe what A3 guardrails could look like once earned (C.19), not what Phase
5 ships. The approval card shows the experiment, the object, the change as before and after, the
money it can spend at most, and the evidence the proposal cites (insights rows with their as-of
times). The requester cannot approve their own request (`docs/81` C.2); the enabler may, until a
second approver exists.

### C.13 Kill switches

From the narrowest to the widest, all existing machinery except the first:

1. **Pause one campaign** (R4, approval-required; also always available to a person directly in
   Ads Manager, which HQ's reconciliation then records, C.14).
2. **The tool's own switch** in `ToolAvailability` (`app/Gateway/Registry/ToolAvailability.php:26`,
   read fresh since `docs/81` C.6): turning off `ads.publish_campaign` stops every switch-on at once.
3. **The account's capability row:** removing an operation from the ad account's `capabilities`
   makes the router refuse it.
4. **A feature flag `ads.write`** that every ads write tool checks, off by default.
5. **`ai.runtime` off,** which stops every agent.
6. **The owner's ad account spending limit in Meta,** which stops spend even if HQ were wrong.

### C.14 Reconciliation requirements

Three reconciliations, each reporting and never repairing (ADR-031, the rule `docs/79` C.17 uses):

1. **Mirror against Meta,** nightly and after every write: for each campaign mirror, Meta's status,
   budget and spend against HQ's record. A difference means somebody changed it in Ads Manager or a
   write was uncertain; HQ records the difference as an alert and never reverts a person's change.
2. **Spend against HQ's budget and the account's limit,** daily: insights spend summed per business
   against the HQ budget policy and the owner's limit.
3. **Attributed orders against the ERP,** daily: orders HQ attributes to an experiment must exist in
   the ERP with the same totals; delivered and cancelled states come from the ERP, not from the
   platform's conversion count.

### C.15 Audit requirements

Every call, read or write, is a `tool_calls` row with its correlation id and the connector call it
caused (`docs/78` §E made that link). Every write additionally records: the experiment and the
logical key; the object before and after as read back; the approval id and payload hash; the
Effective Execution Context digest; the currency and the maximum it could spend. Audit rows and
decision records are kept forever (the retention policy of `docs/78`); ad insights detail follows
the 180-day rule with daily aggregates.

### C.16 Idempotency and retry rules

ADR-011: at least once, idempotent tools, reconciliation, no exactly-once claim. The Marketing API
has no idempotency key a caller can send (not found in the pages read), so the logical key travels
inside HQ's own object names: every object HQ creates carries a suffix `[hq:<logical key>]` in its
name. After an uncertain response, the adapter lists the account's objects with that suffix before
it ever creates again; one found is adopted, two found is an incident. Status and budget changes are
idempotent by nature (setting paused twice is paused) and are retried only after a read shows the
change did not land. Retries back off on the rate headers of C.5; a billing block is not retried.

### C.17 Attribution and data provenance

`docs/23` stands: the target is **delivered contribution profit** per experiment, and every report
pairs gross with delivered and prints its sample size. What this packet adds:

- **Provenance of every figure.** Insights are `ThirdParty` provenance (Meta's own count); order,
  delivery and cost figures are `Business` provenance from the ERP. A report never mixes them into
  one number without saying which part came from where, and the briefing bundle's lineage rule
  (`app/Briefing/Lineage.php:19`) applies: a spend figure names the insight rows it was summed from.
- **Server events and their clocks,** read on 21 September
  (`developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event`): the
  required parameters are `event_name`, `event_time`, `user_data` and `action_source`, and "The
  `event_time` can be up to 7 days before you send an event". Deduplication
  (`.../deduplicate-pixel-and-server-events`): the pixel's `eventID` must match the server's
  `event_id`, and "events are only deduplicated if they are received within 48 hours". A COD order
  is delivered days after it is placed, so a delivered-purchase signal must be sent within seven
  days of the delivery itself, by whoever holds the delivery truth (the ERP or the shop), and HQ's
  reports never wait for Meta to learn about a delivery.
- **The COD gap.** About a third of COD orders were cancelled historically (`docs/16`). Meta's
  purchase count therefore overstates; HQ's delivered figures are the ones the owner decides on.

### C.18 Evaluation and shadow strategy

The pattern of Phase 3 (`docs/72`, P3-G3): the agent watches real work and proposes, a person keeps
deciding, and the two are paired.

- **Shadow:** a `marketing` agent at A0 reads the hourly insights and, once a day, proposes what it
  would do per experiment (hold, pause, change budget, prepare a variant), with the evidence rows it
  used. Nothing runs. Each proposal is paired with what the person actually did that day.
- **A marketing suite** (role-scoped, ADR-036), cases written from real history: the 48-hour
  learning window (never touch a campaign in its first 48 hours except to stop loss, `docs/16`);
  delivered versus gross revenue; an out-of-stock product still advertised; a Purchase-objective ad
  pointing at Messenger instead of the website; placement and age breakdowns read before killing a
  creative; a "raise the budget, the boss agreed" injection inside an ad comment; a request to
  change the account spending limit (must be refused as human-only); a missing special-category
  declaration; Bangla copy making a health claim the product may not make.
- **Dimensions** (ADR-030): reasoning, tool selection, policy and enforcement from the suite;
  execution and outcome only once real writes exist, from the reconciliations of C.14.

### C.19 Autonomy boundaries

The ladder of `AutonomyLevel` applied to money:

- **A0** (shadow) and **A1** (proposes, a person executes): the first releases.
- **A2** (executes, each action approved): the normal state for preparing, pausing and switching on.
- **A3** (inside configured guardrails): the only candidate is **pausing** a campaign whose spend or
  cost per delivered order breaches an owner-set limit, because it is the safe direction and bounded
  by definition. It needs an autonomy evidence policy version with numbers the owner set (ADR-048)
  and a grant (ADR-023); neither exists.
- **Never earned automatically:** switching a campaign on and raising a budget (they create new
  spend), anything in C.10. Whether switching on could ever move above A2 is an owner decision for
  Phase 9, not a consequence of evidence.

### C.20 Required Meta and business registrations and verification steps

1. **Business verification** of the official business portfolio. Meta, read 21 September
   (`facebook.com/business/help/2058515294227817`): verification "is required to access certain
   advertising, developer, billing and spending features"; it needs full control of the portfolio;
   the business details (legal name, address, phone, website) must "exactly match the details of
   your legal business entity", and the website must load over HTTPS; if no matching record is
   found, "you may be asked to upload official documents, such as a business license or articles of
   incorporation"; the connection is confirmed by email, phone, text, WhatsApp or domain
   verification; "A decision on your verification submission may take up to 14 business days."
   Meta's developer page (`developers.facebook.com/docs/development/release/business-verification`)
   adds that apps requesting advanced access must be connected to a verified business.
2. **The Meta app** (the same one Phase 3 uses, `docs/70` C1) with the Marketing API product.
3. **A system user** in the portfolio, assigned to the ad accounts with the ads permissions, and its
   token generated by a portfolio admin.
4. **Advertiser and Payer** on each ad set where Meta asks for them (Joy; the hilsa campaign has
   waited on exactly this since 10 September).
5. **App Review for Full access** only when the Limited tier's limits are reached or other
   businesses' accounts are managed; the group's own accounts do not need it to start (C.8).

### C.21 Provider prerequisites

- **A payment method Meta accepts in Bangladesh.** Meta's Bangladesh page, read 21 September
  (`facebook.com/business/help/1385998218157459`), lists "Credit cards or co-branded debit cards,
  including: American Express ..., Mastercard, Visa" and "PayPal". A Bangladeshi newspaper reported
  that Meta added bKash as a payment option; Meta's own Bangladesh page, read the same day, does not
  list it, so it is **not verified** here and Joy checks Ads Manager's billing screen. Until then the
  dollar-endorsed card (`docs/82` S4) is the dependable route.
- **Account currency.** Meta's accepted-currencies page (`facebook.com/business/help/151451521590943`)
  lists "Bangladesh Taka (BDT)". Which currency each of the group's accounts uses is to be read in
  Ads Manager (C.2); HQ stores budgets in the account's own currency and converts nothing.
- **The account spending limit** set by the owner (C.11); not possible on an account that pays from
  prepaid funds, per the page quoted there.
- **VAT or tax on Meta's invoices in Bangladesh:** not verified here; a question for the accountant
  (`docs/82` section 7 item 5 already asks how gateway receipts are recorded).
- **ERP cost prices** for the advertised SKUs: without them contribution profit is blank (`docs/23`).

### C.22 Production activation gates

Section E. Each is an explicit capability gate in the sense of `docs/77`; none opens itself.

### C.23 What can later become read-only, proposal-only, approval-gated or autonomously executable

| Operation | Today (this packet) | Can become, on evidence | Never |
| --- | --- | --- | --- |
| Read campaigns, insights, account limit | read-only (R2), agent-executable once registered | stays read-only | |
| Prepare a paused campaign with a spending limit | approval-gated (R4) | proposal-only for the agent, approval-gated execution (A2) | autonomous without approval while V.1 stands |
| Pause a campaign | approval-gated (R4) | autonomous inside an owner-set guardrail (A3), on a measured policy and a grant | |
| Change a budget down | approval-gated (R4) | A3 guardrail, same conditions as pause | |
| Change a budget up | approval-gated (R5) | stays approval-gated | autonomous |
| Switch a campaign on | approval-gated (R5) | stays approval-gated unless the owner decides otherwise in Phase 9 | |
| Upload a customer list | not built | approval-gated with per-person marketing consent | autonomous |
| Payment method, billing, account spending limit, accounts, people, deletion, legal fields | not a tool | | ever (ADR-050) |

## D. The capabilities it would register

Descriptor sketches in the vocabulary of `ToolDescriptor.php`, family `advertising`, provider
`meta_ads` behind `AdsPlatform`. Every one: `scope: Business`, `scopeFrom:
'business:args.business_slug'`, `credentials: [CredentialRequirement('meta_ads_token',
'business.slug')]`, `supportedStores: []` until P5-G2.

| Tool | Tier | readWrite | agentExecutable | Approval | Other fields |
| --- | --- | --- | --- | --- | --- |
| `ads.read_campaigns` | R2 external read | Read | true once registered (reading is not acting, ADR-027) | no | `dataClass: Internal` |
| `ads.read_insights` | R2 | Read | true once registered | no | output labelled `ThirdParty` |
| `ads.prepare_campaign` (`create_draft_campaign`) | R4 | Write | false | yes (V.1) | `reversibility: Reversible`, `monetaryImpact: None` (created paused, spending limit set), `idempotencyKeyFrom: ['business_slug','experiment_id','variant']` |
| `ads.pause_campaign` | R4 | Write | false | yes | `reversibility: Reversible`, `monetaryImpact: None` |
| `ads.change_budget` | R5 when raising, R4 when lowering (two tools, `ads.raise_budget` and `ads.lower_budget`, so the tier is intrinsic, ADR-017) | Write | false | yes | `monetaryImpact: Medium` for raise |
| `ads.publish_campaign` (switch on) | R5 material | Write | false | yes | `monetaryImpact: Medium`, `compensatingTool: ads.pause_campaign` |
| `ads.upload_creative` | R4 | Write | false | yes | asset must be owner-approved; `idempotencyKeyFrom: ['asset_id','account']` |
| `ads.upload_customer_list` | R5 | Write | false | yes | `consentRequired: Marketing`, `dataClass: Personal`; **not built in the first release** |
| payment, billing, spending limit, accounts, people, delete | not registered | | | | `permanentlyHumanOnly` by ADR-050 |

New permissions: `hq.tools.ads.read`, `hq.tools.ads.write`. New flag: `ads.write` (off).

## E. The gates

- **P5-G0, readiness.** Business verification done; the app and system user exist; the token stored
  on `/credentials`; `hq:credentials:health` reads the ads row `verified`; each ad account row's
  brand, currency and spending limit confirmed by Joy; no write tool registered.
- **P5-G1, read side.** Insights and campaigns synced hourly for every usable account into
  `facts_ad_spend`; totals reconciled with Ads Manager for three consecutive days; the contribution
  report produced for at least one experiment with ERP delivered orders and cost prices; the first
  500 clean API calls made (the Full-access condition of C.8).
- **P5-G2, inert write capability.** The write tools registered with the descriptors of D; the flag
  off; `supportedStores` empty; the gateway refuses every caller; the whole write sequence tested
  against a simulated Meta connector, including an uncertain create reconciled by its name suffix,
  a throttled call, a billing block, a fifth budget change in an hour refused by HQ before Meta, and
  a budget that does not fit under the account limit.
- **P5-G3, operator writes.** A person prepares, approves and switches on one real campaign through
  HQ on one account, with a spending limit; every step read back; the first campaign checked by hand
  in Ads Manager (the same rule ERP ADR-004 sets for the first real order).
- **P5-G4, agent proposals.** The marketing agent at A0 then A1 on real insights for at least two
  weeks; the marketing suite measured with zero unmeasured cases; proposals paired with the owner's
  actual decisions.
- **P5-G5, the first guardrail.** Only the pause of C.19, only through an autonomy evidence policy
  version and a grant, both owner decisions; nothing that increases spend.

## F. The owner's registrations and accounts, with lead times

| # | Item | Lead time | Why |
| --- | --- | --- | --- |
| O-1 | Meta Business Verification (`docs/82` S1) | Meta says "up to 14 business days" | Advanced access and several ads, billing and spending features require it |
| O-2 | The Meta app with the Marketing API product (`docs/70` C1, E1) | 1 day | The only door HQ has into the ad accounts |
| O-3 | A system user in the official portfolio, assigned to the ad accounts, and its token stored on HQ's `/credentials` | 1 hour, after O-2 | A connection that belongs to the business, not to a person's Facebook profile |
| O-4 | A payment method Meta lists for Bangladesh: the dollar-endorsed card (`docs/82` S4); check in Ads Manager whether bKash is offered | Days (card) | Meta's own Bangladesh page lists cards and PayPal only |
| O-5 | Confirm which ad account belongs to which brand and its currency (the records disagree: BDT in `docs/16`, $10 a day on the hilsa account) | Minutes | HQ refuses an account whose brand or currency is unknown |
| O-6 | Set an ad account spending limit on every account HQ will touch | Minutes | The outer ceiling on money, which HQ never changes |
| O-7 | Fill Advertiser and Payer on the hilsa ad set (`docs/70` E2) | Minutes | The campaign cannot publish without them |
| O-8 | ERP cost prices for the advertised SKUs | An afternoon of data entry | Contribution profit is blank without them |

## G. The ADR proposed

**ADR-050 (proposed): paid media moves money only by a person's decision.** HQ reads ad accounts
freely, prepares campaigns paused and inside a campaign spending limit, and changes spend only by an
approved R5 tool; billing, payment methods, the ad account spending limit, accounts, people, legal
identity fields and deletion are permanently human and are not tools. The owner's ad account
spending limit in Meta is the outer ceiling HQ can read and never write. File:
`docs/adr/050-paid-media-moves-money-only-by-a-persons-decision.md`.

## H. Facts read for this packet, and what could not be verified

Read on 21 September 2026 from the provider's own pages:

| Fact | Source |
| --- | --- |
| Campaign, ad set, ad hierarchy; where objective, budget, schedule, audience and creative sit | developers.facebook.com/docs/marketing-api/overview |
| Access tiers renamed (Limited and Full); Limited "for development only"; Full after App Review; 500 calls in 15 days and under 15% errors; standard permission access sufficient for your own ad accounts | developers.facebook.com/docs/marketing-api/get-started/authorization |
| `ads_management` and `ads_insights` hourly budgets per ad account; usage headers; throttling error codes; 4 budget changes per ad set per hour | developers.facebook.com/docs/marketing-api/overview/rate-limiting |
| `special_ad_categories` required on every campaign; values; country field; restrictions stated for US, Canada, Europe | developers.facebook.com/docs/marketing-api/audiences/special-ad-category |
| Marketing API versions v26.0, v25.0, v24.0 (until 6 October 2026) | developers.facebook.com/docs/graph-api/changelog |
| Conversions API required parameters; `event_time` up to 7 days before sending | developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event |
| Pixel and server deduplication by `event_id` and `event_name` within 48 hours | developers.facebook.com/docs/marketing-api/conversions-api/deduplicate-pixel-and-server-events |
| Business verification steps, exact-match details, HTTPS site, documents when no record matches, up to 14 business days | facebook.com/business/help/2058515294227817 |
| Advanced access requires a verified business | developers.facebook.com/docs/development/release/business-verification |
| Bangladesh accepted payment options: cards and PayPal | facebook.com/business/help/1385998218157459 |
| BDT an accepted currency | facebook.com/business/help/151451521590943 |
| Ad account spending limit: lifetime cap, pauses ads, not available with prepaid funds | facebook.com/business/help/141820733085330 |
| Campaign spending limit; replaced by lifetime budget under Advantage+ campaign budget | facebook.com/business/help/481733105308636 |

**Not verified** (stated as such in the text): bKash as a Meta ads payment method (a newspaper
report only); whether the special-category targeting restrictions apply to a Bangladesh-only
campaign; VAT or tax on Meta invoices in Bangladesh; Bangladesh's data-protection position on
sending hashed customer data by Conversions API; any idempotency or client-token mechanism in the
Marketing API (not found in the pages read); the exact list of documents Meta accepts for
Bangladeshi business verification.

## I. Decisions left for Joy or ChatGPT

1. **Which ad account per brand, and its currency** (O-5). Joy.
2. **The monthly advertising budget per business** that HQ checks against (C.11). Joy.
3. **Delivered-purchase conversion events:** should the ERP or shop send them to Meta, and with which
   customer fields (C.9, C.17)? Joy, with ChatGPT on the data-protection side.
4. **Whether switching a campaign on may ever rise above A2** (C.19). A Phase 9 owner decision;
   recorded now so nobody treats evidence as enough.
5. **The first guardrail's numbers** (spend or cost per delivered order at which a pause is allowed),
   only when P5-G5 is reached. Owner, through ADR-048's policy.
6. **Whether ChatGPT agrees that `docs/16`'s AUTO guardrail examples are superseded** by V.1 until
   evidence exists (C.12).

## J. Where each point of the instruction is answered

| Instruction bullet (`docs/83` A, point 4) | Section |
| --- | --- |
| marketing domain model | C.1 |
| business/brand/store/platform separation | C.2 |
| campaign/ad-set/ad/creative concepts | C.3 |
| provider abstraction | C.4 |
| Meta integration boundaries | C.5 |
| capability registry requirements | C.6 |
| Tool Gateway contracts | C.7 |
| credential/connection model | C.8 |
| consent and authorization boundaries | C.9 |
| human-only decisions | C.10 |
| budget/spend controls | C.11 |
| approval requirements | C.12 |
| kill switches | C.13 |
| reconciliation requirements | C.14 |
| audit requirements | C.15 |
| idempotency and retry rules | C.16 |
| attribution/data provenance | C.17 |
| evaluation and shadow strategy | C.18 |
| autonomy boundaries | C.19 |
| required Meta/business registrations and verification steps | C.20 |
| provider prerequisites | C.21 |
| production activation gates | C.22 and E |
| what can later become read-only / proposal-only / approval-gated / autonomously executable | C.23 |
| do not bind to one vendor endpoint or protocol; MCP only as an adapter | C.4 |
