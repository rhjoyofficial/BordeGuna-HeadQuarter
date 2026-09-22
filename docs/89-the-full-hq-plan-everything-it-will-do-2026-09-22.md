# 89 - The full HQ plan: everything Bor de Guna HQ will do (22 September 2026)

**Written for Joy, at his request (22 September, verbatim):** "What it will do when finished it's seems to me
shorter version what we needs. i want you to write full plan was more bigger. share, I will share plan again."

`docs/68` section 2a gave a short answer, one paragraph per phase. This document is the long answer: every
capability HQ is planned to have, organised by the work of the business rather than by phase, each with a real
example, the system it reads or acts on, who approves it, which phase builds it and where it stands today.

Where it comes from. Everything here is read from the repository on 22 September 2026: `docs/00` (the original
vision), `docs/03`, `docs/08`, `docs/10`, `docs/14` to `docs/23`, `docs/30`, `docs/31`, `docs/68`, `docs/70`,
`docs/72`, `docs/79`, `docs/82`, `docs/84`, `docs/85`, `docs/87`, `docs/88` A and the ADRs. **Anything that goes
beyond what those documents already plan is labelled PROPOSED, for Joy and ChatGPT to confirm.** Nothing labelled
PROPOSED will be built until one of you says so. Figures in examples are illustrations, not readings.

Joy will share his own plan again. Section 9 says how it is merged in: anything in it that is missing here becomes
a new row, and nothing in it is dropped.

---

## 1. What HQ is, who uses it, and the promise

### 1.1 The head office above the street

Think of the group as shops on one street. Bor de Guna runs its ERP (stock, money, staff, sales, purchase,
manufacturing). LaLaDia and Bionic Garden are online shops. Econic sells electronics on WooCommerce today and will
be rebuilt as a multi-seller shop. Azmion is the technical services brand. Each has its own till and its own books.

Until September there was no office above the street. Every question about "the whole group" meant opening five
systems. HQ is that office. It is a separate website (`hq.bordeguna.com`) that:

- **watches** every business and tells the owner what needs attention,
- **answers** questions in Bangla or English from live records, never from memory,
- **acts** through controlled tools, each action checked against rules and a named person's authority,
- **schedules** work that must happen later or every day, and makes sure it happens once,
- **remembers** the owner's preferences, decisions and approved procedures, so it grows more aligned over time,
- **writes down** why every action happened, who allowed it, and what came of it.

It never becomes the truth. The ERP still owns stock, money and staff. The shops (and later the ERP's own website
module) own orders. HQ holds dated copies, and every copy carries its age.

### 1.2 Who uses it

| Who | What they do in HQ |
| --- | --- |
| **The owner** (the boss, C M Moin) | Reads the briefing, asks questions, approves what only the owner may approve, sees exceptions |
| **The operator** (Joy) | Holds every approval right so nothing is left blank (`docs/31` Q22); runs agents, credentials, policies and releases |
| **Brand managers** (later) | Their brand's content, campaigns, conversations and approvals, nothing else |
| **Support staff** (later) | The inbox: take over a customer thread, send or edit a suggested reply, hand back to the AI |
| **Finance viewer** (the accountant) | Reports and cost dashboards, read-only |
| **AI agents** | Each is a role with a job description and a list of doors its key opens, never wider than the person who enabled it |
| **Customers** | Never log in to HQ. They meet it indirectly: a reply on Messenger, a WhatsApp answer, an order confirmation |

### 1.3 The promise

1. **One view.** Every business on one page, every number traceable to the record it came from.
2. **One assistant.** One place to ask, in your own words and your own language.
3. **Every action governed.** Nothing reaches a customer, a campaign budget, a payment or a record without passing
   policy, permission, consent and approval, and every action is audited.
4. **Nothing moves money or speaks to a customer on HQ's own authority** until autonomy is earned from measured
   evidence, one step at a time, and each step is signed by the owner. Some things never become autonomous at all
   (section 6).

---

## 2. How to read the capability map

**Status of each capability today:**

- **LIVE**: running in production now (read-only work only; nothing AI is switched on).
- **BUILT, INERT**: the code exists in production and is switched off on purpose until its gate passes.
- **IN PROGRESS**: being built now, in one of the seven parallel streams started on 22 September (`docs/88` B).
- **PLANNED**: designed in a repository document, not yet started.
- **PROPOSED**: implied by the vision but planned nowhere yet; for Joy and ChatGPT to confirm.

**Risk tier** (ADR-017): R0 pure computation; R1 reading HQ's own records; R2 reading an outside system; R3 writing
only inside HQ; R4 an outside change that can be undone; R5 material or irreversible (money, a message a customer
sees, a publication, a price, a cancellation, a deletion).

**Who approves:** AUTO (no approval), APPROVAL (a named person approves this exact action), HUMAN_ONLY (only a person
does it; HQ offers no tool at all, at any autonomy level, ADR-025).

**Autonomy ladder** (ADR-020): A0 shadow (the agent only drafts, a person does everything), A1 propose, A2 each action
approved, A3 bounded (acts alone inside limits the owner set), A4 review afterwards, A5 exists on paper and is
unreachable. Every agent starts at A0. Every step up needs evidence and the owner's signature (ADR-048).

**Phase and stream:** the roadmap phases 0 to 10 (`docs/30`) and the streams of `docs/88` (Q channels, R orders, S ads,
T research and content, U voice and avatar, V screens, W the Gate 13 correction).

---

## 3. The full capability map, by business function

### 3.1 Owner command center

**Morning briefing.** One page at 09:00 Dhaka: yesterday's orders on LaLaDia and Bionic Garden, the ERP's sales and
stock alerts, what is waiting for approval, which data is stale and why, what needs a person today. Example: "LaLaDia
14 orders (3 cancelled COD), Bionic 6; mustard oil 1 L below reorder level; 2 approvals waiting; ERP snapshot 40
minutes old." Reads: ERP, shops, HQ facts. Risk: R1 and R2, AUTO. Phase 2. **BUILT, INERT** (the Executive agent;
live after Gate 13, P3-G2 and the seven-day shadow week).

**Ask HQ, in Bangla or English.** Type a question in your own words and get an answer built from live records, with
the source of every number. Examples: "গতকাল LaLaDia তে কয়টা অর্ডার এসেছে?", "which Bionic orders are still unpaid
after two days?", "how much hilsa pickle did we sell this month compared with last month?". Reads: ERP, shops, HQ
facts. Risk: R1 and R2, AUTO. Phase 2 (M6). **BUILT, INERT** behind the `ask_hq` flag.

