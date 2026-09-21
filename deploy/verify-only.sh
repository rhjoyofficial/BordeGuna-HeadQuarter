#!/bin/bash
# Deployment preflight. It CHANGES NOTHING and it RELEASES NOTHING (docs/81 C.3).
#
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh --verify-only             # what `deploy.sh` would meet
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh --verify-only --no-pull   # what `deploy.sh --no-pull` would meet
#   HQ_ROOT=/opt/bordeguna-hq bash /tmp/hq-verify-only.sh                # a copy, run against the checkout
#
# It walks as much of the real deployment preflight as can be walked without touching anything, says
# `ok`, `WARN` or `FAIL` for each check with the reason, and exits 1 if anything FAILED.
#
# ⛔ It is an operational safety tool, NOT a replacement for a deployment and NOT a proof that one
# will succeed: it takes no dump, builds no image and runs no migration, and those are where a
# release most often fails. A clean run means "nothing visible from here stands in the way".
#
# READ-ONLY BY CONSTRUCTION. This file contains no `git fetch`, `git merge`, `git checkout`, no
# `mkdir`, no `touch`, no `up`, no `build`, no `backup.sh`, no write to deploy/RELEASES.md or
# deploy/release-state/. What it runs:
#   - git: rev-parse, status, log, diff, show, ls-tree, cat-file, merge-base, and `ls-remote` (which
#     asks origin where main is WITHOUT moving a ref or storing an object; it does not fetch);
#   - docker: `compose version`, `compose config -q`, `compose ps`, `inspect`, and `compose exec` of
#     commands that only read (`wget /up`, the health GET the monitor makes every five minutes,
#     `stat`);
#   - the host: stat, df, reading the backup markers, reading the host lock's one-line record (the
#     lock itself is never opened, created or taken: docs/81 C.9), `kill -0` and /proc to ask whether
#     the process that wrote it is alive.
# Its one footprint is a temporary directory under ${TMPDIR:-/tmp}, removed on exit, used to hand
# the TARGET's compose file to `config -q` without checking it out.
#
# ⚠ The dead bind-mount check is NOT the one deploy.sh uses. deploy.sh proves the mount by writing a
# probe file through it (and repairs it). This compares the inode the container sees at /backups
# with the inode of the host directory: a deleted-and-recreated host directory has a new inode while
# the container still holds the old one. Reading only, so it can say "dead" but cannot repair.
set -uo pipefail
# Normally run as `./deploy/deploy.sh --verify-only` from the checkout. HQ_ROOT names the checkout
# when this file is run from anywhere else: before the first deploy that carries it, production's
# checkout does not have this file yet, so it is run from a copy read out of git (deploy/README.md,
# "NOT YET APPLIED TO PRODUCTION").
cd "${HQ_ROOT:-$(dirname "$0")/..}" 2>/dev/null || { echo "verify-only: cannot enter the checkout '${HQ_ROOT:-$(dirname "$0")/..}'. Nothing was changed."; exit 2; }
# ⚠ Even `git status` writes: it refreshes .git/index when it can take the lock. Not here.
export GIT_OPTIONAL_LOCKS=0
COMPOSE="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
HOST_BACKUP_DIR=${HOST_BACKUP_DIR:-/root/backups/bordeguna-hq}
RELEASE_STATE_DIR=${RELEASE_STATE_DIR:-"$(pwd)/deploy/release-state"}
MIGRATIONS_PATH=${MIGRATIONS_PATH:-apps/hq/database/migrations}
VERIFY_MIN_FREE_MB=${VERIFY_MIN_FREE_MB:-5120}
VERIFY_MAX_DUMP_AGE_HOURS=${VERIFY_MAX_DUMP_AGE_HOURS:-26}
VERIFY_REMOTE_TIMEOUT=${VERIFY_REMOTE_TIMEOUT:-30}

NO_PULL=0
for arg in "$@"; do
    case "$arg" in
        --no-pull) NO_PULL=1 ;;
        --verify-only) ;;
        *) echo "verify-only: unknown argument '${arg}'"; exit 2 ;;
    esac
done

OKS=0; WARNS=0; FAILS=0
section() { echo; echo "== $* =="; }
ok()      { OKS=$((OKS+1));     echo "  ok    $*"; }
warn()    { WARNS=$((WARNS+1)); echo "  WARN  $*"; }
fail()    { FAILS=$((FAILS+1)); echo "  FAIL  $*"; }
note()    { echo "        $*"; }

