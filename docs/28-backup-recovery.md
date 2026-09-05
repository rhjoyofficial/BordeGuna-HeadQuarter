# 28 - Backup and Disaster Recovery

## Decision (5 Sep 2026)

**Backblaze B2 is not available and is not part of the current deployment.** No offsite object-storage provider is configured for HQ. HQ's backup is a **local, on-VPS PostgreSQL backup**. This is a current architectural decision, not an open question.

⚠ **LOCAL VPS BACKUP IS NOT DISASTER-PROOF.** It protects against a bad migration, a dropped table, a corrupt row or a bad release. It does **not** protect against losing the VPS, its disk, or the Hostinger account. Nothing in this document is an offsite backup.

## HQ backup (what runs)

| Item | Value |
|---|---|
| Mechanism | `backup` container (postgres:17-alpine) running `deploy/backup.sh`, daily at 01:00 UTC, plus `backup.sh once` before every release |
| Format | `pg_dump -Fc` (custom, compressed), `--no-owner --no-privileges` |
| Location | host directory `/root/backups/bordeguna-hq/{daily,weekly}` bind-mounted into the container; **separate from the database volume** (`dbdata`), same disk |
| Verification | size floor (4 KB) and `pg_restore --list` must enumerate at least 5 tables; otherwise the run fails, alerts, and retention is skipped |
| Retention | 14 daily, 8 weekly (weekly copy taken on Sundays), pruned only after a verified dump exists |
| Markers | `LAST_RUN`, `LAST_STATUS` (0/1), `LAST_OK` (newest verified dump); the host monitor alerts when the newest backup is older than 30 h |
| Alerts | Telegram on local dump failure |
| Extension point | `OFFSITE_HOOK`: an executable receiving the verified dump path; unset today. A future offsite provider plugs in here without changing the dump pipeline |

## HQ restore

`deploy/restore.sh` restores a dump into a **scratch** database by default (`hq_restore_drill`), prints table and row counts, and refuses to target the production database unless `TARGET_DB` and `CONFIRM_PRODUCTION_RESTORE=yes` are both set. Procedure and commands: `deploy/README.md` ("Backup and restore", "Rollback").

Drills: recorded in `deploy/RESTORE-DRILL.md`. Cadence: after the first production deploy, then monthly and after every schema change.

## Other backups on the VPS (state as observed 5 Sep 2026, untouched by HQ work)

| System | Backup | Offsite | Notes |
|---|---|---|---|
| ERP (MySQL 8) | nightly `mysqldump` in its own `backup` container, 14 daily / 8 weekly, `/root/backups/bordeguna-erp`; fresh daily (verified 3, 4, 5 Sep) | **none running.** The ERP compose defines an `offsite` service (restic) behind a profile; it has never started because its B2 credentials are empty. B2 is not available, so this service stays inactive. Not an HQ concern; any change to it is a separate, approved ERP task | restore drill PASSED 5 Sep (see RESTORE-DRILL.md) |
| n8n + bot Postgres | nightly script, 14 days, `/root/backups/automation` | none | |
| Zeno | nightly, on-box | none | |
| LaLaDia, Bionic (cPanel) | manual dumps before deploys, in `/home/sangramn` | none | |
| Hostinger platform snapshot | weekly per earlier notes; **UNKNOWN whether enabled** | provider-side | the only thing that would survive a disk loss; worth confirming in hPanel |

## What restic is for, and why HQ does not use it

restic is an encrypted, deduplicating backup tool whose value is an **offsite repository**. With no offsite destination it adds a binary, a password to protect and a repository on the same disk, for no protection the plain dump does not already give. It is therefore **removed from the HQ backup path**. The ERP's inactive restic definition is left as it is.

## Recovery procedures

| Scenario | Procedure | Target time |
|---|---|---|
| Bad migration / data error | restore the pre-deploy dump into `hq_restore_drill`, inspect, then restore into `hq` after stopping app/horizon/scheduler (`deploy/README.md`) | 30 min |
| Bad release | `deploy/rollback.sh <sha>` (code only; schema changes are additive) | 10 min |
| Compromised credential | revoke at the provider, revoke the row in HQ, audit `tool_calls`/`audit_logs` for the window | 1 h |
| Provider outage | queues hold, health degrades, humans notified; no data loss by design | n/a |
| **Lost VPS** | **not covered by the current backups.** Recovery would depend on a Hostinger snapshot if one exists. Rebuild from git is possible for code and configuration (secrets excepted); data since the last snapshot would be lost | not guaranteed |

## Future enhancement (not scheduled, not a prerequisite)

An offsite copy of `/root/backups/bordeguna-hq` to any object storage or a second machine, attached through `OFFSITE_HOOK`. Candidates when the business wants it: Hostinger Object Storage, a second VPS with rsync over SSH, or any S3-compatible store. This would be the point to reconsider restic. Until then the limitation above stands and is stated wherever backups are described.

## Migration rollback rule

Schema changes are additive (new column nullable, new table); destructive changes happen in a later release after the code stopped reading the old shape, and only after a verified backup. Standing rule across the ERP and shops.
