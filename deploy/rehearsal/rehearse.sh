#!/bin/bash
# Migration and deployment rehearsal on an ISOLATED, THROWAWAY copy (docs/128; ruling docs/120 D.1 and D.2).
#
#   bash deploy/rehearsal/rehearse.sh --dump <pg_dump -Fc file> --candidate <sha> [options]
#
#   --dump <file>          a verified production dump (for example /root/backups/bordeguna-hq/daily/hq-<stamp>.dump).
#                          It is COPIED into the rehearsal; the original is never opened for writing.
#   --candidate <sha>      the release candidate commit. Must exist in --source.
#   --source <repo>        where to clone the candidate from. Default /opt/bordeguna-hq (read with `git clone` only).
#   --project <name>       compose project. Default bordeguna-hq-rehearsal; must start with that prefix.
#   --db <name>            rehearsal database. Default hq_rehearsal; must start with hq_rehearsal.
#   --root <dir>           throwaway working directory. Default /root/hq-rehearsal/<UTC stamp>.
#   --seed-corruption      plant the docs/121 section 5 shape (and null-store duplicates, ambiguous ownership,
#                          foreign evidence) into the copy BEFORE migrating, and show what W1 quarantines.
#   --repair-command <c>   the artisan command of the W2 repair dry run. Auto-detected when absent; skipped when none.
#   --laladia-business-slug <slug>  default laladia      (for --seed-corruption)
#   --bionic-store-slug <slug>      default bionic-garden (for --seed-corruption)
#   --laladia-store-slug <slug>     default laladia-com   (for --seed-corruption)
#   --keep                 leave the throwaway stack running and the copies in place afterwards.
#   --plan                 print what would run and exit 0: no docker call, no file written.
#
# WHAT IT DOES, in order (every step leaves a file in <root>/evidence):
#   guards -> clone the candidate -> rewrite its compose file for isolation (make-compose.sh, refusals inside)
#   -> a fresh deploy/.env with a NEW APP_KEY (restored credentials cannot be decrypted: nothing can reach a shop,
#   the ERP, Meta or Telegram from the copy) -> db + redis + backup up -> restore the dump (deploy/restore.sh)
#   -> row counts and schema BEFORE (snapshot.sql), the W0 scope queries, the posture (flags, agents, grants, shadow)
#   -> [--seed-corruption] -> the candidate's OWN deploy.sh --verify-only --no-pull, then deploy.sh --no-pull
#   (host lock, backup boundary, verified dump, build stamped with the commit, up, entrypoint `migrate --force`,
#   horizon restart, /up, /api/health naming the commit, release records) -> schema and counts AFTER ->
#   constraints (verify-constraints.sql) -> permission seeder --class and the thirteen permissions -> detector dry
#   run, cost dry run, repair dry run and tz backfill dry run if the commands exist; proof they wrote nothing (the tz
#   dry run by row hashes) -> [quarantine listing]
#   -> ROLLBACK one migration at a time until a down() refuses or the pre-release schema is reached; counts compared
#   -> RESTORE PROOF: the pre-deploy dump deploy.sh took, restored into a second scratch database and compared with
#   the BEFORE snapshot -> RE-APPLY and verify again -> posture unchanged -> EVENT BRIDGE (docs/132): scheduler
#   stopped, a dump, three deliveries after it, hq:events:export-since, restore.sh, hq:events:import; none lost, none
#   processed -> teardown (project volumes only).
#
# WHAT IT REFUSES (before any docker call): the production or staging project name; a database name that is `hq`,
# `postgres`, the production DB_DATABASE, or not prefixed hq_rehearsal; a root inside the production checkout or the
# production backup directory; a missing dump; an unknown argument; a project whose containers already exist.
#
# WHAT IT DOES NOT DO: it never runs deploy/rollback.sh. A code rollback checks out the TARGET's compose file, which
# is production's (Traefik rule Host(hq.bordeguna.com), the production backup mount): started in any project it
# would receive production traffic. rollback.sh's invariants are covered by deploy/tests/deploy-safety-test.sh in CI.
# It never connects to the production project, never reads deploy/.env's values (only the DB name, for the refusal),
# never ssh-es, and never writes outside <root>.
set -euo pipefail

SELF_DIR=$(cd "$(dirname "$0")" && pwd)
PRODUCTION_ROOT=${PRODUCTION_ROOT:-/opt/bordeguna-hq}
PRODUCTION_BACKUP_DIR=${PRODUCTION_BACKUP_DIR:-/root/backups/bordeguna-hq}
PRODUCTION_ENV_FILE=${PRODUCTION_ENV_FILE:-${PRODUCTION_ROOT}/deploy/.env}
PRODUCTION_PROJECT=bordeguna-hq
STAGING_PROJECT=bordeguna-hq-staging

DUMP=""; CANDIDATE=""; SOURCE="$PRODUCTION_ROOT"; PROJECT=bordeguna-hq-rehearsal; DB=hq_rehearsal
ROOT=""; SEED=0; REPAIR_CMD=""; KEEP=0; PLAN=0
LALADIA_BUSINESS=laladia; BIONIC_STORE=bionic-garden; LALADIA_STORE=laladia-com

log() { echo "[rehearsal $(date -u +%FT%TZ)] $*"; }
die() { log "REFUSED: $*"; log "Nothing was started, written or changed."; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --dump) DUMP=${2:-}; shift 2 ;;
        --candidate) CANDIDATE=${2:-}; shift 2 ;;
        --source) SOURCE=${2:-}; shift 2 ;;
        --project) PROJECT=${2:-}; shift 2 ;;
        --db) DB=${2:-}; shift 2 ;;
        --root) ROOT=${2:-}; shift 2 ;;
        --seed-corruption) SEED=1; shift ;;
        --repair-command) REPAIR_CMD=${2:-}; shift 2 ;;
        --laladia-business-slug) LALADIA_BUSINESS=${2:-}; shift 2 ;;
        --bionic-store-slug) BIONIC_STORE=${2:-}; shift 2 ;;
        --laladia-store-slug) LALADIA_STORE=${2:-}; shift 2 ;;
        --keep) KEEP=1; shift ;;
        --plan) PLAN=1; shift ;;
        *) die "unknown argument '$1'. Usage: rehearse.sh --dump <file> --candidate <sha> [--source <repo>] [--project <name>] [--db <name>] [--root <dir>] [--seed-corruption] [--repair-command <c>] [--keep] [--plan]" ;;
    esac
done

