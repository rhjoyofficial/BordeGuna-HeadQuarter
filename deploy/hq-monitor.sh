#!/bin/bash
# Host monitor for srv1808022, installed as root cron every 5 minutes:
#   */5 * * * * /opt/bordeguna-hq/deploy/hq-monitor.sh >> /var/log/hq-monitor.log 2>&1
#
# Checks RAM, swap, disk, CPU load, OOM kills, container restarts, HQ health endpoint components,
# Postgres/Redis/Horizon/scheduler (via /api/health). Alerts to Telegram ONLY on state change
# (ok -> alert, alert -> recovered) plus one daily heartbeat, so it cannot spam. Each alert names
# the check, the value, the threshold and where to look.
#
# Config: /root/.hq-monitor.env with TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID (mode 600). Without it,
# the script logs only.
set -uo pipefail
STATE_DIR=/var/lib/hq-monitor; mkdir -p "$STATE_DIR"
[ -f /root/.hq-monitor.env ] && . /root/.hq-monitor.env
HOST=$(hostname)

RAM_MIN_MB=${RAM_MIN_MB:-1024}
SWAP_MAX_PCT=${SWAP_MAX_PCT:-50}
DISK_MAX_PCT=${DISK_MAX_PCT:-80}
LOAD_MAX=${LOAD_MAX:-4.0}

# Containers an operator has decided restart on a designed cycle. Logged, never texted.
# ⚠ It does NOT suppress a restart-loop: RESTART_LOOP_DELTA is checked first, so a named container
# that starts failing fast still pages. An allowlist that silenced everything would be a way to make
# a crash invisible by remembering to add it to a list.
RESTART_ALERT_IGNORE=${RESTART_ALERT_IGNORE:-"bordeguna-erp-worker-1"}
# Restarts within one 5-minute tick that mean "looping" rather than "recycled". A worker on
# --max-time=3600 cannot reach 2.
RESTART_LOOP_DELTA=${RESTART_LOOP_DELTA:-2}

log() { echo "[$(date -u +%FT%TZ)] $*"; }
tg() {
    [ -n "${TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${TELEGRAM_CHAT_ID:-}" ] || return 0
    curl -s -m 10 -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" -d chat_id="${TELEGRAM_CHAT_ID}" -d disable_web_page_preview=true --data-urlencode text="$1" >/dev/null 2>&1 || true
}
# state-change alerting: check <name> <0=ok|1=alert> <message>
check() {
    local name=$1 bad=$2 msg=$3 f="$STATE_DIR/$1.state" prev
    prev=$(cat "$f" 2>/dev/null || echo 0)
    if [ "$bad" = 1 ] && [ "$prev" = 0 ]; then log "ALERT $name: $msg"; tg "🔴 ${HOST} ${name}: ${msg}"; fi
    if [ "$bad" = 0 ] && [ "$prev" = 1 ]; then log "RECOVERED $name: $msg"; tg "🟢 ${HOST} ${name} recovered: ${msg}"; fi
    echo "$bad" > "$f"
}

# RAM / swap / disk / load
AVAIL_MB=$(free -m | awk '/^Mem:/{print $7}')
check ram $([ "$AVAIL_MB" -lt "$RAM_MIN_MB" ] && echo 1 || echo 0) "available RAM ${AVAIL_MB} MB (min ${RAM_MIN_MB}). Look: docker stats --no-stream"
SWAP_TOTAL=$(free -m | awk '/^Swap:/{print $2}'); SWAP_USED=$(free -m | awk '/^Swap:/{print $3}')
SWAP_PCT=$([ "$SWAP_TOTAL" -gt 0 ] && echo $((SWAP_USED*100/SWAP_TOTAL)) || echo 0)
check swap $([ "$SWAP_PCT" -gt "$SWAP_MAX_PCT" ] && echo 1 || echo 0) "swap ${SWAP_PCT}% used (max ${SWAP_MAX_PCT}%). Memory pressure: consider raising limits or a second VPS (ADR-012)"
DISK_PCT=$(df --output=pcent / | tail -1 | tr -dc 0-9)
check disk $([ "$DISK_PCT" -gt "$DISK_MAX_PCT" ] && echo 1 || echo 0) "disk ${DISK_PCT}% used (max ${DISK_MAX_PCT}%). Look: docker system df; du -sh /root/backups/*"
LOAD=$(cut -d' ' -f1 /proc/loadavg)
check load $(awk -v l="$LOAD" -v m="$LOAD_MAX" 'BEGIN{print (l>m)?1:0}') "load ${LOAD} (max ${LOAD_MAX})"

