#!/bin/bash
# Shared by deploy.sh and rollback.sh. SOURCED, never executed.
#
#   THE INVARIANT (docs/58 H.1, docs/81 C.3)
#   A verified dump exists BEFORE anything that changes what is released: before the checkout moves,
#   before a build, before an `up`. That holds for a deploy and for a rollback alike, and it is
#   implemented here ONCE so the two scripts cannot drift apart again. Until docs/81 C.3, rollback.sh
#   moved the checkout with no dump at all.
#
# The caller provides: $COMPOSE, log(), die(), BF_VERB ("deploy" or "roll back") and BF_NOUN ("deploy" or "rollback") so a refusal
# names the thing that was refused. HOST_BACKUP_DIR may be overridden (the tests do).
#
# ⚠ rollback.sh sources this BEFORE its `git checkout`, because the target commit may be older than
# this file.

HOST_BACKUP_DIR=${HOST_BACKUP_DIR:-/root/backups/bordeguna-hq}
BF_VERB=${BF_VERB:-deploy}
BF_NOUN=${BF_NOUN:-deploy}

# ── What is actually running ───────────────────────────────────────────────────────────────────
#
# Read from the RUNNING container's image label rather than inferred from the checkout. The checkout
# is a statement of intent; the label is a statement of fact, and when they disagree the fact is the
# one worth recording. `unknown` is honest for a stack built before the label existed.
running_release() {
    local id
    id=$($COMPOSE ps -q app 2>/dev/null | head -1) || true
    [ -n "${id:-}" ] || { echo "none"; return 0; }
    docker inspect -f '{{ index .Config.Labels "com.bordeguna.commit" }}' "$id" 2>/dev/null | grep -E '^[0-9a-f]{7,40}$' || echo "unknown"
}

# 0 when the database container is running, which is what "there is something to dump" means.
bf_stack_is_up() {
    $COMPOSE ps --status running db >/dev/null 2>&1 && [ -n "$($COMPOSE ps -q db 2>/dev/null)" ]
}

# ── The backup boundary, before the dump that depends on it ────────────────────────────────────
#
# ⚠ A bind mount holds the directory INODE. When the host directory is deleted, the still-running
# container keeps pointing at the dead inode, and `mkdir -p` inside it fails with "No such file or
# directory" rather than quietly recreating the path. Recreating the host directory alone does NOT
# repair a container that is already mounted; the container has to be recreated so its mount
# reattaches. That is the repair from docs/58 section C, performed here instead of by hand.
ensure_backup_boundary() {
    mkdir -p "${HOST_BACKUP_DIR}/daily" "${HOST_BACKUP_DIR}/weekly"
    write_boundary_marker

    if backup_mount_is_live; then
        return 0
    fi

    log "backup mount is not writable from inside the container; recreating the backup service"
    $COMPOSE up -d --force-recreate --no-deps backup || die "could not recreate the backup service; refusing to ${BF_VERB}"
    sleep 3

    backup_mount_is_live || die "backup mount is STILL not writable after recreating the service. Refusing to ${BF_VERB}, and the checkout has not moved. Look: ls -la ${HOST_BACKUP_DIR}; docker inspect \$($COMPOSE ps -q backup)"
    log "backup mount repaired"
}

backup_mount_is_live() {
    $COMPOSE exec -T backup sh -c 'mkdir -p /backups/daily /backups/weekly && touch /backups/.mount-probe && rm -f /backups/.mount-probe' >/dev/null 2>&1
}

# A note for the next person consolidating backups on this box. It cannot stop an `rm -rf`, and it
# is not pretending to: it is there so the deletion is a decision rather than an accident.
write_boundary_marker() {
    local marker="${HOST_BACKUP_DIR}/DO-NOT-DELETE-README.md"
    [ -f "$marker" ] && return 0
    cat > "$marker" <<'MARKER'
# BordeGuna HQ backups - do not delete or replace this directory

This directory is BIND-MOUNTED into the running `bordeguna-hq-backup-1` container as `/backups`.

Deleting or renaming it does not merely remove files. The running container keeps pointing at the
deleted inode, so every later backup fails with "No such file or directory" while the container
still looks healthy, and `deploy.sh` refuses to release until somebody notices.

That happened on 9 September 2026 during a backup consolidation, and it took HQ's entire dump
history with it (docs/58).

If you are consolidating backups on this host:

  - copy out of this directory, do not move or replace it;
  - if you must recreate it, also recreate the container so the mount reattaches:
        cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env \
            up -d --force-recreate --no-deps backup
  - `deploy/deploy.sh` and `deploy/rollback.sh` verify and repair this automatically before they
    change anything;
  - `deploy/hq-monitor.sh` alerts if this directory goes missing or its dumps go stale.

Retention is handled by `deploy/backup.sh` (14 daily / 8 weekly) and only ever runs after a
verified dump exists. Nothing else should be applying retention here.
MARKER
    log "wrote backup boundary marker"
}

# ── The dump, and its verification, BEFORE the checkout moves ──────────────────────────────────
#
# `backup.sh once` already verifies what it wrote (size, `pg_restore --list`, table count) and
# records LAST_STATUS / LAST_OK. This checks those markers as well as the exit code, because an exit
# code says the process ended and the markers say what it actually produced.
#
# Sets DUMP (the path inside the backup container) and DUMP_AT. Dies, having moved nothing, on any
# doubt.
take_verified_dump() {
    local status
    log "pre-${BF_NOUN} dump (before any checkout change)"
    $COMPOSE exec -T backup /backup.sh once || die "pre-${BF_NOUN} backup FAILED; refusing to ${BF_VERB}. The checkout has NOT moved."

    status=$($COMPOSE exec -T backup sh -c 'cat /backups/LAST_STATUS 2>/dev/null || echo 1' | tr -dc 0-9)
    [ "${status:-1}" = "0" ] || die "pre-${BF_NOUN} backup reported status ${status:-unknown}; refusing to ${BF_VERB}. The checkout has NOT moved."

    DUMP=$($COMPOSE exec -T backup sh -c 'cat /backups/LAST_OK 2>/dev/null' | tr -d '\r\n')
    [ -n "${DUMP:-}" ] || die "pre-${BF_NOUN} backup left no LAST_OK marker; refusing to ${BF_VERB}. The checkout has NOT moved."
    $COMPOSE exec -T backup sh -c "[ -s '${DUMP}' ]" || die "pre-${BF_NOUN} dump ${DUMP} is missing or empty; refusing to ${BF_VERB}. The checkout has NOT moved."

    DUMP_AT=$(rs_now)
    log "verified dump: ${DUMP}"
}
