# BordeGuna-HeadQuarter

The central operating and intelligence layer for every Bor De Guna business: LaLaDia, Bionic Garden, Azmion, Econic, and whatever comes next.

**Status: AUDIT + DESIGN. No application code exists yet.** Everything in this repository is documentation produced from a read-only inspection of the live systems on 5 September 2026. Nothing in production was changed.

## Read in this order

1. `docs/00-executive-vision.md` - what HQ is and is not
2. `docs/01-current-state-audit.md` - what exists today, verified
3. `docs/02-target-architecture.md` - the recommended shape and why
4. `docs/30-development-roadmap.md` - phases, dependency graph, the first step
5. `docs/31-open-questions.md` - decisions only Joy or the boss can make
6. `docs/33-nikolahost-to-hostinger-migration.md` - the hosting move

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
