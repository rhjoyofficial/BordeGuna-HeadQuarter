#!/bin/bash
# Release BordeGuna-HeadQuarter on the VPS.
#
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh            # fast-forward to origin/main and release
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh --no-pull  # release the checked-out commit
#
# ORDER, and it is the whole point of this script:
#
#   backup boundary verified -> dump taken -> dump verified -> ONLY THEN the checkout moves
#   -> build -> up -> migrate (app entrypoint) -> horizon restart -> smoke -> record
#
# ⛔ **Nothing that changes what is released happens before a verified backup exists.** A refused
# deploy must leave the checkout exactly where it was, because a checkout ahead of the running
# containers is a lie that outlives the failure: the next run reads that HEAD as its predecessor and
# records it, and RELEASES.md then says a release followed itself.
#
# That is not hypothetical. On 9 September 2026 this script fast-forwarded, then failed its dump
# because another session had deleted the backup directory out from under the sidecar's bind mount,
# and the next release recorded "previous db3430a" when the true predecessor was 2784663 (docs/58).
#
# Nothing outside /opt/bordeguna-hq and /root/backups/bordeguna-hq is touched.
# Rollback: ./deploy/rollback.sh <sha>.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
COMPOSE="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
HOST_BACKUP_DIR=${HOST_BACKUP_DIR:-/root/backups/bordeguna-hq}
log() { echo "[deploy $(date -u +%FT%TZ)] $*"; }
die() { log "$*"; exit 1; }

[ -f deploy/.env ] || die "deploy/.env missing"
[ "$(stat -c %a deploy/.env)" = "600" ] || die "deploy/.env must be mode 600"

# ── What is actually running, before anything moves ────────────────────────────────────────────
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

CHECKOUT_BEFORE=$(git rev-parse --short HEAD)
RUNNING=$(running_release)
log "checkout ${CHECKOUT_BEFORE}; running ${RUNNING}"

if [ "$RUNNING" != "none" ] && [ "$RUNNING" != "unknown" ] && [ "$RUNNING" != "$CHECKOUT_BEFORE" ]; then
    # Drift: somebody moved the checkout without releasing, or a previous run was refused. Say so
    # rather than quietly adopting the checkout as the predecessor.
    log "WARNING: checkout ${CHECKOUT_BEFORE} is not the running release ${RUNNING}. Recording ${RUNNING} as the predecessor."
fi
# ⚠ When the running image carries no commit label - it predates the label, or the stack has never
# been deployed - there is no way to KNOW the predecessor, and the checkout is only a guess at it.
# Recording that guess unqualified is how a release comes to claim a predecessor it did not have.
# It is recorded, and marked as unverified, because a record that hedges is worth more than one that
# is confidently wrong.
if [ "$RUNNING" = "none" ] || [ "$RUNNING" = "unknown" ]; then
    PREV="$CHECKOUT_BEFORE"
    PREV_NOTE=" (unverified: the running image carries no commit label)"
else
    PREV="$RUNNING"
    PREV_NOTE=""
fi

STACK_IS_UP=0
if $COMPOSE ps --status running db >/dev/null 2>&1 && [ -n "$($COMPOSE ps -q db 2>/dev/null)" ]; then
    STACK_IS_UP=1
fi

# ── 1. The backup boundary, before the dump that depends on it ─────────────────────────────────
#
# ⚠ A bind mount holds the directory INODE. When the host directory is deleted, the still-running
# container keeps pointing at the dead inode, and `mkdir -p` inside it fails with "No such file or
# directory" rather than quietly recreating the path. Recreating the host directory alone does NOT
# repair a container that is already mounted; the container has to be recreated so its mount
# reattaches. That is the repair from docs/58 section C, performed here instead of by hand.
ensure_backup_boundary() {
    [ "$STACK_IS_UP" = "1" ] || return 0

    mkdir -p "${HOST_BACKUP_DIR}/daily" "${HOST_BACKUP_DIR}/weekly"
    write_boundary_marker

    if backup_mount_is_live; then
        return 0
    fi

    log "backup mount is not writable from inside the container; recreating the backup service"
    $COMPOSE up -d --force-recreate --no-deps backup || die "could not recreate the backup service; refusing to deploy"
    sleep 3

    backup_mount_is_live || die "backup mount is STILL not writable after recreating the service. Refusing to deploy, and the checkout has not moved. Look: ls -la ${HOST_BACKUP_DIR}; docker inspect \$($COMPOSE ps -q backup)"
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
  - `deploy/deploy.sh` verifies and repairs this automatically before every release;
  - `deploy/hq-monitor.sh` alerts if this directory goes missing or its dumps go stale.

Retention is handled by `deploy/backup.sh` (14 daily / 8 weekly) and only ever runs after a
verified dump exists. Nothing else should be applying retention here.
MARKER
    log "wrote backup boundary marker"
}

