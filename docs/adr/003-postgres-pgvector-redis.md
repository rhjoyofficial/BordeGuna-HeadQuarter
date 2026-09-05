# ADR-003: Postgres 17 + pgvector + Redis for HQ

**Status:** proposed

## Context
The VPS runs MySQL 8 (ERP) and two Postgres instances (n8n memory on 17, Zeno on 16). HQ needs JSONB-heavy tables (traces, memory, payloads), vector similarity, and a real queue backend.

## Decision
HQ gets its own Postgres 17 container with the pgvector extension and its own Redis 7. The ERP stays on MySQL. The hPanel Postgres 17 (`postgresql-4jjn`) is not reused for HQ.

## Alternatives
- Reuse `postgresql-4jjn`: saves ~300 MB RAM but couples HQ to an hPanel-managed instance with no compose file on disk and mixes it with the bot's tables. Rejected; the ERP made the same call for the same reason.
- MySQL for HQ for uniformity with the ERP: no native vector type, weaker JSON. Rejected.
- Separate vector DB (Qdrant): another service to back up for a corpus of a few thousand chunks. Rejected until scale demands it.
- Database queue driver (as the shops use): cannot carry Horizon-grade load or supervision. Rejected.

## Consequences
One more Postgres on the host (RAM budgeted in doc 27); one backup stream; embeddings rebuildable so a vector model change is a job, not a migration.