TMP=""
cleanup() { [ -n "$TMP" ] && rm -rf "$TMP" 2>/dev/null; return 0; }
trap cleanup EXIT

# The few pure helpers of release-state-lib.sh that are needed, without sourcing a file whose other
# functions write.
health_status()  { printf '%s' "${1:-}" | grep -o '^{"status":"[a-z]*"' | cut -d'"' -f4 || true; }
health_release() { printf '%s' "${1:-}" | grep -o '"release":"[^"]*"' | head -1 | cut -d'"' -f4 || true; }
# The components a health answer says are not ok, with their detail: "scheduler fail (no tick
# recorded)". A copy of rs_health_failing in release-state-lib.sh, because this script runs alone from
# a copy on a host whose checkout may not carry the library yet.
health_failing() {
    local part name st why out=""
    while IFS= read -r part; do
        [ -n "$part" ] || continue
        name=$(printf '%s' "$part" | cut -d'"' -f2)
        st=$(printf '%s' "$part" | grep -o '"status":"[a-z]*"' | cut -d'"' -f4)
        why=$(printf '%s' "$part" | grep -oE '"(detail|error)":"[^"]*"' | head -1 | cut -d'"' -f4)
        out="${out:+${out}, }${name} ${st}${why:+ (${why})}"
    done <<EOF
$(printf '%s' "${1:-}" | grep -oE '"[a-z_]+":\{"status":"(fail|degraded)"[^}]*' || true)
EOF
    printf '%s' "$out"
}
same_commit() {
    local a=${1:-} b=${2:-}
    [ "${#a}" -ge 7 ] && [ "${#b}" -ge 7 ] || return 1
    case "$a" in "$b"*) return 0 ;; esac
    case "$b" in "$a"*) return 0 ;; esac
    return 1
}

echo "deploy verify-only $(date -u +%FT%TZ)"
echo "This is a PREFLIGHT. It changes nothing and releases nothing. It is not a deployment, and a"
echo "clean result does not prove one will succeed: no dump is taken, no image built, no migration run."
if [ "$NO_PULL" = "1" ]; then echo "Mode: what './deploy/deploy.sh --no-pull' would meet (release the checked-out commit)."
else echo "Mode: what './deploy/deploy.sh' would meet (fast-forward to origin/main and release it)."; fi

# ── 1. The checkout ────────────────────────────────────────────────────────────────────────────
section "checkout"
HEAD_FULL=$(git rev-parse HEAD 2>/dev/null || true)
HEAD_SHORT=$(git rev-parse --short HEAD 2>/dev/null || true)
if [ -z "$HEAD_FULL" ]; then
    fail "this is not a git checkout; nothing else can be checked"
    echo; echo "RESULT: FAILED. Nothing was changed."; exit 1
fi
BRANCH=$(git symbolic-ref -q --short HEAD 2>/dev/null || true)
if [ -n "$BRANCH" ]; then ok "checkout is ${HEAD_SHORT} on branch ${BRANCH}"
else warn "checkout is ${HEAD_SHORT} with a DETACHED HEAD (a rollback leaves it so). Before a normal deploy: git checkout main"; fi

# ⛔ A hand edit on the production checkout is one merge away from being destroyed, and until then it
# is code nobody reviewed, running nowhere but about to be built into an image (found once on this
# host). Untracked files are not counted: deploy/.env, RELEASES.md and release-state/ live there.
DIRTY=$(git status --porcelain --untracked-files=no 2>/dev/null || true)
if [ -z "$DIRTY" ]; then
    ok "no tracked file is modified on this checkout"
else
    fail "tracked files are MODIFIED on this checkout (a hand edit on production). Look: git status; git diff"
    echo "$DIRTY" | while IFS= read -r line; do note "$line"; done
fi

