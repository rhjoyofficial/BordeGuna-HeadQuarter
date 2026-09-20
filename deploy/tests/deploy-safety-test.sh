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
    chmod +x deploy/deploy.sh deploy/release-state-refresh.sh deploy/rollback.sh
    : > deploy/docker-compose.yml
    echo "APP_KEY=x" > deploy/.env; chmod 600 deploy/.env
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

echo
echo "  ${PASS} passed, ${FAIL} failed"
echo
[ "$FAIL" -eq 0 ]
