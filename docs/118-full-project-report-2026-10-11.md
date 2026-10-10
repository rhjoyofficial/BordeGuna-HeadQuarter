# 118. BordeGuna HQ: full project report (11 October 2026)

Written at Joy's request: finish the running work, test it fully, sync memory, write a full report, make a resume point, and pause at a safe stage. This is that report. The resume point is `RESUME-HERE-2026-10-11.md`.

## 1. Where HQ stands in one paragraph
HQ is the private head office above the Bor de Guna businesses (`hq.bordeguna.com`): it watches every business, answers from live records, acts only through governed tools, schedules work, remembers approved procedures, and writes down why everything happened. **Production runs `b96a396`** (released by Joy on 9 Oct), fully inert: the AI runtime is off, all three agents are paused at A0, there are no grants and no open shadow week. The Support agent **passed its safety gate (P3-G2 attempt 5) on 10 Oct**. Since then three build waves were made on ChatGPT's rulings and are now **merged on main `6726f51`** (see §3 and §8). None of it is released. The next step that is Joy's alone is opening the Executive shadow week (approved twice by ChatGPT).

## 2. The plan (docs/89) and progress
HQ's plan has 11 phases (0 to 10), 14 agents and a never-list (HQ never moves money, invents a product fact or testimonial, messages without consent, acts beyond a named person's authority, publishes anything but the approved version, uses a face or voice without written consent, deletes its own record, or promotes itself).

Estimates (engineering judgement, method as docs/104 §5), updated for the work merged since 10 Oct:

| Phase | Built | In real use | Main thing left |
|---|---|---|---|
| 0 Foundation | 100% | 100% | none |
| 1 Integrations | 100% | ~80% | new shop admin tokens; shop to HQ network block |
| 2 Command center | ~98% | ~50% | shadow week (7 days), briefing job |
| 2.5 Shared foundation | 100% | 100% | none |
| 3 Customer AI | ~85% | 0% | Meta verification, App Review, WhatsApp number; provider registration; comment replies |
| 4 AI orders | ~65% | 0% | a store admitted, second approver, ERP write token, payment, courier, fraud check |
| 5 Ads | ~35% | 0% | system-user token; write side |
| 6 Content + research | ~40% | 0% | keys, claims policy, Page tokens; image/video generation |
| 7 Voice | ~20% | 0% | speech key, telephony |
| 8 Avatar | ~5% | 0% | consent decision |
| 9 Skills + delegation | ~35% | 0% | delegation; the learning loop now has its reader (S5) and playbooks (S6) |
| 10 Controlled autonomy | ~15% | 0% | earned by evidence only |

**Whole plan: about 68% built, about 30% in real use, 0% of the AI live** (by design until the shadow week). Against Joy's vision document: about 45% built (it was about a third on 3 Oct). Phase 3, 4 and 9 moved most: Customer 360, consent enforcement, follow-ups, outcome attribution, the decision-corpus reader and the playbook store were all "missing" in the 3 Oct vision audit (docs/95 §5).

## 3. What was built since the release (all merged on main, none released)

### Wave 1 (merged 10 Oct at `a8a6410`, CI run 38029016996 green)
| Stream | What it does | Record |
|---|---|---|
| S1 safety preconditions | Support settles an EXECUTED reply correctly (A1 cap unchanged); the eight effectful tools all reach the Tool Gateway; closed a real gap where the scheduler bypassed the gateway entirely when a flag was off | docs/105 |
| Cost attribution | evaluation runs record their real model cost (they had always shown 0) | docs/106 |
| S3 outcome model | one canonical, append-only outcome/attribution ledger; HQ stores references, never ERP money facts | docs/107 |
| S7 consent enforcement | withdrawal, do-not-contact and marketing opt-in enforced inside the Tool Gateway before any send; consent screens | docs/108 |
| S2 Customer 360 | read-only, business-scoped customer page; observed, inferred, memory, consent and order copies kept apart | docs/109 |

### Waves 2 and 3 (merged 11 Oct at `5812d42`)
| Stream | What it does | Record |
|---|---|---|
| S5 decision-corpus reader | owner page + command: recurring human edits of AI proposals, classified by fixed rules, no customer text shown | docs/111 |
| S6 playbook store | versioned per-brand guidance, draft → review → activate by people, loaded read-only into agent context, grants nothing (ADR-052) | docs/112 |
| S4 follow-ups (inert part) | opportunities and follow-ups as proposals; consent checked at proposal and approval; a workflow that only waits for a person; nothing reaches a customer | docs/113 |
| S7 follow-up | consent per identity (phone, Messenger, Instagram); lifting a do-not-contact needs two different people | docs/114 |
| S3 follow-up | the D1-D19 map ChatGPT asked for (10 approved, 2 changed, 7 review-pending); attributions with no business refused; operator attribution only through a human-only gateway tool | docs/115 |
| Cost + bypass | pre-throw model calls now counted; the old scheduler bypass switch removed entirely; a repeat-pattern alert (16th rule) | docs/116 |
| S2 quarantine | old cross-business merges detected, flagged and hidden from Customer 360; review by a person, audited; nothing undone or deleted | docs/117 |

