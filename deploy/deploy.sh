#!/bin/bash
# Release BordeGuna-HeadQuarter on the VPS.
#
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh            # fast-forward to origin/main and release
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh --no-pull  # release the checked-out commit
#
# Every release: pre-deploy database dump -> build image -> up -> migrate (app entrypoint) ->
# graceful horizon restart -> smoke checks -> record in deploy/RELEASES.md. Nothing outside
# /opt/bordeguna-hq and /root/backups/bordeguna-hq is touched. Rollback: ./deploy/rollback.sh <sha>.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
COMPOSE="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
log() { echo "[deploy $(date -u +%FT%TZ)] $*"; }

[ -f deploy/.env ] || { log "deploy/.env missing"; exit 1; }
[ "$(stat -c %a deploy/.env)" = "600" ] || { log "deploy/.env must be mode 600"; exit 1; }

PREV=$(git rev-parse --short HEAD)
if [ "${1:-}" != "--no-pull" ]; then
    git fetch -q origin
    git merge --ff-only origin/main
fi
NEW=$(git rev-parse --short HEAD)
log "releasing ${NEW} (previous ${PREV})"

# 1. Pre-deploy backup, only if the stack already runs (first deploy has nothing to dump).
if $COMPOSE ps --status running db >/dev/null 2>&1 && [ -n "$($COMPOSE ps -q db)" ]; then
    log "pre-deploy dump"
    $COMPOSE exec -T backup /backup.sh once || { log "pre-deploy backup FAILED; refusing to deploy"; exit 1; }
fi

# 2. Build.
log "building images"
$COMPOSE build --pull app backup

# 3. Release. `app` runs migrations in its entrypoint (RUN_MIGRATIONS=1); horizon/scheduler wait on db only.
log "starting stack"
$COMPOSE up -d --remove-orphans
sleep 5
$COMPOSE exec -T horizon php artisan horizon:terminate || true
# nginx resolves the `app` upstream once at start-up; after `app` is recreated it keeps the old IP and
# answers 502 until restarted (seen on the first deploy). Recreate `web` so it resolves the new container.
$COMPOSE up -d --force-recreate --no-deps web

# 4. Smoke.
log "smoke checks"
for i in $(seq 1 30); do
    if $COMPOSE exec -T web wget -qO- http://127.0.0.1/up >/dev/null 2>&1; then break; fi
    sleep 2
    [ "$i" -eq 30 ] && { log "/up never answered"; $COMPOSE ps; exit 1; }
done
HEALTH=$($COMPOSE exec -T app php -r 'echo @file_get_contents("http://web/api/health") ?: "{}";' 2>/dev/null || echo '{}')
log "health: ${HEALTH:0:400}"
$COMPOSE ps

# 5. Record.
mkdir -p deploy
echo "| $(date -u +%FT%TZ) | ${NEW} | previous ${PREV} | $(echo "${HEALTH}" | head -c 120 | tr '\n' ' ') |" >> deploy/RELEASES.md
log "released ${NEW}"
