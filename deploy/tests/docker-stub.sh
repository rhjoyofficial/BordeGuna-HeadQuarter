#!/bin/bash
# A stand-in for `docker`, used only by deploy-safety-test.sh.
#
# It answers the handful of calls deploy.sh makes, and it is scripted through environment variables
# so one test can say "the backup fails" and another "the mount is dead but repairable". Every call
# is appended to $STUB_TRACE so a test can assert on ORDER as well as outcome.
#
# ⭐ The important line is the one under `/backup.sh once`: it records the repository's HEAD at the
# moment the dump was requested. That is what makes "the backup happened before the checkout moved"
# a measured fact rather than something read off the script.
set -uo pipefail

ALL="$*"
echo "docker ${ALL}" >> "${STUB_TRACE}"

# `docker inspect -f '{{ ... }}' <id>` - what commit the running image was built from.
#
# Once a release has gone `up` in this world, THAT is what is running: the stub remembers the commit
# the stack was started from, so a second deploy, a rollback or the release-state refresher reads the
# same fact the real label would give. Before any release it is whatever the test says.
if [ "${1:-}" = "inspect" ]; then
    if [ -s "${STUB_STATE}/released" ]; then cat "${STUB_STATE}/released"; else echo "${STUB_RUNNING_COMMIT:-}"; fi
    exit 0
fi

[ "${1:-}" = "compose" ] || exit 0

case "${ALL}" in
    # ── the backup service ──────────────────────────────────────────────────────────────────────
    *"mount-probe"*)
        # Dead mount unless the service has since been recreated (and the test says that repairs it).
        if [ -f "${STUB_STATE}/backup-recreated" ] && [ "${STUB_MOUNT_REPAIRABLE:-0}" = "1" ]; then
            exit 0
        fi
        exit "${STUB_MOUNT_EXIT:-0}"
        ;;
    *"/backup.sh once"*)
        (cd "${STUB_REPO}" && git rev-parse --short HEAD) > "${STUB_STATE}/head-at-backup"
        exit "${STUB_BACKUP_EXIT:-0}"
        ;;
    *"LAST_STATUS"*)
        echo "${STUB_LAST_STATUS:-0}"; exit 0 ;;
    *"LAST_OK"*)
        echo "${STUB_LAST_OK-/backups/daily/hq-20260910-000000.dump}"; exit 0 ;;
    *"-s '"*)
        # deploy.sh asserting the dump file is present and non-empty.
        exit "${STUB_DUMP_PRESENT:-0}" ;;

    # ── lifecycle ───────────────────────────────────────────────────────────────────────────────
    *"up -d --force-recreate --no-deps backup"*)
        touch "${STUB_STATE}/backup-recreated"; exit 0 ;;
    *" build "*)
        # `--build-arg GIT_COMMIT=<sha>` is what stamps the image; remember it so `up` can "run" it.
        case "${ALL}" in *"GIT_COMMIT="*) echo "${ALL}" | sed 's/.*GIT_COMMIT=\([0-9a-f]*\).*/\1/' > "${STUB_STATE}/built" ;; esac
        case "${ALL}" in *" app"*) exit "${STUB_BUILD_EXIT:-0}" ;; esac
        exit 0 ;;
    *" up -d"*)
        [ "${STUB_UP_D_EXIT:-0}" = "0" ] || exit "${STUB_UP_D_EXIT}"
        [ -s "${STUB_STATE}/built" ] && cp "${STUB_STATE}/built" "${STUB_STATE}/released"
        exit 0 ;;
    *" ps -q app"*)
        [ "${STUB_APP_RUNNING:-1}" = "1" ] && echo "cid-app"; exit 0 ;;
    *" ps -q db"*)
        [ "${STUB_DB_RUNNING:-1}" = "1" ] && echo "cid-db"; exit 0 ;;
    *" ps --status running db"*)
        exit "$([ "${STUB_DB_RUNNING:-1}" = "1" ] && echo 0 || echo 1)" ;;

    # ── the rest of a release, which these tests do not exercise beyond "it was reached" ─────────
    *"php -r"*)
        # /api/health as the released image would answer it: an overall status, and the commit the
        # image was built from. `unreadable` is a 503 or no answer at all, which deploy.sh sees as {}.
        if [ "${STUB_HEALTH_STATUS:-ok}" = "unreadable" ]; then echo '{}'; exit 0; fi
        rel=${STUB_HEALTH_RELEASE-$(cat "${STUB_STATE}/released" 2>/dev/null)}
        echo "{\"status\":\"${STUB_HEALTH_STATUS:-ok}\",\"checked_at\":\"x\",\"checks\":{\"application\":{\"status\":\"ok\",\"release\":\"${rel}\"}}}"
        exit 0 ;;
    *"wget"*)
        exit "${STUB_UP_EXIT:-0}" ;;
    *)
        exit 0 ;;
esac
