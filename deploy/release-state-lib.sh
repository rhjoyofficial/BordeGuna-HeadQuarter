#!/bin/bash
# Shared by deploy.sh, rollback.sh and release-state-refresh.sh. SOURCED, never executed.
#
# The release-state directory (docs/80 C.4) is how the running application learns what the HOST
# knows and the container cannot: what was last released and whether it was verified, and where
# origin/main stands. The container gets no git checkout and no GitHub credential; it gets this
# directory, mounted read-only.
#
#   release.json        the last release ATTEMPT (deploy or rollback), released or failed
#   last-verified.json  a copy of release.json, written ONLY when the smoke verification passed
#   main.json           origin/main against the running release (release-state-refresh.sh)
#
# ⚠ Every write is "temporary name, then rename". The directory is bind-mounted into the containers,
# and a reader must never see half a file. It is the DIRECTORY that is mounted, not the files: a
# bind-mounted file pins its inode and would never see the rename.
#
# Nothing here exits the calling script. A failure to write release state must never fail a deploy
# that has otherwise succeeded, and must never be the reason a rollback stops half way.

# Resolved against the checkout root, which every caller has already `cd`-ed to.
RELEASE_STATE_DIR=${RELEASE_STATE_DIR:-"$(pwd)/deploy/release-state"}

rs_now() { date -u +%FT%TZ; }

# A JSON string literal, quotes included. Backslash and double quote are escaped, control characters
# (tabs and newlines among them) are dropped: a commit subject is somebody else's text, and one
# stray quote must not turn the whole manifest into something the application cannot parse.
rs_json_string() {
    printf '"%s"' "$(printf '%s' "${1:-}" | tr -d '\000-\037' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')"
}

# `null` for an empty value, otherwise a JSON string.
rs_json_nullable() {
    if [ -n "${1:-}" ]; then rs_json_string "$1"; else printf 'null'; fi
}

# A JSON array of strings from stdin, one item per line.
rs_json_array() {
    local first=1 line
    printf '['
    while IFS= read -r line || [ -n "$line" ]; do
        [ -n "$line" ] || continue
        [ "$first" = 1 ] || printf ','
        first=0
        rs_json_string "$line"
    done
    printf ']'
}

rs_ensure_dir() {
    mkdir -p "$RELEASE_STATE_DIR" 2>/dev/null || return 1
    # The application reads this as www-data through a read-only mount.
    chmod 755 "$RELEASE_STATE_DIR" 2>/dev/null || true
}

# rs_write <name>  (content on stdin)
rs_write() {
    local name=$1 tmp
    rs_ensure_dir || return 1
    tmp="${RELEASE_STATE_DIR}/.${name}.tmp.$$"
    cat > "$tmp" || { rm -f "$tmp"; return 1; }
    chmod 644 "$tmp" 2>/dev/null || true
    mv -f "$tmp" "${RELEASE_STATE_DIR}/${name}" || { rm -f "$tmp"; return 1; }
}

# rs_write_release <kind> <status> <failed_stage> <commit> <previous> <previous_known 0|1> \
#                  <dump> <dump_verified_at> <main_at_release> <verification> <up> <health> <health_release> <checked_at>
#
#   kind          deploy | rollback
#   status        released | failed
#   verification  passed | failed | not_run
rs_write_release() {
    local kind=$1 status=$2 stage=$3 commit=$4 previous=$5 previous_known=$6 dump=$7 dump_at=$8 main_at=$9
    local verification=${10} up=${11} health=${12} health_release=${13} checked_at=${14}
    {
        printf '{"schema":1'
        printf ',"kind":%s' "$(rs_json_string "$kind")"
        printf ',"status":%s' "$(rs_json_string "$status")"
        printf ',"failed_stage":%s' "$(rs_json_nullable "$stage")"
        printf ',"commit":%s' "$(rs_json_string "$commit")"
        printf ',"previous":%s' "$(rs_json_nullable "$previous")"
        printf ',"previous_known":%s' "$([ "$previous_known" = 1 ] && echo true || echo false)"
        printf ',"recorded_at":%s' "$(rs_json_string "$(rs_now)")"
        printf ',"dump":%s' "$(rs_json_nullable "$dump")"
        printf ',"dump_verified_at":%s' "$(rs_json_nullable "$dump_at")"
        printf ',"origin_main_at_release":%s' "$(rs_json_nullable "$main_at")"
        printf ',"verification":{"status":%s' "$(rs_json_string "$verification")"
        printf ',"up":%s' "$(rs_json_nullable "$up")"
        printf ',"health":%s' "$(rs_json_nullable "$health")"
        printf ',"health_release":%s' "$(rs_json_nullable "$health_release")"
        printf ',"checked_at":%s}' "$(rs_json_nullable "$checked_at")"
        printf '}\n'
    } | rs_write release.json
}

# Promote the record just written to "last verified". Only ever called after a passed verification.
rs_mark_verified() {
    [ -s "${RELEASE_STATE_DIR}/release.json" ] || return 1
    rs_write last-verified.json < "${RELEASE_STATE_DIR}/release.json"
}

# What a health answer says, without a JSON parser: the overall status and the release it names.
# ⚠ `|| true`: the callers run under `set -e -o pipefail`, and "no match" is an answer, not an error.
rs_health_status()  { printf '%s' "${1:-}" | grep -o '^{"status":"[a-z]*"' | cut -d'"' -f4 || true; }
rs_health_release() { printf '%s' "${1:-}" | grep -o '"release":"[^"]*"' | head -1 | cut -d'"' -f4 || true; }

# The same commit, whatever length each side abbreviated it to.
rs_same_commit() {
    local a=${1:-} b=${2:-}
    [ "${#a}" -ge 7 ] && [ "${#b}" -ge 7 ] || return 1
    case "$a" in "$b"*) return 0 ;; esac
    case "$b" in "$a"*) return 0 ;; esac
    return 1
}
