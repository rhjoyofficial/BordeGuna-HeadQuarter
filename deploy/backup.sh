#!/bin/bash
# Nightly HQ database backup + encrypted offsite replication.
#
# Local:   pg_dump -Fc -> /backups/daily/hq-<stamp>.dump, verified with pg_restore --list,
#          retention 14 daily / 8 weekly (weekly copy taken on Sundays).
# Offsite: restic backup of /backups to RESTIC_REPOSITORY (Backblaze B2) when the B2
#          credentials are present; restic check nightly, read-data subset weekly.
#
# The local dump NEVER fails because offsite is unconfigured: an unconfigured offsite is a
# loud WARNING every night, not a silent skip and not a crash loop.
#
# Run modes:  /backup.sh            long-lived loop, backs up at BACKUP_HOUR_UTC (default 01)
#             /backup.sh once       one backup now, then exit (used by the deploy runbook)
set -uo pipefail

BACKUP_DIR=/backups
RETAIN_DAILY=${RETAIN_DAILY:-14}
RETAIN_WEEKLY=${RETAIN_WEEKLY:-8}
BACKUP_HOUR_UTC=${BACKUP_HOUR_UTC:-01}
export PGPASSWORD="${DB_PASSWORD:?DB_PASSWORD is not set}"

log() { echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*"; }
notify() {
    # Optional Telegram notice; silent if not configured.
    [ -n "${ALERT_TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${ALERT_TELEGRAM_CHAT_ID:-}" ] || return 0
    curl -s -m 10 -X POST "https://api.telegram.org/bot${ALERT_TELEGRAM_BOT_TOKEN}/sendMessage" \
        -d chat_id="${ALERT_TELEGRAM_CHAT_ID}" --data-urlencode text="$1" >/dev/null 2>&1 || true
}

offsite_configured() {
    [ -n "${RESTIC_REPOSITORY:-}" ] && [ -n "${RESTIC_PASSWORD:-}" ] && [ -n "${B2_ACCOUNT_ID:-}" ] && [ -n "${B2_ACCOUNT_KEY:-}" ]
}

take_backup() {
    local stamp target size tables
    stamp=$(date -u +'%Y%m%d-%H%M%S')
    mkdir -p "${BACKUP_DIR}/daily" "${BACKUP_DIR}/weekly"
    target="${BACKUP_DIR}/daily/hq-${stamp}.dump"

    if ! pg_dump --host="${DB_HOST:-db}" --port="${DB_PORT:-5432}" --username="${DB_USERNAME}" \
            --format=custom --compress=6 --no-owner --no-privileges "${DB_DATABASE}" > "${target}"; then
        log "FAILED: pg_dump returned non-zero"; rm -f "${target}"; return 1
    fi

    size=$(stat -c %s "${target}")
    if [ "${size}" -lt 4096 ]; then
        log "FAILED: dump suspiciously small (${size} bytes)"; return 1
    fi

    # Verify the archive is readable and lists tables. A dump that cannot be listed cannot be restored.
    tables=$(pg_restore --list "${target}" 2>/dev/null | grep -c 'TABLE DATA')
    if [ -z "${tables}" ] || [ "${tables}" -lt 5 ]; then
        log "FAILED: pg_restore --list found only ${tables:-0} tables in the dump"; return 1
    fi
    log "dump ok: ${target} (${size} bytes, ${tables} tables)"
    echo "${target}" > "${BACKUP_DIR}/LAST_OK"

    if [ "$(date -u +%u)" = "7" ]; then
        cp "${target}" "${BACKUP_DIR}/weekly/hq-week-$(date -u +%Y%m%d).dump" && log "weekly copy taken"
    fi

    # Retention (local). Only after a verified dump exists.
    ls -t "${BACKUP_DIR}/daily/"hq-*.dump 2>/dev/null | tail -n +$((RETAIN_DAILY + 1)) | xargs -r rm -f
    ls -t "${BACKUP_DIR}/weekly/"hq-week-*.dump 2>/dev/null | tail -n +$((RETAIN_WEEKLY + 1)) | xargs -r rm -f
    return 0
}

offsite() {
    if ! offsite_configured; then
        log "WARNING: offsite not configured (RESTIC_REPOSITORY / RESTIC_PASSWORD / B2_ACCOUNT_ID / B2_ACCOUNT_KEY). Local dump only."
        notify "HQ backup: local dump OK but OFFSITE IS NOT CONFIGURED. Payroll-grade data has one copy on one disk."
        return 2
    fi
    if ! restic cat config >/dev/null 2>&1; then
        log "initialising restic repository"
        restic init || { log "FAILED: restic init"; return 1; }
    fi
    if ! restic backup "${BACKUP_DIR}" --tag nightly --host bordeguna-hq; then
        log "FAILED: restic backup"; return 1
    fi
    restic forget --tag nightly --keep-daily 14 --keep-weekly 8 --keep-monthly 24 --keep-yearly 7 --prune \
        || log "WARNING: forget/prune failed; snapshot itself is safe"
    if [ "$(date -u +%u)" = "7" ]; then
        restic check --read-data-subset=10% || { log "FAILED: restic check (read-data)"; return 1; }
    else
        restic check || { log "FAILED: restic check"; return 1; }
    fi
    log "offsite ok: $(restic snapshots --latest 1 --json 2>/dev/null | head -c 200)"
    return 0
}

run_cycle() {
    local rc=0
    if take_backup; then
        offsite; local orc=$?
        [ "${orc}" -eq 1 ] && { notify "HQ backup: OFFSITE FAILED. Local dump is fine. See docker logs bordeguna-hq-backup-1."; rc=1; }
    else
        notify "HQ backup: LOCAL DUMP FAILED. See docker logs bordeguna-hq-backup-1."
        rc=1
    fi
    date -u +%FT%TZ > "${BACKUP_DIR}/LAST_RUN"
    echo "${rc}" > "${BACKUP_DIR}/LAST_STATUS"
    return "${rc}"
}

if [ "${1:-}" = "once" ]; then
    run_cycle; exit $?
fi

log "backup loop started; daily at ${BACKUP_HOUR_UTC}:00 UTC"
while true; do
    if [ "$(date -u +%H)" = "${BACKUP_HOUR_UTC}" ] && [ ! -f "${BACKUP_DIR}/.ran-$(date -u +%F)" ]; then
        run_cycle
        touch "${BACKUP_DIR}/.ran-$(date -u +%F)"
        find "${BACKUP_DIR}" -maxdepth 1 -name '.ran-*' -mtime +3 -delete
    fi
    sleep 300
done