# ── 1b. One deploy or rollback at a time (docs/81 C.9) ─────────────────────────────────────────
#
# READ ONLY: the lock file is never opened, created or locked here; its one-line record is read.
# deploy.sh and rollback.sh hold `flock` on it for as long as they run and write who they are into
# it (deploy/host-lock-lib.sh). A record whose process is alive and is a release script means one is
# running now: everything below is then read mid-release, and the deploy this previews would be
# refused, so it is a FAIL. A record whose process is gone was left by a release that was killed; the
# kernel released its lock with it, so it is a WARN.
section "host lock (read only: this check never opens or takes the lock)"
HQ_DEPLOY_LOCK=${HQ_DEPLOY_LOCK:-/tmp/bordeguna-hq-deploy.lock}
lock_holder_alive() {
    local pid=${1:-}
    [ -n "$pid" ] || return 1
    kill -0 "$pid" 2>/dev/null || [ -d "/proc/${pid}" ] || return 1
    # A pid can be reused; where the host can say what the process is, it must be a release script.
    if [ -r "/proc/${pid}/cmdline" ]; then
        tr '\0' ' ' < "/proc/${pid}/cmdline" 2>/dev/null | grep -Eq 'deploy\.sh|rollback\.sh' || return 1
    fi
    return 0
}
command -v flock >/dev/null 2>&1 || fail "flock (util-linux) is not installed: deploy.sh and rollback.sh refuse to run without it"
LOCK_OPENABLE=1
if [ -e "$HQ_DEPLOY_LOCK" ]; then
    [ -w "$HQ_DEPLOY_LOCK" ] || LOCK_OPENABLE=0
else
    LOCK_DIR=$(dirname "$HQ_DEPLOY_LOCK")
    { [ -d "$LOCK_DIR" ] && [ -w "$LOCK_DIR" ]; } || LOCK_OPENABLE=0
fi
if [ "$LOCK_OPENABLE" = "0" ]; then
    fail "the host lock ${HQ_DEPLOY_LOCK} cannot be opened by this user (it, or its directory, is missing or not writable): deploy.sh and rollback.sh would refuse"
elif [ -s "$HQ_DEPLOY_LOCK" ]; then
    LOCK_RECORD=$(head -c 300 "$HQ_DEPLOY_LOCK" 2>/dev/null | tr -d '\r\n')
    LOCK_PID=$(printf '%s' "$LOCK_RECORD" | grep -o '^pid=[0-9]*' | cut -d= -f2 || true)
    if lock_holder_alive "$LOCK_PID"; then
        fail "a deploy or rollback is running on this host now (${LOCK_RECORD}). Everything below is read mid-release, and the deploy this previews would be refused. Run this again when it has finished"
    else
        warn "the host lock holds the record of a release that is no longer running (${LOCK_RECORD}): it was killed before it could clear it. The lock itself is free (the kernel released it with the process); the next release overwrites the record"
    fi
else
    ok "no deploy or rollback holds the host lock (${HQ_DEPLOY_LOCK})"
fi

# ── 2. deploy/.env: present, private, complete. Names only, never a value. ─────────────────────
section "deploy/.env"
env_has()      { grep -Eq "^${1}=" deploy/.env 2>/dev/null; }
env_nonempty() { grep -Eq "^${1}=(\"[^\"]+\"|'[^']+'|[^\"' ][^ ]*)" deploy/.env 2>/dev/null; }
if [ ! -f deploy/.env ]; then
    fail "deploy/.env is missing (deploy.sh refuses without it). Template: deploy/.env.example"
else
    MODE=$(stat -c %a deploy/.env 2>/dev/null || echo "?")
    if [ "$MODE" = "600" ]; then ok "deploy/.env is present, mode 600"
    else fail "deploy/.env is mode ${MODE}, must be 600 (deploy.sh refuses). Fix: chmod 600 deploy/.env"; fi

    # What the compose file interpolates, plus what the application cannot start without.
    INTERPOLATED=$(grep -o '\${[A-Z_][A-Z0-9_]*' deploy/docker-compose.yml 2>/dev/null | tr -d '${' | sort -u | tr '\n' ' ')
    REQUIRED=$(printf '%s\n' $INTERPOLATED APP_KEY APP_ENV APP_URL DB_CONNECTION DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD REDIS_HOST REDIS_PASSWORD | sort -u)
    MISSING=""
    for k in $REQUIRED; do env_nonempty "$k" || MISSING="${MISSING}${k} "; done
    if [ -z "$MISSING" ]; then ok "every key the compose file interpolates or the application needs to start is set ($(echo "$REQUIRED" | wc -w | tr -d ' ') keys; values not read out)"
    else fail "deploy/.env is missing, or has empty, required keys: ${MISSING}"; fi

    # A stray RUN_MIGRATIONS (docs/81 C.9). Every PHP service reads deploy/.env through `env_file`;
    # only `app` sets RUN_MIGRATIONS itself. Anything but 0 here reaches horizon and the scheduler,
    # which would migrate at the same moment as app: deploy.sh and rollback.sh both refuse it. A 0 is
    # inert today but is a second place deciding whether a container migrates. The value is never
    # printed, like every other value in this file.
    RM_LINE='^[[:space:]]*(export[[:space:]]+)?RUN_MIGRATIONS[[:space:]]*='
    if grep -Eq "$RM_LINE" deploy/.env 2>/dev/null; then
        RM_VALUE=$(grep -E "$RM_LINE" deploy/.env | tail -1 | cut -d= -f2- | tr -d " \"'\r")
        if [ "$RM_VALUE" = "0" ]; then
            warn "deploy/.env sets RUN_MIGRATIONS (to 0). Inert today, since app sets its own and 0 is every other container's default, but it is a second place deciding whether a container migrates. Remove the line"
        else
            fail "deploy/.env sets RUN_MIGRATIONS to a value other than 0: every PHP container reads that file, so horizon and the scheduler would migrate at the same moment as app (deploy.sh and rollback.sh refuse it). Remove the line"
        fi
    else
        ok "deploy/.env does not set RUN_MIGRATIONS: app's own setting is the only one"
    fi
