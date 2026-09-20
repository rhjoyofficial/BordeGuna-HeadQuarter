#!/bin/bash
# Regression coverage for the deployment/backup safety invariant.
#
#   THE INVARIANT
#   A verified backup exists BEFORE any release-changing checkout mutation or production deployment.
#   A refused deployment leaves the checkout aligned with the running release, and the predecessor
#   recorded in RELEASES.md stays truthful.
#
# This runs the REAL deploy/deploy.sh against a stubbed `docker` and a throwaway git repository, so
# it tests the shipped script rather than a copy of its logic. `git` is not stubbed: the checkout
# really does move, or really does not.
#
#   bash deploy/tests/deploy-safety-test.sh
set -uo pipefail

REPO_ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PASS=0; FAIL=0

# ⚠ deploy.sh refuses to run unless deploy/.env is mode 600, and a filesystem that cannot represent
# a mode (Git Bash on NTFS reports 644 whatever chmod is given) can never satisfy it. Say so loudly
# rather than passing vacuously: a suite that quietly asserts nothing is worse than one that fails.
__probe=$(mktemp -d); : > "${__probe}/f"; chmod 600 "${__probe}/f"
if [ "$(stat -c %a "${__probe}/f" 2>/dev/null)" != "600" ]; then
    rm -rf "${__probe}"
    echo
    echo "  SKIPPED: this filesystem cannot represent file modes (chmod 600 reads back as"
    echo "  $(stat -c %a /dev/null 2>/dev/null || echo '?')), so deploy.sh's own mode-600 guard can never pass here."
    echo "  Run this on Linux - CI does, and so does the VPS."
    echo
    exit 0
fi
rm -rf "${__probe}"
GREEN=$'\033[32m'; RED=$'\033[31m'; OFF=$'\033[0m'

ok()   { PASS=$((PASS+1)); echo "  ${GREEN}ok${OFF}   $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  ${RED}FAIL${OFF} $1"; }
is()   { [ "$2" = "$3" ] && ok "$1" || bad "$1 (expected '$3', got '$2')"; }
isnt() { [ "$2" != "$3" ] && ok "$1" || bad "$1 (expected anything but '$3')"; }

# A throwaway repo with an origin one commit ahead, so `git merge --ff-only origin/main` has
# somewhere to go and moving the checkout is observable.
new_world() {
    WORLD=$(mktemp -d)
    export STUB_STATE="${WORLD}/state"; mkdir -p "$STUB_STATE"
    export STUB_TRACE="${WORLD}/trace"; : > "$STUB_TRACE"

    git init -q -b main "${WORLD}/origin.git" --bare
    git clone -q "${WORLD}/origin.git" "${WORLD}/repo" 2>/dev/null
    export STUB_REPO="${WORLD}/repo"

    cd "$STUB_REPO"
    git config user.email t@t.test; git config user.name t
    mkdir -p deploy
    cp "${REPO_ROOT}/deploy/deploy.sh" deploy/deploy.sh
    # The release-state pieces deploy.sh sources and calls (docs/80 C.4), and rollback.sh, which
    # records itself the same way. The real files, as shipped.
    cp "${REPO_ROOT}/deploy/release-state-lib.sh" "${REPO_ROOT}/deploy/release-state-refresh.sh" "${REPO_ROOT}/deploy/rollback.sh" deploy/
    # The backup-first functions both scripts share, and the preflight (docs/81 C.3).
    for f in backup-first-lib.sh verify-only.sh; do
        [ -f "${REPO_ROOT}/deploy/${f}" ] && cp "${REPO_ROOT}/deploy/${f}" deploy/
    done
    chmod +x deploy/deploy.sh deploy/release-state-refresh.sh deploy/rollback.sh
    # ⭐ The REAL compose file and the real .env template. The stub resolves RUN_MIGRATIONS from the
    # compose files a command is given, so "a deploy migrates, a rollback does not" is measured
    # against what docker-compose.yml actually says, not against a copy of the expectation.
    cp "${REPO_ROOT}/deploy/docker-compose.yml" "${REPO_ROOT}/deploy/.env.example" deploy/
    printf '%s
' APP_KEY=x APP_ENV=production APP_URL=https://hq.test DB_CONNECTION=pgsql DB_HOST=db         DB_DATABASE=hq DB_USERNAME=hq DB_PASSWORD=x REDIS_HOST=redis REDIS_PASSWORD=x > deploy/.env
    chmod 600 deploy/.env
    # As in the real repository: the host's own files are not tracked.
    printf '%s
' deploy/.env deploy/RELEASES.md deploy/release-state/ > .gitignore
    echo one > file.txt
    git add -A >/dev/null; git commit -qm one; git push -q origin main
    OLD_HEAD=$(git rev-parse --short HEAD)

    # One more commit on origin only, so the checkout has something to fast-forward TO.
    git clone -q "${WORLD}/origin.git" "${WORLD}/other" 2>/dev/null
    (cd "${WORLD}/other" && git config user.email t@t.test && git config user.name t \
        && echo two > file.txt && git add -A && git commit -qm two && git push -q origin main)
    git fetch -q origin
    NEW_HEAD=$(git rev-parse --short origin/main)

    # The stub, first on PATH.
    mkdir -p "${WORLD}/bin"
    cp "${REPO_ROOT}/deploy/tests/docker-stub.sh" "${WORLD}/bin/docker"
    chmod +x "${WORLD}/bin/docker"
    # `sleep`, real unless a test asks otherwise: the "/up never answers" path waits 30 x 2 s, and a
    # test of what gets RECORDED there has no reason to spend a minute proving that sleep sleeps.
    REAL_SLEEP=$(command -v sleep)
    printf '#!/bin/bash\n[ "${STUB_FAST_SLEEP:-0}" = "1" ] && exit 0\nexec "%s" "$@"\n' "$REAL_SLEEP" > "${WORLD}/bin/sleep"
    chmod +x "${WORLD}/bin/sleep"
    export PATH="${WORLD}/bin:${PATH}"
    export HOST_BACKUP_DIR="${WORLD}/backups"

    # Defaults: a healthy stack running the checked-out commit.
    export STUB_RUNNING_COMMIT="$OLD_HEAD"
    export STUB_DB_RUNNING=1 STUB_APP_RUNNING=1
    export STUB_BACKUP_EXIT=0 STUB_MOUNT_EXIT=0 STUB_MOUNT_REPAIRABLE=0
    export STUB_LAST_STATUS=0 STUB_DUMP_PRESENT=0
    unset STUB_LAST_OK 2>/dev/null || true
    unset STUB_BUILD_EXIT STUB_UP_D_EXIT STUB_UP_EXIT STUB_HEALTH_STATUS STUB_HEALTH_RELEASE STUB_FAST_SLEEP 2>/dev/null || true
    unset STUB_IGNORE_OVERRIDE STUB_CONFIG_EXIT STUB_BACKUP_INODE STUB_RELEASE_STATE_INODE STUB_RELEASE_STATE_MOUNTED 2>/dev/null || true
    unset VERIFY_MIN_FREE_MB VERIFY_MAX_DUMP_AGE_HOURS VERIFY_REMOTE_TIMEOUT 2>/dev/null || true
    unset RELEASE_STATE_DIR HEALTH_TRIES HEALTH_WAIT FETCH_MIN_INTERVAL RS_FETCHED_NOW 2>/dev/null || true
}

