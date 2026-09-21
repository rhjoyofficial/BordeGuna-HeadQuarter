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

# What RUN_MIGRATIONS resolves to for `app`, the way compose resolves it: every `-f` file is read in
# order and the LAST one that sets the key wins (docs/81 C.3). The test world carries the real
# docker-compose.yml, so a plain `up` resolves to whatever that file really says, and a rollback's
# override file is honoured only if it really is passed on THIS command and really sets the key.
# STUB_IGNORE_OVERRIDE=1 plays a compose that does not merge it, so the script's own check is tested.
resolved_run_migrations() {
    local prev="" arg val="" v n=0
    for arg in "$@"; do
        if [ "$prev" = "-f" ]; then
            n=$((n+1))
            if [ "$n" -gt 1 ] && [ "${STUB_IGNORE_OVERRIDE:-0}" = "1" ]; then prev="$arg"; continue; fi
            # A path relative to the repository, as compose would see it from the script's cwd.
            case "$arg" in /*|?:*) f="$arg" ;; *) f="${STUB_REPO}/${arg}" ;; esac
            v=$(grep -E '^[[:space:]]*RUN_MIGRATIONS:' "$f" 2>/dev/null | tail -1 | tr -dc '0-9')
            [ -n "$v" ] && val="$v"
        fi
        prev="$arg"
    done
    echo "${val:-unset}"
}

# `docker inspect -f '{{ ... }}' <id>` - what commit the running image was built from.
#
# Once a release has gone `up` in this world, THAT is what is running: the stub remembers the commit
# the stack was started from, so a second deploy, a rollback or the release-state refresher reads the
# same fact the real label would give. Before any release it is whatever the test says.
if [ "${1:-}" = "inspect" ]; then
    case "${ALL}" in
        *".Config.Env"*)
            # The environment of the `app` container that was actually started.
            echo "APP_ENV=production"
            echo "RUN_MIGRATIONS=$(cat "${STUB_STATE}/app-run-migrations" 2>/dev/null || echo 1)"
            exit 0 ;;
        *".Mounts"*)
            [ "${STUB_RELEASE_STATE_MOUNTED:-1}" = "1" ] && echo "${STUB_REPO}/deploy/release-state /var/www/html/release-state false"
            echo "vol /var/www/html/storage true"
            exit 0 ;;
    esac
    if [ -s "${STUB_STATE}/released" ]; then cat "${STUB_STATE}/released"; else echo "${STUB_RUNNING_COMMIT:-}"; fi
    exit 0
fi

[ "${1:-}" = "compose" ] || exit 0

case "${ALL}" in
    # ── the backup service ──────────────────────────────────────────────────────────────────────
    *"stat -c %i /backups"*)
        # verify-only's read-only dead-mount check: the inode the container sees.
        if [ -n "${STUB_BACKUP_INODE:-}" ]; then echo "${STUB_BACKUP_INODE}"; else stat -c %i "${HOST_BACKUP_DIR}" 2>/dev/null; fi
        exit 0 ;;
    *"stat -c %i /var/www/html/release-state"*)
        if [ -n "${STUB_RELEASE_STATE_INODE:-}" ]; then echo "${STUB_RELEASE_STATE_INODE}"; else stat -c %i "${STUB_REPO}/deploy/release-state" 2>/dev/null; fi
        exit 0 ;;
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
    *" config"*)
        [ "${STUB_CONFIG_EXIT:-0}" = "0" ] || { echo "stub: compose file is invalid" >&2; exit "${STUB_CONFIG_EXIT}"; }
        case "${ALL}" in *" config -q"*|*" --quiet"*) exit 0 ;; esac
        echo "services:"; echo "  app:"; echo "    environment:"
        echo "      RUN_MIGRATIONS: \"$(resolved_run_migrations "$@")\""
        exit 0 ;;
    *" up -d"*)
        [ "${STUB_UP_D_EXIT:-0}" = "0" ] || exit "${STUB_UP_D_EXIT}"
        # Does this `up` start `app`? A bare `up -d` starts everything; `--no-deps web` does not.
        case "${ALL}" in
            *"--no-deps"*) case " ${ALL} " in *" app "*) starts_app=1 ;; *) starts_app=0 ;; esac ;;
            *) starts_app=1 ;;
        esac
        if [ "$starts_app" = "1" ]; then
            resolved_run_migrations "$@" > "${STUB_STATE}/app-run-migrations"
            cat "${STUB_STATE}/app-run-migrations" >> "${STUB_STATE}/app-run-migrations-history"
        fi
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
        # /api/health as the released image would answer it, and as PHP hands it over (docs/81 C.8):
        # an overall status, the commit the image was built from, and the components. A failing
        # component makes the endpoint answer HTTP 503, and `file_get_contents` returns false on a
        # non-2xx answer unless the call asks for the `ignore_errors` context option, so the script
        # prints its `{}`. Measured on production on 21 September: the same read of a 404 gives `{}`
        # without the option and the body with it. Until docs/81 C.8 this stub handed over the body of
        # a 503 whatever the call asked for, which is how a script that printed 'unreadable' on
        # production passed "an unhealthy stack is 'fail'" here.
        # `unreadable` is nothing answering at all (refused, timed out), which is {} either way.
        if [ "${STUB_HEALTH_STATUS:-ok}" = "unreadable" ]; then echo '{}'; exit 0; fi
        sched='{"status":"ok","last_tick_age_seconds":20}'
        if [ "${STUB_HEALTH_STATUS:-ok}" = "fail" ]; then
            case "${ALL}" in *ignore_errors*) ;; *) echo '{}'; exit 0 ;; esac
            sched='{"status":"fail","detail":"no tick recorded"}'
        fi
        rel=${STUB_HEALTH_RELEASE-$(cat "${STUB_STATE}/released" 2>/dev/null)}
        echo "{\"status\":\"${STUB_HEALTH_STATUS:-ok}\",\"checked_at\":\"x\",\"checks\":{\"application\":{\"status\":\"ok\",\"release\":\"${rel}\"},\"database\":{\"status\":\"ok\",\"latency_ms\":1},\"scheduler\":${sched}}}"
        exit 0 ;;
    *"wget"*)
        exit "${STUB_UP_EXIT:-0}" ;;
    *)
        exit 0 ;;
esac