fi

# ── 3. Docker, compose, and the compose file ───────────────────────────────────────────────────
section "docker and compose"
DOCKER_OK=0
if ! command -v docker >/dev/null 2>&1; then
    fail "docker is not installed or not on PATH"
elif ! docker compose version >/dev/null 2>&1; then
    fail "the docker compose plugin is not available"
elif ! docker ps -q >/dev/null 2>&1; then
    fail "the docker daemon does not answer (docker ps failed)"
else
    DOCKER_OK=1
    ok "docker and the compose plugin answer"
    if [ -f deploy/.env ]; then
        if ERR=$($COMPOSE config -q 2>&1); then ok "deploy/docker-compose.yml is valid (compose config -q)"
        else fail "deploy/docker-compose.yml does not pass 'compose config -q': $(echo "$ERR" | head -3 | tr '\n' ' ')"; fi
    else
        warn "compose file not validated: it needs deploy/.env"
    fi
fi

# ── 4. What is running ─────────────────────────────────────────────────────────────────────────
section "what is running"
RUNNING="none"; APP_ID=""; STACK_UP=0
if [ "$DOCKER_OK" = "1" ]; then
    APP_ID=$($COMPOSE ps -q app 2>/dev/null | head -1) || true
    if [ -n "${APP_ID:-}" ]; then
        RUNNING=$(docker inspect -f '{{ index .Config.Labels "com.bordeguna.commit" }}' "$APP_ID" 2>/dev/null | grep -E '^[0-9a-f]{7,40}$' || echo "unknown")
    fi
    if $COMPOSE ps --status running db >/dev/null 2>&1 && [ -n "$($COMPOSE ps -q db 2>/dev/null)" ]; then STACK_UP=1; fi
fi
case "$RUNNING" in
    none)    warn "no app container is running. deploy.sh treats that as a first deploy and takes NO dump" ;;
    unknown) warn "the running image carries no commit label, so the predecessor would be recorded as unverified" ;;
    *)
        ok "running release is ${RUNNING} (read from the image label)"
        same_commit "$RUNNING" "$HEAD_SHORT" || warn "the checkout (${HEAD_SHORT}) is NOT the running release (${RUNNING}): somebody moved the checkout without releasing, or a run was refused. deploy.sh would record ${RUNNING} as the predecessor"
        ;;
esac
if [ "$DOCKER_OK" = "1" ]; then
    if [ "$STACK_UP" = "1" ]; then ok "the database container is running"; else warn "the database container is not running: deploy.sh would take no dump"; fi
fi

# ── 5. Health of what is running ───────────────────────────────────────────────────────────────
section "health"
HEALTH_NAMES=""
if [ -z "${APP_ID:-}" ]; then
    warn "nothing is running, so nothing answers /up or /api/health"