run_deploy() { (cd "$STUB_REPO" && ./deploy/deploy.sh >"${WORLD}/out" 2>&1); echo $?; }
head_now()   { (cd "$STUB_REPO" && git rev-parse --short HEAD); }
cleanup()    { cd /; rm -rf "$WORLD"; }

echo
echo "deployment/backup safety invariant"
echo

# ── 1. A failed backup must mutate nothing ──────────────────────────────────────────────────────
new_world
export STUB_BACKUP_EXIT=1
RC=$(run_deploy)
isnt "a failed backup refuses the deploy"                      "$RC" "0"
is   "a failed backup leaves the checkout where it was"        "$(head_now)" "$OLD_HEAD"
is   "a failed backup writes no RELEASES.md entry"             "$( [ -f "${STUB_REPO}/deploy/RELEASES.md" ] && echo yes || echo no )" "no"
is   "a failed backup never reaches the build"                 "$(grep -c 'compose.*build' "$STUB_TRACE")" "0"
grep -q 'checkout has NOT moved' "${WORLD}/out" \
    && ok "the refusal says the checkout has not moved" \
    || bad "the refusal should say the checkout has not moved"
cleanup

# ── 2. A verified-but-unhappy backup (bad marker) must also mutate nothing ──────────────────────
new_world
export STUB_LAST_STATUS=1
RC=$(run_deploy)
isnt "a backup reporting a non-zero status refuses the deploy" "$RC" "0"
is   "...and leaves the checkout where it was"                 "$(head_now)" "$OLD_HEAD"
cleanup

new_world
export STUB_LAST_OK=""
RC=$(run_deploy)
isnt "a backup that leaves no LAST_OK marker refuses"          "$RC" "0"
is   "...and leaves the checkout where it was"                 "$(head_now)" "$OLD_HEAD"
cleanup

new_world
export STUB_DUMP_PRESENT=1
RC=$(run_deploy)
isnt "a LAST_OK naming a missing dump refuses"                 "$RC" "0"
is   "...and leaves the checkout where it was"                 "$(head_now)" "$OLD_HEAD"
cleanup

# ── 3. A dead backup mount that cannot be repaired must mutate nothing ──────────────────────────
new_world
export STUB_MOUNT_EXIT=1 STUB_MOUNT_REPAIRABLE=0
RC=$(run_deploy)
isnt "an unrepairable backup mount refuses the deploy"         "$RC" "0"
is   "...and leaves the checkout where it was"                 "$(head_now)" "$OLD_HEAD"
is   "...and never takes a dump it cannot store"               "$(grep -c 'backup.sh once' "$STUB_TRACE")" "0"
grep -q 'force-recreate --no-deps backup' "$STUB_TRACE" \
    && ok "...but it did try to repair the mount first" \
    || bad "it should attempt to recreate the backup service before giving up"
cleanup

# ── 4. A dead mount that IS repairable proceeds, having repaired it ─────────────────────────────
new_world
export STUB_MOUNT_EXIT=1 STUB_MOUNT_REPAIRABLE=1
RC=$(run_deploy)
is   "a repairable backup mount is repaired and the deploy proceeds" "$RC" "0"
is   "...and the checkout moves"                               "$(head_now)" "$NEW_HEAD"
cleanup

# ── 5. The HQ backup boundary is protected ──────────────────────────────────────────────────────
new_world
RC=$(run_deploy)
is   "a healthy deploy succeeds"                               "$RC" "0"
is   "the backup root is created"                              "$( [ -d "${WORLD}/backups/daily" ] && echo yes || echo no )" "yes"
is   "a do-not-delete marker is left for the next person"      "$( [ -f "${WORLD}/backups/DO-NOT-DELETE-README.md" ] && echo yes || echo no )" "yes"
grep -q 'recreate the container so the mount reattaches' "${WORLD}/backups/DO-NOT-DELETE-README.md" \
    && ok "the marker explains the dead-inode repair" \
    || bad "the marker should explain that recreating the directory alone is not enough"
cleanup

# ── 6. Ordering: the dump is taken while the checkout is still the OLD commit ───────────────────
new_world
RC=$(run_deploy)
is   "a healthy deploy succeeds"                               "$RC" "0"
is   "the checkout moved to origin/main"                       "$(head_now)" "$NEW_HEAD"
# ⭐ The measurement that matters. The stub recorded HEAD at the instant the dump was requested.
is   "the dump was taken BEFORE the checkout moved"            "$(cat "${STUB_STATE}/head-at-backup" 2>/dev/null)" "$OLD_HEAD"
cleanup

