#!/bin/bash
# Roll HQ back to an earlier release.
#
#   cd /opt/bordeguna-hq && ./deploy/rollback.sh <sha>
#
# THE INVARIANT (docs/81 C.3), and it is the whole point of this script:
#
#   A rollback changes CODE and nothing else. It never runs a migration, up or down, and it writes
#   nothing to the database itself. It happens only behind a verified dump, exactly like a deploy.
#
# What that means for the SCHEMA: it is NOT rolled back. Migrations in this project are additive, so
# the earlier code runs against the newer, additive schema. Undoing a migration, or returning data to
# an earlier state, is deploy/restore.sh from the pre-deploy dump: a separate, deliberate act with
# its own confirmation (deploy/README.md, "Database rollback"), never a side effect of this script.
#
# ORDER:
#
#   target known and refusable -> the resolved compose configuration says RUN_MIGRATIONS=0
#   (both are reads: a refused rollback takes nothing, not even a dump) -> backup boundary
#   verified -> dump taken -> dump verified -> ONLY THEN the checkout moves -> build -> up
#   (migrations OFF) -> the started container's environment is read back -> smoke and verify
#   -> record (kind: rollback)
#
# ⛔ Until docs/81 C.3 this script said "Do NOT run migrations" and ran them: it set RUN_MIGRATIONS=0
# on `docker compose build`, where it reaches nothing, while docker-compose.yml hardcodes "1" on
# `app` and the entrypoint migrates whenever it reads 1. It also moved the checkout with no dump.
#
# HOW MIGRATIONS ARE KEPT OFF. A compose override, written to a temporary file OUTSIDE the checkout
# and passed as a second `-f` on `up`, sets services.app.environment.RUN_MIGRATIONS to "0". It has to
# be an override and not a variable in docker-compose.yml: `git checkout <target>` replaces that file
# with the TARGET's copy, and every release older than this one hardcodes "1".
#
# ⚠ After a rollback, a bare `docker compose up -d` typed by hand sees a changed `app` definition and
# recreates it with RUN_MIGRATIONS=1. A restart or a reboot does not. The way forward is
# `git checkout main && ./deploy/deploy.sh --no-pull`, which is a deploy and migrates by design.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
BF_VERB="roll back"; BF_NOUN="rollback"
log() { echo "[rollback $(date -u +%FT%TZ)] $*"; }
die() { log "$*"; exit 1; }