# OOM kills since last run (kernel log)
OOM_F="$STATE_DIR/oom.count"; OOM_PREV=$(head -1 "$OOM_F" 2>/dev/null | tr -dc 0-9); OOM_PREV=${OOM_PREV:-0}
OOM_NOW=$(journalctl -k --since "7 days ago" 2>/dev/null | grep -ci "out of memory\|oom-kill"); OOM_NOW=${OOM_NOW:-0}
echo "$OOM_NOW" > "$OOM_F"
if [ "$OOM_NOW" -gt "$OOM_PREV" ]; then
    WHO=$(journalctl -k --since "1 hour ago" 2>/dev/null | grep -i "killed process" | tail -1)
    log "ALERT oom: $WHO"; tg "🔴 ${HOST} OOM kill detected: ${WHO:-see journalctl -k}"
fi

# Container restarts (any container) since last run.
#
# ⚠ A restart is not automatically a fault, and treating it as one is how a monitor teaches people to
# ignore it. `bordeguna-erp-worker-1` runs `queue:work --max-time=3600`, so it EXITS DELIBERATELY
# every hour with code 0 and `restart: unless-stopped` brings it back - the ordinary Laravel worker
# recycle. That produced an alert every hour (docs/58 section H.4 recorded it as unexplained), and an
# hourly false alarm is worse than no alarm, because it is the one people mute.
#
# Three rules, in this order, and each closes a hole the others leave:
#
#   1. RATE FIRST. More than one restart inside a single 5-minute tick is a crash-loop, and it pages
#      whatever the exit code says. A worker on `--max-time=3600` restarts at most once every twelve
#      ticks; a process failing fast can exit 0 every few seconds, and rule 2 alone would silence
#      exactly that. Rate is checked first so nothing below can suppress it.
#   2. AN EXPLICIT ALLOWLIST. `RESTART_ALERT_IGNORE` names containers an operator has decided are
#      self-recycling. Logged, never texted.
#   3. A CLEAN EXIT. Code 0 and not OOM-killed is the ordinary recycle, logged and not texted -
#      so a new self-recycling worker does not page for the days before somebody adds it to rule 2.
#
# ⚠⚠ Rules 2 and 3 were written independently on 10 September - rule 2 by hand on the production
# checkout, rule 3 in this repository - for the same symptom, and each has a hole the other closes.
# An allowlist alone silences a genuine crash of a named container forever; a clean-exit rule alone
# silences a fast exit-0 loop of any container. Keeping both, with the rate check above them, is the
# reconciliation rather than a preference between two authors.
R_F="$STATE_DIR/restarts.txt"; touch "$R_F"
docker ps -a --format '{{.Names}}' | while read -r c; do
    n=$(docker inspect -f '{{.RestartCount}}' "$c" 2>/dev/null || echo 0)
    p=$(grep "^$c " "$R_F" | awk '{print $2}'); p=${p:-0}
    if [ "$n" -gt "$p" ]; then
        code=$(docker inspect -f '{{.State.ExitCode}}' "$c" 2>/dev/null || echo unknown)
        oom=$(docker inspect -f '{{.State.OOMKilled}}' "$c" 2>/dev/null || echo unknown)
        delta=$((n - p))

        if [ "$delta" -ge "$RESTART_LOOP_DELTA" ]; then
            log "ALERT restart-loop: $c $p->$n (${delta} in one tick) exit=${code} oom=${oom}"
            tg "🔴 ${HOST} container ${c} is RESTART-LOOPING: ${delta} restarts in one 5-minute tick (${p} -> ${n}), exit ${code}, oom ${oom}. Look: docker logs --tail 100 ${c}"
        elif echo " $RESTART_ALERT_IGNORE " | grep -q " $c "; then
            log "restart (allowlisted as self-recycling, not texted): $c $p->$n"
        elif [ "$code" = "0" ] && [ "$oom" = "false" ]; then
            log "restart (clean exit, not texted): $c $p->$n"
        else
            log "ALERT restart: $c $p->$n exit=${code} oom=${oom}"
            tg "🟠 ${HOST} container ${c} restarted (${p} -> ${n}), exit ${code}, oom ${oom}. Look: docker logs --tail 100 ${c}"
        fi
    fi
done
docker ps -a --format '{{.Names}} {{.RestartCount}}' > "$R_F" 2>/dev/null || docker ps -a --format '{{.Names}}' | while read -r c; do echo "$c $(docker inspect -f '{{.RestartCount}}' "$c")"; done > "$R_F"

# Unhealthy containers
UNHEALTHY=$(docker ps --filter health=unhealthy --format '{{.Names}}' | tr '\n' ' ')
check unhealthy $([ -n "$UNHEALTHY" ] && echo 1 || echo 0) "unhealthy containers: ${UNHEALTHY:-none}"

