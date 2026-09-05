# 32 - Architecture Decisions

Index of ADRs in `docs/adr/`. Each ADR: context, decision, alternatives, consequences, status.

| ADR | Decision | Status |
|---|---|---|
| [ADR-001](adr/001-separate-hq-application.md) | HQ is a separate Laravel application, not an ERP module | proposed |
| [ADR-002](adr/002-laravel-core-node-edge.md) | PHP/Laravel core; Node only for the realtime voice gateway | proposed |
| [ADR-003](adr/003-postgres-pgvector-redis.md) | Postgres 17 + pgvector + Redis for HQ; ERP stays on MySQL | proposed |
| [ADR-004](adr/004-tool-gateway-policy-first.md) | Every AI action passes a typed tool gateway and a deterministic policy engine | proposed |
| [ADR-005](adr/005-durable-scheduler-over-cron.md) | Business scheduling lives in HQ's `scheduled_jobs` + Horizon; cron is only the tick | proposed |
| [ADR-006](adr/006-n8n-transitional.md) | n8n is retained as a transitional executor and replaced workflow by workflow after shadow mode | proposed |
| [ADR-007](adr/007-truth-stays-in-systems-of-record.md) | HQ holds read models only; systems of record are never duplicated | proposed |
| [ADR-008](adr/008-commerce-tier-separate-vps.md) | Shops migrate to a second VPS (commerce tier), not onto the ERP/HQ box | proposed |

Decisions inherited from the existing estate and treated as settled unless Joy reopens them: LaLaDia is the product and Bionic its fork (fixes flow upstream then down); brands are a product dimension in the ERP, not tenants; per-account identifiers are settings rows, never code literals; every schema change ships with its migration in the same commit; deploy only after CI is green.