ensure_backup_boundary

# ── 2. The dump, and its verification, BEFORE the checkout moves ───────────────────────────────
#
# `backup.sh once` already verifies what it wrote (size, `pg_restore --list`, table count) and
# records LAST_STATUS / LAST_OK. This checks those markers as well as the exit code, because an exit
# code says the process ended and the markers say what it actually produced.
if [ "$STACK_IS_UP" = "1" ]; then
    log "pre-deploy dump (before any checkout change)"
    $COMPOSE exec -T backup /backup.sh once || die "pre-deploy backup FAILED; refusing to deploy. The checkout has NOT moved."

    STATUS=$($COMPOSE exec -T backup sh -c 'cat /backups/LAST_STATUS 2>/dev/null || echo 1' | tr -dc 0-9)
    [ "${STATUS:-1}" = "0" ] || die "pre-deploy backup reported status ${STATUS:-unknown}; refusing to deploy. The checkout has NOT moved."

    DUMP=$($COMPOSE exec -T backup sh -c 'cat /backups/LAST_OK 2>/dev/null' | tr -d '\r\n')
    [ -n "${DUMP:-}" ] || die "pre-deploy backup left no LAST_OK marker; refusing to deploy. The checkout has NOT moved."
    $COMPOSE exec -T backup sh -c "[ -s '${DUMP}' ]" || die "pre-deploy dump ${DUMP} is missing or empty; refusing to deploy. The checkout has NOT moved."

    log "verified dump: ${DUMP}"
else
    log "stack is not running (first deploy); nothing to dump"
fi

# ── 3. Only now may the checkout move ──────────────────────────────────────────────────────────
if [ "${1:-}" != "--no-pull" ]; then
    git fetch -q origin
    git merge --ff-only origin/main
fi
NEW=$(git rev-parse --short HEAD)
log "releasing ${NEW} (previous ${PREV})"

# ── 4. Build. The commit is stamped into the image so the next deploy can read what is running. ─
log "building images"
$COMPOSE build --pull --build-arg GIT_COMMIT="${NEW}" app
$COMPOSE build --pull backup

# ── 5. Release. `app` runs migrations in its entrypoint (RUN_MIGRATIONS=1). ─────────────────────
log "starting stack"
$COMPOSE up -d --remove-orphans
sleep 5
$COMPOSE exec -T horizon php artisan horizon:terminate || true
# nginx resolves the `app` upstream once at start-up; after `app` is recreated it keeps the old IP and
# answers 502 until restarted (seen on the first deploy). Recreate `web` so it resolves the new container.
$COMPOSE up -d --force-recreate --no-deps web

# ── 6. Smoke. ──────────────────────────────────────────────────────────────────────────────────
log "smoke checks"
for i in $(seq 1 30); do
    if $COMPOSE exec -T web wget -qO- http://127.0.0.1/up >/dev/null 2>&1; then break; fi
    sleep 2
    [ "$i" -eq 30 ] && { log "/up never answered"; $COMPOSE ps; exit 1; }
done
HEALTH=$($COMPOSE exec -T app php -r 'echo @file_get_contents("http://web/api/health") ?: "{}";' 2>/dev/null || echo '{}')
log "health: ${HEALTH:0:400}"
$COMPOSE ps

# ── 7. Record, with the predecessor that was actually running. ─────────────────────────────────
mkdir -p deploy
echo "| $(date -u +%FT%TZ) | ${NEW} | previous ${PREV}${PREV_NOTE} | $(echo "${HEALTH}" | head -c 120 | tr '\n' ' ') |" >> deploy/RELEASES.md
log "released ${NEW}"
