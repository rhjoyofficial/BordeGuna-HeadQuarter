#!/bin/bash
# Roll HQ back to a previous commit. Code-only by design: migrations are additive, so the
# previous code runs against the newer schema. If a migration MUST be undone, restore the
# pre-deploy dump with deploy/restore.sh (see deploy/README.md, "Database rollback").
#
#   ./deploy/rollback.sh <sha>
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
log() { echo "[rollback $(date -u +%FT%TZ)] $*"; }

TARGET=${1:?usage: rollback.sh <sha>}
CUR=$(git rev-parse --short HEAD)
git rev-parse --verify "${TARGET}^{commit}" >/dev/null
log "rolling back ${CUR} -> ${TARGET}"

# shellcheck source=deploy/release-state-lib.sh
. deploy/release-state-lib.sh

git checkout -q "${TARGET}"
SHORT=$(git rev-parse --short HEAD)

# A rollback is a release, and the application is told about it the same way (docs/80 C.4). A
# rollback that dies after the checkout moved is recorded as a failed attempt; the last verified
# release on record is never touched on that path.
STAGE=build; VER_STATUS=not_run; VER_UP=""; VER_HEALTH=""; VER_HEALTH_RELEASE=""; VER_AT=""
record_failed_rollback() {
    [ "$STAGE" != "done" ] || return 0
    log "rollback to ${SHORT} FAILED at stage '${STAGE}'"
    rs_write_release rollback failed "$STAGE" "$SHORT" "$CUR" 0 "" "" "" "$VER_STATUS" "$VER_UP" "$VER_HEALTH" "$VER_HEALTH_RELEASE" "$VER_AT" || true
    return 0
}
trap record_failed_rollback EXIT

# Do NOT run migrations on rollback.
# ⚠ The commit is stamped into the image here too. It was not before, so a rolled-back stack ran an
# image with an empty label: the next deploy recorded its predecessor as "unverified", and the
# application could not have said what it was running.
RUN_MIGRATIONS=0 $COMPOSE build --build-arg GIT_COMMIT="${SHORT}" app
STAGE=up
$COMPOSE up -d --no-deps app horizon scheduler web
sleep 5
$COMPOSE exec -T horizon php artisan horizon:terminate || true
STAGE=smoke
VER_STATUS=failed; VER_UP=failed; VER_AT=$(rs_now)
$COMPOSE exec -T web wget -qO- http://127.0.0.1/up >/dev/null && log "/up ok" || { log "/up FAILED after rollback"; exit 1; }
VER_UP=ok

# The same meaning of "verified" as deploy.sh: health ok, and the health answer names this commit.
# ⚠ A release older than docs/80 C.4 does not name its commit in /api/health, so rolling back to one
# is recorded as UNVERIFIED. That is the honest answer: nothing observed says which image answered.
HEALTH_TRIES=${HEALTH_TRIES:-18}
HEALTH_WAIT=${HEALTH_WAIT:-5}
for i in $(seq 1 "$HEALTH_TRIES"); do
    HEALTH=$($COMPOSE exec -T app php -r 'echo @file_get_contents("http://web/api/health") ?: "{}";' 2>/dev/null || echo '{}')
    VER_HEALTH=$(rs_health_status "$HEALTH"); VER_HEALTH=${VER_HEALTH:-unreadable}
    VER_HEALTH_RELEASE=$(rs_health_release "$HEALTH")
    VER_AT=$(rs_now)
    if [ "$VER_HEALTH" = "ok" ] && rs_same_commit "$VER_HEALTH_RELEASE" "$SHORT"; then VER_STATUS=passed; break; fi
    if [ "$i" -lt "$HEALTH_TRIES" ]; then sleep "$HEALTH_WAIT"; fi
done
[ "$VER_STATUS" = "passed" ] && log "verified: health ok and names ${SHORT}" || log "WARNING: rollback to ${SHORT} is UNVERIFIED: health ${VER_HEALTH}, names '${VER_HEALTH_RELEASE:-none}'"

echo "| $(date -u +%FT%TZ) | ROLLBACK to ${TARGET} | from ${CUR} | | verification ${VER_STATUS} |" >> deploy/RELEASES.md
if rs_write_release rollback released "" "$SHORT" "$CUR" 0 "" "" "$(git rev-parse --short --verify -q origin/main 2>/dev/null || true)" \
    "$VER_STATUS" "$VER_UP" "$VER_HEALTH" "$VER_HEALTH_RELEASE" "$VER_AT"; then
    if [ "$VER_STATUS" = "passed" ]; then rs_mark_verified || true; fi
else
    log "WARNING: could not write ${RELEASE_STATE_DIR}/release.json"
fi
STAGE=done
if [ -x deploy/release-state-refresh.sh ]; then ./deploy/release-state-refresh.sh || true; fi
log "rolled back to ${TARGET}. To return to main later: git checkout main && ./deploy/deploy.sh --no-pull"
