#!/bin/bash
# Rewrite the candidate's deploy/docker-compose.yml for an ISOLATED rehearsal project (docs/128, ruling docs/120 D.1, D.2).
#
#   bash deploy/rehearsal/make-compose.sh <source compose> <destination> <project name> <host backup dir>
#
# The production compose file binds four things to production that a rehearsal must never share:
#   1. the project name `bordeguna-hq` (container, network and volume names);
#   2. the image tags `bordeguna-hq:latest` and `bordeguna-hq-backup:latest` (a rehearsal build would retag
#      the image a hand-typed `docker compose up -d` on production then picks up);
#   3. the Traefik labels on `web` (Traefik watches EVERY container on the host: a second container with
#      `Host(hq.bordeguna.com)` would receive real production traffic);
#   4. the host bind mount `/root/backups/bordeguna-hq` on `backup` (the production dump history).
# This script replaces each of them and then REFUSES to write a file in which any of the four survives, so a
# compose file that could touch production never reaches `docker compose`. Everything else (the Dockerfile,
# the entrypoint, RUN_MIGRATIONS on app only, the health checks, the memory limits) is the candidate's own.
set -euo pipefail

SRC=${1:-}; DEST=${2:-}; PROJECT=${3:-}; BACKUP_DIR=${4:-}
[ -n "$SRC" ] && [ -n "$DEST" ] && [ -n "$PROJECT" ] && [ -n "$BACKUP_DIR" ] \
    || { echo "usage: make-compose.sh <source compose> <destination> <project name> <host backup dir>" >&2; exit 64; }
[ -f "$SRC" ] || { echo "make-compose: source compose file '$SRC' does not exist" >&2; exit 65; }
case "$PROJECT" in
    bordeguna-hq|bordeguna-hq-staging) echo "make-compose: refusing the project name '$PROJECT' (production or staging)" >&2; exit 66 ;;
esac
case "$BACKUP_DIR" in
    /root/backups/bordeguna-hq|/root/backups/bordeguna-hq/*) echo "make-compose: refusing the production backup directory as the rehearsal backup directory" >&2; exit 66 ;;
    /*) ;;
    *) echo "make-compose: the host backup dir must be absolute, got '$BACKUP_DIR'" >&2; exit 64 ;;
esac

# The source must be the shape this script knows how to rewrite: exactly one top-level `name:` line, the two
# image tags, the Traefik labels and the one bind mount. A different shape is refused rather than half-rewritten.
count() { grep -cE "$1" "$SRC" || true; }
[ "$(count '^name:[[:space:]]*bordeguna-hq[[:space:]]*$')" = "1" ] || { echo "make-compose: expected exactly one 'name: bordeguna-hq' line in $SRC" >&2; exit 67; }
[ "$(count '^[[:space:]]*image:[[:space:]]*bordeguna-hq:latest[[:space:]]*$')" = "1" ] || { echo "make-compose: expected exactly one 'image: bordeguna-hq:latest' in $SRC" >&2; exit 67; }
[ "$(count '^[[:space:]]*image:[[:space:]]*bordeguna-hq-backup:latest[[:space:]]*$')" = "1" ] || { echo "make-compose: expected exactly one 'image: bordeguna-hq-backup:latest' in $SRC" >&2; exit 67; }
[ "$(count '^[[:space:]]*-[[:space:]]*/root/backups/bordeguna-hq:/backups[[:space:]]*$')" = "1" ] || { echo "make-compose: expected exactly one '/root/backups/bordeguna-hq:/backups' mount in $SRC" >&2; exit 67; }
[ "$(count '^[[:space:]]*-[[:space:]]*traefik\.')" -ge 1 ] || { echo "make-compose: expected Traefik labels in $SRC" >&2; exit 67; }

TMP=$(mktemp "${TMPDIR:-/tmp}/hq-rehearsal-compose.XXXXXX")
trap 'rm -f "$TMP"' EXIT

# One pass, line by line. The first Traefik label becomes `traefik.enable=false` (so `labels:` keeps a
# list and Traefik is told, explicitly, to ignore the container); every other Traefik label is dropped.
TRAEFIK_SEEN=0
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
        name:*bordeguna-hq*)
            printf 'name: %s\n' "$PROJECT" ;;
        *image:*bordeguna-hq:latest*)
            printf '%s\n' "${line/bordeguna-hq:latest/${PROJECT}:latest}" ;;
        *image:*bordeguna-hq-backup:latest*)
            printf '%s\n' "${line/bordeguna-hq-backup:latest/${PROJECT}-backup:latest}" ;;
        *-\ /root/backups/bordeguna-hq:/backups*)
            printf '%s\n' "${line//\/root\/backups\/bordeguna-hq/${BACKUP_DIR}}" ;;
        *'- traefik.'*)
            if [ "$TRAEFIK_SEEN" = "0" ]; then
                TRAEFIK_SEEN=1
                indent=${line%%-*}
                printf '%s- traefik.enable=false\n' "$indent"
            fi ;;
        *)
            printf '%s\n' "$line" ;;
    esac
done < "$SRC" > "$TMP"

# ⛔ The refusals. A file that still names production is not written anywhere.
refuse() { echo "make-compose: REFUSING to write the rehearsal compose file: $1" >&2; exit 68; }
grep -q 'hq\.bordeguna\.com' "$TMP" && refuse "it still names hq.bordeguna.com"
grep -q '/root/backups' "$TMP" && refuse "it still mounts a directory under /root/backups"
grep -qE 'bordeguna-hq(-backup)?:latest' "$TMP" && refuse "it still tags the production image"
grep -qE '^name:[[:space:]]*bordeguna-hq[[:space:]]*$' "$TMP" && refuse "it still names the production project"
[ "$(grep -cE '^[[:space:]]*-[[:space:]]*traefik\.' "$TMP")" = "1" ] || refuse "it carries a Traefik label other than traefik.enable=false"
grep -qE '^[[:space:]]*-[[:space:]]*traefik\.enable=false[[:space:]]*$' "$TMP" || refuse "traefik.enable=false is missing"
grep -qE "^name:[[:space:]]*${PROJECT}[[:space:]]*$" "$TMP" || refuse "the project name was not written"
grep -qE "image:[[:space:]]*${PROJECT}:latest" "$TMP" || refuse "the app image was not retagged"
grep -qE "image:[[:space:]]*${PROJECT}-backup:latest" "$TMP" || refuse "the backup image was not retagged"
grep -qF -- "- ${BACKUP_DIR}:/backups" "$TMP" || refuse "the backup mount was not redirected"

mkdir -p "$(dirname "$DEST")"
cp "$TMP" "$DEST"
echo "make-compose: wrote ${DEST} for project ${PROJECT} (images ${PROJECT}:latest and ${PROJECT}-backup:latest, backups at ${BACKUP_DIR}, Traefik disabled)"