**Scheduling in words.** "Every morning at 8 check LaLaDia's orders", "remind me on the 23rd to lock payroll". HQ
turns it into a job with a preview card, and the job exists only after you confirm it. Ambiguity always becomes a
question back, never a guess. Reads/writes: HQ scheduler. Risk: R3 to create the job; the job's own tool keeps its
own risk and approval. Phase 2 (M6). **BUILT, INERT**.

**Alerts on Telegram.** HQ's own bot `@bordeguna_hq_bot` sends alerts: a shop token rejected, the scheduler silent,
a backup failed, a fact mismatch between a shop and HQ. Deduplicated until resolved. Risk: R3 (internal
notification). Phase 2 (M7). **LIVE**.

**Scheduled reports.** A weekly sales summary per brand, a month-end pack for the owner, a COD cancellation report by
district. Delivered in-app and on Telegram. Risk: R1 and R2. Phase 2 skill `generate_report` plus Phase 5 analytics.
**PLANNED** (`docs/08`, `docs/23`).

**What changed since I last looked.** A short list of what moved since the owner's last visit: new approvals, orders
above a size, a stock-out, a failed job, an agent refusal worth reading. Risk: R1. **PROPOSED** (implied by `docs/00`
"tells the owner what needs attention"; no document plans the diff view itself).

**Approvals inbox.** Every action that needs a person lands here with the exact payload, the reason, the evidence and
the risk. Approve, reject or ask for a change; the approval binds a hash of the exact payload, so a changed action
needs a new approval (ADR-018). Phase 2 (M3). **LIVE** (exercised with a demo tool; real tools arrive per phase).

**Observability dashboards.** Four read-only dashboards: AI health (runs, cost, refusals), connector health,
scheduler and jobs, business facts freshness. Phase 2 (M7). **LIVE**.

**Cost per unit.** AI cost per conversation, per AI-taken order, per content item, per campaign, per brand, beside
the business value it produced (`docs/26`). Risk: R1. Phase 2 foundation LIVE (every model call is costed); the
per-unit rollups **PLANNED** with Phases 3 to 6.

**Operator screens in Bangla and English.** Readiness, execution context, evidence, approvals, capability state,
role restrictions, autonomy evidence, provider readiness, shadow status, failures and reconciliation, audit
references; English digits in both languages. Stream V. **IN PROGRESS**.

### 3.2 Sales and orders

**Orders across every store in one list.** LaLaDia, Bionic Garden and the ERP counter side by side, each order with
its store, channel, payment state, delivery state and age. Reads: shops' admin APIs and events (`order.created`,
`order.status_changed`, `order.updated`), ERP sales. Risk: R2. Phase 1. **LIVE**.

**Sales questions and comparisons.** "Which product sold most on Bionic Garden last week?", "LaLaDia sales this Eid
week against last Eid week", grouped by store, product, source or day, with the coverage behind every figure stated.
Tool `facts.query_orders`. Risk: R1. Phase 2. **BUILT, INERT** (answers through the Executive and Analytics agents).

**Gross versus delivered revenue, always.** About a third of COD orders are cancelled (`docs/03`), so every revenue
figure shows gross and delivered side by side and never one alone. Phase 2 facts, Phase 5 reports. **LIVE** in the
facts; the reports **PLANNED**.

