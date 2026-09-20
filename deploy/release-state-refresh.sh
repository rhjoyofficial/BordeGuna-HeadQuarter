#!/bin/bash
# Where origin/main stands against the release that is RUNNING, written for the application to read.
#
#   /opt/bordeguna-hq/deploy/release-state-refresh.sh
#
# Called at the end of hq-monitor.sh (root cron, every 5 minutes) and at the end of deploy.sh. It
# writes deploy/release-state/main.json, which the app containers mount read-only (docs/80 C.4).
# The container has no git checkout and no GitHub credential, and must not get either; the host
# already has both, because it deploys.
#
# CHEAP, AND QUIET BY DESIGN
#   - `git fetch` at most once every FETCH_MIN_INTERVAL seconds (30 minutes). The attempt is stamped
#     BEFORE the fetch, so a remote that is down is not retried every five minutes.
#   - A failed fetch is logged and recorded in the file (`fetch_ok: false`). It never pages: "main is
#     ahead" and "GitHub did not answer" are not incidents. The APPLICATION shows the age of the last
#     successful fetch, and that is where staleness is made visible.
#   - It only ever fetches. It never merges, checks out, builds or touches a container, so it cannot
#     disturb the backup-first ordering of deploy.sh.
#
# ⚠ Absence must never read as "up to date". When the running release or main cannot be determined,
# the counts are written as null with a reason - never as zero.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 0
# shellcheck source=deploy/release-state-lib.sh
. deploy/release-state-lib.sh

COMPOSE="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
FETCH_MIN_INTERVAL=${FETCH_MIN_INTERVAL:-1800}
FETCH_TIMEOUT=${FETCH_TIMEOUT:-60}
MAX_SUBJECTS=${MAX_SUBJECTS:-50}
MIGRATIONS_PATH=${MIGRATIONS_PATH:-apps/hq/database/migrations}
log() { echo "[release-state $(rs_now)] $*"; }

rs_ensure_dir || { log "cannot create ${RELEASE_STATE_DIR}; nothing written"; exit 0; }

# ── 1. Fetch, throttled ────────────────────────────────────────────────────────────────────────
ATTEMPT_F="${RELEASE_STATE_DIR}/.last-fetch-attempt"
OK_F="${RELEASE_STATE_DIR}/.last-fetch-ok"
now_s=$(date +%s)
FETCH_OK=""   # "" = not attempted this run

if [ "${RS_FETCHED_NOW:-0}" = "1" ]; then
    # deploy.sh fetched origin seconds ago; that fetch counts.
    rs_now > "$OK_F"; touch "$ATTEMPT_F"; FETCH_OK=true
else
    last_attempt=$(stat -c %Y "$ATTEMPT_F" 2>/dev/null || echo 0)
    if [ $((now_s - last_attempt)) -ge "$FETCH_MIN_INTERVAL" ]; then
        touch "$ATTEMPT_F"
        if GIT_TERMINAL_PROMPT=0 timeout "$FETCH_TIMEOUT" git fetch -q origin main 2>/dev/null; then
            rs_now > "$OK_F"; FETCH_OK=true
        else
            FETCH_OK=false
            log "git fetch origin main failed or timed out (not paged; the application shows the age)"
        fi
    fi
fi
FETCHED_AT=$(head -1 "$OK_F" 2>/dev/null | tr -dc '0-9TZ:-')

# ── 2. What is running ─────────────────────────────────────────────────────────────────────────
#
# The image label first, exactly as deploy.sh reads it: a statement of fact. The release record is a
# fallback and is NAMED as one, so the application can say what the comparison was made against.
RELEASE=""; BASIS=""
id=$($COMPOSE ps -q app 2>/dev/null | head -1) || true
if [ -n "${id:-}" ]; then
    RELEASE=$(docker inspect -f '{{ index .Config.Labels "com.bordeguna.commit" }}' "$id" 2>/dev/null | grep -E '^[0-9a-f]{7,40}$' || true)
    [ -n "$RELEASE" ] && BASIS="running-image-label"
fi
if [ -z "$RELEASE" ] && [ -s "${RELEASE_STATE_DIR}/last-verified.json" ]; then
    RELEASE=$(grep -o '"commit":"[0-9a-f]*"' "${RELEASE_STATE_DIR}/last-verified.json" | head -1 | cut -d'"' -f4 || true)
    [ -n "$RELEASE" ] && BASIS="last-verified-record"
fi

# ── 3. Main against it ─────────────────────────────────────────────────────────────────────────
MAIN=$(git rev-parse --short --verify -q origin/main 2>/dev/null || true)
AHEAD=null; BEHIND=null; REASON=""; SUBJECTS='[]'; MIGRATIONS='[]'; TRUNCATED=false

if [ -z "$MAIN" ]; then
    REASON="origin/main is not known to this checkout"
elif [ -z "$RELEASE" ]; then
    REASON="the running release could not be determined (no commit label on the running image, and no verified release on record)"
elif ! git cat-file -e "${RELEASE}^{commit}" 2>/dev/null; then
    REASON="the running release ${RELEASE} is not a commit this checkout knows"
else
    AHEAD=$(git rev-list --count "${RELEASE}..origin/main" 2>/dev/null || echo null)
    BEHIND=$(git rev-list --count "origin/main..${RELEASE}" 2>/dev/null || echo null)
    # Merges included, so the list and the count describe the same commits.
    SUBJECTS=$(git log --format=%s --max-count="$MAX_SUBJECTS" "${RELEASE}..origin/main" 2>/dev/null | rs_json_array)
    if [ "$AHEAD" != "null" ] && [ "$AHEAD" -gt "$MAX_SUBJECTS" ]; then TRUNCATED=true; fi
    # ADDED files only: a migration main has and this release does not is exactly a pending migration.
    MIGRATIONS=$(git diff --name-only --diff-filter=A "${RELEASE}..origin/main" -- "$MIGRATIONS_PATH" 2>/dev/null | sed 's|.*/||' | sort | rs_json_array)
fi

{
    printf '{"schema":1'
    printf ',"read_at":%s' "$(rs_json_string "$(rs_now)")"
    printf ',"fetched_at":%s' "$(rs_json_nullable "$FETCHED_AT")"
    printf ',"fetch_ok":%s' "${FETCH_OK:-null}"
    printf ',"main_commit":%s' "$(rs_json_nullable "$MAIN")"
    printf ',"release_commit":%s' "$(rs_json_nullable "$RELEASE")"
    printf ',"release_basis":%s' "$(rs_json_nullable "$BASIS")"
    printf ',"ahead":%s,"behind":%s' "$AHEAD" "$BEHIND"
    printf ',"reason":%s' "$(rs_json_nullable "$REASON")"
    printf ',"subjects":%s,"subjects_truncated":%s' "$SUBJECTS" "$TRUNCATED"
    printf ',"migrations":%s' "$MIGRATIONS"
    printf '}\n'
} | rs_write main.json || { log "could not write ${RELEASE_STATE_DIR}/main.json"; exit 0; }

exit 0
