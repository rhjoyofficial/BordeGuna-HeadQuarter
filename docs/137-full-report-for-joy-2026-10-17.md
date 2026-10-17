# 137. Bor de Guna HQ: full report, your instructions, what HQ can do, readiness (17 October 2026)

Written for Joy. Plain English, short bullets. Every claim here is backed by a record in `docs/`; the record is named in brackets.

## 1. Where we are, in five lines

- The identity leak (one Bionic order attached to a LaLaDia customer) is **contained**: you stopped the HQ scheduler on 17 Oct 03:35 UTC. Shop orders keep arriving and wait safely. (docs/121 §5, §6)
- The fix, the repair tools and three waves of other work are **finished, tested and frozen** as release candidate `c0db11e` (tag `hq-rc-2026-10-17`). GitHub CI is green on that exact commit (run 39000064674). (docs/128 §19)
- The **server rehearsal passed** on a copy of production: migrations, backup restore with the real `restore.sh`, no event lost. (docs/128 §19)
- Verdict: **READY FOR YOUR RELEASE DECISION.** You said "release". The release now waits for you to paste four lines, one at a time (section 3).
- Your design ("BordeGuna HQ · Chat Console") is **built as a new HQ interface** on a separate branch, tested, waiting for the release to finish first (section 5).

## 2. Readiness in percentages

How measured: "built" means the code exists and is tested; "in use" means it runs on real data today. Same method as docs/118.

- **Whole plan: about 72% built, about 30% in real use, 0% of the AI live.** The AI stays off by design until the shadow week.
- **This release (waves 1 to 4): about 95%.** Only your four release steps are left.
- **The new interface: about 85%.** Built and tested for every existing screen; the parts that need new abilities (voice, reels, email ingestion) are shown as "not available yet".
- Per phase:
  - Phase 0, foundation: 100% built, 100% in use.
  - Phase 1, connections to the shops and the ERP: 100% built, about 80% in use (shop tokens expired).
  - Phase 2, the command centre: about 99% built, about 50% in use (the shadow week is not opened yet).
  - Phase 2.5, shared foundation: 100% built, 100% in use.
  - Phase 3, customer AI on Messenger, Instagram and WhatsApp: about 88% built, 0% in use (needs Meta verification, App Review and a WhatsApp number).
  - Phase 4, AI taking orders: about 70% built, 0% in use (needs a second approver, the ERP write token, a payment gateway and a courier connection).
  - Phase 5, ads: about 35% built, 0% in use.
  - Phase 6, content and research: about 40% built, 0% in use.
  - Phase 7, voice: about 22% built (the voice screen exists, switched off), 0% in use.
  - Phase 8, avatar: about 5% built.
  - Phase 9, skills and delegation: about 40% built.
  - Phase 10, controlled autonomy: about 15%, earned only by evidence.

## 3. Your instructions, in order (each step is yours; I check each result before the next)

Every line below runs in this Claude Code session: type `!` and paste it. My safety system does not let me change production myself, even with your permission, so these are yours to paste.

### Step 1: read-only checks before the release (changes nothing)
- The exact line is in this session's chat (the one starting `! cd /h/WORK/BorDeGuna/BordeGuna-HeadQuarter && scp deploy/tools/release-posture-readonly.sql ...`).
- It saves today's settings on the server so we can compare after the release.

### Step 2: the release itself
- I give you this line after step 1 looks right. It will:
  - add `HQ_EVENTS_DRAIN_PAUSED=true` to the server's settings, so waiting shop orders are not processed yet;
  - move the server to the candidate `c0db11e`;
  - run `./deploy/deploy.sh --no-pull --keep-scheduler-stopped`, which takes and checks a fresh backup before changing anything.
- Why this order: an ordinary deploy would restart the scheduler and push the waiting orders through before the repair. (docs/132 §5B)

### Step 3: permissions and tool list
- Adds the 15 new permissions (14 from this work plus one reserved ads permission) and the new tools' on/off rows. Running it twice changes nothing.

