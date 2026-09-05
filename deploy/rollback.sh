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

git checkout -q "${TARGET}"
# Do NOT run migrations on rollback.
RUN_MIGRATIONS=0 $COMPOSE build app
$COMPOSE up -d --no-deps app horizon scheduler web
sleep 5
$COMPOSE exec -T horizon php artisan horizon:terminate || true
$COMPOSE exec -T web wget -qO- http://127.0.0.1/up >/dev/null && log "/up ok" || { log "/up FAILED after rollback"; exit 1; }
echo "| $(date -u +%FT%TZ) | ROLLBACK to ${TARGET} | from ${CUR} | |" >> deploy/RELEASES.md
log "rolled back to ${TARGET}. To return to main later: git checkout main && ./deploy/deploy.sh --no-pull"