# ── 7. The recorded predecessor is the release that was actually running ────────────────────────
new_world
# Drift: the checkout was moved by hand, but the containers still run the old release. The truthful
# predecessor is what was RUNNING, not what happened to be checked out.
(cd "$STUB_REPO" && git merge -q --ff-only origin/main)
export STUB_RUNNING_COMMIT="$OLD_HEAD"
RC=$(run_deploy)
is   "a deploy over a drifted checkout still succeeds"         "$RC" "0"
grep -q "previous ${OLD_HEAD}" "${STUB_REPO}/deploy/RELEASES.md" \
    && ok "RELEASES.md records the RUNNING release as the predecessor" \
    || bad "RELEASES.md should record ${OLD_HEAD}: $(tail -1 "${STUB_REPO}/deploy/RELEASES.md")"
grep -q "is not the running release" "${WORLD}/out" \
    && ok "the drift is reported rather than silently adopted" \
    || bad "drift between checkout and running release should be reported"
# The defect this replaces: recording the checkout's own HEAD would name the NEW commit as its own
# predecessor, which is what docs/58 found in RELEASES.md.
grep -q "| ${NEW_HEAD} | previous ${NEW_HEAD} |" "${STUB_REPO}/deploy/RELEASES.md" \
    && bad "a release recorded itself as its own predecessor" \
    || ok "no release records itself as its own predecessor"
cleanup

# ── 8. An unverifiable predecessor is marked, not guessed at silently ──────────────────────────
new_world
# The running image carries no commit label: it predates the label, or the stack was never deployed.
# The checkout is then only a GUESS at the predecessor, and recording a guess unqualified is how a
# release comes to claim a predecessor it did not have.
export STUB_RUNNING_COMMIT=""
RC=$(run_deploy)
is   "a deploy with an unlabelled running image still succeeds"  "$RC" "0"
grep -q "unverified: the running image carries no commit label" "${STUB_REPO}/deploy/RELEASES.md"     && ok "an unverifiable predecessor is recorded as unverified"     || bad "an unverifiable predecessor should be marked: $(tail -1 "${STUB_REPO}/deploy/RELEASES.md")"
cleanup

# ═══ Release state (docs/80 C.4) ═════════════════════════════════════════════════════════════════
#
# The application is told what was released, whether it was verified, and where main stands, through
# deploy/release-state/. These tests hold that record to the same standard as the checkout: it moves
# only after a verified dump, a failure is recorded as a failure, and "last verified" is never
# overwritten by anything that did not pass.
STATE()      { echo "${STUB_REPO}/deploy/release-state"; }
field()      { grep -o "\"$2\":\"[^\"]*\"" "$1" 2>/dev/null | head -1 | cut -d'"' -f4; }
rawfield()   { grep -o "\"$2\":[^,}]*" "$1" 2>/dev/null | head -1 | cut -d: -f2; }
valid_json() {
    if command -v python3 >/dev/null 2>&1; then python3 -c 'import json,sys; json.load(open(sys.argv[1], encoding="utf-8"))' "$1" 2>/dev/null
    elif command -v php >/dev/null 2>&1; then php -r 'exit(json_decode(file_get_contents($argv[1])) === null ? 1 : 0);' "$1"
    elif command -v python >/dev/null 2>&1; then python -c 'import json,sys; json.load(open(sys.argv[1], encoding="utf-8"))' "$1" 2>/dev/null
    else echo "  (no python or php here: JSON validity NOT checked for $1)"; return 0; fi
}
push_commit() { # push_commit <subject> [file]
    (cd "${WORLD}/other" && git pull -q origin main && mkdir -p "$(dirname "${2:-file.txt}")" && echo "$1" >> "${2:-file.txt}" \
        && git add -A && git commit -qm "$1" && git push -q origin main)
}
run_refresh()  { (cd "$STUB_REPO" && ./deploy/release-state-refresh.sh >"${WORLD}/refresh-out" 2>&1); echo $?; }
run_rollback() { (cd "$STUB_REPO" && ./deploy/rollback.sh "$1" >"${WORLD}/out" 2>&1); echo $?; }

echo
echo "release state"
echo

# ── 9. A refused deploy writes no release state, exactly as it moves no checkout ─────────────────
new_world
export STUB_BACKUP_EXIT=1
RC=$(run_deploy)
isnt "a refused deploy is refused"                              "$RC" "0"
is   "a refused deploy writes no release record"                "$( [ -e "$(STATE)/release.json" ] && echo yes || echo no )" "no"
is   "...and no last-verified record"                           "$( [ -e "$(STATE)/last-verified.json" ] && echo yes || echo no )" "no"
cleanup

# ── 10. A healthy, verified deploy records itself, after the dump ───────────────────────────────
new_world
RC=$(run_deploy)
is   "a healthy deploy succeeds"                                "$RC" "0"
is   "release.json names the released commit"                   "$(field "$(STATE)/release.json" commit)" "$NEW_HEAD"
is   "...as released"                                           "$(field "$(STATE)/release.json" status)" "released"
is   "...with the predecessor that was running"                 "$(field "$(STATE)/release.json" previous)" "$OLD_HEAD"
is   "...and the verified dump it was taken behind"             "$(field "$(STATE)/release.json" dump)" "/backups/daily/hq-20260910-000000.dump"
is   "...and main as it stood at the release"                   "$(field "$(STATE)/release.json" origin_main_at_release)" "$NEW_HEAD"
grep -q '"verification":{"status":"passed","up":"ok","health":"ok"' "$(STATE)/release.json" \
    && ok "the verification is recorded as passed: /up ok, health ok" \
    || bad "verification should be passed: $(cat "$(STATE)/release.json")"
is   "the dump was still taken BEFORE the checkout moved"       "$(cat "${STUB_STATE}/head-at-backup" 2>/dev/null)" "$OLD_HEAD"
cmp -s "$(STATE)/release.json" "$(STATE)/last-verified.json" \
    && ok "a verified release becomes the last verified release" \
    || bad "last-verified.json should be a copy of release.json"
