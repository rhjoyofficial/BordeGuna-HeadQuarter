# ADR-005: Business scheduling in HQ tables, cron only as the tick

**Status:** proposed

## Context
Recurring business work today is spread over Laravel `Schedule::` entries in three apps, n8n triggers, a Hermes cron and host crontab lines, with no shared history, no UI and no natural-language entry.

## Decision
All business scheduling (recurring, one-time, interval, event-triggered) is a row in HQ `scheduled_jobs`, with one logical slot per unique `job_runs(job_id, scheduled_for)` row (no duplicate scheduling; execution itself is at-least-once with idempotent tools, ADR-011), dispatched to Horizon, with retry, pause/resume, cancel, modify and per-run history. `schedule:work` in its own container is the tick. Host cron and systemd timers are for infrastructure only.

## Alternatives
- n8n Schedule Triggers: no policy layer, weak history, credentials outside HQ. Rejected for business jobs.
- Temporal or another workflow server: strong, but a heavy additional service on an 8 GB box for one operator. Revisit if workflow volume grows.

## Consequences
The ERP's and shops' own `Schedule::` entries stay where they are (they are internal to those systems). HQ exposes the NL entry point and the UI.
