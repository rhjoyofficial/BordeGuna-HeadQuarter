#!/bin/bash
# Nightly HQ PostgreSQL backup: LOCAL, ON THIS VPS.
#
#   pg_dump -Fc  ->  ${BACKUP_DIR}/daily/hq-<stamp>.dump   verified with pg_restore --list
#   retention     14 daily / 8 weekly (weekly copy on Sundays), applied only after a verified dump exists
#   marker files  LAST_RUN, LAST_STATUS (0 ok / 1 failed), LAST_OK (path of the newest verified dump)
#
# ⚠ THIS IS NOT DISASTER-PROOF. The dumps live on the same VPS as the database (a different
# directory, not the database volume, but the same disk and the same machine). This protects
# against a bad migration, a dropped table or a corrupt row. It does NOT protect against losing
# the VPS. No offsite provider is part of the current deployment; see docs/28-backup-recovery.md.
#
# Extension point: if OFFSITE_HOOK is set to an executable path, it is run after a verified dump
# with the dump path as $1. A failing hook is logged and alerted; it never fails the local backup.
#
# Run modes:  /backup.sh            long-lived loop, backs up daily at BACKUP_HOUR_UTC (default 01)
#             /backup.sh once       one backup now, then exit (used by deploy.sh before a release)
set -uo pipefail

BACKUP_DIR=${BACKUP_DIR:-/backups}
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

    if [ -n "${OFFSITE_HOOK:-}" ]; then
        if [ -x "${OFFSITE_HOOK}" ]; then
            if "${OFFSITE_HOOK}" "${target}"; then log "offsite hook ok"; else log "WARNING: offsite hook failed (local dump is fine)"; notify "HQ backup: local dump OK, offsite hook FAILED."; fi
        else
            log "WARNING: OFFSITE_HOOK is set but not executable: ${OFFSITE_HOOK}"
        fi
    fi
    return 0
}

run_cycle() {
    local rc=0
    if ! take_backup; then
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

log "backup loop started; daily at ${BACKUP_HOUR_UTC}:00 UTC; local dumps only (not disaster-proof)"
while true; do
    if [ "$(date -u +%H)" = "${BACKUP_HOUR_UTC}" ] && [ ! -f "${BACKUP_DIR}/.ran-$(date -u +%F)" ]; then
        run_cycle
        touch "${BACKUP_DIR}/.ran-$(date -u +%F)"
        find "${BACKUP_DIR}" -maxdepth 1 -name '.ran-*' -mtime +3 -delete
    fi
    sleep 300
done