valid_json "$(STATE)/release.json" && ok "release.json is valid JSON" || bad "release.json is not valid JSON"
grep -q "verification passed" "${STUB_REPO}/deploy/RELEASES.md" \
    && ok "RELEASES.md records the verification result" \
    || bad "RELEASES.md should say 'verification passed'"
is   "main.json is written at the end of the deploy"            "$(field "$(STATE)/main.json" main_commit)" "$NEW_HEAD"
is   "...compared against what is now RUNNING"                  "$(field "$(STATE)/main.json" release_commit)" "$NEW_HEAD"
is   "...and main is 0 commits ahead of it"                     "$(rawfield "$(STATE)/main.json" ahead)" "0"

# ── 11. A failed build is recorded as a failure, and the last verified release is untouched ─────
# (same world: NEW_HEAD is released and verified)
push_commit three
cp "$(STATE)/last-verified.json" "${WORLD}/verified-before"
export STUB_BUILD_EXIT=1
RC=$(run_deploy)
THREE=$(cd "$STUB_REPO" && git rev-parse --short origin/main)
isnt "a failed build fails the deploy"                          "$RC" "0"
is   "the failed attempt is recorded, naming the commit"        "$(field "$(STATE)/release.json" commit)" "$THREE"
is   "...as FAILED, not released"                               "$(field "$(STATE)/release.json" status)" "failed"
is   "...at the stage it reached"                               "$(field "$(STATE)/release.json" failed_stage)" "build"
grep -q '"verification":{"status":"not_run"' "$(STATE)/release.json" \
    && ok "...with a verification that never ran" \
    || bad "a failed build should record verification not_run"
cmp -s "${WORLD}/verified-before" "$(STATE)/last-verified.json" \
    && ok "the last verified release is byte-identical to before the failure" \
    || bad "a failed release must not touch last-verified.json"
is   "the last verified release is still the one that passed"   "$(field "$(STATE)/last-verified.json" commit)" "$NEW_HEAD"
valid_json "$(STATE)/release.json" && ok "the failure record is valid JSON" || bad "the failure record is not valid JSON"
cleanup

# ── 12. A release whose health never comes good is UNVERIFIED ───────────────────────────────────
new_world
export STUB_HEALTH_STATUS=fail HEALTH_TRIES=2 HEALTH_WAIT=0
RC=$(run_deploy)
is   "an unhealthy release still exits 0 (the stack is up, as before)" "$RC" "0"
is   "...and is recorded as released"                           "$(field "$(STATE)/release.json" status)" "released"
grep -q '"verification":{"status":"failed","up":"ok","health":"fail"' "$(STATE)/release.json" \
    && ok "...with its verification recorded as FAILED" \
    || bad "verification should be failed: $(cat "$(STATE)/release.json")"
is   "an unverified release never becomes the last verified one" "$( [ -e "$(STATE)/last-verified.json" ] && echo yes || echo no )" "no"
grep -q "UNVERIFIED" "${WORLD}/out" && ok "the operator is told, loudly" || bad "the deploy should warn that the release is UNVERIFIED"
grep -q "verification failed" "${STUB_REPO}/deploy/RELEASES.md" && ok "RELEASES.md says so too" || bad "RELEASES.md should say 'verification failed'"
cleanup

new_world
export STUB_HEALTH_STATUS=unreadable HEALTH_TRIES=2 HEALTH_WAIT=0
RC=$(run_deploy)
grep -q '"status":"failed","up":"ok","health":"unreadable"' "$(STATE)/release.json" \
    && ok "a health endpoint that does not answer is 'unreadable', and unverified" \
    || bad "an unreadable health should be recorded: $(cat "$(STATE)/release.json")"
cleanup

# ── 13. Health that is ok but names ANOTHER release proves nothing about this one ───────────────
new_world
export STUB_HEALTH_RELEASE=deadbeef HEALTH_TRIES=2 HEALTH_WAIT=0
RC=$(run_deploy)
grep -q '"verification":{"status":"failed"' "$(STATE)/release.json" \
    && ok "an ok health answer from a DIFFERENT commit does not verify the release" \
    || bad "a health answer naming another commit must not verify: $(cat "$(STATE)/release.json")"
is   "...and the commit it did name is on record"               "$(field "$(STATE)/release.json" health_release)" "deadbeef"
is   "...and nothing is promoted to last verified"              "$( [ -e "$(STATE)/last-verified.json" ] && echo yes || echo no )" "no"
cleanup

# ── 14. /up never answering fails the deploy AND is recorded ────────────────────────────────────
new_world
export STUB_UP_EXIT=1 STUB_FAST_SLEEP=1
RC=$(run_deploy)
isnt "a release whose /up never answers fails the deploy"       "$RC" "0"
is   "...and is recorded as failed"                             "$(field "$(STATE)/release.json" status)" "failed"
is   "...at the smoke stage"                                    "$(field "$(STATE)/release.json" failed_stage)" "smoke"
grep -q '"verification":{"status":"failed","up":"failed"' "$(STATE)/release.json" \
    && ok "...with /up recorded as failed" \
    || bad "/up failure should be recorded: $(cat "$(STATE)/release.json")"
cleanup

# ── 15. The refresher: main against the running release ─────────────────────────────────────────
new_world
RC=$(run_deploy)                       # NEW_HEAD is running and verified
push_commit 'a subject with "quotes" and a \ backslash'
push_commit 'add a migration' apps/hq/database/migrations/2026_09_20_000001_create_things_table.php
push_commit 'add another' apps/hq/database/migrations/2026_09_20_000002_create_more_table.php
MAIN_BEFORE=$(field "$(STATE)/main.json" main_commit)

# Throttled: a fetch happened seconds ago (the deploy's), so this run must not fetch again.
RC=$(FETCH_MIN_INTERVAL=1800 run_refresh)
is   "the refresher exits 0"                                    "$RC" "0"
is   "inside the fetch interval it does NOT fetch again"        "$(field "$(STATE)/main.json" main_commit)" "$MAIN_BEFORE"

