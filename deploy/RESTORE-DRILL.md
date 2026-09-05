# Restore drills

A backup counts only once it has been restored and the application has run against the restored data.
Record every drill here with the exact output that decided it.

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

Open: **offsite replication of these dumps is still not running.** The compose `offsite` service needs `B2_ACCOUNT_ID` and `B2_ACCOUNT_KEY` in `/opt/bordeguna-erp/.env` (both currently empty). Until then every ERP backup lives on the same disk as the database.

## HQ (PostgreSQL 17)

_First drill to be recorded after the first production deploy: `docker compose ... exec backup /restore.sh` into `hq_restore_drill`, then table and row counts, then drop._
