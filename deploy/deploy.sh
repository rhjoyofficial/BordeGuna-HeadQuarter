#!/bin/bash
# Release BordeGuna-HeadQuarter on the VPS.
#
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh            # fast-forward to origin/main and release
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh --no-pull  # release the checked-out commit
#   cd /opt/bordeguna-hq && ./deploy/deploy.sh --verify-only [--no-pull]
#                                     # preflight only: changes NOTHING, releases nothing (verify-only.sh)
#
# ORDER, and it is the whole point of this script:
#
#   backup boundary verified -> dump taken -> dump verified -> ONLY THEN the checkout moves
#   -> build -> up -> migrate (app entrypoint) -> horizon restart -> smoke and verify -> record
#   -> release state (deploy/release-state/, docs/80 C.4)
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
BF_VERB="deploy"; BF_NOUN="deploy"
log() { echo "[deploy $(date -u +%FT%TZ)] $*"; }
die() { log "$*"; exit 1; }

# ── Arguments. An argument this script does not know is REFUSED: until docs/81 C.3 anything other
# than `--no-pull` (a typing mistake such as `--nopull` included) meant "fast-forward to origin/main".
NO_PULL=0; VERIFY_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --no-pull)     NO_PULL=1 ;;
        --verify-only) VERIFY_ONLY=1 ;;
        *) die "unknown argument '${arg}'. Usage: deploy.sh [--no-pull] [--verify-only]. Nothing has moved." ;;
    esac
done

# `--verify-only` changes NOTHING: it is handed to a script that contains no mutating command at
# all, before this one has done anything. It is a preflight, not a deployment (docs/81 C.3).
if [ "$VERIFY_ONLY" = "1" ]; then
    [ -f deploy/verify-only.sh ] || die "deploy/verify-only.sh is missing from this checkout"
    if [ "$NO_PULL" = "1" ]; then exec bash deploy/verify-only.sh --no-pull; fi
    exec bash deploy/verify-only.sh
fi

[ -f deploy/.env ] || die "deploy/.env missing"
# shellcheck source=deploy/release-state-lib.sh
. deploy/release-state-lib.sh
# shellcheck source=deploy/backup-first-lib.sh
. deploy/backup-first-lib.sh
[ "$(stat -c %a deploy/.env)" = "600" ] || die "deploy/.env must be mode 600"

# ── What is actually running, before anything moves ────────────────────────────────────────────
#
# `running_release` (deploy/backup-first-lib.sh) reads the RUNNING container's image label rather
# than inferring from the checkout. The checkout is a statement of intent; the label is a statement
# of fact, and when they disagree the fact is the one worth recording.
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
    PREV_KNOWN=0
else
    PREV="$RUNNING"
    PREV_NOTE=""
    PREV_KNOWN=1
fi

# ── Release state: what the application is told about this attempt (docs/80 C.4) ───────────────
#
# ⛔ Nothing is written here until the checkout has moved, and the checkout moves only after a
# verified dump. A REFUSED deploy therefore leaves the release state exactly as it was, for the same
# reason it leaves the checkout: a record ahead of the running containers is a lie that outlives
# the failure.
#
# Once the checkout HAS moved, a failure is recorded rather than lost: the attempt, the stage it
# reached, and nothing else. `last-verified.json` is never touched on this path, so the last release
# that actually passed its checks stays on record. The application compares its OWN commit (baked
# into the image) with this record, so a failed build reads as "the attempt failed, production still
# runs the old release" and never as a release of the new commit.
RELEASE_STAGE=""
NEW=""; DUMP=""; DUMP_AT=""; MAIN_AT=""
VER_STATUS="not_run"; VER_UP=""; VER_HEALTH=""; VER_HEALTH_RELEASE=""; VER_AT=""
record_failed_release() {
    [ -n "$RELEASE_STAGE" ] && [ "$RELEASE_STAGE" != "done" ] || return 0
    log "release of ${NEW} FAILED at stage '${RELEASE_STAGE}'. Recording the failure; the last verified release on record is left as it was."
    rs_write_release deploy failed "$RELEASE_STAGE" "$NEW" "$PREV" "$PREV_KNOWN" "$DUMP" "$DUMP_AT" "$MAIN_AT" \
        "$VER_STATUS" "$VER_UP" "$VER_HEALTH" "$VER_HEALTH_RELEASE" "$VER_AT" \
        || log "WARNING: could not write ${RELEASE_STATE_DIR}/release.json"
    return 0
}
trap record_failed_release EXIT

STACK_IS_UP=0
if bf_stack_is_up; then
    STACK_IS_UP=1
fi

# ── 1. The backup boundary, before the dump that depends on it ─────────────────────────────────
# ── 2. The dump, and its verification, BEFORE the checkout moves ───────────────────────────────
#
# Both live in deploy/backup-first-lib.sh, shared with rollback.sh so the invariant has ONE
# implementation (docs/81 C.3): the dead bind-mount repair, the do-not-delete marker, `backup.sh
# once`, and the LAST_STATUS / LAST_OK / non-empty-dump checks behind it.
if [ "$STACK_IS_UP" = "1" ]; then
    ensure_backup_boundary
    take_verified_dump
else
    log "stack is not running (first deploy); nothing to dump"
fi

