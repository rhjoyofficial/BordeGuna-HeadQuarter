#!/bin/bash
# Restore an HQ dump into a database. Defaults to a SCRATCH database so a drill never
# touches production. Used from inside the backup container:
#
#   docker compose -f deploy/docker-compose.yml exec backup /restore.sh                 # newest local dump -> hq_restore_drill
#   docker compose -f deploy/docker-compose.yml exec backup /restore.sh /backups/daily/hq-X.dump
#   docker compose -f deploy/docker-compose.yml exec backup /restore.sh <dump> hq_restore_drill
#
# Restoring INTO PRODUCTION requires TARGET_DB=<production db> and CONFIRM_PRODUCTION_RESTORE=yes,
# and is only done after `docker compose stop app horizon scheduler`.
#
# ⚠ docs/132: restoring PRODUCTION drops every `events` row that arrived after the dump; those webhooks were
# answered 202 and will not be resent. Pre-step: hq:events:export-since. Post-step, before the scheduler starts:
# hq:events:import (dry run, then --apply). This script does neither; deploy/rehearsal/README.md has the steps.
set -euo pipefail
export PGPASSWORD="${DB_PASSWORD:?DB_PASSWORD is not set}"
HOST=${DB_HOST:-db}; PORT=${DB_PORT:-5432}; USER=${DB_USERNAME}
DUMP=${1:-$(ls -t /backups/daily/hq-*.dump | head -1)}
TARGET=${2:-${TARGET_DB:-hq_restore_drill}}

log() { echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*"; }

if [ "${TARGET}" = "${DB_DATABASE}" ] && [ "${CONFIRM_PRODUCTION_RESTORE:-no}" != "yes" ]; then
    log "REFUSED: target is the production database. Set CONFIRM_PRODUCTION_RESTORE=yes to proceed."; exit 2
fi

log "dump: ${DUMP} ($(stat -c %s "${DUMP}") bytes)"
log "verifying archive"
TABLES=$(pg_restore --list "${DUMP}" | grep -c 'TABLE DATA')
log "archive lists ${TABLES} tables"

log "recreating ${TARGET}"
psql -h "${HOST}" -p "${PORT}" -U "${USER}" -d postgres -v ON_ERROR_STOP=1 -q \
    -c "DROP DATABASE IF EXISTS \"${TARGET}\";" -c "CREATE DATABASE \"${TARGET}\";"
psql -h "${HOST}" -p "${PORT}" -U "${USER}" -d "${TARGET}" -q -c "CREATE EXTENSION IF NOT EXISTS vector;" 2>/dev/null || true

log "restoring"
pg_restore -h "${HOST}" -p "${PORT}" -U "${USER}" -d "${TARGET}" --no-owner --no-privileges --exit-on-error "${DUMP}"

log "verifying restored database"
psql -h "${HOST}" -p "${PORT}" -U "${USER}" -d "${TARGET}" -At <<'SQL'
select 'tables=' || count(*) from information_schema.tables where table_schema='public';
select 'users=' || count(*) from users;
select 'scheduled_jobs=' || count(*) from scheduled_jobs;
select 'job_runs=' || count(*) from job_runs;
select 'audit_logs=' || count(*) from audit_logs;
select 'last_migration=' || coalesce(max(migration),'none') from migrations;
SQL
log "restore into ${TARGET} complete"
if [ "${TARGET}" != "${DB_DATABASE}" ]; then
    log "drill database left in place for inspection; drop it with: psql ... -c 'DROP DATABASE \"${TARGET}\"'"
fi
