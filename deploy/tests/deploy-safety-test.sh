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
    chmod +x deploy/deploy.sh
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
    export PATH="${WORLD}/bin:${PATH}"
    export HOST_BACKUP_DIR="${WORLD}/backups"

    # Defaults: a healthy stack running the checked-out commit.
    export STUB_RUNNING_COMMIT="$OLD_HEAD"
    export STUB_DB_RUNNING=1 STUB_APP_RUNNING=1
    export STUB_BACKUP_EXIT=0 STUB_MOUNT_EXIT=0 STUB_MOUNT_REPAIRABLE=0
    export STUB_LAST_STATUS=0 STUB_DUMP_PRESENT=0
    unset STUB_LAST_OK 2>/dev/null || true
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

echo
echo "  ${PASS} passed, ${FAIL} failed"
echo
[ "$FAIL" -eq 0 ]