# ── Guards. Every one of these fires before docker is called. ─────────────────────────────────────
[ -n "$DUMP" ] || die "--dump is required"
[ -n "$CANDIDATE" ] || die "--candidate is required"
[ "$PROJECT" != "$PRODUCTION_PROJECT" ] || die "the project name '$PROJECT' is the PRODUCTION project"
[ "$PROJECT" != "$STAGING_PROJECT" ] || die "the project name '$PROJECT' is the staging project"
[[ "$PROJECT" =~ ^bordeguna-hq-rehearsal(-[a-z0-9]+)?$ ]] || die "the project name must be bordeguna-hq-rehearsal or bordeguna-hq-rehearsal-<suffix>, got '$PROJECT'"
[[ "$DB" =~ ^hq_rehearsal(_[a-z0-9]+)?$ ]] || die "the database name must be hq_rehearsal or hq_rehearsal_<suffix>, got '$DB'"
[ "$DB" != "hq" ] && [ "$DB" != "postgres" ] || die "the database name '$DB' is not a rehearsal database"
if [ -r "$PRODUCTION_ENV_FILE" ]; then
    PROD_DB=$(grep -E '^[[:space:]]*DB_DATABASE[[:space:]]*=' "$PRODUCTION_ENV_FILE" | tail -1 | cut -d= -f2- | tr -d " \"'\r" || true)
    [ -z "${PROD_DB:-}" ] || [ "$DB" != "$PROD_DB" ] || die "the database name equals the production database name"