RC=$(FETCH_MIN_INTERVAL=0 run_refresh)
TIP=$(cd "${WORLD}/other" && git rev-parse --short HEAD)
is   "past the interval it fetches, and main moves"             "$(field "$(STATE)/main.json" main_commit)" "$TIP"
is   "main is 3 commits ahead of the running release"           "$(rawfield "$(STATE)/main.json" ahead)" "3"
is   "...and 0 behind"                                          "$(rawfield "$(STATE)/main.json" behind)" "0"
is   "the comparison names its basis: the running image"        "$(field "$(STATE)/main.json" release_basis)" "running-image-label"
grep -q '"migrations":\["2026_09_20_000001_create_things_table.php","2026_09_20_000002_create_more_table.php"\]' "$(STATE)/main.json" \
    && ok "the migrations main has and the release does not are listed by file name" \
    || bad "migrations ahead should be listed: $(cat "$(STATE)/main.json")"
valid_json "$(STATE)/main.json" && ok "a subject with quotes and a backslash still yields valid JSON" || bad "main.json is not valid JSON: $(cat "$(STATE)/main.json")"
is   "the refresher never moves the checkout"                   "$(head_now)" "$NEW_HEAD"
is   "the refresher never touches the release record"           "$(field "$(STATE)/release.json" commit)" "$NEW_HEAD"

# A remote that does not answer: quiet, exit 0, and the age of the last GOOD fetch is kept.
FETCHED_BEFORE=$(field "$(STATE)/main.json" fetched_at)
(cd "$STUB_REPO" && git remote set-url origin "${WORLD}/no-such-remote.git")
RC=$(FETCH_MIN_INTERVAL=0 run_refresh)
is   "a failing remote does not fail the refresher"             "$RC" "0"
is   "...and is recorded as a failed fetch"                     "$(rawfield "$(STATE)/main.json" fetch_ok)" "false"
is   "...keeping the time of the last successful fetch"         "$(field "$(STATE)/main.json" fetched_at)" "$FETCHED_BEFORE"
cleanup

# Nothing running and nothing on record: the counts are null with a reason, never zero.
new_world
export STUB_APP_RUNNING=0
RC=$(FETCH_MIN_INTERVAL=0 run_refresh)
is   "with no running release the refresher still exits 0"      "$RC" "0"
is   "...and 'ahead' is null, not 0"                            "$(rawfield "$(STATE)/main.json" ahead)" "null"
grep -q '"reason":"the running release could not be determined' "$(STATE)/main.json" \
    && ok "...with the reason stated" \
    || bad "an undeterminable release should carry a reason: $(cat "$(STATE)/main.json")"
cleanup

# ── 16. A rollback stamps the image and records itself ──────────────────────────────────────────
new_world
RC=$(run_deploy)
export STUB_FAST_SLEEP=1
RC=$(run_rollback "$OLD_HEAD")
is   "a rollback succeeds"                                      "$RC" "0"
grep -q "build --build-arg GIT_COMMIT=${OLD_HEAD} app" "$STUB_TRACE" \
    && ok "the rolled-back image is stamped with its commit" \
    || bad "rollback.sh should pass --build-arg GIT_COMMIT"
is   "the rollback is recorded as a rollback"                   "$(field "$(STATE)/release.json" kind)" "rollback"
is   "...of the target commit"                                  "$(field "$(STATE)/release.json" commit)" "$OLD_HEAD"
is   "...from the release it replaced"                          "$(field "$(STATE)/release.json" previous)" "$NEW_HEAD"
is   "a verified rollback becomes the last verified release"    "$(field "$(STATE)/last-verified.json" commit)" "$OLD_HEAD"
cleanup

# ═══ One rollback invariant (docs/81 C.3) ════════════════════════════════════════════════════════
#
#   A rollback changes code and nothing else. It never runs a migration, and it happens only behind
#   a verified dump, exactly like a deploy.
#
# Until docs/81 C.3 rollback.sh set RUN_MIGRATIONS=0 on `build`, where it reaches nothing, the
# compose file hardcodes "1", and the old image's entrypoint ran `migrate --force`. It also moved
# the checkout with no dump. The stub resolves RUN_MIGRATIONS from the compose files each command
# is really given, and records it at every `up` that starts `app`.
migrations_at_last_up() { cat "${STUB_STATE}/app-run-migrations" 2>/dev/null || echo "never-started"; }
# Everything a refused run must leave alone, as one digest: HEAD, every ref, the index against the
# tree, and every file under deploy/ (RELEASES.md and release-state/ among them) byte for byte.
snapshot() {
    (cd "$STUB_REPO" && git rev-parse HEAD && git for-each-ref && GIT_OPTIONAL_LOCKS=0 git status --porcelain \
        && cksum .git/FETCH_HEAD .git/index 2>/dev/null; find deploy -type f | sort | xargs cksum) 2>&1
}
# What the stub saw that CHANGES containers or images.
release_mutations() { grep -cE ' (up|build|stop|start|restart|down|rm|run|kill|pull) |horizon:terminate|migrate' "$STUB_TRACE" || true; }
# ...or anything at all, the dump and the mount probe included.
any_mutations()     { grep -cE ' (up|build|stop|start|restart|down|rm|run|kill|pull) |horizon:terminate|migrate|backup\.sh once|mount-probe' "$STUB_TRACE" || true; }

echo
echo "one rollback invariant"
echo

# ── 17. A deploy migrates; a rollback does not ──────────────────────────────────────────────────
new_world
RC=$(run_deploy)
is   "a normal deploy still starts app with migrations ON"      "$(migrations_at_last_up)" "1"
export STUB_FAST_SLEEP=1
: > "$STUB_TRACE"; rm -f "${STUB_STATE}/head-at-backup"
RC=$(run_rollback "$OLD_HEAD")
is   "a rollback succeeds"                                      "$RC" "0"
is   "a rollback starts app with migrations OFF"                "$(migrations_at_last_up)" "0"
is   "...and only the DEPLOY ever started app with them on"     "$(grep -c '^1$' "${STUB_STATE}/app-run-migrations-history" 2>/dev/null)" "1"
grep -q "app started with RUN_MIGRATIONS=0: no migration was run" "${WORLD}/out" \
    && ok "the rollback READS BACK the started container and says no migration ran" \
    || bad "the rollback should state what the started container's environment was: $(tail -3 "${WORLD}/out")"
