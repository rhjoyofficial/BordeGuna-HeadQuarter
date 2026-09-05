# Restore drills

A backup counts only once it has been restored and the application has run against the restored data.
Record every drill here with the exact output that decided it.

⚠ All backups described here are **local to the VPS** and are **not disaster-proof** (docs/28).

## ERP (Bor De Guna ERP, MySQL 8) - 5 September 2026 08:47-08:49 UTC

Scripts: `/opt/bordeguna-erp/deploy/restore-drill.sh` then `restore-appcheck.sh` (copied to `/root/`).
Source: newest nightly dump `/root/backups/bordeguna-erp/daily/erp-20260905-010000.sql.gz` (taken 01:00 UTC).
Target: scratch database `bordeguna_restore_drill` inside the live MySQL container. **Live `bordeguna_erp` untouched** (verified by the drill: 200 tables, 211 accounts, 388 punches before and after).

Result: **PASS.**

- gzip integrity OK; the dump restored with exit 0.
- Core tables present with matching counts to live: employees 9, attendance_punches 388, daily_attendance 387, leave_ledger 19, payroll_runs 1, products 153, suppliers 6, customers 4, product_prices 52, salary_structures 10, journals 14, journal_lines 38, foreign keys 515.
- Row-content hashes identical for users, employees and punches. `account_code_hash` differed only because `accounts` grew from 70 to 211 rows after the dump (the master expense chart of accounts was applied by two migrations on 5 Sep after 01:00). That is the backup being older than live, not corrupt.
- App check (throwaway container, `config:clear`, proved `DATABASE() = bordeguna_restore_drill`): 2 migrations pending (the same two), ledger integrity engine consistent (14 journals, 4 businesses), stock ledger consistent, 28 reconciliations agree, application read businesses/employees/punches/accounts/users from the restored data.
- Scratch database dropped afterwards.

State of the ERP's own backups: nightly local dumps healthy; the ERP compose file also defines an inactive `offsite` (restic) service whose destination credentials are empty. It has never run. Backblaze B2 is not available, so it stays inactive; it is not an HQ dependency and is not changed by HQ work.

## HQ (PostgreSQL 17) - local pipeline drill, 5 September 2026 (developer machine, Postgres 17.2)

Purpose: prove `deploy/backup.sh` and `deploy/restore.sh` do what they claim before they run in production.
Source: the local `hq` database (migrated + seeded). Target: scratch database `hq_restore_drill`.

Result: **PASS** (11:25 UTC).

- `backup.sh once` (BACKUP_DIR redirected to a scratch directory): `dump ok: hq-20260905-112556.dump (96944 bytes, 32 tables)`, `LAST_STATUS=0`, `LAST_OK` written.
- `restore.sh <dump> hq_restore_drill`: archive listed 32 tables; database recreated; `pg_restore --exit-on-error` completed; verification printed `tables=32 users=1 scheduled_jobs=4 job_runs=2 audit_logs=8 last_migration=2026_09_05_100400_add_two_factor_and_pgvector`.
- Live vs restored: both `5 businesses, 4 stores, 12 permissions, 32 tables`.
- Safety: `restore.sh <dump> hq` (the live database) was **REFUSED** without `CONFIRM_PRODUCTION_RESTORE=yes`, as designed.
- Scratch database dropped.

Limitation, stated plainly: the production copy of this pipeline writes to `/root/backups/bordeguna-hq` on the same VPS. It is not an offsite backup and does not survive loss of the VPS (ADR-014).

## HQ (PostgreSQL 17) - production, 5 September 2026 11:40 UTC (first deploy)

- `exec backup /backup.sh once`: `dump ok: /backups/daily/hq-20260905-114058.dump (190929 bytes, 32 tables)`.
- `exec backup /restore.sh` into `hq_restore_drill`: restored, verification printed `tables=32 users=1 scheduled_jobs=0 job_runs=0 audit_logs=1 last_migration=2026_09_05_100400_add_two_factor_and_pgvector` (matches production at that moment). Scratch database dropped.
- Result: **PASS.** Backups live in `/root/backups/bordeguna-hq` on the VPS only (ADR-014).
