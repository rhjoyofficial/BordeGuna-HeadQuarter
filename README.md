# BordeGuna-HeadQuarter

The central operating and intelligence layer for every Bor De Guna business: LaLaDia, Bionic Garden, Azmion, Econic, and whatever comes next.

**Status: Phase 0 accepted and deployed at https://hq.bordeguna.com (5 Sep 2026). Phase 1 is in readiness review; no Phase 1 code exists.** `apps/hq` is the Phase 0 application; `deploy/` is its production stack; `docs/` is the architecture set. The architecture-correction review is `docs/34-architecture-review-2026-09-05.md`.

## Read in this order

1. `docs/00-executive-vision.md` - what HQ is and is not
2. `docs/01-current-state-audit.md` - what exists today, verified
3. `docs/02-target-architecture.md` - the recommended shape and why
4. `docs/30-development-roadmap.md` - phases, dependency graph, the first step
5. `docs/31-open-questions.md` - decisions only Joy or the boss can make
6. `docs/34-architecture-review-2026-09-05.md` - locked decisions, models, Phase 0 checklist (Phase 0 accepted, deployed 5 Sep 2026)
7. `docs/35-phase-1-readiness-review-2026-09-05.md` - Phase 1 readiness review: contracts, flows, order, risks, open decisions
8. `docs/37-resume-briefing-2026-09-06.md` - read-first briefing for anyone joining cold: what exists, what is deployed, what waits
9. `docs/38-phase-1-production-readiness-2026-09-06.md` - **the current step**: exact production change lists, migration impact, token/webhook plans, backup and rollback per repository, P-1 recommendation, order of operations
10. `docs/33-nikolahost-to-hostinger-migration.md` - the future hosting move (not a Phase 0 prerequisite)

Everything else in `docs/` is a subsystem design and can be read when that subsystem is being built.

## Ground rules baked into every document

- ERP, shops, CRM and finance are the sources of truth. HQ reasons over them and performs controlled actions. It never becomes a second copy of business truth.
- Every AI action goes: intent -> structured action -> policy -> permission -> approval (if required) -> tool execution -> audit -> feedback.
- Anything not verified is marked **UNKNOWN**. Nothing in these documents is assumed.
- Nothing destructive, no migration of production systems, no irreversible change without explicit approval.

## Repository layout (planned)

```
docs/            architecture set (this audit)
docs/adr/        architecture decision records
apps/hq/         Laravel 12 application (Phase 0 onward)
deploy/          docker compose stack for the Hostinger VPS
```