grep -q "No migration was run and the database was not restored" "${WORLD}/out" \
    && ok "...and says plainly that the schema and data were not rolled back" \
    || bad "the rollback should say the schema is not rolled back"
# Backup first, the same invariant as deploy.sh.
is   "the rollback took a dump"                                 "$(grep -c 'backup.sh once' "$STUB_TRACE")" "1"
is   "...BEFORE the checkout moved"                             "$(cat "${STUB_STATE}/head-at-backup" 2>/dev/null)" "$NEW_HEAD"
is   "the checkout then moved to the target"                    "$(head_now)" "$OLD_HEAD"
is   "release.json records the dump the rollback stood behind"  "$(field "$(STATE)/release.json" dump)" "/backups/daily/hq-20260910-000000.dump"
is   "...and a predecessor read from the running image, so KNOWN" "$(rawfield "$(STATE)/release.json" previous_known)" "true"
grep -q "ROLLBACK to ${OLD_HEAD} | from ${NEW_HEAD} | code only, no migrations run" "${STUB_REPO}/deploy/RELEASES.md" \
    && ok "RELEASES.md says: code only, no migrations run" \
    || bad "RELEASES.md should record the rollback as code only: $(tail -1 "${STUB_REPO}/deploy/RELEASES.md")"
# And forward again is a DEPLOY, which migrates by design.
(cd "$STUB_REPO" && git checkout -q main)
RC=$(cd "$STUB_REPO" && ./deploy/deploy.sh --no-pull >"${WORLD}/out" 2>&1; echo $?)
is   "returning to main with deploy.sh --no-pull succeeds"      "$RC" "0"
is   "...and starts app with migrations ON again"               "$(migrations_at_last_up)" "1"
cleanup

# ── 18. A rollback whose backup fails moves NOTHING ─────────────────────────────────────────────
new_world
RC=$(run_deploy)
BEFORE=$(snapshot); : > "$STUB_TRACE"
export STUB_BACKUP_EXIT=1
RC=$(run_rollback "$OLD_HEAD")
isnt "a rollback whose dump fails is refused"                   "$RC" "0"
is   "...the checkout, refs, RELEASES.md and release state are byte-identical" "$(snapshot)" "$BEFORE"
is   "...and no container or image was touched"                 "$(release_mutations)" "0"
grep -q 'checkout has NOT moved' "${WORLD}/out" && ok "...and it says the checkout has not moved" || bad "the refusal should say the checkout has not moved"
cleanup

new_world
RC=$(run_deploy)
BEFORE=$(snapshot); : > "$STUB_TRACE"
export STUB_MOUNT_EXIT=1 STUB_MOUNT_REPAIRABLE=0 STUB_FAST_SLEEP=1
rm -f "${STUB_STATE}/backup-recreated"
RC=$(run_rollback "$OLD_HEAD")
isnt "a rollback over a dead, unrepairable backup mount is refused" "$RC" "0"
is   "...and moves the checkout, RELEASES.md and release state nowhere" "$(snapshot)" "$BEFORE"
is   "...and never takes a dump it cannot store"                "$(grep -c 'backup.sh once' "$STUB_TRACE")" "0"
is   "...and never reaches a build"                             "$(grep -c ' build ' "$STUB_TRACE")" "0"
cleanup

# ── 19. Targets that are refused, each moving nothing at all (not even a dump) ──────────────────
new_world
RC=$(run_deploy)
BEFORE=$(snapshot); : > "$STUB_TRACE"
RC=$(run_rollback "$NEW_HEAD")
isnt "a rollback to the release that is already running is refused" "$RC" "0"
grep -q "already what is running" "${WORLD}/out" && ok "...with the reason" || bad "should say it is already running: $(tail -1 "${WORLD}/out")"
is   "...and moves nothing"                                     "$(snapshot)" "$BEFORE"
is   "...not a dump, not a probe, not a container"              "$(any_mutations)" "0"

: > "$STUB_TRACE"
RC=$(run_rollback "0123abc")
isnt "a rollback to a commit this checkout does not know is refused" "$RC" "0"
grep -q "is not a commit this checkout knows" "${WORLD}/out" && ok "...with the reason" || bad "should say the commit is unknown: $(tail -1 "${WORLD}/out")"
is   "...and moves nothing"                                     "$(snapshot)" "$BEFORE"
is   "...not a dump, not a probe, not a container"              "$(any_mutations)" "0"

: > "$STUB_TRACE"
RC=$(cd "$STUB_REPO" && ./deploy/rollback.sh >"${WORLD}/out" 2>&1; echo $?)
isnt "a rollback with no target is refused"                     "$RC" "0"
is   "...and moves nothing"                                     "$(snapshot)" "$BEFORE"

# A commit on another line: never released, not an ancestor of what runs. It may carry migrations
# production has never seen, so releasing it is a deploy, not a rollback.
(cd "${WORLD}/other" && git checkout -q -b side "$OLD_HEAD" && echo side > side.txt && git add -A && git commit -qm side && git push -q origin side)
(cd "$STUB_REPO" && git fetch -q origin)
SIDE=$(cd "$STUB_REPO" && git rev-parse --short origin/side)
BEFORE=$(snapshot); : > "$STUB_TRACE"
RC=$(run_rollback "$SIDE")
isnt "a rollback to a commit that is neither an ancestor nor a recorded release is refused" "$RC" "0"
grep -q "neither an ancestor of the running release" "${WORLD}/out" && ok "...with the reason" || bad "should say it is not an ancestor: $(tail -1 "${WORLD}/out")"
is   "...and moves nothing"                                     "$(snapshot)" "$BEFORE"
is   "...not a dump, not a probe, not a container"              "$(any_mutations)" "0"