**Product, price and availability answers.** Product, variant, SKU, price at a given store and quantity, delivery
charge, and whether the product is orderable (status-based, never stock quantity, `docs/31` Q9), read live from the
ERP. Phase 4 read side. **BUILT, INERT** (`erp.read_price` and siblings; the Phase 4 read token is Joy's to issue).
⚠ Since 21 September a dealer, distributor or corporate customer with no group price gets no price, by the ERP's rule.

**An order taken in conversation, as an ERP draft.** The customer says "2 ta nibo". The agent builds a proposal; the
ERP (not HQ) re-checks product, price, stock, delivery charge, address and phone and stores a draft with no order
number, no stock move and no journal. Any figure that differs is refused, never rewritten. Tool `commerce.take_order`,
R4, APPROVAL, and the person who asked can never approve it, owner included. Phase 4. **BUILT, INERT** on the HQ
side; the ERP side is on ERP master, not yet on ERP production (Joy's line when Phase 4 needs it).

**Quote, read back and cancel.** `commerce.quote_order` (the ERP prices it), `commerce.get_order_status` (read back by
reference), `commerce.cancel_sales_order` (refused by the ERP once a delivery exists). An approved draft becomes a
real ERP sales order only through the ERP's own approval path. Phase 4, stream R. **IN PROGRESS**.

**Second approver.** No order can be approved by the person who requested it. With no second approver named, no
order can ever be approved, by design. Decision: Joy (section 7.5).

**Payment link and courier booking.** bKash, Nagad or a gateway payment link, and a Pathao, Steadfast or RedX
booking, are the ERP's responsibility (the system that owns the order). HQ only reads the resulting payment and
delivery state and tells the customer. Phase 4, ERP programme. **PLANNED** (needs Joy's S6 gateway and S7 courier).

**Order follow-up.** After delivery: a thank-you and review request (consent-checked), a reorder nudge after 30 days
(approval for first-time customers). Workflow `order_lifecycle_followup`. Risk: R5 (customer message), APPROVAL.
Phase 4. **PLANNED** (`docs/22`).

**Operator-taken orders through HQ.** Before any agent proposes an order, a person takes orders through HQ on one
store that lives on the ERP website module, and the first one is checked by hand (gate P4-G2). **PLANNED**.

**Dealer and corporate quotations.** Draft a quotation for a dealer from the ERP's price groups and send it for
approval. Reads/writes: ERP CRM quotations. Risk: R4, APPROVAL. **PROPOSED** (the `sales` agent in `docs/08` has
no phase).

**COD risk flag.** Before dispatch, flag a COD order whose phone has cancelled before, or whose address does not
match the district, so a person can call first. Reads: ERP and shop order history. Risk: R1, a flag only.
**PROPOSED** (the COD cancellation problem is in `docs/03` and `docs/19`; this flag is planned nowhere).

### 3.3 Customer service

**One inbox for every channel.** Messenger, Instagram DMs, Facebook comments, WhatsApp and later web chat and voice
notes land in one inbox, each thread with its state (AI active, waiting, human required, human active, resolved,
closed), the customer's history in a side column, and a provenance badge on every message. Phase 3. **BUILT, INERT**
(`/inbox`; while the support agent is paused every thread lands with a person).

**Suggested replies from live facts.** A customer writes in Banglish on the LaLaDia page: "hilsa pickle er dam koto?
Dhaka te delivery koto din?" The support agent reads price and delivery charge through HQ's tools and proposes ONE
reply in the customer's own script (Bangla for Bangla, Latin for Banglish or English). A person sends, edits or
discards it; every choice is recorded as a decision that teaches alignment. Tool `messaging.send_reply`, R5 when
sent, APPROVAL at A0 to A2. Phase 3. **BUILT, INERT** (P3-G2 approved to run now, `docs/88` A.17).

**Hand-over to a person, instantly.** Deterministic triggers in the core, not in the model: the customer asks for a
person (Bangla or English), refund, payment or complaint words, a second message while waiting, the agent saying it
cannot serve. The thread moves to a person with the transcript, the customer profile and the order facts, and a
Telegram ping. "Resume AI" hands it back with the full transcript. Phase 3. **BUILT, INERT**.

**Messenger and Instagram sending.** Real send adapters (Graph API) with idempotency, throttling and delivery
verification: a message counts as sent when Meta's delivery webhook says so, not when the request returned 200.
Stream Q. **IN PROGRESS** (registered nowhere until P3-G4, which needs Meta verification and App Review).

**WhatsApp.** Inbound normaliser, send adapter and its own tool `messaging.send_whatsapp_reply` with the 24-hour
customer-service window, template messages outside it, and consent. Stream Q. **IN PROGRESS** (live needs Joy's S1
verification and S3 SIM).

**Facebook comments.** Classify each comment (question, order intent, complaint, spam, praise); answer questions with
live facts, move order intent to a private reply, escalate complaints, hide spam (approval at first). `docs/18`.
Phase 3. **PLANNED**.

**Channel shadow beside the old bot.** Meta keeps sending to n8n; n8n forwards a copy to HQ; HQ proposes, humans
reply as today, and every HQ proposal is paired with what was really sent, for at least a week (gate P3-G3). Stream
Q builds the copy-forward. **IN PROGRESS** (the gate itself needs the Meta app).

**Delivery receipts and read state.** Sent, delivered, read, failed, stored per message and reconciled daily. Stream
Q. **IN PROGRESS**.

**Web chat on the shops.** A chat widget on laladia.com and bionic.garden using the same engine and the same
hand-over. `docs/14` "later". **PLANNED** (no date).

**Order status answers.** "amar order kothay?" The agent finds the customer's order by phone and reads its delivery
state. Reads: shop or ERP order and courier state. Risk: R2. Phase 3 read tools plus Phase 4 status. **PLANNED**.

**Complaint to ERP ticket.** A complaint in the inbox becomes a ticket in the ERP CRM with the transcript attached.
Risk: R4, APPROVAL. Phase 4 (`docs/30`). **PLANNED**.

**Customer-facing answers never invent.** No product, price, stock, delivery date or refund promise that a tool did
not return; no testimonials or reviews generated, ever (`docs/03`). A rule of every phase.

### 3.4 Inventory, supply and manufacturing

**Low stock watch.** `inventory.low` from the ERP or a shop raises an alert and a line in the briefing: "mustard oil 5
L: 3 left at the main warehouse". Reads: ERP stock, shop sellable view. Risk: R1 and R2. Phase 1 events **LIVE**;
the agent's use **BUILT, INERT**.

**Shop versus ERP stock disagreement.** The shop's sellable view and the ERP's accounting view are not synchronised;
HQ shows both and flags when they disagree, never "fixes" either (`docs/15`). **PLANNED** (as a report; the data is
already read).

**Reorder suggestions.** From sales velocity and current stock, suggest what to buy and how much, as a proposal the
purchase officer reviews. Skill `low_stock_sweep`, agent `inventory`. Risk: R1 for the suggestion, a purchase
order draft R4 with APPROVAL. Phase 4 (`docs/30`). **PLANNED**.

**Stock protection for ads.** When a product goes low or inactive, pause its ads (policy-gated) and resume them when
restocked. Workflow `stock_protection`. Risk: R4 (a pause), APPROVAL until evidence. Phases 4 and 5. **PLANNED**.

**Supplier follow-up drafts.** "The Barguna supplier's hilsa batch is 4 days late": HQ drafts a follow-up message for
a person to send. HQ never messages a supplier itself (the Executive's role forbids outbound messages). Risk: R3
draft. **PROPOSED** (the `procurement` agent exists in `docs/08` with no phase).

**Purchase planning for seasons.** Before Eid, the hilsa season or Pohela Boishakh, a plan of what to stock from last
year's same weeks and this year's trend. Risk: R1. **PROPOSED**.

**Expiry and batch watch.** Pickles, oils and food products carry batches (the ERP has FIFO batch layers); flag
batches near expiry so they are sold, bundled or written off by a person. Risk: R1. **PROPOSED**.

**Manufacturing yield watch.** The ERP's manufacturing module records input and output; flag a batch whose yield
falls outside the usual range (for example mustard oil pressing). Risk: R1. **PROPOSED**.

**Stock count follow-up.** After an ERP cycle count, summarise variances by product and bin for the manager. Risk:
R1. **PROPOSED**.

### 3.5 Finance oversight (read-only; HQ never moves money)

**Cash position across ledgers.** One line per money place: BRAC bank, bKash, the till, each with its ledger balance
and the time it was read. The ERP now keeps a ledger for every cash box, bank account and mobile wallet. Reads: ERP
accounting. Risk: R2. **PROPOSED** as an HQ view (the `finance` agent is in `docs/08` with no phase; the ERP data
exists).

**Receivables and payables ageing.** Who owes Bor de Guna, who Bor de Guna owes, by age, with the biggest and oldest
first. Reads: ERP ageing reports. Risk: R2. **PROPOSED** (ERP has the reports; HQ planned only "read-only finance
summaries", `docs/03`).

**Anomaly flags.** An expense far above its usual size, a journal posted on a Friday night, a supplier paid twice in
a week, a refund without a return. A flag for a person, never a correction. Risk: R1. **PROPOSED** (listed as the
`finance` agent's KPI in `docs/08`).

**Expense claims oversight.** Claims waiting too long, claims above a threshold, repeated claims for the same thing.
Reads: ERP expense claims. Risk: R2. **PROPOSED**.

**Payroll cycle checks.** Around the 24-23 cycle: attendance incomplete, a salary changed since last month, an
advance not recovered. Read-only summaries; every HR and payroll action stays HUMAN_ONLY (`docs/03`). Risk: R2.
**PROPOSED**.

**Recurring expense reminders.** The ERP already predicts and reminds (rent, hosting); HQ can carry those reminders
into the briefing. Risk: R2. **PROPOSED**.

**Month-end pack for the accountant.** P&L, balance sheet, trial balance and the ledger balances as one read-only
pack, plus a list of what looks unfinished. Risk: R2. **PROPOSED**.

**Journal proposals only.** Where HQ sees something to post (for example ad invoices), it may propose a journal for
the accountant; it never posts one (`docs/03`). Risk: R3 proposal. **PLANNED** in principle, no phase.

**Never:** a payment, a transfer, a refund, a salary change, a price change on its own authority (section 6).

### 3.6 Marketing and ads

**Ad results beside real sales.** Every day, per brand: spend, reach, clicks, landing views, orders, delivered
orders, cost per delivered order, from Meta's insights beside the ERP's delivered orders. Tools `ads.read_insights`,
`ads.read_campaigns`, R2, AUTO once registered. Phase 5, stream S. **IN PROGRESS** (live needs Joy's system user token
and ad account confirmations, O-3, O-5).

**Contribution profit, not ROAS.** Delivered revenue minus product cost, shipping, payment fees, cancellation
effects and allocated ad spend, per campaign, product and brand (`docs/23`). Needs ERP cost prices (O-8). Stream S.
**IN PROGRESS**.

**Budget guard.** The monthly ads budget per business set by the owner, checked against spend; the ad account's
spending limit read (never written) as the outer ceiling (ADR-050, accepted 22 September). Risk: R1 and R2, a read
only. Stream S. **IN PROGRESS**.

**Waste flags.** "This ad set has spent for three days with no order", "clicks but few landing views" (the
click-to-view leak found on 1 September). A flag and a recommendation for a person. Phase 5. **PLANNED**.

**Prepare a campaign, paused.** From an experiment (objective, audience, offer, creative, budget, hypothesis, KPI),
HQ prepares a campaign paused and inside a campaign spending limit, for approval. Risk: R4, APPROVAL. Gate P5-G2.
**PLANNED** (inert first).

**Pause, lower a budget, upload a creative.** R4, APPROVAL, each with precondition checks, the exact proposal,
authorisation, execution, read-back and reconciliation, and no silent repair (ADR-050). **PLANNED**.

**Raise a budget or switch a campaign on.** R5, APPROVAL at minimum; only a person moves money. Whether switching on
may ever go above A2 is an owner decision in Phase 9 (`docs/84` I.4). **PLANNED**.

**Campaign guardian.** Hourly: sync, spot anomalies against a 7-day baseline, diagnose, recommend, apply within
guardrails or ask; never touch a campaign in its first 48 hours except to stop a loss. The first automatic guardrail
(a pause at a cost the owner sets) comes only at gate P5-G5, through an evidence policy and a grant. Phase 5.
**PLANNED**. ⚠ `docs/16`'s AUTO budget-raise example is superseded by ADR-050: raising spend is never automatic.

**Marketing experiments record.** Every campaign carries its hypothesis, KPI, result and the decision taken (scale,
hold, kill), so HQ knows why, and the platform knows what happened. Phase 5. **PLANNED**.

**Customer lists for ads.** Upload only with per-person marketing consent; not in the first release (R5). **PLANNED
later**.

**Google Ads, TikTok.** Behind the same adapter interface, after a product feed exists (`docs/31` Q17, Q18). X: no.
**PLANNED later**.

**Never:** billing, payment methods, the account's hard spending limit, adding or removing people, account
ownership, legal identity, deleting an ad account. These are not tools at all (ADR-050).

### 3.7 Content factory and publishing

**Posts from product facts.** Drafts of posts, captions and product text in Bangla and English, built from the ERP's
own facts (weight, ingredients, price) and nothing else; a figure not in the evidence is refused. Tool
`content.create_draft`, R3. Phase 6, stream T. **IN PROGRESS**.

**Claims policy per product line.** What may and may not be said about each product, for example mustard oil and
health, honey and medicine, pickles and "no preservatives". Every draft is checked against it; HQ never writes the
policy itself. Stream T builds the check; **the policy is Joy's decision** (section 7.5).

**Images and product cards.** Product-on-template cards rendered in a real browser, because the old renderer broke
Bangla letters; product photos come from the asset library and are never generated. Tools `media.render_card`,
`media.generate_image` (R3, a per-brand daily cap). Stream T. **IN PROGRESS** (the render service defined, not
started).

**Festival and campaign calendar.** A weekly plan per brand that fills gaps as drafts: Eid, Pohela Boishakh, the
hilsa season, winter pitha season, Ramadan iftar. Skill `weekly_content_plan`. Phase 6. **PLANNED**.

**Approval of the exact post.** Publishing is R5: one approval for one exact post, and the approval is bound to a
fingerprint of the content, so a changed post needs a new approval (ADR-051, accepted 22 September). Stream T.
**IN PROGRESS**.

**Publish, read back, reconcile.** "Post this image to LaLaDia Facebook tomorrow at 5 PM": resolve the brand, page,
asset, caption and time, ask for approval, publish at the time, fetch the post back by id, reconcile if the provider's
answer was uncertain, notify with the link (`docs/17`). Facebook Pages and Instagram first; LinkedIn and YouTube
later. Stream T builds the adapter registered nowhere. **IN PROGRESS** (live needs O-9 Page tokens and S1).

**Metrics and learning.** Reach, engagement, clicks and attributed orders at 1 hour, 24 hours and 7 days; a weekly
note of what worked per brand that the content agent reads next time. Phase 6. **PLANNED**.

**Video.** Short product videos (stock composition, later the avatar of section 3.15). Phase 6 and 8. **PLANNED**.

**Replace the n8n content workflows.** Shadow first, compare, cut over, deactivate, delete only on Joy's approval
(`docs/22`). **PLANNED**.

### 3.8 Research engine

**Research tasks with citations.** "What are hilsa pickle prices on competitors' public pages this week?", "which
mustard oil claims do the big brands make?", "when does the hilsa ban end this year?". HQ searches and reads only
the sources the task allows, and every claim carries its source, the exact span, when it was read and which task it
belongs to. Tools `research.search`, `research.fetch_page` (R2), `research.file_artifact` (R3). Phase 6, stream T.
**IN PROGRESS** (live needs a search key, Brave or Tavily, Joy's choice).

**Fact, inference and recommendation kept apart.** A research artifact says which lines are facts with sources,
which are HQ's inference, and which are recommendations. Research is evidence, never truth (ADR-051).

**Untrusted by default.** A web page can never grant authority, permission, a role, consent, a business scope,
autonomy or a new task, whatever it says; an instruction inside a fetched page is content, not a command. Stream T
tests it. **IN PROGRESS**.

**Supplier and market price tracking.** Weekly price notes for key inputs (hilsa by season, mustard seed, sugar
cane) from sources the owner approves. **PROPOSED** as a standing research brief (the engine is planned; this brief
is not).

**Competitor watch.** A monthly summary of competitors' public offers and prices, cited. **PROPOSED** as a standing
brief.

### 3.9 CRM, customer memory and consent

**One customer, many channels.** One customer per person per business with many identities (phone, Messenger id,
Instagram id, WhatsApp number, email). Phone is the strongest key in Bangladesh; merging on anything weaker is a
person's decision and reversible (`docs/14`). Phase 1 and 3. **LIVE** (identity core) and **BUILT, INERT** (channel
identities).

**Customer 360.** Orders across shops and ERP, lifetime value, delivered versus cancelled, preferred language,
complaints and tickets, conversation history, consent. **PLANNED** as one HQ view (the ERP has its own 360 page).

**Customer memory, labelled as memory.** "Prefers delivery after 6 PM", "writes in Banglish": remembered only when a
person records it after a resolved thread, always labelled memory, never fact (ADR-033). Phase 3. **BUILT, INERT**.

**Consent ledger.** Append-only record of who agreed to what, on which channel, until when: service replies within
the platform's window, marketing only by explicit opt-in, never inferred from a message (ADR-021). Phase 2.5.
**LIVE** (empty until customers exist).

**Lead follow-up.** Leads in the ERP CRM that nobody answered in 48 hours, surfaced with a suggested next step.
**PROPOSED** (the `sales` agent in `docs/08` has no phase).

**Win-back and segments.** Customers who bought pickle before Eid last year and not since, as a consent-checked list
for a campaign the owner approves. **PROPOSED**.

**SMS and email campaigns.** The ERP already has a phone book and SMS/email campaigns (approval above 50
recipients). HQ could draft the message and pick the segment; the ERP sends. **PROPOSED** (needs S5 SMS masking).

### 3.10 HR oversight (read-only, no decisions)

**Attendance and leave summaries.** Who is absent today, late patterns, leave balances, from the ERP. Risk: R2.
**PROPOSED** (the `hr` agent in `docs/08` has no phase; HR decisions are HUMAN_ONLY, `docs/03`).

**Payroll cycle readiness.** Before the 24-23 cycle closes: missing attendance, unrecovered advances, pending
expense reimbursements. Risk: R2. **PROPOSED**.

**Recruitment status.** Open positions and candidates by stage, from the ERP's recruitment module. Risk: R2.
**PROPOSED**.

**Rule:** HQ inherits the ERP's permission map; employee and payroll data are visible only to people who already
hold the ERP permission. HQ never widens it, and never takes an HR action.

### 3.11 Data quality and integrity

**Fact integrity checks.** HQ compares its copy of each order with the shop's own record and reports any
discrepancy (`hq:facts:check`; 16 of 16 matched when last recorded, 8 September). Phase 1. **LIVE**.

**Credential readiness.** Every connection's state (verified, rejected, unverified, unsupported by design) on one
table, with the last successful use. Phase 2.5. **LIVE** (6 verified, 0 incidents on 22 September).

**Stale data warnings.** Every figure carries its age; a briefing says when a source is stale and why. **BUILT,
INERT** in the briefing, **LIVE** in the facts.

**Catalogue gaps.** Products without cost prices, without barcodes, without a price for dealer groups: the ERP data
gaps that block reports, listed for the person who fixes them. **PROPOSED** (the gaps are known; no HQ view is
planned).

### 3.12 Security, audit and compliance

**Every action audited.** Who asked, which agent, under whose authority, which tool, with what payload, what the
policy said, who approved, what happened, and the correlation id that ties it together. **LIVE**.

**Two layers of boundaries around the AI.** Before the model: everything that can be decided from the operator's own
words (no task, out-of-role requests, role expansion, security intent, prohibited requests). After the model: the
authoritative policy, permission, execution context and capability checks, then the gateway. Neither layer can be
weakened by model text (`docs/88` A.9). Stream W. **IN PROGRESS**.

**Retention.** Audit and decision records forever; tool calls and model runs 180 days then daily aggregates; a
nightly, deterministic, auditable retention job. Phase 2 (M7). **LIVE**.

**Credentials in a vault.** Every key and token stored encrypted, replaced from the screen, never shown back, never
in code. Phase 0 to 2.5. **LIVE**.

**Platform policy.** Meta's messaging windows and templates, BTRC rules for SMS masking and phone numbers, consent for
recordings. Built into the channel adapters and the consent ledger. Phases 3 and 7.

**Backups.** Daily database dumps with verification, a dump before every release. **LIVE**. Off-server copies (R2)
**PLANNED** (N6).

**A staging copy of HQ.** Customer-facing AI must not be tested first on production. Stream Q defines it; running it
needs the server upgrade (N3). **IN PROGRESS** (definition).

### 3.13 Automation and scheduling

**A durable scheduler.** One-time, recurring, interval and event-triggered jobs in Dhaka time, with retry, missed-slot
rules, run history, pause, resume and cancel; each slot runs once, and an uncertain outside result is reconciled
before any retry (ADR-011). Phase 0 and 2. **LIVE** (the ERP polls, shop reconciliation and housekeeping run on it today).

**Workflows with waits and approvals.** Sequences that wait for an approval, an event or a time and survive restarts:
message to order, order follow-up, campaign guardian, content publish, stock protection, daily briefing (`docs/22`).
Phases 3 to 6. **PLANNED**.

**Skills.** Repeated work becomes a tested, approved procedure that can run cheaply (`docs/10`). The skill proposal
loop (HQ notices a repeated pattern and proposes a skill for the owner to approve, never self-activating) is Phase 9.
**PLANNED**.

### 3.14 Voice

**Bangla voice notes.** A voice note on Messenger or WhatsApp is transcribed (Google Speech-to-Text, bn-BD) and
handled exactly like the same words typed: no more authority because it was spoken. Stream U. **IN PROGRESS**
(inert; needs a Google Cloud key later).

**Spoken replies.** A natural Bangla voice (ElevenLabs, or Azure bn-BD as the cheaper fallback) for replies that the
customer hears. Stream U builds the interface registered nowhere. **IN PROGRESS**.

**COD confirmation calls.** The highest-value voice case: before dispatch, call the customer to confirm a COD order,
disclosing that it is an AI assistant, only between 09:00 and 20:00, at most two attempts (`docs/19`). Phase 7.
**PLANNED** (needs a BTRC-licensed local number, slow).

**Inbound receptionist.** Identify the caller by phone, answer order status and product questions, take an order,
hand over to a person. Phase 7. **PLANNED**.

**Owner voice commands.** Ask HQ by Telegram voice note first, phone later (`docs/19` use case 4). **PLANNED**.

### 3.15 Avatar

**A consented video presenter.** Short product videos for Reels and YouTube spoken by a presenter: a synthetic
presenter by default, the boss's own face and voice only with written consent (`docs/31` Q21). No identity, no
generation; revocation stops everything scheduled with it at once (`docs/20`). Phase 8, stream U builds the consent
record design. **IN PROGRESS** (design) and **PLANNED** (build, after Joy's decision).

**YouTube channel publishing.** Through the same publishing adapter with approval of the exact video. Phase 8.
**PLANNED** (needs H2).

### 3.16 Measured autonomy

**Shadow first.** Each agent starts at A0: it drafts, a person does everything, and every draft is paired with what
the person really did. The Executive's seven-day shadow week opens only after Gate 13 and P3-G2 pass, only by Joy
with `--by`, with twice-daily probes and hard stops. **BUILT, INERT** (`hq:shadow:open`).

**Evidence, not time.** An agent steps up one level only when its measured evidence (how many cases, how many passed,
how recent, per dimension) meets numbers the owner sets in a versioned evidence policy, and the owner signs the
grant (ADR-048). HQ ships with no numbers by design. **BUILT, INERT** (the policy, the producer and the evidence view).

**Per task, per channel, per value.** Autonomy is granted narrowly: "may answer order-status questions on Messenger
for returning customers during business hours, never refunds, never above a value", and the lowest of all limits
always wins (ADR-020). **BUILT** (the resolver).

**Automatic stop.** If a safety condition breaks, HQ switches the agent off at once and writes down why, without
waiting for anyone. **BUILT** (the shadow's hard stops).

**Never above A4, never HUMAN_ONLY.** A5 is unreachable, and the permanent human-only list never widens.

### 3.17 Multi-business expansion and the platform

**Shops through the ERP.** The long-term shape: every shop operates through the ERP's website module, so HQ talks to
one system of record for orders (ADR-046; per-shop adapters are a bridge until then). **PLANNED** (an ERP programme
item).

**Econic rebuilt as a multi-seller shop.** Econic's own products plus LaLaDia, Bionic and other Bor de Guna products,
later other sellers, behind the same commerce interface (ADR-009, `docs/31` Q6). HQ integrates when it exists.
**PLANNED**.

**Onboarding a new business or shop.** Add a business, brand, store and platform accounts, connect credentials, and
it appears in the briefing, the facts and the inbox, without code changes to the agents. **PLANNED** (the entity model
exists, ADR-010).

**Provider independence.** Any AI provider, any tool protocol, any vendor stays replaceable; the business and control
contracts stay. HQ runs on OpenAI today and can return to Anthropic on Joy's decision (ADR-049). **LIVE** (principle
and router).

---

## 4. The agents

Agents are configuration, not code: one runtime, one row per agent with its role, prompt, tools, memory scope and
model tier. None talks to another; the Executive delegates by starting a workflow (`docs/08`). Each starts at A0.

| Agent | Its job | Its tools (planned) | It may never | Gate before it acts | Today |
| --- | --- | --- | --- | --- | --- |
| **Executive** | Briefings, priorities, cross-business questions | every read tool, reports, notifications | send a message outside HQ, publish, reply to a customer, take on another role (roles v2), move money | Gate 13, P3-G2, then the seven-day A0 shadow | BUILT, paused A0 |
| **Analytics** | KPIs, trends, comparisons with coverage stated | the facts tools, reports | the same forbidden classes, as its own role profile (`docs/88` A.10) | measured with the Executive's changes in Gate 13 | BUILT, paused A0 |
| **Support** | Customer conversations in the customer's own language | read tools, `messaging.send_reply`, later `send_whatsapp_reply`, later `take_order` proposals | promise what no tool returned, invent a product fact, handle money or complaints (hand over), expand its own role | P3-G2 now, then P3-G3 to P3-G5, then P4-G3 for orders | BUILT, paused A0, no enabling user yet |
| **Operations** | Orders stuck past a time limit, delivery exceptions | order and courier read tools, request a human | book a parcel, refund, cancel after delivery | Phase 4 gates | PLANNED (`docs/30` Phase 4) |
| **Inventory** | Stock watch, reorder recommendations | ERP and shop stock tools, purchase order draft | raise a purchase order itself | Phase 4 gates | PLANNED |
| **Marketing** | Strategy, experiments, audience and offer hypotheses | analytics reads, prepare experiments | move ad money | P5-G4 (two weeks of proposals) | PLANNED |
| **Advertising** | Campaign operations inside guardrails | ads read, prepare, pause, lower budget | raise a budget or switch a campaign on (R5), touch billing or limits | P5-G2 to P5-G5 | PLANNED |
| **Content** | Posts, scripts, weekly plans per brand persona | research, drafts, render, draft posts | publish without approval of the exact post | P6-G4 (two weeks of proposals) | PLANNED |
| **Creative** | Image and video briefs and quality checks | media generation | generate a real person's likeness without consent | P6 and P8 gates | PLANNED |
| **Voice** | Phone and voice-note conversations | the support tools through the voice gateway | gain authority from being spoken to | P7 gates (proposed P7-G0 to P7-G3) | PLANNED, stream U designing |
| **Finance** | Receivables, cash, cost alerts, anomaly flags | ERP finance read tools | post, pay, transfer or correct anything | none planned yet | **PROPOSED** to schedule (`docs/08` has it, no phase) |
| **Procurement** | Supplier comparison, purchase order drafts, follow-up drafts | ERP purchasing tools | send to a supplier, commit money | none planned yet | **PROPOSED** to schedule |
| **Sales** | Lead follow-up, dealer quotations | ERP CRM tools, shop tools | send without approval, price below the ERP's rules | none planned yet | **PROPOSED** to schedule |
| **HR** | Attendance and leave summaries only | ERP HR read tools | take any HR decision | none; nothing autonomous ever | **PROPOSED** to schedule |

---

## 5. The phases 0 to 10

Three clocks decide when each phase goes live (`docs/87` A): **engineering** (what we build; parallel streams shorten
it), **Joy's outside items** (Meta verification, WhatsApp number, gateway, courier; only starting them shortens them)
and **calendar time by design** (the seven-day shadow week, P3-G3's week of paired proposals, two-week proposal
periods in Phases 5 and 6). Completion figures are rough estimates.

| Phase | Scope | Gates | Built | Left | Live depends on |
| --- | --- | --- | --- | --- | --- |
| **0 Foundation** | App, login, 2FA, roles, business/brand/store model, vault, audit, scheduler, Docker stack, backups | done | done | none | live |
| **1 Integrations** | ERP API, LaLaDia and Bionic adapters, events, identity, order facts | done | done | Econic (WooCommerce) unsupported by design | live (read-only) |
| **2 Command center** | Gateway, policy, approvals, model router, Executive and Analytics agents, briefing, Ask HQ, scheduling in words, dashboards, alerts, retention | Gates 1 to 12 run; Gate 13 next; then the shadow week | about 95% | the Gate 13 correction (stream W) | Gate 13 pass, P3-G2 pass, Joy opens the shadow, seven days |
| **2.5 Shared foundation** | Capability registry, consent ledger, autonomy resolver, evaluation, media assets, conversation and memory core | done | done | none | live (inert pieces stay off) |
| **3 Customer AI** | Inbox, hand-over, support agent, Messenger, Instagram, comments, WhatsApp, memory | P3-G1 (passed locally), P3-G2 (now), P3-G3, P3-G4, P3-G5 | about 65% | channel adapters (stream Q), comments, web chat | Meta verification (S1, up to 14 business days), App Review (S2), WhatsApp SIM (S3), staging (N3) |
| **4 AI orders** | Order proposals as ERP drafts, quote, status, cancel, second approver, payment and courier state, follow-ups | P4-G0 to P4-G4 | about 40% | ERP completion endpoints and HQ tools (stream R), the ERP website store | a store on the ERP website module, the second approver, S6 gateway, S7 courier |
| **5 Ads** | Insights, contribution profit, budget guard, experiments, prepared campaigns, guardian | P5-G0 to P5-G5 | about 10% | the read side (stream S), then inert writes | system user token (O-3), ad accounts and currency (O-5), spending limits (O-6), ERP cost prices (O-8) |
| **6 Content and research** | Research engine, drafts from facts, claims check, Bangla render, publishing, metrics | P6-G0 to P6-G5 | about 10% | stream T, then publishing gates | claims policy, source list, post approver, search key, Page tokens (O-9), Instagram accounts (O-10) |
| **7 Voice** | Voice notes first, then COD confirmation calls and a receptionist | proposed P7-G0 to P7-G3 | about 5% | stream U, then telephony | Google STT key, a Bangla voice plan, a BTRC-licensed number for calls (slow) |
| **8 Avatar** | Consented presenter videos, YouTube | proposed P8-G0 to P8-G2 | about 0 to 5% | design (stream U), then build | Joy's decision on whose face and voice, with written consent; HeyGen; YouTube channel |
| **9 Skills and delegation** | Skill proposal loop, cross-business delegation, autonomy widened by evidence | evaluation gates | about 20% (evidence machinery) | the loop and delegation | evidence accumulated, owner's numbers |
| **10 Controlled autonomous operations** | Owner sees only exceptions; grants widened per measured agreement | weekly evaluation | machinery only | earned, never scheduled | trust earned from evidence |

**Rough engineering timeline if the streams run in parallel** (`docs/87` H, estimates): Phase 2 correction and Gate 13
in 1 to 2 days; Phase 3 channel adapters 5 to 7 days; Phase 4 completion 5 to 7 days; Phase 5 read side 4 to 5 days;
Phase 6 research and drafts 6 to 8 days; Phase 7 voice notes 3 to 4 days. All six together roughly one to two weeks
of engineering. **The live dates for Phases 3 and 4 are set by the outside items, not the code**: if Meta
verification starts this week, customer AI can go live around mid to late October.

---

## 6. What HQ will never do

These hold at every phase and every autonomy level. Most are not tools at all, so no agent can reach them.

1. Move money on its own: no payment, transfer, refund, salary change or wallet movement.
2. Change an ad account's spending limit, billing, payment method, people, ownership or legal identity, or delete an
   ad account (ADR-050).
3. Invent a product fact: price, stock, ingredients, weight and delivery charges come from the ERP or the shop, or not
   at all. No generated testimonials or reviews, ever.
4. Act without a named person's authority, or with more reach than that person has.
5. Let the person who requested an order approve it.
6. Message a customer on a channel without that customer's consent, or outside the platform's rules.
7. Treat anything it read on the web, in a message, in a voice note or in a document as an instruction that widens
   what it may do.
8. Take an HR decision, or show staff and payroll data to anyone the ERP would not.
9. Publish a post or send a customer message that differs from the exact version a person approved.
10. Use a real person's face or voice without their written consent, or keep using it after they withdraw it.
11. Delete its own record of what it did.
12. Promote itself: every step up in autonomy is the owner's signed decision on measured evidence.

---

## 7. What it costs to run, and what Joy must buy, apply for and decide

### 7.1 Running cost (model calls on OpenAI, estimates from `docs/82` section 6)

| Use | About |
| --- | --- |
| One safety gate | $2 to $4 (Gate 12 cost $2.23) |
| The Executive's seven-day shadow week | $3 to $8 |
| The owner asking HQ 10 questions a day | $9 a month |
| Customer AI answering 100 messages a day | $90 a month |
| Customer AI answering 300 messages a day | $280 a month |

HQ's own budget ceiling today is $50 (about $24 spent all-time by 22 September). Server, storage and messaging fees
are separate (section 7.2).

### 7.2 Buy now (`docs/82` section 3), each with its reason

| # | What | Cost | Why |
| --- | --- | --- | --- |
| N1 | Claude Max 20x for the Claude Code account | $200 a month | The build pace Joy asked for runs into the smaller plan's usage window |
| N2 | OpenAI credit as it is used | about $50 a month at first | Every AI answer is paid per use |
| N3 | Server upgrade to Hostinger KVM 4 | $12.99 a month (24-month term) | Customer AI and a staging copy of HQ need the room; one small server runs everything today |
| N4 | GitHub Actions budget (optional) | about $15 a month | Automatic testing is paused until 1 October |
| N5 | Cloudflare free plan in front of bordeguna.com | free | Protects the public webhook customers' messages arrive on |
| N6 | Cloudflare R2 storage | free to 10 GB | Customer photos and voice notes, and a backup copy off the server |

### 7.3 Start this week, because they take weeks (`docs/82` section 4)

| # | What | Lead time | Why |
| --- | --- | --- | --- |
| S1 | Meta Business Verification | up to 14 business days | Without it only admins can message the app: no real customers, no ads access, no publishing |
| S2 | Meta developer app with Messenger, Instagram and Webhooks, then App Review | days to weeks after S1 | The only door to real customer messages |
| S3 | A new SIM never used on WhatsApp | days | WhatsApp Cloud API needs its own number; the channel customers use most |
| S4 | Dual-currency card from the business bank | days | Paying Meta, OpenAI and other dollar bills in the business name |
| S5 | SMS with a masking name (BTRC approval) | 1 to 3 weeks | OTP, order updates and campaigns by SMS |
| S6 | A payment gateway (SSLCommerz, aamarPay, or bKash and Nagad directly) | 1 to 4 weeks | Customers paying online for an AI-taken order; COD works without it |
| S7 | A courier API account (the courier the shops already use) | days | Telling the customer where the order is |

### 7.4 Later, about an hour each, when that phase starts (`docs/82` section 5)

Meta Marketing API and a system user for ads (Phase 5); ad account per brand with its currency and a spending limit
(Phase 5); Page tokens and Instagram professional accounts per brand (Phase 6); a search key, Brave or Tavily (Phase
6); image generation on the existing OpenAI account (Phase 6); Google Speech-to-Text and a Bangla voice plan (Phase 7);
a BTRC-licensed number for calls (Phase 7, slow); HeyGen and a YouTube channel (Phase 8).

### 7.5 Decisions only Joy (or the boss) can make

| Decision | Why it matters | When |
| --- | --- | --- |
| The second person who may approve orders | With nobody named, no AI order can ever be approved | before Phase 4 goes live |
| May an order be delivered to someone other than the buyer? | Decides the order form and the fraud rules | before Phase 4 goes live |
| Is COD the only method the assistant offers in conversation? | Decides the conversation script and the payment step | before Phase 4 goes live |
| Which ad account belongs to which brand, its currency, the monthly ads budget per business | HQ refuses an account whose brand or currency is unknown, and checks spend against the budget | before Phase 5 reads |
| The claims policy per product line | HQ never decides what may be claimed about mustard oil, honey or pickles | before Phase 6 drafting |
| The source list for research, and who approves posts per brand | The research engine reads only approved sources; publishing needs a named approver | before Phase 6 |
| The search provider | Brave or Tavily, on cost | before Phase 6 research |
| Whose face and voice the avatar uses, with written consent | No identity, no generation | before Phase 8 |
| The autonomy numbers per agent | HQ ships none; they are the owner's | when evidence exists (Phase 9) |
| How the accountant records wallet and gateway receipts, and VAT on ad invoices | The books stay correct when AI orders take money | before online payments |
| Whether to return to Anthropic | HQ runs on OpenAI; Anthropic needs its own full gate | only when Joy says |

---

## 8. Proposed additions (for Joy and ChatGPT to confirm)

The vision (`docs/00`: "watches every business, tells the owner what needs attention") and the agent registry
(`docs/08`) imply the capabilities below, but no phase or stream plans them yet. Each is read-only or draft-only
unless it says otherwise, and none will be built without a yes.

1. **Finance watcher agent**: cash position across BRAC, bKash and the till ledgers, receivables and payables ageing,
   anomaly flags, month-end pack for the accountant; read-only, never posts or pays.
2. **HR oversight summaries**: attendance, leave, payroll-cycle readiness and recruitment status from the ERP,
   read-only, inside the ERP's own permission map.
3. **Procurement agent**: supplier comparison, purchase order drafts, supplier follow-up drafts for a person to send.
4. **Sales agent**: unanswered ERP leads, dealer and corporate quotation drafts from the ERP's price groups.
5. **COD risk flag**: before dispatch, flag COD orders from phones that cancelled before or with mismatched districts,
   for a confirmation call by a person.
6. **Seasonal purchase and stock planning**: Eid, hilsa season, Pohela Boishakh, Ramadan plans from last year's same
   weeks and this year's trend.
7. **Expiry and batch watch** on food products, using the ERP's batch layers.
8. **Manufacturing yield watch**: flag a production batch whose yield falls outside the usual range.
9. **Stock count variance summaries** after each ERP cycle count.
10. **"What changed since I last looked"** view for the owner.
11. **Catalogue gap report**: products without cost prices, barcodes or group prices, listed for the person who fixes
    them.
12. **Standing research briefs**: weekly input-price notes (hilsa, mustard seed, cane sugar) and a monthly competitor
    watch, cited.
13. **Customer win-back and segments**, consent-checked, for campaigns the owner approves; SMS and email campaigns
    drafted in HQ and sent by the ERP (needs S5).
14. **Courier performance report**: delivery time, returns and cancellations by courier and district.
15. **Cash-flow outlook**: the ERP's recurring expenses and receivables turned into a 30-day view.
16. **Email as a customer channel**, after the mail provider decision (`docs/31` Q25).
17. **A phone-friendly HQ** (a progressive web app) so the owner can read the briefing and approve on the move.

---

## 9. How to give us your plan

Share your plan in the chat as it is, in Bangla or English, in any shape. I will:

1. read it line by line against this document;
2. mark each point as already here (with the section number), here but in a different form, or missing;
3. add every missing point as a new row in section 3 (if it fits a phase already planned) or section 8 (if it needs
   your and ChatGPT's yes first), with its example, its system of record, its risk tier and who approves;
4. change nothing you wrote, and drop nothing: if a point conflicts with a safety rule in section 6, I will say so
   and ask, not remove it;
5. then put the merged plan to ChatGPT, so the next instruction covers everything you want.

This document is updated in place when your plan arrives; it does not become a new file.
