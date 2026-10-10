# 104. HQ progress report: the plan, what is done, what is left (10 October 2026)

Written at Joy's request. The plan is `docs/89` (the full HQ plan, 22 Sep) measured against his vision document (`docs/95`, 3 Oct). Percentages are engineering estimates, not measurements; the method is in section 5.

## 1. The plan in one page
**What HQ is:** the head office above the street. One private website (`hq.bordeguna.com`) above the ERP, LaLaDia, Bionic Garden, Econic and Azmion that:
- **watches** every business and says what needs attention;
- **answers** questions in Bangla or English from live records, never from memory;
- **acts** only through controlled tools, each checked against rules and a named person's authority;
- **schedules** work and makes sure it happens once;
- **remembers** the owner's preferences and approved procedures;
- **writes down** why every action happened, who allowed it and what came of it.
It never becomes the truth: the ERP owns stock, money and staff; the shops own orders.

**The promise:** one view, one assistant, every action governed, and nothing moves money or speaks to a customer on HQ's own authority until autonomy is earned from measured evidence and signed by the owner.

**The 14 agents:** Executive, Analytics, Support (built) · Operations, Inventory, Marketing, Advertising, Content, Creative, Voice (planned) · Finance, Procurement, Sales, HR (proposed).

**The phases:**
| Phase | Scope |
|---|---|
| 0 Foundation | app, login, 2FA, roles, business/brand/store model, vault, audit, scheduler, Docker, backups |
| 1 Integrations | ERP API, LaLaDia and Bionic adapters, events, identity, order facts |
| 2 Command center | gateway, policy, approvals, model router, Executive + Analytics, briefing, Ask HQ, dashboards, alerts |
| 2.5 Shared foundation | capability registry, consent ledger, autonomy resolver, evaluation, media, conversation + memory core |
| 3 Customer AI | inbox, hand-over, Support agent, Messenger, Instagram, comments, WhatsApp |
| 4 AI orders | order proposals as ERP drafts, quote, status, cancel, second approver, payment + courier state, follow-ups |
| 5 Ads | insights, contribution profit, budget guard, experiments, prepared campaigns |
| 6 Content + research | research engine, drafts from facts, claims check, Bangla render, publishing, metrics, image/video |
| 7 Voice | voice notes, then COD confirmation calls and a receptionist |
| 8 Avatar | consented presenter videos, YouTube |
| 9 Skills + delegation | skill proposals, cross-business delegation, autonomy widened by evidence |
| 10 Controlled autonomy | owner sees only exceptions; grants widened only on measured evidence |

**The never list (every phase, every level):** move money; touch ad limits/billing/ownership; invent a product fact or testimonial; act beyond a named person's authority; let a requester approve their own order; message without consent; take instructions from content it read; take HR decisions; publish anything other than the exact approved version; use a face or voice without written consent; delete its own record; promote itself.

## 2. What was accomplished (to 10 Oct)
- **Production:** live since 5 Sep, 454 commits, now on `b96a396` (released 9 Oct, backup first, verified). 1,847 automated tests; CI green.
- **Phase 0, 1, 2.5:** complete and live.
- **Phase 2:** complete. All safety gates now passed: Gate 13 (26 Sep), Executive integrity 12/12, **P3-G2 attempt 5 PASSED 10 Oct** (Support 43/43, safety 80/80, 0 script violations). Only the 7-day shadow week (Joy opens it) stands between the owner briefing and live use.
- **Since 22 Sep, merged:** OpenAI provider; runtime role boundaries; Phase 3 channel adapters (Messenger, Instagram, WhatsApp, inert); live wake-up path + hand-over Telegram ping; Bangla/English language contract; event-triggered jobs + delivery reconciliation; Phase 4 order path into the ERP approval inbox (ERP side live on ERP production); Phase 5 ads read side; Phase 6 research engine + content drafts + FB/IG publishers (inert); Phase 7 voice-note foundation; ConnectorRegistry; ErpPlatform; HQ-native WorkflowEngine with 180-day retention; Bangla/English operator screens + Activation posture page.

## 3. Percentages
| Phase | Built (code) | Live (in real use) | Main thing left |
|---|---|---|---|
| 0 Foundation | 100% | 100% | none |
| 1 Integrations | 100% | ~80% | new shop admin tokens; shop to HQ network block |
| 2 Command center | ~98% | ~50% (dashboards, alerts; AI not yet) | shadow week (7 days), briefing job |
| 2.5 Shared foundation | 100% | 100% (inert parts stay off) | none |
| 3 Customer AI | ~80% | 0% | Meta verification + App Review + WhatsApp number; provider registration; comment replies; Customer 360 page; consent screens |
| 4 AI orders | ~60% | 0% | a store admitted, second approver, ERP write token, payment link, courier, fraud check, find-customer-orders tool |
| 5 Ads | ~35% | 0% | system-user token; write side (prepare/pause) not built; cost prices in ERP |
| 6 Content + research | ~40% | 0% | search key, claims policy, Page tokens; image/video generation not started; render service |
| 7 Voice | ~20% | 0% | speech key; telephony + licensed number |
| 8 Avatar | ~5% | 0% | consent decision, design only |
| 9 Skills + delegation | ~25% | 0% | proposal loop, delegation |
| 10 Controlled autonomy | ~10% (machinery) | 0% | earned by evidence, never scheduled |

**Whole plan, weighted by phase size:** about **65% built**, about **30% in real use**, **0% of AI live** (by design until the shadow week opens).
Against the vision document (docs/95's 27 items): about **40%** built (up from about a third on 3 Oct).

## 4. What is still left
**Engineering (mine, no outside wait):** Customer 360 page; follow-up / opportunity / outcome entities (inert); action to order to revenue linkage; decision-corpus reader (learning loop); playbook store; ads write side (inert); image-generation provider adapter once chosen; comment replies; consent withdrawal / do-not-contact screens; `SupportWakeUp::settle()` fix before any A2; route the other 8 effectful tools through the gateway; `evaluation_runs.cost_usd` recording.
**Joy's (they set the live dates):** open the shadow week; approve the daily briefing job; new LaLaDia + Bionic admin tokens; Hostinger shop to HQ block; **Meta Business Verification (slowest clock)**, App Review, WhatsApp SIM in the company's name; second order approver; ERP write token; company card on providers; KVM 4; search key; claims policy; Page tokens; image provider choice; legal read (PDPA 2026, BTRC).

## 5. How the numbers were made
Phase figures start from docs/89 §5 (22 Sep) and docs/95 §3-4 (3 Oct), then add the streams merged since (git history of main). Weights: 0 8, 1 8, 2 14, 2.5 8, 3 14, 4 12, 5 9, 6 9, 7 6, 8 4, 9 4, 10 4. "Live" counts only what production uses today.