else
    if $COMPOSE exec -T web wget -qO- http://127.0.0.1/up >/dev/null 2>&1; then ok "/up answers"
    else fail "/up does NOT answer on the running stack. Look: $COMPOSE ps ; $COMPOSE logs --tail 50 web app"; fi

    # ⚠ `ignore_errors` (docs/81 C.8): /api/health answers 503 when a component fails, and without it PHP
    # hands over false instead of the body that says which component. On 21 September this line said
    # "'unreadable' ... {}" while the body named the scheduler.
    HEALTH=$($COMPOSE exec -T app php -r 'echo @file_get_contents("http://web/api/health", false, stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]])) ?: "{}";' 2>/dev/null || echo '{}')
    H_STATUS=$(health_status "$HEALTH"); H_STATUS=${H_STATUS:-unreadable}
    HEALTH_NAMES=$(health_release "$HEALTH")
    H_FAILING=$(health_failing "$HEALTH")
    if [ "$H_STATUS" = "ok" ]; then ok "/api/health is ok"
    elif [ "$H_STATUS" = "unreadable" ]; then fail "/api/health gave no readable answer BEFORE any deploy (nothing answered, it timed out, or the body is not the health JSON): ${HEALTH:0:300}"
    else fail "/api/health is '${H_STATUS}', not ok, BEFORE any deploy${H_FAILING:+; failing: ${H_FAILING}}. Body: ${HEALTH:0:300}"; fi

    if [ -z "$HEALTH_NAMES" ]; then
        warn "/api/health names no commit (a release older than docs/80 C.4 does not), so nothing observed says which image answered"
    elif [ "$RUNNING" = "unknown" ] || [ "$RUNNING" = "none" ]; then
        warn "/api/health names ${HEALTH_NAMES}, but the running image has no label to compare it with"
    elif same_commit "$HEALTH_NAMES" "$RUNNING"; then
        ok "/api/health names the running commit (${HEALTH_NAMES})"
    else
        fail "/api/health names ${HEALTH_NAMES} but the running image label says ${RUNNING}: what answers is not what compose says is running"
    fi
fi

# ── 6. The backup boundary. NO dump is taken here. ─────────────────────────────────────────────
section "backup boundary (no dump is taken by this check)"
if [ "$STACK_UP" != "1" ]; then
    warn "the stack is not up, so deploy.sh would skip the dump; the backup boundary is not checked"
else
    if [ -d "$HOST_BACKUP_DIR" ] && [ -d "${HOST_BACKUP_DIR}/daily" ]; then
        if [ -w "${HOST_BACKUP_DIR}/daily" ]; then ok "${HOST_BACKUP_DIR}/daily exists and is writable on the host"
        else fail "${HOST_BACKUP_DIR}/daily is not writable on the host"; fi
    else
        fail "${HOST_BACKUP_DIR} (or its daily/) DOES NOT EXIST on the host. The sidecar's bind mount is dead or was never made; deploy.sh would recreate the directory and the backup container"
    fi

    if [ -d "$HOST_BACKUP_DIR" ]; then
        HOST_INODE=$(stat -c %i "$HOST_BACKUP_DIR" 2>/dev/null || true)
        CONT_INODE=$($COMPOSE exec -T backup stat -c %i /backups 2>/dev/null | tr -dc 0-9 || true)
        if [ -z "$HOST_INODE" ] || [ -z "$CONT_INODE" ]; then
            warn "could not compare the backup mount's inode (host '${HOST_INODE:-?}', container '${CONT_INODE:-?}'): is the backup container running? deploy.sh proves the mount by writing through it"
        elif [ "$HOST_INODE" = "$CONT_INODE" ]; then
            ok "the backup container's /backups IS the host directory (inode ${HOST_INODE}): the bind mount is live"
        else
            fail "DEAD BIND MOUNT: the backup container's /backups is inode ${CONT_INODE}, the host directory is inode ${HOST_INODE}. The directory was deleted and recreated under a running container (docs/58). Every dump is failing. deploy.sh would repair it; by hand: $COMPOSE up -d --force-recreate --no-deps backup"
        fi

        FREE_MB=$(df -Pk "$HOST_BACKUP_DIR" 2>/dev/null | awk 'NR==2 {print int($4/1024)}')
        if [ -z "${FREE_MB:-}" ]; then warn "could not read free disk space for ${HOST_BACKUP_DIR}"
        elif [ "$FREE_MB" -ge "$VERIFY_MIN_FREE_MB" ]; then ok "free space at the backup root: ${FREE_MB} MB (minimum ${VERIFY_MIN_FREE_MB} MB, VERIFY_MIN_FREE_MB)"
        else fail "free space at the backup root is ${FREE_MB} MB, under the minimum of ${VERIFY_MIN_FREE_MB} MB (VERIFY_MIN_FREE_MB): a dump plus an image build may not fit"; fi

        LAST_STATUS=$(tr -dc 0-9 < "${HOST_BACKUP_DIR}/LAST_STATUS" 2>/dev/null || true)
        LAST_OK=$(tr -d '\r\n' < "${HOST_BACKUP_DIR}/LAST_OK" 2>/dev/null || true)
        if [ -z "$LAST_STATUS" ]; then warn "no LAST_STATUS marker: no backup has ever completed here"
        elif [ "$LAST_STATUS" = "0" ]; then ok "the last backup run reported success"
        else fail "the last backup run reported FAILURE (LAST_STATUS=${LAST_STATUS}); the deploy's own dump is likely to fail the same way. Look: docker logs --tail 50 bordeguna-hq-backup-1"; fi
        if [ -n "$LAST_OK" ]; then
            HOST_DUMP="${HOST_BACKUP_DIR}${LAST_OK#/backups}"
            if [ -s "$HOST_DUMP" ]; then
                AGE_H=$(( ( $(date +%s) - $(stat -c %Y "$HOST_DUMP" 2>/dev/null || echo 0) ) / 3600 ))
                if [ "$AGE_H" -le "$VERIFY_MAX_DUMP_AGE_HOURS" ]; then ok "newest verified dump: ${LAST_OK}, ${AGE_H} h old"
                else warn "newest verified dump ${LAST_OK} is ${AGE_H} h old (nightly dumps should keep it under ${VERIFY_MAX_DUMP_AGE_HOURS} h). The deploy takes its own, but the nightly job deserves a look"; fi
            else
                fail "LAST_OK names ${LAST_OK}, which is missing or empty on the host (${HOST_DUMP})"
            fi
        else
            warn "no LAST_OK marker: no verified dump is on record"
        fi
    fi
