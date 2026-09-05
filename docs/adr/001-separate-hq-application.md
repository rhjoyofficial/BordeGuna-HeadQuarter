# ADR-001: HQ is a separate application, not an ERP module

**Status:** proposed (5 Sep 2026)

## Context
The Bor De Guna ERP is live, 200 tables, 3,081 tests, runs payroll and accounting, and already has a permission-scoped AI assistant. It would be natural to add HQ as its ninth module.

## Decision
Build HQ as its own Laravel application with its own database, deployed beside the ERP, integrating through a new ERP API.

## Alternatives
1. **Extend the ERP.** Fastest start; shares RBAC and approvals directly. Rejected because HQ's workload (external webhooks, model calls, ad-platform writes, media generation, long-running workflows, Redis/Horizon, pgvector) would land in the same process and failure domain as payroll; the ERP stack deliberately has no route to n8n or the internet beyond its own needs; its release cadence and 3k-test suite would slow every HQ iteration; and the ERP is MySQL while HQ wants pgvector.
2. **Node/Python control plane.** Rejected in ADR-002.

## Consequences
- An ERP API must be built (additive, read-first).
- RBAC and approval patterns are copied, not shared; role names are mirrored.
- Two deploys, two databases, two backup streams; accepted for isolation.
- HQ can later be sold with the shop platform without dragging the ERP along.