### Step 4: read-only checks after the release
- Confirms the new code is running, the new database rules exist, nothing was switched on by accident, and the leak is closed.
- Expected: health shows the scheduler as down. That is correct while the scheduler is stopped.

### After the release, each needs your separate yes (I prepare each one, you approve)
1. Mark the bad rows (writes review flags only).
2. Review them on the new quarantine screen: order #16 and the two shop identities.
3. Repair order #16 onto its own Bionic customer, with the full history kept. You press "Apply" on the screen or paste one line.
4. Resume the scheduler, so the waiting shop orders are processed by the fixed code.
5. Time-zone fix: on today's data it would change nothing (0 rows provably wrong). No action needed now.
6. Cost history fill-in for old test runs: optional.
7. Much later: open the Executive shadow week (7 days of HQ watching and proposing, nothing sent). ChatGPT said: only after the repair is verified.

### Rollback, if ever needed
- The new identity rules cannot be "undone" by the normal rollback. The way back is the backup taken in step 2, restored with `restore.sh`. The rehearsal proved this works, and that no shop order is lost across it. (docs/127, docs/132 §5C2)

## 4. What HQ can do, and how

### Working now (after the release, with the AI still off)
- **One login for every business.** LaLaDia, Bionic, Econic and the ERP counter, each kept separate. A person limited to some businesses sees only those, on every screen. (docs/122, docs/136)
- **Live business picture.** Orders, deliveries and stock from the shops and the ERP, refreshed from their own records; HQ never invents a figure. If data is missing, the screen says so.
- **Customer 360.** One page per customer per business: orders, conversations, consent, follow-ups. A customer of LaLaDia and Bionic stays two customers, by design. (docs/109, docs/122)
- **Consent register.** Who agreed to be contacted, on which channel, for what. Lifting a "do not contact" needs two different people. (docs/108, docs/114)
- **Approvals.** Anything that would reach a customer or change money waits for a named person to approve. (ADR-018)
- **Follow-ups and opportunities.** HQ can record promises and interests; nothing is sent by itself. Overdue ones alert the responsible person inside HQ only. (docs/113, docs/123)
- **Playbooks.** Your rules per brand, drafted, reviewed and switched on by people. They guide the AI; they never give it more power. (docs/112)
- **Outcome ledger.** What happened after a decision, recorded by people, never deleted. (docs/107, docs/115)
- **Scheduler.** Jobs on a timetable, every effect through the approval gate. Paused now by containment. (docs/116)
- **Alerts, cost and audit.** Every action recorded with who and why; alerts to Telegram (paused with the scheduler).
- **Data-quality tools.** Cross-business detector, repair with full history, time-zone check, cost check: all dry-run first. (docs/117, docs/126, docs/133)
- **Safe recovery.** Backup before every release, tested restore, shop orders carried across a restore. (docs/132)

### Built and switched off (ready when you open it)
- **The AI agents:** Executive (business questions and the morning brief), Analytics, Support (customer replies). All three passed their safety exams. They start in "propose only" mode.
- **Ask HQ:** ask a question in words, Bangla or English. Off until the AI runtime is on.
- **Morning brief:** composed daily for you once you approve the job.
- **Customer replies on Messenger and Instagram:** built, waiting for Meta verification and App Review.

### Not built yet (needs a ChatGPT ruling first, then work)
- From your design: voice mode, photo/video to a reel, email and PDF invoices into the ERP, courier tracking (Pathao and others), "Do" mode where HQ carries out a plan from the chat, watches that trigger on events.
- The full list of 13 questions for ChatGPT is in section 6.

## 5. The new HQ interface (from your design)