fi

# ── 7. origin/main, and what the release would bring ───────────────────────────────────────────
section "what would be released"
TARGET=""; TARGET_SHORT=""
if [ "$NO_PULL" = "1" ]; then
    TARGET="$HEAD_FULL"
    ok "--no-pull: the release would be the checked-out commit ${HEAD_SHORT}; origin is not consulted"
else
    LOCAL_MAIN=$(git rev-parse --verify -q origin/main 2>/dev/null || true)
    # `ls-remote` asks origin where main is. It moves no ref and stores no object: it is NOT a fetch.
    REMOTE_MAIN=$(GIT_TERMINAL_PROMPT=0 timeout "$VERIFY_REMOTE_TIMEOUT" git ls-remote origin refs/heads/main 2>/dev/null | awk 'NR==1 {print $1}' || true)
    if [ -z "$REMOTE_MAIN" ]; then
        fail "origin is NOT reachable (git ls-remote origin refs/heads/main gave nothing within ${VERIFY_REMOTE_TIMEOUT} s): deploy.sh would take its dump and then fail at 'git fetch'. Check the deploy key and the network"
        TARGET="$LOCAL_MAIN"
    else
        ok "origin is reachable; origin/main is ${REMOTE_MAIN:0:7}"
        if [ "$REMOTE_MAIN" = "$LOCAL_MAIN" ]; then
            TARGET="$REMOTE_MAIN"
        elif git cat-file -e "${REMOTE_MAIN}^{commit}" 2>/dev/null; then
            TARGET="$REMOTE_MAIN"
            note "this checkout's origin/main ref still says ${LOCAL_MAIN:0:7}, but the commit itself is already here, so the listing below is exact"
        else
            TARGET="$LOCAL_MAIN"
            warn "origin/main has moved to ${REMOTE_MAIN:0:7}, which this checkout has NOT fetched (this tool never fetches). What follows describes the last FETCHED main, ${LOCAL_MAIN:0:7}; the real deploy would release ${REMOTE_MAIN:0:7}. The cron refresher fetches at most every 30 minutes; run this again after it has"
        fi
    fi
    [ -n "$TARGET" ] || fail "origin/main is not known to this checkout, so what would be released cannot be described"
fi