# ── 3. Only now may the checkout move ──────────────────────────────────────────────────────────
FETCHED_NOW=0
if [ "$NO_PULL" != "1" ]; then
    git fetch -q origin
    FETCHED_NOW=1
    git merge --ff-only origin/main
fi
NEW=$(git rev-parse --short HEAD)
MAIN_AT=$(git rev-parse --short --verify -q origin/main 2>/dev/null || true)
RELEASE_STAGE=build
log "releasing ${NEW} (previous ${PREV})"

# ── 4. Build. The commit is stamped into the image so the next deploy can read what is running. ─
log "building images"
$COMPOSE build --pull --build-arg GIT_COMMIT="${NEW}" app
$COMPOSE build --pull backup

# ── 5. Release. `app` runs migrations in its entrypoint (RUN_MIGRATIONS=1). ─────────────────────
RELEASE_STAGE=up
log "starting stack"
$COMPOSE up -d --remove-orphans
sleep 5
$COMPOSE exec -T horizon php artisan horizon:terminate || true
# nginx resolves the `app` upstream once at start-up; after `app` is recreated it keeps the old IP and
# answers 502 until restarted (seen on the first deploy). Recreate `web` so it resolves the new container.
$COMPOSE up -d --force-recreate --no-deps web

# ── 6. Smoke, and the verification that makes a release "verified". ────────────────────────────
#
# Verified means three things, all of them observed: `/up` answered; `/api/health` answered with
# overall `ok`; and that health answer NAMES the commit just released, so what answered is known to
# be the new image and not the old one still draining. Health is retried for a bounded time because
# Horizon was terminated a few seconds ago and reads as `fail` until its supervisor is back.
#
# ⚠ A health answer that never comes good does NOT fail the deploy - it never did, and the stack is
# already up. It is recorded as an UNVERIFIED release, loudly, and the last verified release on
# record stays where it was.
RELEASE_STAGE=smoke
log "smoke checks"
VER_STATUS=failed; VER_UP=failed; VER_AT=$(rs_now)
for i in $(seq 1 30); do
    if $COMPOSE exec -T web wget -qO- http://127.0.0.1/up >/dev/null 2>&1; then VER_UP=ok; break; fi
    sleep 2
    [ "$i" -eq 30 ] && { log "/up never answered"; $COMPOSE ps; exit 1; }
done

HEALTH_TRIES=${HEALTH_TRIES:-18}
HEALTH_WAIT=${HEALTH_WAIT:-5}
HEALTH='{}'
for i in $(seq 1 "$HEALTH_TRIES"); do
    HEALTH=$($COMPOSE exec -T app php -r 'echo @file_get_contents("http://web/api/health") ?: "{}";' 2>/dev/null || echo '{}')
    VER_HEALTH=$(rs_health_status "$HEALTH")
    VER_HEALTH=${VER_HEALTH:-unreadable}
    VER_HEALTH_RELEASE=$(rs_health_release "$HEALTH")
    VER_AT=$(rs_now)
    if [ "$VER_HEALTH" = "ok" ] && rs_same_commit "$VER_HEALTH_RELEASE" "$NEW"; then
        VER_STATUS=passed
        break
    fi
    if [ "$i" -lt "$HEALTH_TRIES" ]; then sleep "$HEALTH_WAIT"; fi
done
log "health: ${HEALTH:0:400}"
if [ "$VER_STATUS" = "passed" ]; then
    log "verified: /up ok, health ok, health names ${NEW}"
else
    log "WARNING: release ${NEW} is UNVERIFIED: /up ${VER_UP}, health ${VER_HEALTH}, health names release '${VER_HEALTH_RELEASE:-none}' (want ${NEW}). The stack is up; look at /api/health, then re-run with --no-pull once it is ok."
fi
$COMPOSE ps

# ── 7. Record, with the predecessor that was actually running. ─────────────────────────────────
RELEASE_STAGE=record
mkdir -p deploy
echo "| $(date -u +%FT%TZ) | ${NEW} | previous ${PREV}${PREV_NOTE} | $(echo "${HEALTH}" | head -c 120 | tr '\n' ' ') | verification ${VER_STATUS} |" >> deploy/RELEASES.md

# ── 8. Release state for the application (read-only mount; docs/80 C.4). ───────────────────────
#
# Never fatal: the release has happened, and failing the script now would say otherwise.
if rs_write_release deploy released "" "$NEW" "$PREV" "$PREV_KNOWN" "$DUMP" "$DUMP_AT" "$MAIN_AT" \
    "$VER_STATUS" "$VER_UP" "$VER_HEALTH" "$VER_HEALTH_RELEASE" "$VER_AT"; then
    if [ "$VER_STATUS" = "passed" ]; then
        rs_mark_verified || log "WARNING: could not write ${RELEASE_STATE_DIR}/last-verified.json"
    fi
else
    log "WARNING: could not write ${RELEASE_STATE_DIR}/release.json; the application will say it has no record of this release"
fi
RELEASE_STAGE=done

# Main against what is now running, so the panel is right immediately rather than in five minutes.
if [ -x deploy/release-state-refresh.sh ]; then
    RS_FETCHED_NOW="$FETCHED_NOW" ./deploy/release-state-refresh.sh || log "WARNING: release-state refresh failed (not fatal)"
fi
log "released ${NEW}"