fi
[ -n "$ROOT" ] || ROOT="/root/hq-rehearsal/$(date -u +%Y%m%d-%H%M%S)"
case "$ROOT" in
    /*) ;;
    *) die "--root must be an absolute path, got '$ROOT'" ;;
esac
case "${ROOT%/}/" in
    "${PRODUCTION_ROOT%/}/"*) die "the root '$ROOT' is the production checkout or inside it" ;;
    "${PRODUCTION_BACKUP_DIR%/}/"*) die "the root '$ROOT' is the production backup directory or inside it" ;;
    "/") die "the root cannot be /" ;;
esac
[ -f "$DUMP" ] || die "the dump '$DUMP' does not exist or is not a regular file"
[ -s "$DUMP" ] || die "the dump '$DUMP' is empty"
case "$(cd "$(dirname "$DUMP")" && pwd)/" in
    "${ROOT%/}/"*) die "the dump must not live inside the rehearsal root" ;;
esac
[[ "$CANDIDATE" =~ ^[0-9a-fA-F]{7,40}$ ]] || die "the candidate must be a commit hash (7 to 40 hex characters), got '$CANDIDATE'"
if [ "$SEED" = "1" ]; then
    for v in "$LALADIA_BUSINESS" "$BIONIC_STORE" "$LALADIA_STORE"; do
        [[ "$v" =~ ^[a-z0-9-]+$ ]] || die "a slug must be lower-case letters, digits and dashes, got '$v'"
    done
fi
[ -f "${SELF_DIR}/make-compose.sh" ] && [ -d "${SELF_DIR}/sql" ] || die "deploy/rehearsal is incomplete next to this script"

CHECKOUT="${ROOT}/checkout"; BACKUPS="${ROOT}/backups"; EVIDENCE="${ROOT}/evidence"
export HQ_DEPLOY_LOCK="${ROOT}/deploy.lock"      # never production's /tmp/bordeguna-hq-deploy.lock
export HOST_BACKUP_DIR="$BACKUPS"                # never /root/backups/bordeguna-hq
export COMPOSE_PROJECT_NAME="$PROJECT"           # belt and braces under the rewritten compose file's own name:

plan() {
    cat <<PLAN
rehearsal plan (nothing has run):
  dump            ${DUMP} (copied to ${BACKUPS}/incoming/)
  candidate       ${CANDIDATE} cloned from ${SOURCE} into ${CHECKOUT} (read-only clone; the compose file is rewritten and committed locally for isolation)
  project         ${PROJECT} (volumes ${PROJECT}_dbdata, ${PROJECT}_redisdata, ${PROJECT}_storage, ${PROJECT}_public)
  database        ${DB} (restore check database ${DB}_restorecheck)
  images          ${PROJECT}:latest, ${PROJECT}-backup:latest
  root            ${ROOT} (evidence in ${EVIDENCE})
  host lock       ${HQ_DEPLOY_LOCK}
  backups         ${BACKUPS}
  seed corruption ${SEED} (business ${LALADIA_BUSINESS}, stores ${BIONIC_STORE} and ${LALADIA_STORE})
  repair command  ${REPAIR_CMD:-auto-detect (hq:*repair*), skipped when absent}
  keep afterwards ${KEEP}
  steps           guards, clone, compose isolation, env, db+redis+backup up, restore, BEFORE snapshot + scope + posture,
                  [seed], verify-only, deploy.sh --no-pull (migrates), AFTER snapshot, constraints, seeder, dry runs,
                  [quarantine listing], rollback until refusal, restore proof, re-apply, verify, posture,
                  event bridge across a restore (scheduler stopped, export, restore, import, none lost, none processed), teardown
  never           the production project, production's lock, production's backup directory, deploy/rollback.sh, ssh
PLAN
}

if [ "$PLAN" = "1" ]; then plan; exit 0; fi

command -v docker >/dev/null 2>&1 || die "docker is not installed"
docker compose version >/dev/null 2>&1 || die "docker compose (v2) is not available"
command -v git >/dev/null 2>&1 || die "git is not installed"
command -v flock >/dev/null 2>&1 || die "flock is not installed (deploy.sh needs it)"
EXISTING=$(docker ps -a --filter "label=com.docker.compose.project=${PROJECT}" -q 2>/dev/null | wc -l | tr -d ' ')
[ "${EXISTING:-0}" = "0" ] || die "containers of project '${PROJECT}' already exist. Remove them first: docker compose -p ${PROJECT} down -v --remove-orphans"
[ ! -e "$ROOT" ] || die "the root '$ROOT' already exists; choose another --root"

mkdir -p "$CHECKOUT" "$BACKUPS/incoming" "$BACKUPS/daily" "$BACKUPS/weekly" "$EVIDENCE"
exec > >(tee -a "${EVIDENCE}/rehearsal.log") 2>&1
plan
log "root ${ROOT}"

VERDICTS=()
verdict() { VERDICTS+=("$1 $2"); log "$1: $2"; }
FAILED=0
fail() { verdict FAIL "$1"; FAILED=1; }
pass() { verdict PASS "$1"; }
warn() { verdict WARN "$1"; }

cleanup() {
    local rc=$?
    set +e
    if [ "$KEEP" = "1" ]; then
        log "--keep: the stack ${PROJECT} stays up. Tear down later with:"
        log "  docker compose -p ${PROJECT} -f ${CHECKOUT}/deploy/docker-compose.yml --env-file ${CHECKOUT}/deploy/.env down -v --remove-orphans && docker image rm ${PROJECT}:latest ${PROJECT}-backup:latest; rm -rf ${BACKUPS} ${CHECKOUT}"
    elif [ -f "${CHECKOUT}/deploy/docker-compose.yml" ] && [ -f "${CHECKOUT}/deploy/.env" ]; then
        log "teardown: project ${PROJECT} (its own containers, network and volumes only)"
        docker compose -p "$PROJECT" -f "${CHECKOUT}/deploy/docker-compose.yml" --env-file "${CHECKOUT}/deploy/.env" down -v --remove-orphans >/dev/null 2>&1 || true
        docker image rm "${PROJECT}:latest" "${PROJECT}-backup:latest" >/dev/null 2>&1 || true
        rm -rf "$BACKUPS" "$CHECKOUT"
        log "teardown done; copies of the dump removed; evidence kept in ${EVIDENCE}"
    fi
    echo
    echo "== rehearsal verdicts =="
    printf '  %s\n' "${VERDICTS[@]}"
    if [ "$FAILED" = "1" ] || [ "$rc" != "0" ]; then
        echo "RESULT: rehearsal FAILED (exit ${rc}, failed verdicts ${FAILED}). Evidence: ${EVIDENCE}"
        exit 1
    fi
    echo "RESULT: rehearsal PASSED. Evidence: ${EVIDENCE}. This was a rehearsal on a copy: nothing was released."
}
trap cleanup EXIT

# ── 1. The candidate, cloned, isolated and committed locally ───────────────────────────────────────
log "cloning ${SOURCE} (read only) and checking out ${CANDIDATE}"
git clone -q --no-checkout "$SOURCE" "$CHECKOUT"
git -C "$CHECKOUT" checkout -q --detach "$CANDIDATE" || die "candidate ${CANDIDATE} is not a commit of ${SOURCE}"
CANDIDATE_FULL=$(git -C "$CHECKOUT" rev-parse HEAD)
CANDIDATE_SHORT=$(git -C "$CHECKOUT" rev-parse --short HEAD)
echo "$CANDIDATE_FULL" > "${EVIDENCE}/00-candidate.txt"
for f in deploy/deploy.sh deploy/verify-only.sh deploy/docker-compose.yml deploy/.env.example deploy/restore.sh deploy/backup.sh deploy/Dockerfile deploy/entrypoint.sh; do
    [ -f "${CHECKOUT}/${f}" ] || die "the candidate has no ${f}; this rehearsal needs the current deploy method"
done
cp "${CHECKOUT}/deploy/docker-compose.yml" "${EVIDENCE}/00-compose-candidate.yml"
bash "${SELF_DIR}/make-compose.sh" "${EVIDENCE}/00-compose-candidate.yml" "${CHECKOUT}/deploy/docker-compose.yml" "$PROJECT" "$BACKUPS"
cp "${CHECKOUT}/deploy/docker-compose.yml" "${EVIDENCE}/00-compose-rehearsal.yml"
git -C "$CHECKOUT" -c user.email=rehearsal@invalid -c user.name=rehearsal commit -q -am "rehearsal isolation: deploy/docker-compose.yml only (NOT the candidate)"
REHEARSAL_HEAD=$(git -C "$CHECKOUT" rev-parse --short HEAD)
git -C "$CHECKOUT" diff --stat "$CANDIDATE_FULL" HEAD > "${EVIDENCE}/00-diff-from-candidate.txt"
if [ "$(git -C "$CHECKOUT" diff --name-only "$CANDIDATE_FULL" HEAD | tr -d '\r')" = "deploy/docker-compose.yml" ]; then
    pass "the rehearsal head ${REHEARSAL_HEAD} differs from the candidate ${CANDIDATE_SHORT} in deploy/docker-compose.yml only (isolation)"
else
    fail "the rehearsal head differs from the candidate in more than deploy/docker-compose.yml"; exit 1
fi

# ── 2. A fresh environment: every key of the candidate's template, new secrets, nothing real ───────
APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
DB_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -d '/+=\n')
REDIS_PASSWORD=$(head -c 24 /dev/urandom | base64 | tr -d '/+=\n')
sed -E -e 's/[[:space:]]+#.*$//' "${CHECKOUT}/deploy/.env.example" \
    | sed -E -e "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" -e "s|^APP_URL=.*|APP_URL=http://rehearsal.invalid|" \
             -e "s|^DB_DATABASE=.*|DB_DATABASE=${DB}|" -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" \
             -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" -e "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=rehearsal.invalid|" \
             -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|" -e "s|^HQ_ERP_BASE_URL=.*|HQ_ERP_BASE_URL=http://erp.invalid|" \
             -e "s|^ALERT_TELEGRAM_BOT_TOKEN=.*|ALERT_TELEGRAM_BOT_TOKEN=|" -e "s|^ALERT_TELEGRAM_CHAT_ID=.*|ALERT_TELEGRAM_CHAT_ID=|" \
             -e "s|^OFFSITE_HOOK=.*|OFFSITE_HOOK=|" -e "s|^MAIL_MAILER=.*|MAIL_MAILER=log|" \
    > "${CHECKOUT}/deploy/.env"
chmod 600 "${CHECKOUT}/deploy/.env"
DB_USER=$(grep -E '^DB_USERNAME=' "${CHECKOUT}/deploy/.env" | cut -d= -f2-)
grep -q "^APP_KEY=${APP_KEY}" "${CHECKOUT}/deploy/.env" && pass "deploy/.env written with a NEW APP_KEY (restored credentials cannot be decrypted), unroutable ERP and shop hosts, no alert bot" \
    || { fail "deploy/.env was not written as intended"; exit 1; }

C="docker compose -p ${PROJECT} -f ${CHECKOUT}/deploy/docker-compose.yml --env-file ${CHECKOUT}/deploy/.env"
$C config -q || die "the rewritten compose file does not pass 'compose config -q'"
$C config 2>/dev/null > "${EVIDENCE}/00-compose-resolved.yml" || true
if grep -q 'hq\.bordeguna\.com\|/root/backups' "${EVIDENCE}/00-compose-resolved.yml"; then fail "the resolved compose configuration still names production"; exit 1; fi
pass "resolved compose configuration names neither hq.bordeguna.com nor /root/backups"

psql_db() { $C exec -T db psql -U "$DB_USER" -d "$1" -v ON_ERROR_STOP=1 -At "${@:2}"; }
artisan() { $C exec -T app php artisan "$@"; }
snapshot() { psql_db "$1" -f - < "${SELF_DIR}/sql/snapshot.sql"; }
posture() {
    psql_db "$DB" -c "SELECT 'flag ' || key || ' ' || environment || ' ' || enabled FROM feature_flags ORDER BY key, environment" \
                  -c "SELECT 'agent ' || key || ' ' || status || ' ' || max_autonomy_level FROM agents ORDER BY key" \
                  -c "SELECT 'grants_active ' || count(*) FROM autonomy_grants WHERE status = 'active'" \
                  -c "SELECT 'shadow_open ' || count(*) FROM shadow_periods WHERE status = 'open'" \
                  -c "SELECT 'tools_disabled ' || count(*) FROM tools WHERE enabled = false" \
                  -c "SELECT 'scheduled_jobs ' || count(*) || ' paused=' || count(*) FILTER (WHERE state = 'paused') FROM scheduled_jobs"
}

# ── 3. Database, redis and the backup sidecar; the dump restored ──────────────────────────────────
log "starting db, redis and backup of project ${PROJECT}"
$C build backup >/dev/null
$C up -d db redis backup
for i in $(seq 1 30); do $C exec -T db pg_isready -U "$DB_USER" -d "$DB" >/dev/null 2>&1 && break; sleep 2; [ "$i" -eq 30 ] && die "the rehearsal database never became ready"; done
cp "$DUMP" "${BACKUPS}/incoming/source.dump"
log "restoring the dump into ${DB} with the candidate's deploy/restore.sh"
# CONFIRM_PRODUCTION_RESTORE names restore.sh's OWN DB_DATABASE, which here is the rehearsal database.
$C exec -T -e CONFIRM_PRODUCTION_RESTORE=yes backup /restore.sh /backups/incoming/source.dump "$DB" > "${EVIDENCE}/01-restore.txt" 2>&1 || { cat "${EVIDENCE}/01-restore.txt"; die "restore failed"; }
grep -q 'restore into' "${EVIDENCE}/01-restore.txt" && pass "dump restored into ${DB} (deploy/restore.sh): $(grep -o 'last_migration=.*' "${EVIDENCE}/01-restore.txt" | head -1)"

snapshot "$DB" > "${EVIDENCE}/02-before-snapshot.txt"
grep '^migration ' "${EVIDENCE}/02-before-snapshot.txt" | cut -d' ' -f2 > "${EVIDENCE}/02-before-migrations.txt"
posture > "${EVIDENCE}/02-before-posture.txt"
if [ -f "${CHECKOUT}/deploy/tools/identity-scope-readonly.sql" ]; then
    psql_db "$DB" -f - < "${CHECKOUT}/deploy/tools/identity-scope-readonly.sql" > "${EVIDENCE}/02-before-scope.txt" 2>&1 || warn "the W0 scope queries did not run clean before the migration (see 02-before-scope.txt)"
fi
pass "BEFORE: $(grep -c '^count ' "${EVIDENCE}/02-before-snapshot.txt") tables counted, $(wc -l < "${EVIDENCE}/02-before-migrations.txt" | tr -d ' ') migrations recorded, posture captured"

# ── 4. Seeded corruption, before the migration ────────────────────────────────────────────────────
if [ "$SEED" = "1" ]; then
    LAL_B=$(psql_db "$DB" -c "SELECT id FROM businesses WHERE slug = '${LALADIA_BUSINESS}'")
    BNC_S=$(psql_db "$DB" -c "SELECT id FROM stores WHERE slug = '${BIONIC_STORE}'")
    LAL_S=$(psql_db "$DB" -c "SELECT id FROM stores WHERE slug = '${LALADIA_STORE}'")
    BNC_B=$(psql_db "$DB" -c "SELECT business_id FROM stores WHERE slug = '${BIONIC_STORE}'")
    [ -n "$LAL_B" ] && [ -n "$BNC_S" ] && [ -n "$LAL_S" ] && [ -n "$BNC_B" ] || die "seed: business '${LALADIA_BUSINESS}' or stores '${BIONIC_STORE}' / '${LALADIA_STORE}' not found in the dump"
    [ "$LAL_B" != "$BNC_B" ] || die "seed: the Bionic store belongs to the LaLaDia business; the shape needs two businesses"
    psql_db "$DB" -v laladia_business_id="$LAL_B" -v bionic_business_id="$BNC_B" -v bionic_store_id="$BNC_S" -v laladia_store_id="$LAL_S" \
        -f - < "${SELF_DIR}/sql/seed-corruption.sql" > "${EVIDENCE}/03-seeded.txt" 2>&1 || { cat "${EVIDENCE}/03-seeded.txt"; die "seeding the corruption failed"; }
    psql_db "$DB" -f - < "${CHECKOUT}/deploy/tools/identity-scope-readonly.sql" > "${EVIDENCE}/03-seeded-scope.txt" 2>&1 || true
    snapshot "$DB" > "${EVIDENCE}/02-before-snapshot.txt"   # the BEFORE now includes the seed; rollback and restore compare against it
    pass "seeded the docs/121 section 5 shape plus duplicates, ambiguous ownership and foreign evidence ($(grep -c '^seeded identity' "${EVIDENCE}/03-seeded.txt") identities)"
fi

# ── 5. The deployment rehearsal: the candidate's own deploy.sh, preflight then release ────────────
log "preflight: deploy.sh --verify-only --no-pull in the throwaway checkout"
if (cd "$CHECKOUT" && ./deploy/deploy.sh --verify-only --no-pull) > "${EVIDENCE}/04-verify-only.txt" 2>&1; then
    pass "deploy.sh --verify-only --no-pull: $(grep -E '^[0-9]+ ok' "${EVIDENCE}/04-verify-only.txt" | head -1)"
else
    fail "deploy.sh --verify-only --no-pull reported a failure (04-verify-only.txt)"; exit 1
fi

log "release: deploy.sh --no-pull (backup first, build stamped ${REHEARSAL_HEAD}, up, migrate in the entrypoint, verify)"
set +e
(cd "$CHECKOUT" && ./deploy/deploy.sh --no-pull) > "${EVIDENCE}/05-deploy.txt" 2>&1
DEPLOY_RC=$?
set -e
[ "$DEPLOY_RC" = "0" ] || { tail -40 "${EVIDENCE}/05-deploy.txt"; fail "deploy.sh exited ${DEPLOY_RC}"; exit 1; }
$C logs --no-color app > "${EVIDENCE}/05-app-log.txt" 2>&1 || true
DUMP_LINE=$(grep -n 'verified dump:' "${EVIDENCE}/05-deploy.txt" | head -1 | cut -d: -f1)
REL_LINE=$(grep -n '^.*releasing ' "${EVIDENCE}/05-deploy.txt" | head -1 | cut -d: -f1)
if [ -n "$DUMP_LINE" ] && [ -n "$REL_LINE" ] && [ "$DUMP_LINE" -lt "$REL_LINE" ]; then
    pass "backup before release: the verified pre-deploy dump (log line ${DUMP_LINE}) precedes 'releasing' (line ${REL_LINE})"
else
    fail "could not prove the dump preceded the release in 05-deploy.txt"
fi
PRE_DUMP=$(cat "${BACKUPS}/LAST_OK" 2>/dev/null | tr -d '\r\n')
[ -n "$PRE_DUMP" ] && [ -s "${BACKUPS}/daily/$(basename "$PRE_DUMP")" ] && pass "pre-deploy dump taken by deploy.sh: ${PRE_DUMP}" || fail "no pre-deploy dump found under ${BACKUPS}"
grep -q 'running migrations' "${EVIDENCE}/05-app-log.txt" && pass "the app entrypoint ran 'migrate --force' (RUN_MIGRATIONS=1 on app only)" || fail "the app log does not show the entrypoint running migrations"
cp "${CHECKOUT}/deploy/release-state/release.json" "${EVIDENCE}/05-release.json" 2>/dev/null || true
if grep -q '"verification":{"status":"passed"' "${EVIDENCE}/05-release.json" 2>/dev/null && grep -q "\"health_release\":\"${REHEARSAL_HEAD}" "${EVIDENCE}/05-release.json"; then
    pass "release identity: /api/health named ${REHEARSAL_HEAD} and the verification passed (release.json)"
else
    warn "release recorded as UNVERIFIED or naming another commit (05-release.json); the stack is up, read 05-deploy.txt"
fi
APP_ID=$($C ps -q app | head -1)
LABEL=$(docker inspect -f '{{ index .Config.Labels "com.bordeguna.commit" }}' "$APP_ID" 2>/dev/null || true)
[ "$LABEL" = "$REHEARSAL_HEAD" ] && pass "the running app image is labelled com.bordeguna.commit=${LABEL}" || fail "the running app image label is '${LABEL}', expected ${REHEARSAL_HEAD}"
# ⛔ Traefik watches every container on the host. Any routing label on this project is a stop.
if docker ps --filter "label=com.docker.compose.project=${PROJECT}" --format '{{.Labels}}' | grep -q 'traefik.http.routers'; then
    fail "a container of ${PROJECT} carries a Traefik router label: tear down NOW ($C down -v)"; exit 1
fi
pass "no container of ${PROJECT} carries a Traefik router label"
$C exec -T web wget -qO- http://127.0.0.1/up > "${EVIDENCE}/06-up.txt" 2>&1 && pass "/up answered" || fail "/up did not answer"
$C exec -T app php -r 'echo @file_get_contents("http://web/api/health", false, stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]])) ?: "{}";' > "${EVIDENCE}/06-health.json" 2>/dev/null || true
head -c 300 "${EVIDENCE}/06-health.json"; echo

# ── 6. AFTER: schema, constraints, seeder, dry runs ───────────────────────────────────────────────
artisan migrate:status > "${EVIDENCE}/07-migrate-status.txt" 2>&1 || true
snapshot "$DB" > "${EVIDENCE}/08-after-snapshot.txt"
grep '^migration ' "${EVIDENCE}/08-after-snapshot.txt" | cut -d' ' -f2 > "${EVIDENCE}/08-after-migrations.txt"
NEW_MIGRATIONS=$(comm -13 "${EVIDENCE}/02-before-migrations.txt" "${EVIDENCE}/08-after-migrations.txt")
echo "$NEW_MIGRATIONS" > "${EVIDENCE}/08-new-migrations.txt"
pass "migrations applied by the release: $(echo "$NEW_MIGRATIONS" | grep -c .)"
psql_db "$DB" -f - < "${SELF_DIR}/sql/verify-constraints.sql" > "${EVIDENCE}/09-constraints.txt" 2>&1 || { cat "${EVIDENCE}/09-constraints.txt"; fail "verify-constraints.sql did not run"; }
if grep -q '^FAIL' "${EVIDENCE}/09-constraints.txt"; then fail "constraints: $(grep -c '^FAIL' "${EVIDENCE}/09-constraints.txt") FAIL line(s) in 09-constraints.txt"; else pass "constraints: all $(grep -c '^PASS' "${EVIDENCE}/09-constraints.txt") checks PASS, 29 expected (W1 keys, composite FKs, triggers, the repair, reversal and tz ledgers, twelve migrations)"; fi

artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force > "${EVIDENCE}/10-seeder.txt" 2>&1 || fail "the permission seeder failed (10-seeder.txt)"
PERMS=$(psql_db "$DB" -c "SELECT count(*) FROM permissions WHERE name IN ('hq.consent.view','hq.consent.restrict','hq.consent.grant','hq.playbooks.view','hq.playbooks.draft','hq.playbooks.review','hq.playbooks.activate','hq.followups.view','hq.followups.propose','hq.followups.decide','hq.consent.lift_restriction','hq.outcomes.attribute','hq.customers.quarantine.review')")
[ "$PERMS" = "13" ] && pass "seeder: the thirteen wave 1-4 permissions exist" || fail "seeder: ${PERMS} of 13 permissions exist"
psql_db "$DB" -c "SELECT r.name || ' ' || count(p.id) FROM roles r LEFT JOIN role_has_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id GROUP BY r.name ORDER BY r.name" > "${EVIDENCE}/10-roles.txt"

snapshot "$DB" > "${EVIDENCE}/11-before-dry-runs.txt"
artisan hq:identity:cross-business --dry-run > "${EVIDENCE}/12-detector-dry-run.txt" 2>&1 && pass "detector dry run ran: $(grep -E 'finding\(s\)|Nothing to flag' "${EVIDENCE}/12-detector-dry-run.txt" | head -1)" || fail "hq:identity:cross-business --dry-run failed"
artisan hq:evaluation:attribute-cost > "${EVIDENCE}/13-cost-dry-run.txt" 2>&1 && pass "cost attribution dry run ran ($(grep -c 'DRY RUN' "${EVIDENCE}/13-cost-dry-run.txt") DRY RUN banner)" || fail "hq:evaluation:attribute-cost (dry run) failed"
artisan hq:decisions:report --unassigned > "${EVIDENCE}/14-decisions-unassigned.txt" 2>&1 || warn "hq:decisions:report --unassigned did not run clean"
if [ -z "$REPAIR_CMD" ]; then
    REPAIR_CMD=$(artisan list --raw 2>/dev/null | awk '{print $1}' | grep -E '^hq:(identity|customers):.*repair' | head -1 || true)
fi
if [ -n "$REPAIR_CMD" ]; then
    artisan help "$REPAIR_CMD" > "${EVIDENCE}/15-repair-help.txt" 2>&1 || true
    set +e; artisan "$REPAIR_CMD" > "${EVIDENCE}/15-repair-dry-run.txt" 2>&1; RRC=$?; set -e
    if grep -qE -- '--(apply|execute|write|mutate)' "${EVIDENCE}/15-repair-help.txt"; then
        pass "repair command ${REPAIR_CMD}: a bare invocation ran (exit ${RRC}); mutation needs an explicit option per its help"
    else
        warn "repair command ${REPAIR_CMD}: its help names no explicit mutating option; read 15-repair-help.txt before trusting the default"
    fi
else
    warn "no repair command found on the candidate (W2 not merged); the repair dry run is skipped"
fi
# docs/133 (ruling docs/131 item 8, Stage B): the time-zone backfill dry run, only when the candidate carries the
# command. Never --apply, --key or --by. Row hashes (sql/row-hashes.sql, no row content) before and after prove it
# wrote nothing.
if artisan list --raw 2>/dev/null | awk '{print $1}' | grep -qx 'hq:facts:tz-backfill'; then
    psql_db "$DB" -f - < "${SELF_DIR}/sql/row-hashes.sql" | tr -d '\r' | grep '^rows ' > "${EVIDENCE}/15t-before-tz-rows.txt" || true
    if artisan hq:facts:tz-backfill > "${EVIDENCE}/15t-tz-backfill-dry-run.txt" 2>&1 && grep -q 'Dry run: nothing written' "${EVIDENCE}/15t-tz-backfill-dry-run.txt"; then
        pass "tz backfill dry run: $(grep -E '^facts_orders rows ' "${EVIDENCE}/15t-tz-backfill-dry-run.txt" | head -1)"
    else
        fail "hq:facts:tz-backfill (dry run) failed (15t-tz-backfill-dry-run.txt)"
    fi
    psql_db "$DB" -f - < "${SELF_DIR}/sql/row-hashes.sql" | tr -d '\r' | grep '^rows ' > "${EVIDENCE}/15t-after-tz-rows.txt" || true
    if [ -s "${EVIDENCE}/15t-before-tz-rows.txt" ] && diff -q "${EVIDENCE}/15t-before-tz-rows.txt" "${EVIDENCE}/15t-after-tz-rows.txt" >/dev/null; then
        pass "the tz backfill dry run wrote nothing, row level: all $(grep -c . "${EVIDENCE}/15t-after-tz-rows.txt") tables hold identical rows before and after"
    else
        diff "${EVIDENCE}/15t-before-tz-rows.txt" "${EVIDENCE}/15t-after-tz-rows.txt" > "${EVIDENCE}/15t-tz-rows-diff.txt" || true
        fail "the tz backfill dry run changed rows, or the row hashes could not be read (15t-tz-rows-diff.txt)"
    fi
else
    warn "no hq:facts:tz-backfill on the candidate (docs/133 not merged); the tz dry run is skipped"
fi
snapshot "$DB" > "${EVIDENCE}/16-after-dry-runs.txt"
if diff -q <(grep '^count ' "${EVIDENCE}/11-before-dry-runs.txt") <(grep '^count ' "${EVIDENCE}/16-after-dry-runs.txt") >/dev/null; then
    pass "the dry runs wrote nothing: every table count is unchanged"
else
    diff <(grep '^count ' "${EVIDENCE}/11-before-dry-runs.txt") <(grep '^count ' "${EVIDENCE}/16-after-dry-runs.txt") > "${EVIDENCE}/16-dry-run-diff.txt" || true
    fail "a dry run changed a table count (16-dry-run-diff.txt)"
fi
psql_db "$DB" -f - < "${SELF_DIR}/sql/show-quarantine.sql" > "${EVIDENCE}/17-quarantine.txt" 2>&1 || true
if [ "$SEED" = "1" ]; then
    if grep -q 'seeded identity .* shop_user .* quarantined=store_business_mismatch conflict=quarantined/store_business_mismatch' "${EVIDENCE}/17-quarantine.txt" \
        && grep -q 'seeded identity .* shop_order .* quarantined=store_business_mismatch' "${EVIDENCE}/17-quarantine.txt" \
        && grep -q 'seeded identity .* phone .* quarantined=duplicate_same_customer' "${EVIDENCE}/17-quarantine.txt" \
        && [ "$(grep -c 'seeded identity .* email .* quarantined=duplicate_ambiguous_owner' "${EVIDENCE}/17-quarantine.txt")" = "2" ] \
        && grep -q 'seeded identity .* messenger_psid .* quarantined=contradicted_by_evidence' "${EVIDENCE}/17-quarantine.txt" \
        && grep -q 'seeded order .* cross_business=t' "${EVIDENCE}/17-quarantine.txt"; then
        pass "W1 quarantined the seeded store mismatch (shop_user, shop_order), the duplicate phone copy, both ambiguous e-mails and the foreign PSID; the seeded Bionic order still points at the LaLaDia customer (W2's work)"
    else
        fail "the seeded corruption was not quarantined as docs/122 describes (17-quarantine.txt)"
    fi
    if grep -q 'seeded identity .* phone .* quarantined=no' "${EVIDENCE}/17-quarantine.txt"; then pass "the phone with evidence in its own business stayed live"; else fail "the first phone copy should have stayed live"; fi
fi
grep -q 'store_mismatch_total [0-9]* quarantined=' "${EVIDENCE}/17-quarantine.txt" && {
    T=$(grep -o 'store_mismatch_total [0-9]*' "${EVIDENCE}/17-quarantine.txt" | awk '{print $2}'); Q=$(grep -o 'quarantined=[0-9]*' "${EVIDENCE}/17-quarantine.txt" | head -1 | cut -d= -f2)
    [ "$T" = "$Q" ] && pass "every store-scoped identity under another business's customer is quarantined (${Q} of ${T})" || fail "store-scoped mismatches not all quarantined (${Q} of ${T})"; }

# ── 7. Rollback, one migration at a time, until a down() refuses or the pre-release schema is reached
log "rollback: stopping horizon and scheduler so nothing writes; app stays up for artisan"
$C stop horizon scheduler >/dev/null 2>&1 || true
: > "${EVIDENCE}/18-rollback.txt"
REFUSED=""
for i in $(seq 1 40); do
    LAST=$(psql_db "$DB" -c "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1")
    if grep -qx "$LAST" "${EVIDENCE}/02-before-migrations.txt"; then
        echo "STOP: next is ${LAST}, which the pre-release schema already had" >> "${EVIDENCE}/18-rollback.txt"; break
    fi
    set +e; OUT=$(artisan migrate:rollback --step=1 --force 2>&1); RC=$?; set -e
    if [ "$RC" = "0" ]; then
        echo "ROLLED BACK ${LAST}" >> "${EVIDENCE}/18-rollback.txt"
    else
        REFUSED="$LAST"
        { echo "REFUSED ${LAST}"; echo "$OUT" | grep -iE 'refus|RuntimeException|evidence|backup' | head -3; } >> "${EVIDENCE}/18-rollback.txt"
        break
    fi
done
ROLLED=$(grep -c '^ROLLED BACK' "${EVIDENCE}/18-rollback.txt" || true)
if [ -n "$REFUSED" ]; then
    warn "rollback: ${ROLLED} migration(s) rolled back, then ${REFUSED} REFUSED its down() (one-way door; recovery is the restore path below)"
else
    pass "rollback: every release migration rolled back (${ROLLED})"
fi
snapshot "$DB" > "${EVIDENCE}/19-after-rollback-snapshot.txt"
# Tables that existed before: their counts must be what they were. New tables are gone or still there (refusal).
comm -12 <(grep '^count ' "${EVIDENCE}/02-before-snapshot.txt" | sort) <(grep '^count ' "${EVIDENCE}/19-after-rollback-snapshot.txt" | sort) > "${EVIDENCE}/19-counts-unchanged.txt"
grep '^count ' "${EVIDENCE}/02-before-snapshot.txt" | cut -d' ' -f2 | sort > "${EVIDENCE}/19-tables-before.txt"
join -j1 <(grep '^count ' "${EVIDENCE}/02-before-snapshot.txt" | awk '{print $2, $3}' | sort) <(grep '^count ' "${EVIDENCE}/19-after-rollback-snapshot.txt" | awk '{print $2, $3}' | sort) \
    | awk '$2 != $3 && $1 != "migrations" {print "DIFF", $1, "before", $2, "after_rollback", $3}' > "${EVIDENCE}/19-counts-diff.txt" || true
if [ -s "${EVIDENCE}/19-counts-diff.txt" ]; then
    if [ -n "$REFUSED" ] && [ "$(awk '$2 != "customer_identities" && $2 != "customer_identity_conflicts"' "${EVIDENCE}/19-counts-diff.txt" | wc -l | tr -d ' ')" = "0" ]; then
        pass "after the partial rollback every pre-existing table has its BEFORE count (the refused W1 migration keeps only its own additions)"
    else
        warn "after rollback some pre-existing table counts differ (19-counts-diff.txt; consent_state derived rows are expected to differ when brand- or identity-scoped projection rows existed)"
    fi
else
    pass "after rollback every pre-existing table has exactly its BEFORE count"
fi

# ── 8. Restore proof: the pre-deploy dump deploy.sh took, restored into a second scratch database ──
log "restore proof: ${PRE_DUMP} -> ${DB}_restorecheck"
$C exec -T -e CONFIRM_PRODUCTION_RESTORE=yes backup /restore.sh "$PRE_DUMP" "${DB}_restorecheck" > "${EVIDENCE}/20-restore-proof.txt" 2>&1 || { cat "${EVIDENCE}/20-restore-proof.txt"; fail "restoring the pre-deploy dump failed"; }
snapshot "${DB}_restorecheck" > "${EVIDENCE}/20-restored-snapshot.txt"
if diff -q "${EVIDENCE}/02-before-snapshot.txt" "${EVIDENCE}/20-restored-snapshot.txt" >/dev/null; then
    pass "restore proof: the pre-deploy dump restores to EXACTLY the BEFORE snapshot (counts, migrations, constraints, indexes, triggers, columns)"
else
    diff "${EVIDENCE}/02-before-snapshot.txt" "${EVIDENCE}/20-restored-snapshot.txt" > "${EVIDENCE}/20-restore-diff.txt" || true
    fail "restore proof: the restored database differs from the BEFORE snapshot (20-restore-diff.txt)"
fi
psql_db postgres -c "DROP DATABASE IF EXISTS \"${DB}_restorecheck\"" >/dev/null

# ── 9. Re-apply from the candidate, verify again, posture unchanged ───────────────────────────────
artisan migrate --force > "${EVIDENCE}/21-reapply.txt" 2>&1 && pass "re-apply: migrate --force ran clean" || fail "re-apply failed (21-reapply.txt)"
psql_db "$DB" -f - < "${SELF_DIR}/sql/verify-constraints.sql" > "${EVIDENCE}/22-constraints-after-reapply.txt" 2>&1 || true
grep -q '^FAIL' "${EVIDENCE}/22-constraints-after-reapply.txt" && fail "constraints after re-apply: FAIL lines (22-constraints-after-reapply.txt)" || pass "constraints after re-apply: all PASS"
artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force > "${EVIDENCE}/23-seeder-again.txt" 2>&1 && pass "seeder is idempotent (second run clean)" || fail "second seeder run failed"
snapshot "$DB" > "${EVIDENCE}/24-after-reapply-snapshot.txt"
if diff -q <(grep -v '^count migrations' "${EVIDENCE}/08-after-snapshot.txt" | grep -E '^(count|constraint|index|trigger|column) ') <(grep -v '^count migrations' "${EVIDENCE}/24-after-reapply-snapshot.txt" | grep -E '^(count|constraint|index|trigger|column) ') >/dev/null; then
    pass "re-apply reproduces the post-release schema and counts exactly"
else
    diff <(grep -E '^(count|constraint|index|trigger|column) ' "${EVIDENCE}/08-after-snapshot.txt") <(grep -E '^(count|constraint|index|trigger|column) ' "${EVIDENCE}/24-after-reapply-snapshot.txt") > "${EVIDENCE}/24-reapply-diff.txt" || true
    warn "re-apply differs from the first application (24-reapply-diff.txt): read it; permission rows from the seeder are expected"
fi
$C start horizon scheduler >/dev/null 2>&1 || true
posture > "${EVIDENCE}/25-after-posture.txt"
if diff -q "${EVIDENCE}/02-before-posture.txt" "${EVIDENCE}/25-after-posture.txt" >/dev/null; then
    pass "posture unchanged by the release: flags, agents, grants, shadow periods, tool switches, scheduled jobs"
else
    diff "${EVIDENCE}/02-before-posture.txt" "${EVIDENCE}/25-after-posture.txt" > "${EVIDENCE}/25-posture-diff.txt" || true
    fail "the release changed the posture (25-posture-diff.txt)"
fi
# ── 10. Event bridge across a restore (docs/131 Stage B last bullet; docs/132) ──────────────────────
# Containment Option 2 stops the scheduler while the shops keep delivering. A restore to a dump taken
# before those deliveries would drop them: each was answered 202, so no shop sends it again. Here: stop
# the drain, take a dump, let three deliveries "arrive", export them with the candidate's
# hq:events:export-since, restore the dump with deploy/restore.sh, import with hq:events:import, and prove
# none was lost and none was processed.
log "event bridge: scheduler stopped (Option 2), deliveries after a dump, restore, export/import"
if ! artisan list --raw 2>/dev/null | awk '{print $1}' | grep -qx 'hq:events:export-since' \
    || ! artisan list --raw 2>/dev/null | awk '{print $1}' | grep -qx 'hq:events:import'; then
    fail "event bridge: the candidate has no hq:events:export-since / hq:events:import (docs/132); events received after the pre-deploy dump would be lost by a restore. Re-cut the candidate with the bridge."
else
    $C stop scheduler horizon >/dev/null 2>&1 || true
    ev_hash() { psql_db "$DB" -c "SELECT md5(coalesce(string_agg(e::text, '|' ORDER BY e.id), '')) FROM events e"; }
    BRIDGE_DUMP_HOST="${BACKUPS}/incoming/bridge-base.dump"
    $C exec -T db pg_dump -U "$DB_USER" -d "$DB" -Fc > "$BRIDGE_DUMP_HOST"
    BASE_MAX=$(psql_db "$DB" -c "SELECT coalesce(max(id), 0) FROM events")
    BASE_COUNT=$(psql_db "$DB" -c "SELECT count(*) FROM events")
    # The copy's webhook secrets cannot be decrypted under its new APP_KEY (a signed POST answers 503), so
    # the three deliveries are written with EventRecorder's column shape. Synthetic, nothing personal.
    psql_db "$DB" -c "INSERT INTO events (event_id, type, direction, source, aggregate_type, aggregate_id, business_id, payload, dedupe_key, schema_version, occurred_at, created_at, attempts, max_attempts, status)
        SELECT gen_random_uuid(), 'order.created', 'inbound', 'shop:rehearsal-bridge', 'order', 'RBRIDGE-' || g, NULL,
               jsonb_build_object('order_number', 'RBRIDGE-' || g, 'grand_total', 100.0 * g, '_store', 'rehearsal-bridge', '_schema_version', 1),
               'rehearsal-bridge:' || g, 1, now(), now(), 0, 5, 'pending'
        FROM generate_series(1, 3) AS g" >/dev/null
    psql_db "$DB" -c "SELECT event_id || ' ' || dedupe_key || ' ' || md5(payload::text) FROM events WHERE dedupe_key LIKE 'rehearsal-bridge:%' ORDER BY id" > "${EVIDENCE}/26-bridge-arrived.txt"
    H0=$(ev_hash)
    BRIDGE_FILE=/var/www/html/storage/app/event-bridge/rehearsal-bridge.jsonl
    artisan hq:events:export-since --since-id="$BASE_MAX" --out="$BRIDGE_FILE" > "${EVIDENCE}/26-bridge-export.txt" 2>&1 || fail "event bridge: export failed (26-bridge-export.txt)"
    [ "$(ev_hash)" = "$H0" ] && pass "event bridge: the export is read only (events row hash unchanged)" || fail "event bridge: the export changed the events table"
    if grep -q 'exported 3 inbound event(s)' "${EVIDENCE}/26-bridge-export.txt"; then
        pass "event bridge: the three deliveries after the dump were exported ($(grep -o 'sha256 [0-9a-f]*' "${EVIDENCE}/26-bridge-export.txt"))"
    else
        fail "event bridge: the export did not hold exactly the three deliveries (26-bridge-export.txt)"
    fi

    log "event bridge: restoring the dump taken before the deliveries (app stopped, as restore.sh requires)"
    $C stop app >/dev/null 2>&1 || true
    $C exec -T -e CONFIRM_PRODUCTION_RESTORE=yes backup /restore.sh /backups/incoming/bridge-base.dump "$DB" > "${EVIDENCE}/27-bridge-restore.txt" 2>&1 \
        || { cat "${EVIDENCE}/27-bridge-restore.txt"; fail "event bridge: the restore failed"; }
    $C start app >/dev/null 2>&1 || true
    for i in $(seq 1 60); do artisan --version >/dev/null 2>&1 && break; sleep 2; done
    LOST=$(psql_db "$DB" -c "SELECT count(*) FROM events WHERE dedupe_key LIKE 'rehearsal-bridge:%'")
    if [ "$LOST" = "0" ] && [ "$(psql_db "$DB" -c "SELECT count(*) FROM events")" = "$BASE_COUNT" ]; then
        pass "event bridge: the restore removed the three deliveries (the loss the bridge exists for); events back at ${BASE_COUNT} rows"
    else
        fail "event bridge: after the restore the events table is not the dump's (${LOST} bridge rows left)"
    fi

    H1=$(ev_hash)
    artisan hq:events:import "$BRIDGE_FILE" > "${EVIDENCE}/28-bridge-import-dry-run.txt" 2>&1 || fail "event bridge: the import dry run refused (28-bridge-import-dry-run.txt)"
    if [ "$(ev_hash)" = "$H1" ] && grep -q 'absent, to insert as pending: 3' "${EVIDENCE}/28-bridge-import-dry-run.txt"; then
        pass "event bridge: the import dry run found the three absent and wrote nothing"
    else
        fail "event bridge: the import dry run wrote or misjudged (28-bridge-import-dry-run.txt)"
    fi
    OPERATOR=$(psql_db "$DB" -c "SELECT email FROM users ORDER BY id LIMIT 1")
    artisan hq:events:import "$BRIDGE_FILE" --apply --by="$OPERATOR" --reason="rehearsal: event bridge across a restore" > "${EVIDENCE}/29-bridge-import.txt" 2>&1 \
        || fail "event bridge: the import apply failed (29-bridge-import.txt)"
    psql_db "$DB" -c "SELECT event_id || ' ' || dedupe_key || ' ' || md5(payload::text) FROM events WHERE dedupe_key LIKE 'rehearsal-bridge:%' ORDER BY id" > "${EVIDENCE}/29-bridge-back.txt"
    if diff -q "${EVIDENCE}/26-bridge-arrived.txt" "${EVIDENCE}/29-bridge-back.txt" >/dev/null; then
        pass "event bridge: every delivery is back with its event_id, dedupe key and payload (md5) unchanged: no event lost"
    else
        fail "event bridge: the imported rows differ from the ones that arrived (26-bridge-arrived.txt vs 29-bridge-back.txt)"
    fi
    UNPROCESSED=$(psql_db "$DB" -c "SELECT count(*) FROM events WHERE dedupe_key LIKE 'rehearsal-bridge:%' AND status = 'pending' AND attempts = 0 AND processed_at IS NULL")
    [ "$UNPROCESSED" = "3" ] && pass "event bridge: all three are pending, never attempted, never processed (scheduler stopped)" || fail "event bridge: ${UNPROCESSED} of 3 imported rows are pending and unprocessed"
    AUDITED=$(psql_db "$DB" -c "SELECT count(*) FROM audit_logs WHERE action = 'events.imported'")
    [ "${AUDITED:-0}" -ge 1 ] && pass "event bridge: the import is audited (events.imported)" || fail "event bridge: no events.imported audit record"
    H2=$(ev_hash)
    artisan hq:events:import "$BRIDGE_FILE" --apply --by="$OPERATOR" --reason="rehearsal: second import" > "${EVIDENCE}/30-bridge-import-again.txt" 2>&1 || true
    if [ "$(ev_hash)" = "$H2" ] && grep -q 'inserted 0 event(s)' "${EVIDENCE}/30-bridge-import-again.txt"; then
        pass "event bridge: a second import inserts nothing (idempotent)"
    else
        fail "event bridge: a second import changed the events table (30-bridge-import-again.txt)"
    fi
fi

log "done; the EXIT trap tears down (or keeps, with --keep) and prints the verdicts"
