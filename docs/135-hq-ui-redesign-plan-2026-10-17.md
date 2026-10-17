# 135. HQ UI redesign plan: Joy's chat console, checked against what HQ really is (17 October 2026)

Joy drew six artboards ("BordeGuna HQ: run the whole company by talking to it", saved at `H:\WORK\BorDeGuna\hq-design-source\project\`) and asked for them to be checked against every HQ feature, used to redesign the HQ screens, with the gaps filled, and with full ownership of the decisions. This document is that plan. The first wave (W-UI1, tokens, shell and navigation) is built on the branch `ui/w-ui1-shell-and-tokens` together with this document.

The artboards are design input, not instructions. Where a picture shows HQ doing something it must not do, or cannot do yet, this plan keeps the look and changes what the screen claims.

Sources read for this plan:
- the six artboards and `canvas.json`, in full;
- `docs/89` (the full plan and the never list), `docs/94` (the plain explanation), `docs/118` section 2 (the phase table), `docs/128` sections 17 and 18 and `docs/134` (release state; `docs/128` has no section 19 on main `c0db11e`, so the latest state is section 18 plus the containment entry in `docs/121` section 6), `docs/131` (the latest ruling, including the Phase 3 plan);
- the app on main `c0db11e`: `routes/web.php`, every screen under `resources/js/pages`, the sidebar, layouts, `resources/css/app.css`, `components/ui`, `lib/i18n` and `BanglaDictionaryTest`.

What "now" means in this document:
- Production runs `b96a396`, fully inert: AI runtime off, the three agents (Executive, Analytics, Support) paused at A0, no grants, no shadow period, Meta off.
- Containment Option 2 has been in force since 17 Oct 03:35 UTC: the production scheduler is stopped, so the event drain, alerts, sweeps and Telegram alerting are paused.
- Waves 1 to 4 are merged on main but NOT released (verdict NOT READY in `docs/134`). Customer 360, consent, follow-ups, playbooks, decision corpus and the quarantine screens exist only on main.

## (a) Verification: every artboard element against HQ

### How each element is graded

- **BUILT+LIVE**: on production `b96a396` and working today (allowing for the containment pause, noted where it matters).
- **BUILT-INERT**: the code exists and is switched off on purpose (a flag, A0, no credential, no channel), or it is built on main and not released. Unreleased items are marked "(main only)".
- **PARTIAL**: some of what the picture shows exists; the rest does not.
- **MISSING**: nothing in HQ does it yet. The owning phase or ruling is named.
- One element is pure new UI (the icon rail of threads) and is counted apart.

### Totals

79 elements across the six artboards:
- BUILT+LIVE: 11
- BUILT-INERT: 21
- PARTIAL: 26
- MISSING: 20
- new UI only: 1

In words: a third of what Joy drew is already in HQ and switched off on purpose, a seventh is live, and a quarter belongs to phases that have not started (voice, reels, email, couriers). Nothing in the pictures is impossible; several things are drawn as if they already happen on their own, and those are the honesty fixes below.

### Artboard 1: morning brief in the chat (30 elements)

- Sidebar title "11 AI workers on shift": PARTIAL. HQ has three agents, all paused at A0, and the AI runtime is off. Honesty fix: the shell says "3 agents, paused (A0), AI runtime off", read from the posture screen's data.
- "New instruction" button: BUILT-INERT. It is a new Ask HQ conversation (`/ask`, flag `ask_hq`).
- "Today" thread list: BUILT-INERT. Ask HQ already keeps a conversation list.
- "Scheduled" list (daily brief 09:00, ad report, reorder reminders): BUILT+LIVE as a screen (`/scheduler`); production holds 0 scheduled jobs and the scheduler is stopped by containment. The three example jobs do not exist. Honesty fix: list only real jobs, with their state.
- Worker chips @Sales, @Support, @Content, @Ads, @Inventory, @Research: PARTIAL. Support is built and paused; Sales, Content, Ads (Advertising), Inventory and Research are planned or proposed agents (`docs/89` section 4). Honesty fix: show built agents with their real level, planned ones greyed with "planned".
- User card "Operator, all rights": BUILT+LIVE (roles and permissions).
- "Pause all AI" button: PARTIAL. HQ has per-tool kill switches (`/tools`, `hq.tools.manage`) and feature flags, but no single stop. Pausing an agent is a console or deploy action today by design. Needs a ruling (list in section e).
- "All businesses" scope chip: PARTIAL. Ask HQ and several screens take a business; there is no global scope switch.
- "Sample data" chip: BUILT+LIVE as a convention. Kept, and made mandatory on every preview or demo surface.
- Voice note as the question: MISSING (Phase 7, owner voice commands, `docs/89` section 3.14).
- The brief's greeting and summary: BUILT-INERT (`briefing.compose` and the Executive; live only after the shadow week).
- Source chips with ticks (LaLaDia orders, ERP stock, Meta ads, Pathao and CarryBee, 815 conversations): PARTIAL. Stored briefings carry "Sources and their freshness"; Meta ads are inert, couriers are missing, and there is no live inbound conversation traffic. Honesty fix: a chip shows the source's real age and state (fresh, stale, blind, not connected), never a tick by default, and the shop chips show "token rejected" while that alert is open.
- Metrics strip (orders yesterday, delivered, handled by AI, 7-day delivered revenue): PARTIAL. Orders and delivered sales are read from HQ's order facts; "handled by AI" is 0 by design today. Honesty fix: show 0 with "AI runtime off", and label the shop numbers with their freshness.
- Decision card "Order: 2 x Hilsa Fish Pickle, approve": BUILT-INERT. `commerce.take_order` exists; it needs a store admitted, a second approver and the ERP write token (Phase 4). The person who requested an order may never approve it.
- Decision card "Publish 3 posts at 17:00": BUILT-INERT. `publishing.publish_post` is R5 and approves one exact post, bound to a fingerprint (ADR-051); live needs Page tokens and Meta verification.
- Decision card "Rafiq: take over or let HQ draft": PARTIAL. Take-over is a live inbox action; "let HQ draft" is a Support proposal, inert at A0.
- Routine "answering 41 open customer questions": BUILT-INERT. Support is paused at A0 and Meta inbound is off. Honesty fix: no "doing now" list of customer work exists until Support is live; at A0 the panel can only say "proposals waiting for a person".
- Routine "reconciled 17 Pathao deliveries": MISSING (courier connector; Phase 4, ERP side).
- Routine "drafted a purchase request in the ERP": MISSING (Inventory and Procurement agents are planned or proposed; there is no ERP purchase-draft tool).
- Routine "sent 9 reorder reminders on WhatsApp": PARTIAL. Follow-ups and consent checks exist (main only); nothing sends, and WhatsApp has no number. Honesty fix: this line can never read "sent" for work an agent did alone. It reads "9 follow-ups proposed, waiting for approval; 2 skipped, no consent".
- Routine "@Ads asked to raise a budget, blocked": BUILT-INERT. The budget guard exists on the read side; raising a budget is a person's act forever. The pattern (a refusal shown as a refusal) is exactly right and is kept.
- Joy's free reply carrying three instructions at once: MISSING. Multi-instruction "Do" in chat is not a capability today.
- The receipt "each action read back from its own system": PARTIAL. Approvals record the outcome, and publishing has a read-back design; the receipt card only appears after a real approval ran through the Tool Gateway.
- Composer text box: BUILT-INERT (Ask HQ).
- Attach photo, video, file: MISSING in chat (a media asset store exists in the Phase 2.5 foundation; upload from chat is Phase 6).
- Forward an email: MISSING (no email connector).
- Ask / Do / Plan mode switch: PARTIAL. Ask is Ask HQ (inert); Plan is close to "describe a job" with its preview card (`/scheduler/nl`, inert); Do does not exist and depends on autonomy above A0. Honesty fix: Do and Plan render disabled with the reason ("agents are at A0: HQ proposes, a person acts").
- Live panel, "running" with progress bars: PARTIAL. Scheduler runs and agent runs exist; progress fractions do not.
- Live panel, signals: BUILT+LIVE (`/observe/alerts`; paused by containment, which the panel must say).
- "Authority guard" summary: BUILT+LIVE (`/observe/control-plane`, what policy decided).

### Artboard 2: photo, video and voice to a reel (11 elements)

- Media and a voice instruction posted into the chat: MISSING (chat upload, Phase 6; voice, Phase 7).
- Reel storyboard and render: MISSING (video is Phase 6 and Phase 8, `docs/89` section 3.7; no generation provider exists).
- Two posts built from Joy's photos: PARTIAL. `media.render_card` is defined and not started; `content.create_draft` is inert.
- Bangla caption from catalogue facts: BUILT-INERT (`content.create_draft`; a figure not in the evidence is refused).
- Playbook and claims check: BUILT-INERT. The check exists; the claims policy itself is Joy's to write and is not written. Playbooks exist on main only.
- "Approve and schedule Fri 17:00": BUILT-INERT (publish is R5, one approval per exact post).
- Redo the reel, edit caption: PARTIAL. An approval can be edited before approving ("Approve the edited action?"); there is no "redo" generation.
- Publishing panel (where, when, approver, read back after): BUILT-INERT.
- Versions list: PARTIAL (drafts are versioned by fingerprint; no version view).
- "Last Hilsa posts: 11 orders, 4 orders": MISSING (post metrics are Phase 6; the outcome ledger exists on main but nothing attributes posts yet).
- Hilsa ban note ("ads stay on by your decision"): BUILT-INERT (research engine, needs a search key).

The rule this artboard states, "no AI-made food shots, the jar people see is the jar they get", matches HQ's never list (product photos come from the asset library, never generated). It is kept as written.

### Artboard 3: take over a customer from the chat (13 elements)

- Icon rail of threads: new UI only.
- Customer card (name, area, channels, customer since): BUILT-INERT, main only (Customer 360, `docs/109`).
- Delivered value, orders, "reorders every ~32 days": PARTIAL. Customer 360 has an "inferred or computed" section; the reorder interval is not computed.
- Timeline including a phone complaint: PARTIAL. Orders, conversations and hand-overs exist; phone calls are not a channel (Phase 7).
- Three options A, B, C with a recommendation: MISSING. Support proposes one reply.
- "6 in stock, next purchase due 19 Oct": PARTIAL. ERP stock is read from snapshots; purchase due dates are not read.
- "I've paused the AI for this chat": BUILT+LIVE (`/inbox/{id}/toggle-ai`).
- Bangla draft in the polite form: BUILT-INERT (Support proposal; the language contract of `docs/92` enforces Bangla script).
- "Send to Rafiq on Messenger": BUILT-INERT. The reply goes through the Tool Gateway (`messaging.send_reply`), which checks consent first; the Messenger channel is off until Meta verification and App Review.
- "If he doesn't reply in 2 hours, I'll remind you": PARTIAL. Follow-ups are proposal-only and alert the responsible person inside HQ after a 24-hour grace (`docs/131` item 6); a 2-hour reminder is not configured.
- The live Messenger thread pane: BUILT-INERT. The inbox thread screen is live; no live Meta inbound reaches it.
- "Give back to AI": BUILT+LIVE (`/inbox/{id}/return-to-agent`).
- Writing directly to the customer: BUILT-INERT (the inbox reply composer, gateway-checked, channel off).

This artboard is already honest in the right place: "Nothing reaches the customer until you press Send." The redesign keeps that sentence and adds the channel state ("Messenger: not connected") next to the Send button, so a disabled Send explains itself.

### Artboard 4: email and invoice to the ERP, schedules (12 elements)

- Forwarded email with a PDF invoice: MISSING (no email connector; new capability).
- Reading the invoice into fields: MISSING (document extraction; new capability).
- Checks against the purchase request, the supplier and duplicates: MISSING (the ERP reads exist only for snapshots; purchase requests are not read).
- "I'll create a purchase bill draft": MISSING (an ERP write tool; HQ has no ERP write token).
- "Posting and paying stay with Finance. HQ never moves money": BUILT+LIVE as policy (never list, item 1). Kept word for word.
- "Ask supplier about the 4%": MISSING (supplier messaging; it would be an R5 outbound like any other).
- "I'm watching the goods receipt and will tell you": MISSING (event-triggered workflows are planned, `docs/89` section 3.13).
- Schedule in words with a preview and "Confirm schedule": BUILT-INERT (`/scheduler/nl`, flag `scheduler.nl`; the job exists only after a person confirms).
- Delivery of the brief to Joy and the owner on Telegram: PARTIAL. HQ's alert bot is live (paused by containment); delivering the briefing to people on Telegram is planned.
- "What HQ is connected to" panel: BUILT+LIVE (credentials and platform readiness). Row corrections: ERP "read" (not "read + drafts"); LaLaDia and Bionic "read, token rejected" until new tokens arrive; Meta "not verified, off"; Pathao and CarryBee "not connected"; Email "not connected"; Telegram "alerts, paused"; WhatsApp "needs a number" (correct); Econic "not supported" (correct).
- "Renew token": BUILT+LIVE (`/credentials`, `hq.credentials.manage`).
- "Never, from any chat" list: BUILT+LIVE as policy. Kept, and widened to the full twelve items of `docs/89` section 6 behind a "see all" link.

The schedule card's own line, "a scheduled job can only read; if something needs doing, it asks in this chat", is right in spirit; the exact rule is "a job's tool keeps its own risk and approval". The card will say that.

### Artboard 5: voice mode (6 elements)

- Full-screen voice mode with the orb: MISSING (Phase 7).
- Bangla and English, auto: PARTIAL (the language policy exists for Support replies; the UI has a manual toggle).
- Spoken answer from live records: PARTIAL (the answer is Ask HQ, inert; speech in and out is missing).
- "Read Bionic orders, read ERP stock, read Pathao" chips: BUILT-INERT (Ask HQ lists the tool calls behind every answer; Pathao is missing).
- A spoken "yes, pause the ads": MISSING, and it must stay different from the picture. Pausing ads is an R4 action that needs an approval of the exact payload, and a spoken word gives no extra authority (`docs/89` section 3.14). Honesty fix: in voice mode a yes creates a pending approval card on screen; it never executes.
- Mute, show work, end: MISSING (part of the voice shell).

### Artboard 6: the owner's phone, in Bangla (7 elements)

- Phone layout in Bangla: PARTIAL. The shell collapses to a sheet on a phone and every listed screen has Bangla; nothing is phone-first yet.
- "How are we today?" with an answer: BUILT-INERT (Ask HQ).
- Metrics: PARTIAL, as artboard 1. Honesty fix: Joy's own rule is English digits in both languages (`BanglaDictionaryTest`); the artboard uses Bangla digits, and the build will not.
- "Send the reorder message to 9 Bionic customers? Yes, send": PARTIAL. Follow-ups and consent per identity exist (main only); nothing sends; WhatsApp has no number. Honesty fix: one tap cannot send nine messages. Each message is its own R5 approval bound to its own payload (ADR-018), consent is checked again at send time inside the gateway, and the card reads "Review 9 proposed messages", opening the list. Whether a batch may be approved in one act is a ruling (section e).
- "Is the Loitta Shutki ad profitable? Profit 6,840": PARTIAL. The ads read side and contribution profit are inert, and most ERP cost prices are blank, so a profit figure cannot be stated today. Honesty fix: the answer shows what it could not compute and why.
- Source line "Meta, Pathao, ERP": PARTIAL (Pathao missing).
- Attach and speak buttons: MISSING (Phase 6 and 7).

### The honesty rules every redesigned screen follows

These come from the fixes above and from HQ's governance, and they bind W-UI2 to W-UI5:
- No screen says an AI did, sent, published, created or reconciled something unless an audited run or a gateway call record says it did. At A0 the verb is "proposed".
- Every action that reaches a customer, a campaign, a record outside HQ or money is shown as an approval card that opens the exact payload. No summary card approves on its own (decision 5 in section f).
- Every switched-off capability renders, labelled with why it is off and what turns it on ("Meta not verified", "AI runtime off", "Phase 7"). It never hides, and never pretends.
- Containment and incidents are visible in the shell: while the scheduler is stopped, the live panel says alerts are paused.
- Sample or demo figures carry the "Sample data" chip; real figures carry their source and age.
- Human-only tools (lifting a do-not-contact, outcome credit, identity repair) never appear as a suggestion from HQ; they appear only as a person's own button on their own screen.
- Brand is "Bor de Guna HQ" (not "BordeGuna HQ"), English digits everywhere, no emoji (the artboards' tick and circle glyphs become labelled icons).

## (b) What HQ has that the artboards leave out

The artboards are about talking to HQ. HQ's governance lives in screens the pictures never show. Each keeps its own page and finds a place in the new structure:
- **Approvals queue** (`/approvals`, live): the source of every decision card on the console. Lives under Work.
- **Consent** (`/customers/{id}/consent`, main only): reached from Customer 360 and from any card that would message a customer ("consent on file" becomes a link to this page).
- **Customer 360** (`/customers/{id}`, main only): the take-over pane's customer card is a slice of it, with a link to the full page. Lives under Customers.
- **Quarantine and repair** (`/customers/quarantine`, main only, human-only repair): Customers group, as "Cross-business review", visible only to holders of `hq.customers.quarantine.review`. Now linked from the sidebar (it was reachable only from the customer list).
- **Follow-ups** (`/follow-ups`, main only): the honest version of "reorder reminders". Under Work.
- **Playbooks** (`/playbooks`, main only): what the "playbook check" in artboards 1 and 2 checks against. Under Control.
- **Decision corpus** (`/observe/decision-corpus`, main only): what people changed in AI proposals. Under Observe.
- **Alerts** (`/observe/alerts`, live): the console's signals column. Under Observe.
- **Tool registry and kill switches** (`/tools`, live) and **activation posture** (`/observe/posture`, live): what the "authority guard" summarises. Under Control.
- **Cost** (`/observe/cost`, live): AI spend against the monthly cap. Under Observe; a small figure on the console's live panel.
- **Scheduler and jobs** (`/scheduler`, live; `/scheduler/nl`, inert): the "Scheduled" list. Under Operations.
- **Shadow evidence** (`/agents`, `/agents/runs/{id}` review, `/observe/autonomy-evidence`): how an agent earns a level. Under Operations and Observe.
- **Credentials** (`/credentials`, live): the "connected systems" panel links here. Under Control.
- **Audit log** (`/audit`, live): every receipt card links to its audit row. Under Observe.
- Also kept: feature flags, businesses, AI health, control plane, platform, briefings archive, Horizon queues, sessions and two-factor settings.

## (c) The new information architecture

Two layers, one shell.

**The console (home).** The "Today" screen becomes a chat-first console, shaped like artboard 1:
- left: the shell's navigation, collapsible to an icon rail (artboard 3's rail);
- centre: the brief as the first message (stored briefing, or the read-only Today data while the runtime is off), the decision cards (real pending approvals), and the Ask HQ composer at the bottom;
- right: the live panel (alerts, scheduler state, cost against the cap, the authority guard counts, and the containment notice when it applies);
- the composer is Ask HQ; when the `ask_hq` flag or the runtime is off it shows the reason in place, and the brief and cards still work.
- `/dashboard` stays the route and `/ask` stays its own page, so no link, test or permission moves.

**The workspace.** Every existing screen stays reachable, regrouped by what the person is doing:
- Work: Today, Ask HQ, Approvals, Inbox, Follow-ups.
- Customers: Customers (Customer 360), Cross-business review.
- Operations: Scheduler, Briefings, Agents, Businesses.
- Control: Tools, Playbooks, Credentials, Feature flags, Activation posture.
- Observe: Alerts, AI health, Cost, Control plane, Autonomy evidence, Decision corpus, Platform, Audit log, Queues (Horizon).

**Desktop and phone.** Desktop is three columns (nav, conversation, live panel) from 1280 px, two columns from 1024 px (the live panel folds under the brief), one column below. On a phone the navigation is a sheet today; W-UI5 adds a bottom bar with Today, Approvals, Inbox and Menu, and the composer pinned above it, as artboard 6.

**Bangla first.** The language switch sits in the top bar on every screen. Bangla text uses Noto Sans Bengali with a taller line height; the person's choice is saved to their profile as today. Owner-facing screens (console, approvals, Customer 360) are translated in full before anything else in each wave.

## (d) Design system

### Colour

Two themes, same token names, chosen in the top bar (light, dark, or follow the device):
- **Dark glass** (Joy's look, the default at night): ground `#07080C` with three soft glows (violet top-left, teal top-right, warm orange bottom); panels are frosted white at 4 to 9 percent; text `#F3F4F7`; secondary text `#A9ADBA`; primary action is a light pill (`#F3F4F7` on `#0B0C10` text).
- **Light, for daytime office use**: a warm paper ground `#F5F4F0` with the same glows at a fraction of the strength; panels white at 72 to 90 percent; text `#14151A`; secondary text `#545967`; primary action is an ink pill (`#14151A` with white text).
- Accents, both themes: violet (focus, the HQ orb, links), teal (live and fresh), orange (attention). Status keeps one meaning per colour: green done, amber waiting, orange needs a look, red failed or blocked, blue in progress, grey off.
- The Bor de Guna logo stays the brand mark; the orb is HQ's own mark for the assistant voice.

### Type

- Latin: Geist 400, 500, 600; numbers and codes in Geist Mono with tabular figures.
- Bengali: Noto Sans Bengali 400, 500, 600, picked up automatically for Bengali characters, and placed first when the page language is Bangla.
- Scale (px): 12 caption, 13 small, 14 body, 15 chat body, 18 page title, 22 section display, 26 metric figure. Line height 1.5 for Latin, 1.7 for Bengali text.
- Labels in small caps style (11 px, letter spacing 0.06 em) for group headings, as the artboards do.

### Space, radius, elevation, motion

- Spacing on a 4 px grid; panel padding 16 to 18; gaps 14 to 22 between blocks, as drawn.
- Radius: 8 small controls, 12 inputs and menu items, 18 cards, 22 inner panels, 28 shell panels, pill for buttons and chips.
- Elevation: three levels. Flat (rows), raised (cards: hairline border plus a soft shadow), floating (popovers, dialogs, the composer: a deeper shadow and the strongest blur).
- Motion: 120 ms for hover and press, 200 ms for panels, 320 ms for the orb; ease out. All of it stops under `prefers-reduced-motion`.

### Accessibility

- Text contrast at least 4.5:1 on the worst point of the ground (the brightest glow), checked for both themes; secondary text is a solid colour, never a low alpha.
- Touch targets at least 44 px on touch screens; navigation rows and buttons grow on coarse pointers.
- A visible violet focus ring on every interactive element, keyboard only (`:focus-visible`).
- No emoji. Every icon that carries meaning has a text label or an accessible name.
- Glass falls back to solid panels where `backdrop-filter` is not supported, and blur is skipped under reduced transparency preferences.

### Component inventory, mapped to what exists

- Shell, sidebar, sheet, top bar, breadcrumbs: `ui/sidebar`, `app-sidebar`, `app-sidebar-header`, `breadcrumbs` (restyled in W-UI1).
- Buttons and pills: `ui/button` (pill shape in W-UI1).
- Chips and status: `ui/status-badge` (`StatusBadge`, `CountPill`); a `SourceChip` (source, age, state) is new in W-UI2.
- Panels and figures: `observe/panels` (`Panel`, `Stat`, `StatGrid`, `DataTable`), `ui/card`.
- Decision card: new in W-UI2, built on `approvals/shared` and linking to `/approvals/{id}`.
- Message bubbles, composer and orb: new in W-UI2, the composer posting to the existing `POST /ask`.
- Receipt card: new in W-UI2, read from approval outcomes and audit rows.
- Customer card: a compact view of Customer 360 data, W-UI3.
- Empty and switched-off states: `empty-state`, extended with an "inert" variant that names the reason.
- Dialogs and confirms: `ui/dialog`, `confirm-dialog` (unchanged behaviour).
- Language and theme switches: `language-toggle`, new `theme-toggle`.

## (e) Implementation plan in waves

Every wave is its own branch from main, its own worktree and test database, plan commit first, then WIP checkpoints. The gate for each is the full Pest suite in parallel on Postgres, Pint, `npx tsc --noEmit`, `npx vite build`, `BanglaDictionaryTest` (each touched screen added to its list), and `deploy/tools/structural-searches.sh`. Nothing is merged while main is frozen on the release candidate; each wave waits behind the release.

- **W-UI1, tokens, shell and navigation (built now).** New colour, type, radius, motion and focus tokens for dark glass and light; Geist and Noto Sans Bengali; the glass shell; regrouped navigation with the cross-business review linked; a theme switch beside the language switch; pill buttons; 44 px rows on touch. No route, controller, permission or prop changes. Every screen renders through the same components.
- **W-UI2, the console.** "Today" becomes the console of section (c), using only data the Today page, the Ask HQ page, the approvals list and the alerts list already serve, behind their existing permissions. Decision cards open the approval. The composer posts to `POST /ask`; with the flag or runtime off it shows why. Ask, Do and Plan: only Ask is enabled. Any new read prop on an existing controller is scoped exactly as its own screen and gets a test proving that.
- **W-UI3, restyle of every existing screen.** Page by page, owner-facing first (approvals, Customer 360, consent, inbox, follow-ups), then control and observe screens. Same props, same routes; a screen test per page keeps it rendering.
- **W-UI4, screens for the gaps that need no new capability.** A connected-systems page built from credentials and platform readiness; a "what HQ will never do" page from the policy; switched-off placeholders for email, couriers, reels and voice that name their phase and gate.
- **W-UI5, phone and voice shell.** Bottom bar, pinned composer, Bangla-first owner view; a voice-mode screen that is visibly inert ("voice is Phase 7, not available") with no microphone access.

### What needs ChatGPT's architecture ruling, and what is pure UI

Needs a ruling before any build (each adds a capability, an outside call or a new write path):
1. "Do" mode in the console: agents acting inside limits from chat. Bound to autonomy above A0.
2. Owner voice mode: speech in and out for the operator, a browser microphone, a speech provider (Phase 7).
3. Media upload into chat, and reel or video generation (Phase 6 and 8).
4. Email ingestion (forwarding an email to HQ) and PDF invoice extraction.
5. An ERP purchase-bill draft tool (an ERP write path).
6. A courier connector (Pathao, CarryBee) for delivery state.
7. A one-tap "pause all AI" switch: a new write route, even though it only reduces risk.
8. Approving a batch of customer messages in one act (artboard 6's "send to 9"), against ADR-018's one exact payload per approval.
9. One-click approve on a console card instead of opening the approval (decision 5 below holds until then).
10. Delivering the morning brief to people on Telegram (a new outbound path, even if internal).
11. Several reply options (A, B, C) from Support instead of one proposal (a change to its contract and evaluation).
12. Event watches from chat ("tell me when the oil arrives"): event-triggered workflow creation by the assistant.

Pure UI, no ruling needed: tokens, shell, navigation, the console layout over existing data, restyles, switched-off placeholders, the phone layout, an inert voice screen, and any read prop scoped exactly as its existing screen.

## (f) Decisions made, and why

1. **Joy's look becomes HQ's look, in two themes.** He drew dark glass; HQ is also used in a bright office by day, where dark glass tires the eye and washes out on cheap screens. The light theme keeps the same tokens, glows and shapes. The person chooses, or the device decides.
2. **HQ moves off the ERP's green.** HQ shared the ERP's palette. Joy's design is neutral ink with violet and teal, and it makes HQ read as the office above the businesses rather than one of them. The Bor de Guna logo stays.
3. **The orb is HQ's voice, not the brand.** The orb marks the assistant (answers, the composer, voice mode). The logo marks the company in the shell.
4. **The console sits on `/dashboard`, not on `/ask`.** Ask HQ is behind a flag and the runtime is off; a home page that depends on a switched-off capability would be blank for everyone. The brief, decisions and signals work without the AI; the composer joins them when the flag is on.
5. **A decision card opens the approval; it does not approve.** ADR-018 binds an approval to the exact payload a person read. A summary card is not that payload. One-click approve waits for a ruling.
6. **Switched-off things show, labelled.** Hiding inert features makes HQ look smaller than it is and hides what is waiting on Joy (Meta verification, tokens, a WhatsApp number). Showing them as working would be a lie. So they show, with the reason and the gate.
7. **Navigation regrouped, every link kept.** Five groups by what the person is doing. Each existing nav entry keeps its exact title, URL, icon and permission (several screen tests read them). The cross-business review is linked from the sidebar, because a screen nobody can navigate to is a screen nobody uses.
8. **English digits in Bangla, against the artboard.** Joy's standing rule and the dictionary test win over the drawing.
9. **"Bor de Guna HQ", against the artboard.** The brand spelling rule wins over "BordeGuna HQ".
10. **Fonts from Bunny, not Google.** HQ already loads fonts from fonts.bunny.net (no tracking cookies, same files). Geist, Geist Mono and Noto Sans Bengali are all served there.
11. **W-UI1 changes no behaviour.** No route, controller, permission, prop or test expectation changes in the first wave. That keeps it safe to hold behind the frozen release and merge later without re-proving anything but rendering.

## (g) W-UI1 as built (17 October 2026)

Branch `ui/w-ui1-shell-and-tokens` from main `c0db11e`, pushed, not merged (main is frozen on the release candidate).

What changed:
- `resources/css/app.css`: the token set of section (d) for light and dark glass; Geist, Geist Mono and Noto Sans Bengali (Bangla first on a Bangla page); radius scale; glass, orb, shadow and motion tokens; a `touch` variant for 44 px targets; a global focus ring; themed thin scrollbars; reduced motion and reduced transparency fallbacks.
- `resources/views/app.blade.php`: the three font families from fonts.bunny.net, and the saved theme applied before the first paint.
- Shell: glass sidebar and glass page panel (`ui/sidebar.tsx`), pill buttons (`ui/button.tsx`), HQ name and "Head office" under the logo, a light, dark or device theme switch beside the language switch, the sign-in frame on the same ground and glass.
- Navigation: five groups (Work, Customers, Operations, Control, Observe); every entry keeps its title, URL, icon and permission; the cross-business review is linked; one entry lights per page (longest match).
- One small fix: the theme hook no longer removes the device-theme listener when a screen unmounts, so "System" keeps following the device.
- Bangla: five new dictionary entries; `app-logo.tsx` and `theme-toggle.tsx` added to `BanglaDictionaryTest`.
- New `tests/Feature/Foundation/AppShellTest.php` (8 tests): every sidebar entry has a GET route behind the same permission; the review link is gated; both themes define the same tokens; body and secondary text at 4.5:1 or better on the ground and on the brightest glow; fonts, focus ring, touch size, reduced motion; the theme switch posts nothing.
- No route, controller, permission, prop or existing test expectation changed.

Gate on the final commit: Pest 2,174 passed, 0 failed (parallel, 3 processes, Postgres `hq_test_k`); Pint passed; `tsc --noEmit` clean; Vite build clean; structural searches S1 to S4 PASS.

Rendering proof: a local throwaway database (dropped afterwards) served the branch; headless Edge signed in and opened all 30 screens in both themes (with `ask_hq` and `scheduler.nl` switched on locally so their screens render too): 60 page loads, 0 JavaScript errors, exactly one lit nav entry per page, Noto Sans Bengali active on a Bangla page, and the shell fits a 390 px phone.

## (h) W-UI2 plan: the console on `/dashboard` (17 October 2026)

Branch `ui/w-ui2-console` from `ui/w-ui1-shell-and-tokens`. Owns the dashboard page, the props its controller action hands it, and new components under `components/console`. No other page, no shared layout, no route, no permission, no tool, no capability, no write path.

Numbered steps:
1. A read-only assembler, `App\Observe\Console\ConsoleReader`, called by `AdminController::dashboard` and added as ONE new prop, `console`. Every existing prop stays as it is, so nothing that reads them moves.
2. The brief: the newest stored briefing the person may see (the briefings screen's own visibility rule and permission, `hq.tools.facts.read`), and yesterday's order figures and the source list from the briefing's own deterministic readers (`SourceReader`, `YesterdayOrders`), under the same permission and the person's business restriction. A store without facts gives "no figure", never a zero; a total over some stores is withheld, as the briefing already does. Marketing and finance render as "not connected" with the contract's own reason.
3. Decision cards: pending approvals, scoped by `BusinessScope` exactly as the dashboard already counts them, only for `hq.approvals.view`. A card links to `/approvals/{id}`; it has no approve button. Recent receipts: approvals decided in the last day, same scope, with the outcome label the approvals screen uses ("approved, did not run" stays that).
4. Live panel, each block behind the permission of the screen it summarises: alerts (`hq.audit.view`), scheduler heartbeat and jobs (`hq.jobs.view` for the jobs; the heartbeat is already on the dashboard), cost against the cap (`hq.ai.cost.view`, the budget reader the cost screen uses), the authority guard (`hq.tools.view`, the control plane reader for the last 24 hours, scoped as that screen scopes it), and the posture (AI runtime, agents and their level, as the dashboard already shows). A stopped scheduler says that alerts, the event drain, sweeps and Telegram alerting are paused.
5. The composer: Ask only, posting to the existing `POST /ask`. Shown usable only when the person holds `hq.ask.use`, the flag `ask_hq` is on and the AI runtime is on; otherwise it renders disabled with the reason in place. Do and Plan render disabled with "agents are at A0: HQ proposes, a person acts".
6. Bangla through `t()`; the console files join `BanglaDictionaryTest`; English digits.
7. Tests: props per business and permission, no cards without approval permission, inert reasons, no new route and no form on the console other than the existing `POST /ask` (structural pin).
