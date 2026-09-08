# 32 - Architecture Decisions

Index of ADRs in `docs/adr/`. Each ADR: context, decision, alternatives, consequences, status.

| ADR | Decision | Status |
|---|---|---|
| [ADR-001](adr/001-separate-hq-application.md) | HQ is a separate Laravel application, not an ERP module | proposed |
| [ADR-002](adr/002-laravel-core-node-edge.md) | PHP/Laravel core; Node only for the realtime voice gateway | proposed |
| [ADR-003](adr/003-postgres-pgvector-redis.md) | Postgres 17 + pgvector + Redis for HQ; ERP stays on MySQL | proposed |
| [ADR-004](adr/004-tool-gateway-policy-first.md) | Every AI action passes a typed tool gateway and a deterministic policy engine | **accepted and realised** (gateway M1, policy engine M2, docs/50); refined by ADR-016 and ADR-017 |
| [ADR-005](adr/005-durable-scheduler-over-cron.md) | Business scheduling lives in HQ's `scheduled_jobs` + Horizon; cron is only the tick; scheduling is a core product feature | accepted |
| [ADR-006](adr/006-n8n-transitional.md) | n8n is retained as a transitional executor and replaced workflow by workflow after shadow mode | proposed |
| [ADR-007](adr/007-truth-stays-in-systems-of-record.md) | HQ holds read models only; systems of record are never duplicated | proposed |
| [ADR-008](adr/008-commerce-tier-separate-vps.md) | Shops migrate to a second VPS (commerce tier) | **deferred**: kept as a scaling option, superseded for now by ADR-012 |
| [ADR-009](adr/009-econic-commerce-platform-abstraction.md) | Commerce Platform Interface; WooCommerce is a legacy adapter, Econic will be a custom platform | accepted (Joy, 5 Sep) |
| [ADR-010](adr/010-business-brand-store-entity-model.md) | Business, Brand, Store, Product owner, Seller, Channel, Platform account as separate entities | accepted |
| [ADR-011](adr/011-external-side-effect-idempotency.md) | At-least-once execution with idempotent tools and reconciliation; no exactly-once claim | accepted |
| [ADR-012](adr/012-current-vps-first-capacity-strategy.md) | Deploy on the current VPS with swap, limits and monitoring; scale on evidence | accepted (Joy, 5 Sep) |
| [ADR-013](adr/013-behavior-alignment-without-fine-tuning.md) | Behavior Alignment Layer instead of training | accepted (Joy, 5 Sep) |
| [ADR-014](adr/014-local-backup-no-offsite-provider.md) | Local on-VPS PostgreSQL backup; Backblaze B2 removed; no offsite provider in the current deployment | accepted (Joy, 5 Sep) |
| [ADR-015](adr/015-erp-owns-commerce-hq-adapts-to-it.md) | The ERP owns commerce; HQ reaches it through the platform interface. Amends ADR-009, narrows ADR-010 | accepted (Joy, 6 Sep) |
| [ADR-016](adr/016-one-gateway-for-every-caller.md) | One tool gateway for every caller; the scheduler stops executing tools directly | **accepted and realised** (Phase 2 M2, docs/50; scheduler runs in gateway mode on production) |
| [ADR-017](adr/017-risk-tier-and-action-class-are-orthogonal.md) | Risk tier is intrinsic to a tool; action class is derived by policy per call | **accepted** (Phase 2 M1, docs/49) |
| [ADR-018](adr/018-approval-binds-a-payload-hash.md) | An approval binds a canonical payload hash and a tool version, and is single use | proposed (Phase 2) |
| [ADR-019](adr/019-provider-neutral-model-router.md) | The model router is provider-neutral; no model id appears in business code | proposed (Phase 2) |

Decisions inherited from the existing estate and treated as settled unless Joy reopens them: LaLaDia is the product and Bionic its fork (fixes flow upstream then down); brands are a product dimension in the ERP, not tenants; per-account identifiers are settings rows, never code literals; every schema change ships with its migration in the same commit; deploy only after CI is green.