if [ -n "$TARGET" ]; then
    TARGET_SHORT=$(git rev-parse --short "$TARGET" 2>/dev/null || echo "${TARGET:0:7}")
    if [ "$NO_PULL" != "1" ]; then
        if git merge-base --is-ancestor "$HEAD_FULL" "$TARGET" 2>/dev/null; then ok "${TARGET_SHORT} is a fast-forward of the checkout (${HEAD_SHORT})"
        else fail "${TARGET_SHORT} is NOT a fast-forward of the checkout ${HEAD_SHORT}: 'git merge --ff-only origin/main' would refuse, after the dump. Look: git log --oneline --graph -15 HEAD origin/main"; fi
    fi

    # Against what is RUNNING when that is known, as the release-state refresher does.
    BASE="$HEAD_FULL"; BASE_NAME="the checkout ${HEAD_SHORT}"
    if [ "$RUNNING" != "none" ] && [ "$RUNNING" != "unknown" ] && git cat-file -e "${RUNNING}^{commit}" 2>/dev/null; then
        BASE="$RUNNING"; BASE_NAME="the running release ${RUNNING}"
    fi
    COUNT=$(git rev-list --count "${BASE}..${TARGET}" 2>/dev/null || echo "?")
    if [ "$COUNT" = "0" ]; then
        note "0 commits between ${BASE_NAME} and ${TARGET_SHORT}: a deploy would rebuild and re-release the same commit"
    else
        note "${COUNT} commit(s) between ${BASE_NAME} and ${TARGET_SHORT}:"
        git log --format='%h %s' --max-count=30 "${BASE}..${TARGET}" 2>/dev/null | while IFS= read -r line; do note "  ${line}"; done
        [ "$COUNT" = "?" ] || [ "$COUNT" -le 30 ] || note "  ... and $((COUNT - 30)) more"
    fi

    ADDED=$(git diff --name-only --diff-filter=A "${BASE}..${TARGET}" -- "$MIGRATIONS_PATH" 2>/dev/null | sort || true)
    CHANGED=$(git diff --name-only --diff-filter=MRD "${BASE}..${TARGET}" -- "$MIGRATIONS_PATH" 2>/dev/null | sort || true)
    if [ -z "$ADDED" ]; then
        ok "the release brings no new migration file"
    else
        note "$(echo "$ADDED" | wc -l | tr -d ' ') pending migration file(s), which the app entrypoint would run with 'migrate --force':"
        DESTRUCTIVE=0
        while IFS= read -r f; do
            [ -n "$f" ] || continue
            note "  ${f##*/}"
            # Only up(): a down() is SUPPOSED to drop what up() made.
            HITS=$(git show "${TARGET}:${f}" 2>/dev/null \
                | awk '/function[[:space:]]+up[[:space:]]*\(/ {f=1} /function[[:space:]]+down[[:space:]]*\(/ {f=0} f' \
                | grep -nE 'dropColumns?\(|(::|->)drop(IfExists)?\(|renameColumn\(|(::|->)rename\(|->change\(|DROP[[:space:]]+(TABLE|COLUMN)|TRUNCATE' || true)
            if [ -n "$HITS" ]; then
                DESTRUCTIVE=$((DESTRUCTIVE+1))
                warn "DESTRUCTIVE statement in the up() of ${f##*/}. This project's rule is ADDITIVE migrations: a rollback of the code would meet a schema the old code does not fit. Read it before deploying:"
                echo "$HITS" | head -5 | while IFS= read -r line; do note "    ${line}"; done
            fi
        done <<EOF_ADDED
$ADDED
EOF_ADDED
        [ "$DESTRUCTIVE" -gt 0 ] || ok "no pending migration's up() drops, renames or changes a column or table"
    fi
    if [ -n "$CHANGED" ]; then
        warn "migration file(s) MODIFIED, renamed or deleted rather than added. A migration that already ran will NOT run again:"
        echo "$CHANGED" | while IFS= read -r line; do note "  ${line##*/}"; done
    fi

    # New keys the incoming release's template has and this host's .env does not.
    if [ -f deploy/.env ]; then
        NEWKEYS=""
        for k in $(git show "${TARGET}:deploy/.env.example" 2>/dev/null | grep -E '^[A-Za-z_][A-Za-z0-9_]*=' | cut -d= -f1); do
            env_has "$k" || NEWKEYS="${NEWKEYS}${k} "
        done
        if [ -z "$NEWKEYS" ]; then ok "deploy/.env has every key of ${TARGET_SHORT}'s deploy/.env.example"
        else warn "keys in ${TARGET_SHORT}'s deploy/.env.example that deploy/.env does not have (the defaults in config/ apply until they are added): ${NEWKEYS}"; fi
    fi

    # The target's compose file, when the release changes it, validated without being checked out.
    if [ "$DOCKER_OK" = "1" ] && [ -f deploy/.env ] && ! git diff --quiet HEAD "$TARGET" -- deploy/docker-compose.yml 2>/dev/null; then
        TMP=$(mktemp -d "${TMPDIR:-/tmp}/hq-verify.XXXXXX")
        if git show "${TARGET}:deploy/docker-compose.yml" > "${TMP}/target-compose.yml" 2>/dev/null \
            && ERR=$(docker compose -f "${TMP}/target-compose.yml" --project-directory deploy --env-file deploy/.env config -q 2>&1); then
            ok "the release changes deploy/docker-compose.yml, and ${TARGET_SHORT}'s copy is valid"
        else
            fail "the release changes deploy/docker-compose.yml, and ${TARGET_SHORT}'s copy does not pass 'compose config -q': $(echo "${ERR:-unreadable}" | head -3 | tr '\n' ' ')"
        fi
    fi

    for f in deploy/deploy.sh deploy/rollback.sh deploy/release-state-refresh.sh; do
        M=$(git ls-tree "$TARGET" -- "$f" 2>/dev/null | awk '{print $1}')
        [ -z "$M" ] || [ "$M" = "100755" ] || warn "${f} is not executable in ${TARGET_SHORT} (mode ${M}): './${f}' would fail after this release. Fix in git: git update-index --chmod=+x ${f}"
    done