# HQ application health (through Traefik by Host header; falls back to the web container)
HQ_JSON=$(curl -s -m 10 -H 'Host: hq.bordeguna.com' http://127.0.0.1/api/health 2>/dev/null)
echo "$HQ_JSON" | grep -q '"checks"' || HQ_JSON=$(docker exec bordeguna-hq-web-1 wget -qO- http://127.0.0.1/api/health 2>/dev/null)
echo "$HQ_JSON" | grep -q '"checks"' || HQ_JSON=""
if ! docker ps --format '{{.Names}}' | grep -q '^bordeguna-hq-web-1$'; then
    log "HQ stack not deployed yet; skipping HQ checks"
    HQ_JSON=""; overall="not-deployed"
elif [ -n "$HQ_JSON" ]; then
    for comp in database redis queue scheduler; do
        st=$(echo "$HQ_JSON" | grep -o "\"$comp\":{\"status\":\"[a-z]*\"" | grep -o '"status":"[a-z]*"' | cut -d'"' -f4)
        check "hq_$comp" $([ "$st" = "ok" ] && echo 0 || echo 1) "HQ ${comp} status=${st:-unknown}. Look: docker compose -f /opt/bordeguna-hq/deploy/docker-compose.yml logs --tail 100 ${comp/queue/horizon}"
    done
    overall=$(echo "$HQ_JSON" | grep -o '^{"status":"[a-z]*"' | cut -d'"' -f4)
    check hq_overall $([ "$overall" = "ok" ] && echo 0 || echo 1) "HQ overall=${overall:-unknown}"
else
    check hq_reachable 1 "HQ /api/health unreachable. Look: docker ps; docker logs bordeguna-hq-web-1"
fi
[ -n "$HQ_JSON" ] && check hq_reachable 0 "reachable"
overall=${overall:-unknown}

# ERP still answering (it shares the host)
ERP=$(curl -s -m 10 -o /dev/null -w '%{http_code}' -H 'Host: erp.srv1808022.hstgr.cloud' http://127.0.0.1/up 2>/dev/null)
check erp_up $([ "$ERP" = "200" ] || [ "$ERP" = "301" ] || [ "$ERP" = "302" ] && echo 0 || echo 1) "ERP /up returned ${ERP:-nothing}"

# ── The HQ backup directory itself ──────────────────────────────────────────────────────────────
#
# ⛔⛔ This check used to be `if [ -e "$p" ]`, and that is why the 9 September deletion was silent.
# The directory going missing did not fire the check, it REMOVED the check: absence became silence,
# which is the worst possible behaviour from a monitor watching for absence. HQ's backup history was
# gone for nearly two hours before a deploy happened to trip over it (docs/58).
#
# A missing HQ backup root is now an alert in its own right, separate from staleness, because the two
# need different responses: stale means the job is failing, missing means the mount is dead and the
# container needs recreating.
HQ_BACKUP_ROOT=/root/backups/bordeguna-hq
if [ ! -d "$HQ_BACKUP_ROOT" ]; then
    check backup_hq_root 1 "HQ backup directory ${HQ_BACKUP_ROOT} DOES NOT EXIST. The sidecar's bind mount is dead and every dump is failing. Fix: mkdir -p ${HQ_BACKUP_ROOT}/daily ${HQ_BACKUP_ROOT}/weekly && cd /opt/bordeguna-hq && docker compose -f deploy/docker-compose.yml --env-file deploy/.env up -d --force-recreate --no-deps backup"
else
    check backup_hq_root 0 "present"
fi

# Backups fresh?
for pair in "hq:/root/backups/bordeguna-hq/LAST_RUN" "erp:/root/backups/bordeguna-erp/daily"; do
    n=${pair%%:*}; p=${pair#*:}
    if [ -e "$p" ]; then
        age_h=$(( ( $(date +%s) - $(stat -c %Y "$(ls -t "$p"/* 2>/dev/null | head -1 || echo "$p")") ) / 3600 ))
        check "backup_$n" $([ "$age_h" -gt 30 ] && echo 1 || echo 0) "${n} backup is ${age_h}h old (max 30h)"
    elif [ "$n" = "hq" ]; then
        # ⚠ Missing is not "nothing to report". For HQ it is the strongest thing this monitor can say.
        check "backup_$n" 1 "HQ backup marker ${p} is MISSING: no dump has ever completed here, or the directory was replaced. Look: ls -la ${HQ_BACKUP_ROOT}"
    fi
done

# Daily heartbeat at ~08:00 UTC (14:00 Dhaka) so silence is distinguishable from a dead monitor.
HB="$STATE_DIR/heartbeat.$(date -u +%F)"
if [ "$(date -u +%H)" = "08" ] && [ ! -f "$HB" ]; then
    tg "✅ ${HOST} daily: RAM avail ${AVAIL_MB}MB, swap ${SWAP_PCT}%, disk ${DISK_PCT}%, load ${LOAD}, HQ ${overall:-n/a}, ERP ${ERP:-n/a}"
    touch "$HB"; find "$STATE_DIR" -name 'heartbeat.*' -mtime +2 -delete
fi