### Gates on the combined tree
- Wave 1 combined: 1,941 passed after one cross-stream fix; CI green.
- Waves 2+3 combined: **2,042 passed, 0 failed; CI run 38079877403 green on `6726f51`** (§8).
- Every merge was `--no-ff`, one branch at a time. Collisions resolved by hand: the Bangla dictionary (two keys translated differently, one shared word chosen: "Edited" সম্পাদিত, "Approve" অনুমোদন করুন), the route file (the playbooks group closed before the follow-ups group opens; the consent route keeps S7's added permission), and the two new permanently human-only tools (both registered; the effectful-tool pin now names eleven; the never list holds both, compared sorted).

## 4. Findings worth knowing (found while building)
1. **A scheduler bypass existed** (S1): with `scheduler.gateway` off or missing, a scheduled R4 write would have run with no approval, consent, idempotency key or audit. Production had the flag ON, so it never happened there. Now removed for good (docs/116).
2. **Evaluation cost was never recorded** (cost stream): every evaluation run ever showed $0.
3. **A live identity leak, not yet fixed** (S2 quarantine, docs/117 §11 Q2): `IdentityResolver::byIdentity()` looks up phone, email and channel identities with no business filter, so a Bionic order or message from a phone already known in LaLaDia attaches to the LaLaDia customer, with no merge at all. Detection and suppression now hide the result on Customer 360, but the cause needs a schema decision (identity rows are unique across all businesses today). **This goes to ChatGPT first in the next report.**
4. The thread-binding guard can refuse something the gateway would allow (cost stream, docs/116); no registered tool hits it today; it is now counted and alerted on patterns.

## 5. Release notes for this wave (Joy's release, when he decides)
- Seven additive migrations (`2026_10_10_100000` cost columns and outcome tables, `300000` playbook versions, `700000` consent brand, `800000` consent identity and follow-up tables, `2026_10_11_100000` quarantine tables). Two pairs share a timestamp; Laravel orders them by full filename and no pair depends on its twin.
- `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (additive; ⛔ never full `db:seed`): new permissions for consent (view/restrict/grant/lift_restriction), playbooks (view/draft/review/activate), follow-ups (view/propose/decide), outcomes.attribute and customers.quarantine.review.
- After release: `php artisan hq:identity:cross-business` (dry run) and read the list; `--flag` only on Joy's word. Suppression is active from the release either way.
- After release, the `scheduler.gateway` row becomes inert; the delete line is in docs/116 (keep it one release as a rollback cushion, or delete; Joy's call).
- Cost backfill of evaluation runs #45, #46 and earlier: dry run first, then Joy authorizes `--apply` (ruling (b)).
- Visible changes: Customer 360, consent, playbooks, follow-ups, decision corpus and quarantine screens; affected cross-business customers may show blank phone/email (withheld) and counts from their own business only.

## 6. What is waiting for ChatGPT (next report)
Rulings needed on: the 7 review-pending outcome decisions (D4, D7, D10, D11, D12, D17, D18); **the identity leak in §4.3**; S4's Q1-Q10 (especially Q10, the re-stated "no workflow definitions" pin, now "no listed definition has a tool step"); S5's four (capture what a person would have sent on an "edited" review; reasons on the page; one decision vocabulary; a business column on decision records); S6's five (reviewer ≠ drafter; overlap with claims policy; most-specific-wins; Support runs loading playbooks now); S7's three; S3's seven; the quarantine repair design (docs/117 §6) and its four other questions; the cost/bypass stream's three. Each record's own questions section lists them.

## 7. Joy's items (none block engineering)
1. **Open the Executive shadow week** (dry run held every precondition on 10 Oct; ChatGPT re-approved it on 10 Oct). The apply line is in the resume point.
2. Approve the daily briefing job once in HQ.
3. New LaLaDia and Bionic admin tokens (rejected since 22 Sep).
4. The Hostinger shop to HQ network block.
5. Meta Business Verification (slowest clock), WhatsApp SIM, second order approver, ERP write token, KVM 4.
6. Release this wave when ready (§5).

## 8. Gate result on the final combined tree
- **main = `6726f51`, pushed.** Waves 2+3 merged at `5812d42`, plus one fix found by CI (below).
- Full Pest suite on the combined tree, parallel, on real Postgres: **2,042 passed, 0 failed, 30,817 assertions.** Pint passed; `tsc --noEmit` clean; Vite build clean.
- **GitHub Actions run 38079877403 on `6726f51`: SUCCESS, all three jobs** (Pest + Pint + TypeScript + Vite on PostgreSQL 17 / Redis 7; deployment/backup safety invariant; production image builds).
- **What CI caught that local runs did not** (run 38078297032 on `5812d42`, 1 failed / 2,040 passed): S5's console-report test. Cause: `decision_records.created_at` is timestamptz(0), so Postgres ROUNDS the write time to the nearest second; a record written at hh:mm:05.6 is stored as hh:mm:06, later than a report window ending at hh:mm:05.6 by PHP's clock, so it fell outside the window. CI's faster machine put the records and the report in one second; local runs crossed it. Fix `6726f51`: window bounds widened to whole seconds (start floored, end rounded up). A new test stamps a row exactly as the column rounds it, fails on the old bound and passes on the new; the file passed three runs in a row. (A first theory, dropped microseconds, was tested and disproved before the fix: the column stores no microseconds at all.)
- **Follow-up for the next session (not done, deliberately, to pause at a safe stage):** audit the other readers that bound a `*_at` query at "now" against a whole-second column for the same rounding effect: `AiBudget`, `ShadowEvidence`, `AdBudgetGuard`, `FactsOrdersDeliveredSales`, `AiHealthDashboard`, `ControlPlaneDashboard`, `ToolGateway`, `ToolRegistryController`, `CrossBusinessDetector`. Each may be fine (it depends on the column's precision and on whether the bound is "now"); none is known to be wrong.

