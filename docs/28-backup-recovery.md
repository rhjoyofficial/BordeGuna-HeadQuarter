# 28 - Backup and Disaster Recovery

## State of backups today (verified)

| System | Backup | Offsite | Restore tested |
|---|---|---|---|
| ERP (VPS) | nightly `mysqldump` in the `backup` container, 14 daily / 8 weekly, on the same disk; ~100 pre-deploy dumps in `/root` | **No.** `offsite` (restic -> Backblaze B2) is defined in compose but not running | drill scripts exist (`restore-drill.sh`); last run UNKNOWN |
| n8n + bot Postgres | nightly script, 14 days, `/root/backups/automation` | No | UNKNOWN |
| Zeno | nightly, on-box | No | self-verifying script |
| LaLaDia, Bionic (cPanel) | manual dumps before each deploy, stored in `/home/sangramn` | No (same account) | restores rehearsed on the local machine |
| Econic (Woo) | UNKNOWN | UNKNOWN | UNKNOWN |
| Hostinger platform snapshot | weekly (per earlier notes) | provider-side | never tested |

**Every backup that exists lives on the same disk or account as the data it protects.** The first DR action of Phase 0 is closing that for the ERP (deploy `offsite`), then applying the same pattern to HQ from day one.

## HQ backup design

| What | How | Where | Retention |
|---|---|---|---|
| Postgres | nightly `pg_dump -Fc` + hourly WAL-less logical dump of critical tables (`approvals`, `audit_log`, `scheduled_jobs`) | volume, then restic (encrypted, deduplicated) to Backblaze B2 bucket `bordeguna-hq-backup` | 14 daily, 8 weekly, 12 monthly |
| Media/assets | already in S3-compatible object storage; bucket versioning on | B2 | versions 90 days |
| Configuration | `deploy/`, compose, nginx in git; `.env` and `RESTIC_PASSWORD` in the owner's password manager (not on the server only) | git + password manager | forever |
| Secrets recovery | `integration_credentials` are in the DB dump, decryptable only with `APP_KEY`; `APP_KEY` stored in the password manager with the restic password | password manager | forever |
| Redis | not backed up (queues are re-derivable from `scheduled_jobs`/`events`; cache is disposable) | | |

Every backup self-verifies: size floor, `pg_restore --list` succeeds, restic `check --read-data-subset` weekly, and a monthly **restore drill** into a scratch container that boots the app and runs a read tool. A backup that fails verification alerts Telegram and does not prune older copies.

## Recovery procedures

| Scenario | Procedure | Target time |
|---|---|---|
| Bad migration / data error | restore last verified dump into a scratch db, extract, or full restore with `pg_restore` after `docker compose stop horizon scheduler` | 30 min |
| Lost VPS | new VPS, restore compose from git, `.env` + keys from the password manager, `restic restore` latest, `pg_restore`, repoint DNS (TTL kept at 300 s) | 2-4 h |
| Bad release | doc 27 rollback | 10 min |
| Compromised credential | revoke at provider, rotate row, audit `tool_calls` for the window | 1 h |
| Provider outage (Meta, OpenAI) | circuit breaker opens, queue holds, fallback model tier where allowed, humans notified; no data loss by design | n/a |

## Migration rollback rule

Schema changes are additive (new column nullable, new table); destructive changes happen in a later release after the code stopped reading the old shape, and only after a verified backup. This is the standing rule already applied to the ERP and shops.

## Not production-ready until

Offsite for ERP running and drilled; HQ offsite running from the first deploy; one full "lost VPS" drill performed on a scratch VPS and timed.
