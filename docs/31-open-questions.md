# 31 - Open Questions and Recorded Answers

Decisions only Joy or the boss (C M Moin) can take. Joy answered the whole list on 5 September 2026 (his words are kept in the "Answer" column; the "Applied" column says where the design reflects it). Nothing here is assumed.

## Decisions recorded 5 Sep 2026

| # | Question | Answer (Joy) | Applied |
|---|---|---|---|
| Q1 | GitHub account for the repository | personal `rhjoyofficial` | repo pushed there |
| Q2 | HQ domain | `hq.bordeguna.com` for now; it changes when the domain is transferred later | DNS live -> 200.97.162.40 (verified 5 Sep); Traefik host rule uses it; a rename is a label + `.env` change |
| Q3 | Capacity | same VPS for now; upgrade when needed | ADR-012 |
| Q4 | AI provider accounts | Bor De Guna org; Joy adds keys when needed; must be changeable from the UI | `integration_credentials` screen (Phase 0); model providers read from it (Phase 2) |
| Q5 | Budget caps | "as much as needs, no barrier for now" | doc 12 keeps guardrails as configurable rows; **defaults will ship unlimited until Joy sets caps**; cost is still tracked per run (doc 26) |
| Q6 | Econic | full rework, custom design, multi-vendor: LaLaDia, Bionic and other products | ADR-009, doc 15 |
| Q7 | Econic as ERP business/brand | **No.** Econic is not an ERP business row; Azmion stays IT-services only | HQ keeps Econic as an HQ-only business/store with no ERP id; brand code `ECN` is HQ-only (marketing), not an ERP brand |
| Q8 | Which brands get customer AI first | both (LaLaDia and Bionic) | Phase 3 scope |
| Q9 | Stock truth for availability | inactive product -> unavailable; otherwise always in stock; never depends on quantity | doc 15 availability policy, `get_availability` |
| Q10 | Cost prices in the ERP | Joy fills them right before the attribution task starts | Phase 5 prerequisite, his |
| Q11 | Pathao callback owned by bizmation | remove bizmation; the callback must serve us | Phase 1/4 task: repoint the Pathao webhook to the shop once bizmation is retired |
| Q12 | Meta portfolio consolidation | new plan: all LaLaDia and Bionic Meta access moves to (or is created under) a new official account, not Joy's | docs 16, 18: token and app ownership under the boss's new profile |
| Q13 | One Meta app for all Pages | yes, created from the boss's new profile | doc 18 |
| Q14 | Privacy policy / terms per brand | write once, host on each domain | Phase 1 deliverable before App Review |
| Q15 | Instagram accounts linked | unknown; will check and create where missing | Phase 3 discovery |
| Q16 | WhatsApp Business | new WABA in the Bor De Guna portfolio | Phase 3 |
| Q17 | Google Ads / Merchant / Search Console / YouTube | all exist; Joy shares access when needed | Phase 5 |
| Q18 | TikTok and X | TikTok after a product feed exists; X no | roadmap |
| Q19 | Telephony | research local SIP providers only, no foreign numbers | doc 19 |
| Q20 | Bangla realtime speech quality | test in Phase 7 week 1 | doc 19 |
| Q21 | Avatar and voice | synthetic presenter by default; the boss's real face and voice as an option, with consent | doc 20 |
| Q22 | Approvers | Joy for operations/ads, brand managers for content, boss for HUMAN_ONLY; **Joy holds every approval right so nothing is left blank** | doc 13: Joy is `operator` with all permissions; role matrix in the RBAC seeder |
| Q23 | Hermes Agent | start fresh; skip the existing setup; not a problem to rebuild what is needed | doc 22, ADR-006: Hermes is not migrated; it is retired once HQ covers its one useful cron, on explicit approval |
| Q24 | Old Bionic bot handoff row and bot state | can all be removed; build fresh when needed, keep the know-how as a skill | doc 22: n8n workflows are replaced, not migrated; deletion only after explicit approval |
| Q25 | Mail after the cPanel exit | Zoho Mail or Google Workspace; decide before DNS moves | doc 33 |
| Q26 | CarryBee credentials in OneDrive plaintext | do it this week; the couriers will be used | security task, his |
| Backups | (asked by Joy, 5 Sep) | **Backblaze B2 is not available and not part of the deployment.** Local on-VPS PostgreSQL backup with the limitation documented; offsite is a future enhancement only | ADR-014, doc 28 |
| AI training | (design decision) | no fine-tuning or training | ADR-013 |

## Still genuinely open

| # | Question | Blocks |
|---|---|---|
| Q6b | Is the rebuilt Econic platform a fork of the LaLaDia product or a new codebase, and when? Should HQ integrate legacy WooCommerce meanwhile (needs REST keys)? | Phase 1 adapter list |
| Q12b | When does the boss's new official Meta profile exist, and who administers it day to day? | Phases 3, 5, 6 |
| Hosting snapshot | Is the weekly Hostinger snapshot enabled on `srv1808022`? (the only thing that would survive a disk loss) | disaster-recovery statement in doc 28 |

Nothing in this document asks for Backblaze B2.