# The whole body is one function, called on the last line, so bash has parsed every line of this
# file before `git checkout` replaces it on disk with the target's copy.
main() {
    local TARGET_ARG=${1:-}
    [ -n "$TARGET_ARG" ] && [ "$#" -eq 1 ] || die "usage: rollback.sh <sha>   (nothing has moved)"

    [ -f deploy/.env ] || die "deploy/.env missing. Refusing to roll back; nothing has moved."
    [ "$(stat -c %a deploy/.env)" = "600" ] || die "deploy/.env must be mode 600. Refusing to roll back; nothing has moved."

    # Sourced BEFORE the checkout moves: the target may be older than either file.
    # shellcheck source=deploy/release-state-lib.sh
    . deploy/release-state-lib.sh
    # shellcheck source=deploy/backup-first-lib.sh
    . deploy/backup-first-lib.sh

    # ── 1. The target, and the refusals. Nothing has moved when any of these fires. ────────────
    local TARGET SHORT CUR RUNNING PREV PREV_KNOWN PREV_NOTE
    TARGET=$(git rev-parse --verify -q "${TARGET_ARG}^{commit}" 2>/dev/null) \
        || die "'${TARGET_ARG}' is not a commit this checkout knows. Refusing to roll back; nothing has moved."
    SHORT=$(git rev-parse --short "$TARGET")
    CUR=$(git rev-parse --short HEAD)
    RUNNING=$(running_release)
    log "checkout ${CUR}; running ${RUNNING}; target ${SHORT}"

    if [ "$RUNNING" = "none" ] || [ "$RUNNING" = "unknown" ]; then
        PREV="$CUR"; PREV_KNOWN=0
        PREV_NOTE=" (unverified: the running image carries no commit label)"
    else
        PREV="$RUNNING"; PREV_KNOWN=1; PREV_NOTE=""
        [ "$RUNNING" = "$CUR" ] || log "WARNING: checkout ${CUR} is not the running release ${RUNNING}. Rolling back FROM ${RUNNING}, which is what is running."
    fi

    if rs_same_commit "$PREV" "$SHORT"; then
        die "${SHORT} is already what is running. Refusing to roll back; nothing has moved. To rebuild the same commit: ./deploy/deploy.sh --no-pull"
    fi

    # A rollback goes BACKWARDS along what was released. A commit that is neither an ancestor of the
    # running release nor on record as released may carry migrations production has never seen, and
    # releasing it is a deploy (which dumps AND migrates), not a rollback.
    local base="$PREV" known=0
    git cat-file -e "${base}^{commit}" 2>/dev/null || base=HEAD
    if git merge-base --is-ancestor "$TARGET" "$base" 2>/dev/null; then
        known=1
    elif [ -f deploy/RELEASES.md ] && grep -Eq "^\| [^|]+ \| (ROLLBACK to )?${SHORT}[0-9a-f]* \|" deploy/RELEASES.md; then
        known=1
    fi
    [ "$known" = 1 ] || die "${SHORT} is neither an ancestor of the running release ${PREV} nor a release on record in deploy/RELEASES.md. Refusing to roll back; nothing has moved. Releasing an arbitrary commit is a deploy: git checkout ${SHORT} && ./deploy/deploy.sh --no-pull"

    # A hand edit on the production checkout would be baked into the rebuilt image, or stop the
    # checkout half way. It has happened on this host once; it is refused, with the files named.
    local dirty
    dirty=$(GIT_OPTIONAL_LOCKS=0 git status --porcelain --untracked-files=no 2>/dev/null || true)
    [ -z "$dirty" ] || die "tracked files are modified on this checkout: $(echo "$dirty" | awk '{print $2}' | tr '\n' ' '). Refusing to roll back; nothing has moved. Look: git status; git diff"

    bf_stack_is_up || die "the database container is not running, so no dump can be taken. Refusing to roll back; nothing has moved. Starting a stack is ./deploy/deploy.sh --no-pull"

    # ── 2. Migrations OFF, observed before anything moves ──────────────────────────────────────
    #
    # A read, like every refusal above, so it runs before the dump: a rollback that is going to be
    # refused takes nothing, not even a backup.
    local TMP OVERRIDE TARGET_COMPOSE resolved
    TMP=$(mktemp -d "${TMPDIR:-/tmp}/hq-rollback.XXXXXX")
    # From here the EXIT trap removes the temporary directory. It records nothing until STAGE is
    # set, which happens only after the checkout has moved.
    RB_TMP="$TMP"
    trap record_failed_rollback EXIT
    OVERRIDE="${TMP}/no-migrations.override.yml"
    TARGET_COMPOSE="${TMP}/target-compose.yml"
    cat > "$OVERRIDE" <<'YAML'
# Written by deploy/rollback.sh. A rollback never runs migrations (docs/81 C.3).
services:
  app:
    environment:
      RUN_MIGRATIONS: "0"
YAML
    git show "${TARGET}:deploy/docker-compose.yml" > "$TARGET_COMPOSE" 2>/dev/null \
        || die "${SHORT} has no deploy/docker-compose.yml. Refusing to roll back; the checkout has NOT moved."
    # The target's OWN compose file, read out of git and never checked out, merged with the override
    # exactly as `up` will merge them.
    resolved=$(docker compose -f "$TARGET_COMPOSE" -f "$OVERRIDE" --project-directory deploy --env-file deploy/.env config 2>/dev/null \
        | grep -E '^[[:space:]]*RUN_MIGRATIONS:' | tr -dc '0-9\n' | sort -u | tr '\n' ' ' | sed 's/ *$//') || true
    if [ "$resolved" != "0" ]; then
        die "the compose configuration for ${SHORT} does not resolve RUN_MIGRATIONS to 0 with the rollback override (resolved: '${resolved:-nothing}'), so starting it would run migrations. Refusing to roll back; the checkout has NOT moved."
    fi
    log "resolved configuration for ${SHORT}: app RUN_MIGRATIONS=0 (no migration will run)"
    local COMPOSE_NO_MIGRATE="docker compose -f deploy/docker-compose.yml -f ${OVERRIDE} --env-file deploy/.env"

    # ── 3. Backup first, through the same functions as deploy.sh ───────────────────────────────
    local DUMP="" DUMP_AT=""
    ensure_backup_boundary
    take_verified_dump

    # ── 4. Only now may the checkout move ──────────────────────────────────────────────────────
    log "rolling back ${PREV} -> ${SHORT}"
    git checkout -q "$TARGET"

    # A rollback is a release, and the application is told about it the same way (docs/80 C.4). A
    # rollback that dies after the checkout moved is recorded as a failed attempt; the last verified
    # release on record is never touched on that path.
    STAGE=build; VER_STATUS=not_run; VER_UP=""; VER_HEALTH=""; VER_HEALTH_RELEASE=""; VER_AT=""
    RB_SHORT="$SHORT"; RB_PREV="$PREV"; RB_PREV_KNOWN="$PREV_KNOWN"; RB_DUMP="$DUMP"; RB_DUMP_AT="$DUMP_AT"

    # ⚠ The commit is stamped into the image, as deploy.sh does, so the next deploy can read what is
    # running and the application can say what it is.
    $COMPOSE build --build-arg GIT_COMMIT="${SHORT}" app

    STAGE=up
    $COMPOSE_NO_MIGRATE up -d --no-deps app horizon scheduler web
    sleep 5

    # What actually started, read back rather than assumed.
    local app_id app_env
    app_id=$($COMPOSE ps -q app 2>/dev/null | head -1) || true
    app_env=$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "${app_id:-none}" 2>/dev/null | grep -E '^RUN_MIGRATIONS=' | tail -1 || true)
    if [ "$app_env" = "RUN_MIGRATIONS=0" ]; then
        log "app started with RUN_MIGRATIONS=0: no migration was run. The schema and the data are exactly as they were."
    else
        log "ERROR: app started with '${app_env:-no RUN_MIGRATIONS at all}', not RUN_MIGRATIONS=0. Its entrypoint MAY have run 'migrate --force'. Look now: $COMPOSE logs --tail 80 app | grep -i migrat ; the dump taken before this rollback is ${DUMP}"
        exit 1
    fi

    $COMPOSE exec -T horizon php artisan horizon:terminate || true
    STAGE=smoke
    VER_STATUS=failed; VER_UP=failed; VER_AT=$(rs_now)
    if $COMPOSE exec -T web wget -qO- http://127.0.0.1/up >/dev/null; then log "/up ok"; else log "/up FAILED after rollback"; exit 1; fi
    VER_UP=ok

    # The same meaning of "verified" as deploy.sh: health ok, and the health answer names this commit.
    # ⚠ A release older than docs/80 C.4 does not name its commit in /api/health, so rolling back to
    # one is recorded as UNVERIFIED. That is the honest answer: nothing observed says which image
    # answered.
    local HEALTH i
    HEALTH_TRIES=${HEALTH_TRIES:-18}
    HEALTH_WAIT=${HEALTH_WAIT:-5}
    for i in $(seq 1 "$HEALTH_TRIES"); do
        # `ignore_errors` keeps the body of a 503, which names the failing component (docs/81 C.8).
        HEALTH=$($COMPOSE exec -T app php -r 'echo @file_get_contents("http://web/api/health", false, stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]])) ?: "{}";' 2>/dev/null || echo '{}')
        VER_HEALTH=$(rs_health_status "$HEALTH"); VER_HEALTH=${VER_HEALTH:-unreadable}
        VER_HEALTH_RELEASE=$(rs_health_release "$HEALTH")
        VER_AT=$(rs_now)
        if [ "$VER_HEALTH" = "ok" ] && rs_same_commit "$VER_HEALTH_RELEASE" "$SHORT"; then VER_STATUS=passed; break; fi
        if [ "$i" -lt "$HEALTH_TRIES" ]; then sleep "$HEALTH_WAIT"; fi
    done
    if [ "$VER_STATUS" = "passed" ]; then
        log "verified: health ok and names ${SHORT}"
    else
        VER_FAILING=$(rs_health_failing "$HEALTH")
        log "WARNING: rollback to ${SHORT} is UNVERIFIED: health ${VER_HEALTH}${VER_FAILING:+ (failing: ${VER_FAILING})}, names '${VER_HEALTH_RELEASE:-none}'"
    fi

    STAGE=record
    echo "| $(date -u +%FT%TZ) | ROLLBACK to ${SHORT} | from ${PREV}${PREV_NOTE} | code only, no migrations run, dump ${DUMP} | verification ${VER_STATUS} |" >> deploy/RELEASES.md
    if rs_write_release rollback released "" "$SHORT" "$PREV" "$PREV_KNOWN" "$DUMP" "$DUMP_AT" "$(git rev-parse --short --verify -q origin/main 2>/dev/null || true)" \
        "$VER_STATUS" "$VER_UP" "$VER_HEALTH" "$VER_HEALTH_RELEASE" "$VER_AT"; then
        if [ "$VER_STATUS" = "passed" ]; then rs_mark_verified || true; fi
    else
        log "WARNING: could not write ${RELEASE_STATE_DIR}/release.json"
    fi
    STAGE=done
    if [ -x deploy/release-state-refresh.sh ]; then ./deploy/release-state-refresh.sh || true; fi
    log "rolled back to ${SHORT}: code only. No migration was run and the database was not restored; the schema is as the newer release left it. Dump taken first: ${DUMP}."
    log "To return to main later: git checkout main && ./deploy/deploy.sh --no-pull"
}

record_failed_rollback() {
    rm -rf "${RB_TMP:-/nonexistent-hq-rollback-tmp}" 2>/dev/null || true
    [ "${STAGE:-done}" != "done" ] || return 0
    log "rollback to ${RB_SHORT} FAILED at stage '${STAGE}'"
    rs_write_release rollback failed "$STAGE" "$RB_SHORT" "$RB_PREV" "$RB_PREV_KNOWN" "$RB_DUMP" "$RB_DUMP_AT" "" \
        "$VER_STATUS" "$VER_UP" "$VER_HEALTH" "$VER_HEALTH_RELEASE" "$VER_AT" || true
    return 0
}

main "$@"
