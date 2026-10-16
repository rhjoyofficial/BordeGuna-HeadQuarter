#!/bin/bash
# Stage 6 structural searches with pass/fail per search (ruling docs/120 section C Stage 6; docs/128 section 6).
#
#   bash deploy/tools/structural-searches.sh              searches on this checkout's apps/hq (php on PATH)
#   bash deploy/tools/structural-searches.sh --with-pins  ... and then the Pest pins they twin (needs vendor/ with
#                                                         dev dependencies and the test database, as in CI)
#   bash deploy/tools/structural-searches.sh --container  searches on the DEPLOYED code inside the running `app`
#                                                         container (production image, no dev dependencies)
#
# READ ONLY in every mode: the PHP script reads source files and prints; --with-pins runs Pest against the TEST
# database (RefreshDatabase on DB_DATABASE from apps/hq/.env or the environment, never production's); --container
# pipes the script into `php` inside the app container with `exec -T` and writes nothing there.
# Exit 0 when everything printed PASS, 1 otherwise.
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
SCRIPT="${REPO}/deploy/tools/structural-searches.php"
MODE=local; PINS=0
for a in "$@"; do
    case "$a" in
        --with-pins) PINS=1 ;;
        --container) MODE=container ;;
        *) echo "structural-searches.sh: unknown argument '$a' (use --with-pins or --container)" >&2; exit 2 ;;
    esac
done

RC=0
if [ "$MODE" = "container" ]; then
    cd "$REPO" || exit 2
    docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php -- --root /var/www/html < "$SCRIPT" || RC=1
else
    php "$SCRIPT" --root "${REPO}/apps/hq" || RC=1
fi

if [ "$PINS" = "1" ]; then
    [ "$MODE" = "local" ] || { echo "--with-pins runs on a checkout, not in the container" >&2; exit 2; }
    echo
    echo "Pest pins twinned by the searches:"
    for pin in \
        "S1|tests/Feature/Identity/IdentityBusinessScopeTest.php|finds no lookup of customer_identities" \
        "S2|tests/Feature/Gateway/GatewayCoverageStructureTest.php|" \
        "S3|tests/Feature/Release/StructuralSearchesTest.php|" \
        "S4|tests/Feature/Playbooks/PlaybookStructureTest.php|"; do
        IFS='|' read -r id file filter <<<"$pin"
        args=("$file"); [ -n "$filter" ] && args+=(--filter "$filter")
        if (cd "${REPO}/apps/hq" && php artisan test "${args[@]}" >/dev/null 2>&1); then
            echo "PASS ${id} pin ${file}${filter:+ (${filter})}"
        else
            echo "FAIL ${id} pin ${file}${filter:+ (${filter})}"; RC=1
        fi
    done
fi

[ "$RC" = "0" ] && echo "structural-searches.sh: all PASS" || echo "structural-searches.sh: FAILED"
exit "$RC"