# A hand edit on the production checkout.
(cd "$STUB_REPO" && echo "edited by hand" >> file.txt)
BEFORE=$(snapshot); : > "$STUB_TRACE"
RC=$(run_rollback "$OLD_HEAD")
isnt "a rollback over a hand-edited checkout is refused"        "$RC" "0"
grep -q "tracked files are modified on this checkout: file.txt" "${WORLD}/out" && ok "...naming the file" || bad "should name the modified file: $(tail -1 "${WORLD}/out")"
is   "...and moves nothing"                                     "$(snapshot)" "$BEFORE"
is   "...not a dump, not a probe, not a container"              "$(any_mutations)" "0"
cleanup

# A release that IS on record may be rolled back to even when it is not an ancestor of what runs
# (after a rollback, the newer release is such a commit).
new_world
RC=$(run_deploy)
export STUB_FAST_SLEEP=1
RC=$(run_rollback "$OLD_HEAD")
RC=$(run_rollback "$NEW_HEAD")
is   "a commit RELEASES.md names as released is accepted though it is not an ancestor" "$RC" "0"
is   "...and it too starts with migrations OFF"                 "$(migrations_at_last_up)" "0"
cleanup

# ── 20. If the override would NOT take effect, nothing moves ────────────────────────────────────
new_world
RC=$(run_deploy)
BEFORE=$(snapshot); : > "$STUB_TRACE"
export STUB_IGNORE_OVERRIDE=1
RC=$(run_rollback "$OLD_HEAD")
isnt "a rollback whose resolved configuration still says RUN_MIGRATIONS=1 is refused" "$RC" "0"
grep -q "does not resolve RUN_MIGRATIONS to 0" "${WORLD}/out" && ok "...with the reason" || bad "should say the configuration still migrates: $(tail -1 "${WORLD}/out")"
is   "...the checkout, RELEASES.md and release state are byte-identical" "$(snapshot)" "$BEFORE"
is   "...and no container or image was touched"                 "$(release_mutations)" "0"
cleanup

# ── 21. deploy.sh refuses an argument it does not know ──────────────────────────────────────────
new_world
BEFORE=$(snapshot)
RC=$(cd "$STUB_REPO" && ./deploy/deploy.sh --nopull >"${WORLD}/out" 2>&1; echo $?)
isnt "deploy.sh --nopull (a typing mistake) is refused, not read as 'pull'" "$RC" "0"
is   "...and moves nothing"                                     "$(snapshot)" "$BEFORE"
is   "...not a dump, not a probe, not a container"              "$(any_mutations)" "0"
cleanup

# ═══ deploy.sh --verify-only (docs/81 C.3) ═══════════════════════════════════════════════════════
#
# A preflight that changes NOTHING: not the checkout, not a ref, not a container, not the database,
# not RELEASES.md, not the release state, not the backup root. Proved in a passing world and in each
# failing one by the same digest, plus the backup root and the stub's record of every docker call.
run_verify() { (cd "$STUB_REPO" && ./deploy/deploy.sh --verify-only "$@" >"${WORLD}/out" 2>&1); echo $?; }
seed_backups() {
    mkdir -p "${HOST_BACKUP_DIR}/daily" "${HOST_BACKUP_DIR}/weekly"
    echo "a verified dump" > "${HOST_BACKUP_DIR}/daily/hq-20260910-000000.dump"
    echo 0 > "${HOST_BACKUP_DIR}/LAST_STATUS"; date -u +%FT%TZ > "${HOST_BACKUP_DIR}/LAST_RUN"
    echo "/backups/daily/hq-20260910-000000.dump" > "${HOST_BACKUP_DIR}/LAST_OK"
}
full_snapshot() { snapshot; (cd "$HOST_BACKUP_DIR" 2>/dev/null && find . | sort && find . -type f | sort | xargs cksum) 2>&1; }
# A released, verified world with two commits waiting on main, already fetched (as the cron
# refresher would have), one of them a migration that DROPS a column.
verify_world() {
    new_world
    RC=$(run_deploy)
    seed_backups
    push_commit 'add a table' apps/hq/database/migrations/2026_09_21_000001_create_things_table.php
    (cd "${WORLD}/other" && printf '%s\n' '<?php' 'return new class extends Migration {' \
        '    public function up(): void { Schema::table("things", function ($t) { $t->dropColumn("legacy"); }); }' \
        '    public function down(): void { Schema::table("things", function ($t) { $t->string("legacy")->nullable(); }); }' \
        '};' > apps/hq/database/migrations/2026_09_21_000002_drop_a_column.php \
        && git add -A && git commit -qm 'drop a column' && git push -q origin main)
    (cd "$STUB_REPO" && git fetch -q origin)
    TIP=$(cd "$STUB_REPO" && git rev-parse --short origin/main)
    export VERIFY_MIN_FREE_MB=1
    : > "$STUB_TRACE"
}
assert_nothing_changed() { # assert_nothing_changed <label> <before>
    is "$1: checkout, refs, index, RELEASES.md, release state and backup root are byte-identical" "$(full_snapshot)" "$2"
    is "$1: the stub recorded no docker mutation, no dump and no probe"                          "$(any_mutations)" "0"
}

echo
echo "deploy.sh --verify-only"
echo

# ── 22. The passing case ────────────────────────────────────────────────────────────────────────
verify_world
BEFORE=$(full_snapshot)
RC=$(run_verify)
is   "verify-only passes on a healthy host"                     "$RC" "0"
assert_nothing_changed "passing" "$BEFORE"
is   "...the checkout did not move though main is ahead"        "$(head_now)" "$NEW_HEAD"
grep -q "It is not a deployment" "${WORLD}/out" && grep -q "NOTHING WAS RELEASED" "${WORLD}/out" \
    && ok "it says, top and bottom, that it is not a deployment" || bad "verify-only must say it is not a deployment"
