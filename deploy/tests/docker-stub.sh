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
if [ "${1:-}" = "inspect" ]; then
    echo "${STUB_RUNNING_COMMIT:-}"
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
    *" ps -q app"*)
        [ "${STUB_APP_RUNNING:-1}" = "1" ] && echo "cid-app"; exit 0 ;;
    *" ps -q db"*)
        [ "${STUB_DB_RUNNING:-1}" = "1" ] && echo "cid-db"; exit 0 ;;
    *" ps --status running db"*)
        exit "$([ "${STUB_DB_RUNNING:-1}" = "1" ] && echo 0 || echo 1)" ;;

    # ── the rest of a release, which these tests do not exercise beyond "it was reached" ─────────
    *"php -r"*)
        echo '{"status":"ok","checks":{}}'; exit 0 ;;
    *"wget"*)
        exit 0 ;;
    *)
        exit 0 ;;
esac