- **What I checked:** all 79 pieces of your six screens against what HQ really does. 11 work today, 21 are built but switched off, 26 are partly there, 20 are missing. (docs/135 §a)
- **The honest version of your design:** where your design showed HQ acting on its own ("Yes, send to 9 customers"), the new screens show the real step: "Review 9 proposed messages", each with its own approval and consent check. Switched-off features are shown with the reason and what turns them on.
- **What is built (all tested, on branch `integration/ui-and-scope`):**
  - your dark glass look, plus a light theme for daytime office use, Geist and Noto Sans Bengali fonts;
  - **the console as the home page**: the morning brief, decision cards that open the approval, where each figure came from and how fresh it is, a live side panel (alerts, scheduler, cost, safety guard), and the Ask HQ box;
  - **every one of the 35 other screens** restyled, readable on a phone, add and edit in popups (your rule);
  - **a phone layout** with a bottom bar (Today, Approvals, Inbox, Menu);
  - **the voice screen** in your design's look, clearly marked "not available yet", using no microphone;
  - **Bangla on every screen** (1,601 dictionary entries), English digits, "Bor de Guna" spelling.
- **Security fixes found while building it:** the approvals and scheduler lists, and several counts, did not filter by business for people limited to some businesses. Fixed and tested on the same branch. Production has the same gap today, with no real exposure because you are the only user. (docs/136)
- **Tests:** 2,219 passed, 0 failed; 74 screen loads in both themes, desktop and phone, 0 errors. GitHub CI on this branch: see section 8.
- **When it goes live:** after the release is finished and checked. It becomes the next release, with its own CI and backup, the same way.

## 6. Questions going to ChatGPT (architecture decisions, not yours unless you want them)

1. "Do" mode: HQ carrying out a plan from the chat.
2. Owner voice mode (speech provider, microphone).
3. Photo and video upload into the chat, and reel or video generation.
4. Reading emails and PDF invoices.
5. Drafting an ERP purchase bill (a write into the ERP).
6. Courier connection (Pathao, Steadfast, RedX).
7. A one-tap "pause all AI" button.
8. Approving several customer messages in one act.
9. One-click approve on a console card.
10. Sending the morning brief on Telegram.
11. Several reply options (A, B, C) from Support.
12. Event watches created from the chat.
13. Whether a person limited to some businesses may switch flags or tools that affect every business.

## 7. What only you can arrange (set the pace; full list with costs in docs/82)

- **Slow, start early (weeks):**
  - Meta Business Verification (1 to 4 weeks; papers: trade licence, TIN, utility bill or bank statement, business phone, bank account in the business name).
  - Meta Developer app and App Review for Messenger and Instagram.
  - A new SIM for WhatsApp Business (on no WhatsApp app).
  - SMS masking name (BTRC, 1 to 3 weeks).
  - A payment gateway (SSLCommerz or aamarPay, or bKash and Nagad directly).
  - Courier API with the courier the shops use.
- **Quick:**
  - New admin tokens for LaLaDia and Bionic on HQ's credentials screen (the old ones stopped working on 22 Sep).
  - The Hostinger setting that lets the shops reach HQ directly.
  - A second person who can approve orders.
  - An ERP write token for HQ.
  - Server upgrade KVM 2 to KVM 4 before customer AI goes live (the rehearsal showed the 2 CPUs are tight).
  - A dollar card in the business name.
- **Decisions:** autonomy numbers (how much evidence before HQ may act without asking), ads budget per business, a claims policy per product line, whose face and voice for the avatar (with written consent).
- **Not needed any more:** GitHub billing. The repository is public, so testing is free.

## 8. Records and links

- Release: docs/134 (go/no-go package), docs/128 §19 (final evidence), docs/127 (release notes), docs/132 (event safety and the exact operator steps).
- Incident: docs/121 (containment and the count), docs/122 (the fix), docs/126 (the repair).
- Interface: docs/135 (plan, verification and as-built), docs/136 (business-scope audit).
- ChatGPT rulings: docs/120, docs/131.
- Code: candidate `c0db11e` on `main` (tag `hq-rc-2026-10-17`); the interface on `integration/ui-and-scope`; this report and the final evidence on `docs/release-readiness-final`. All are merged into `main` after the release.