grep -q "running release is ${NEW_HEAD}" "${WORLD}/out"         && ok "it names the running release, from the image label" || bad "should name the running release"
grep -q "/api/health names the running commit" "${WORLD}/out"   && ok "it checks that health names the running commit"   || bad "should check health names the commit"
grep -q "the bind mount is live" "${WORLD}/out"                 && ok "it checks the backup bind mount without writing through it" || bad "should check the backup mount"
grep -q "is a fast-forward of the checkout" "${WORLD}/out"      && ok "it checks origin/main is a fast-forward"         || bad "should check fast-forward"
grep -q "2 commit(s) between the running release" "${WORLD}/out" && ok "it lists the commits the release would bring"  || bad "should list pending commits: $(grep -n 'commit' "${WORLD}/out" | head -3)"
grep -q "2026_09_21_000001_create_things_table.php" "${WORLD}/out" && ok "it lists the pending migration files"        || bad "should list pending migrations"
grep -q "DESTRUCTIVE statement in the up() of 2026_09_21_000002_drop_a_column.php" "${WORLD}/out" \
    && ok "a pending migration that drops a column is a WARNING, with the file named" || bad "should warn about the destructive migration"
grep -q "DESTRUCTIVE statement in the up() of 2026_09_21_000001" "${WORLD}/out" \
    && bad "an additive migration was called destructive" || ok "...and an additive migration is not"
grep -Eq "(APP_KEY|DB_PASSWORD|REDIS_PASSWORD)=" "${WORLD}/out" && bad "a KEY=value line from deploy/.env was printed" || ok "no KEY=value from deploy/.env is printed"

# The same world, with --no-pull: origin is not consulted at all.
: > "$STUB_TRACE"
RC=$(run_verify --no-pull)
is   "verify-only --no-pull passes"                             "$RC" "0"
assert_nothing_changed "passing, --no-pull" "$BEFORE"
grep -q "origin is not consulted" "${WORLD}/out" && ok "...and describes releasing the checked-out commit" || bad "--no-pull should not consult origin"

# Main has moved past what this checkout has fetched: said, never fetched.
push_commit 'not fetched yet'
: > "$STUB_TRACE"
RC=$(run_verify)
is   "with main ahead of the last fetch it still passes"        "$RC" "0"
grep -q "which this checkout has NOT fetched" "${WORLD}/out" && ok "...and SAYS the listing is of the last fetched main" || bad "should say main has moved past the last fetch"
assert_nothing_changed "main ahead of the last fetch (no fetch happened)" "$BEFORE"
cleanup

# ── 23. The failing cases: a specific reason, a non-zero exit, and still nothing changed ────────
verify_fails() { # verify_fails <label> <pattern>
    BEFORE=$(full_snapshot); : > "$STUB_TRACE"
    RC=$(run_verify)
    isnt "$1: verify-only exits non-zero"                       "$RC" "0"
    grep -q "FAIL .*$2" "${WORLD}/out" && ok "$1: the reason is named" || bad "$1: expected a FAIL naming '$2': $(grep FAIL "${WORLD}/out" | head -3)"
    grep -q "RESULT: FAILED" "${WORLD}/out" && ok "$1: the result line says FAILED" || bad "$1: should end RESULT: FAILED"
    assert_nothing_changed "$1" "$BEFORE"
}

verify_world
(cd "$STUB_REPO" && echo "edited by hand" >> file.txt)
verify_fails "a hand edit on the production checkout" "tracked files are MODIFIED"
grep -q "M file.txt" "${WORLD}/out" && ok "...and the file is named" || bad "the modified file should be named"
cleanup

verify_world
sed -i '/^DB_PASSWORD=/d' "${STUB_REPO}/deploy/.env"
verify_fails "a required key missing from deploy/.env" "required keys: DB_PASSWORD"
cleanup

verify_world
export STUB_BACKUP_INODE=1
verify_fails "a dead backup bind mount" "DEAD BIND MOUNT"
cleanup

verify_world
rm -rf "$HOST_BACKUP_DIR"
verify_fails "a deleted backup root" "DOES NOT EXIST on the host"
is   "...and it did NOT recreate the directory (deploy.sh would)" "$( [ -e "$HOST_BACKUP_DIR" ] && echo yes || echo no )" "no"
cleanup

verify_world
echo 1 > "${HOST_BACKUP_DIR}/LAST_STATUS"
verify_fails "a last backup run that failed" "last backup run reported FAILURE"
cleanup

verify_world
export VERIFY_MIN_FREE_MB=999999999
verify_fails "too little free disk" "under the minimum of 999999999 MB"
cleanup

verify_world
export STUB_HEALTH_STATUS=fail
verify_fails "an unhealthy stack" "/api/health is 'fail'"
cleanup

verify_world
export STUB_HEALTH_RELEASE=deadbeef
verify_fails "health naming another commit" "names deadbeef but the running image label says"
cleanup

verify_world
export STUB_UP_EXIT=1
verify_fails "/up not answering" "/up does NOT answer"
cleanup

verify_world
export STUB_CONFIG_EXIT=15
verify_fails "an invalid compose file" "does not pass 'compose config -q'"
cleanup

verify_world
(cd "$STUB_REPO" && git remote set-url origin "${WORLD}/no-such-remote.git")
verify_fails "an unreachable origin" "origin is NOT reachable"
cleanup

verify_world
(cd "$STUB_REPO" && git reset -q --hard "$OLD_HEAD" && echo local > local.txt && git add local.txt && git commit -qm 'a local commit')
verify_fails "a checkout that origin/main is not a fast-forward of" "is NOT a fast-forward"
cleanup

# The mode-600 guard, where the filesystem can show a mode at all (not under the NTFS stat shim).
verify_world
chmod 644 "${STUB_REPO}/deploy/.env"
if [ "$(stat -c %a "${STUB_REPO}/deploy/.env")" = "644" ]; then
    verify_fails "deploy/.env readable by others" "must be 600"
else
    echo "  (skipped: this filesystem reports 600 whatever chmod is given, so the mode FAIL cannot be shown here)"
fi
cleanup

echo
echo "  ${PASS} passed, ${FAIL} failed"
echo
[ "$FAIL" -eq 0 ]
