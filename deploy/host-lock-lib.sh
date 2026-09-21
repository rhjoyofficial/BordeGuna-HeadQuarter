#!/bin/bash
# Shared by deploy.sh and rollback.sh. SOURCED, never executed.
#
#   ONE DEPLOY OR ROLLBACK AT A TIME ON THIS HOST (docs/81 C.9)
#   A release takes this lock before it reads anything it decides on, and holds it until it ends. A
#   second release that starts meanwhile refuses at once, names the one that holds it, and has moved
#   nothing: no dump, no probe, no checkout, no container, no record.
#
# Until docs/81 C.9 neither script took a lock, so two releases at once each took a dump through the
# same `backup.sh once` and the same LAST_OK marker, each moved the checkout, each built and started
# the stack, and each recorded a release the other had half made. On 21 September 2026 the owner ran
# a release by hand while a session was working on the same host.
#
# HOW. `flock -n` on descriptor 9 of one file, the pattern deploy/gate/run.sh already uses for the gate
# runner, in the same directory. The kernel releases the lock when the process ends, however it ends,
# so a killed release never leaves a lock behind. What CAN be left behind is the holder's one-line
# record (pid, kind, start, checkout), written for the refusal message and cleared on exit: a record
# whose process is gone means that release was killed, and it neither blocks the next one nor reads
# as a release in progress (verify-only.sh says so).
#
# ⚠ The file is opened for APPEND, never truncated on open: a refused run must not wipe the record of
# the run that holds the lock by merely opening the file. Only the holder truncates it.
#
# ⚠ Production's copy of deploy.sh before this file existed takes no lock, so it cannot see this one
# and this one cannot see it. The protection starts with the first release run BY a script that has it.
#
# The caller provides: die(), BF_VERB ("deploy" or "roll back"). HQ_DEPLOY_LOCK may be overridden (the
# tests do); verify-only.sh reads the same default.

HQ_DEPLOY_LOCK=${HQ_DEPLOY_LOCK:-/tmp/bordeguna-hq-deploy.lock}
HL_HELD=0

# hl_acquire <deploy|rollback>
hl_acquire() {
    local kind=$1 holder
    command -v flock >/dev/null 2>&1 \
        || die "flock (util-linux) is not installed, so this cannot make sure it is the only release running. Refusing to ${BF_VERB}; nothing has moved."
    if ! { exec 9>>"$HQ_DEPLOY_LOCK"; } 2>/dev/null; then
        die "cannot open the host lock ${HQ_DEPLOY_LOCK}. Refusing to ${BF_VERB}; nothing has moved."
    fi
    if ! flock -n 9; then
        holder=$(head -c 300 "$HQ_DEPLOY_LOCK" 2>/dev/null | tr -d '\r\n')
        exec 9>&-
        die "another deploy or rollback is already running on this host (${holder:-it has not written its record yet}). Refusing to ${BF_VERB}; nothing has moved. Let it finish, then run this again."
    fi
    HL_HELD=1
    : > "$HQ_DEPLOY_LOCK"
    printf 'pid=%s kind=%s started=%s checkout=%s\n' "$$" "$kind" "$(date -u +%FT%TZ)" \
        "$(git rev-parse --short HEAD 2>/dev/null || echo unknown)" >&9
    # The caller's own EXIT trap replaces this one and calls hl_release itself.
    trap hl_release EXIT
}

# Clears the record. The lock itself goes when the process ends and descriptor 9 closes.
hl_release() {
    [ "${HL_HELD:-0}" = "1" ] || return 0
    : > "$HQ_DEPLOY_LOCK" 2>/dev/null || true
    HL_HELD=0
    return 0
}