fi

# ── 8. Release state (docs/80 C.4) ─────────────────────────────────────────────────────────────
section "release state"
if [ ! -d "$RELEASE_STATE_DIR" ]; then
    warn "${RELEASE_STATE_DIR} does not exist yet. Expected ONCE, before the first deploy that carries docs/80 C.4; compose creates it"
else
    if [ -w "$RELEASE_STATE_DIR" ]; then ok "${RELEASE_STATE_DIR} exists and is writable by this user"
    else fail "${RELEASE_STATE_DIR} exists but is NOT writable by this user: the release could not be recorded"; fi

    if [ -n "${APP_ID:-}" ]; then
        if docker inspect -f '{{range .Mounts}}{{println .Source .Destination .RW}}{{end}}' "$APP_ID" 2>/dev/null | grep -q ' /var/www/html/release-state '; then
            H_I=$(stat -c %i "$RELEASE_STATE_DIR" 2>/dev/null || true)
            C_I=$($COMPOSE exec -T app stat -c %i /var/www/html/release-state 2>/dev/null | tr -dc 0-9 || true)
            if [ -n "$H_I" ] && [ "$H_I" = "$C_I" ]; then ok "it is mounted in app, and the mount is live (inode ${H_I})"
            elif [ -z "$H_I" ] || [ -z "$C_I" ]; then warn "it is mounted in app, but the inodes could not be compared"
            else warn "it is mounted in app on a DEAD inode (container ${C_I}, host ${H_I}): the panel reads nothing. The next 'up' of a new image recreates the container and repairs it; by hand: $COMPOSE up -d --force-recreate --no-deps app horizon scheduler"; fi
        else
            warn "it is NOT mounted in the running app container (expected once: the running release predates docs/80 C.4)"
        fi
    fi

    if [ -s "${RELEASE_STATE_DIR}/release.json" ]; then
        R_COMMIT=$(grep -o '"commit":"[^"]*"' "${RELEASE_STATE_DIR}/release.json" | head -1 | cut -d'"' -f4 || true)
        R_KIND=$(grep -o '"kind":"[^"]*"' "${RELEASE_STATE_DIR}/release.json" | head -1 | cut -d'"' -f4 || true)
        R_STATUS=$(grep -o '"status":"[^"]*"' "${RELEASE_STATE_DIR}/release.json" | head -1 | cut -d'"' -f4 || true)
        R_VER=$(grep -o '"verification":{"status":"[^"]*"' "${RELEASE_STATE_DIR}/release.json" | head -1 | cut -d'"' -f6 || true)
        note "last attempt on record: ${R_KIND:-?} of ${R_COMMIT:-?}, ${R_STATUS:-?}, verification ${R_VER:-?}"
        if [ "$R_STATUS" = "released" ] && [ "$R_VER" != "passed" ] && same_commit "${R_COMMIT:-}" "$RUNNING"; then
            if same_commit "${HEALTH_NAMES:-}" "$RUNNING" && [ "${H_STATUS:-}" = "ok" ]; then
                warn "the running release ${RUNNING} is on record as UNVERIFIED, and health is now ok and names it: './deploy/deploy.sh --no-pull' on that commit will verify and record it"
            else
                warn "the running release ${RUNNING} is on record as UNVERIFIED"
            fi
        fi
    else
        note "no release.json: this host has no record of a release yet"
    fi
fi

# ── Result ─────────────────────────────────────────────────────────────────────────────────────
echo
echo "${OKS} ok, ${WARNS} warning(s), ${FAILS} failure(s)."
if [ "$FAILS" -gt 0 ]; then
    echo "RESULT: FAILED. Do not deploy until every FAIL above is understood. Nothing was changed."
    exit 1
fi
echo "RESULT: no failure found. Nothing was changed and NOTHING WAS RELEASED: this was a preflight, not a"
echo "deployment. The dump, the build and the migrations have not been tried; './deploy/deploy.sh' tries them."
exit 0
