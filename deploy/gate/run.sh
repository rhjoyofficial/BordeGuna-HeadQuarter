#!/bin/bash
# The safety gate, run end to end on the VPS (docs/66 §E, the same sequence gates 1 to 3 used by hand):
#
#   cd /opt/bordeguna-hq && GATE=gate-4 nohup ./deploy/gate/run.sh > /dev/null 2>&1 &
#   until grep -q "== gate end" /tmp/gate-4.log; do sleep 30; done; cat /tmp/gate-4.log
#
#   0. posture before, from the database
#   1. corpus seeded, so every case in the release exists on production
#   2. dry probe (credential configured, breaker closed, budget)
#   3. the api.anthropic.com BYPASS TEST with the vault's key - stops here if it is not HTTP 200
#   4. ai.runtime ON, executive ACTIVE, both audited; live probe
#   5. hq:agent:evaluate executive
#   6. executive PAUSED, ai.runtime OFF, both audited; posture after
#
# ⛔ Step 6 runs from an EXIT trap the moment step 4 has happened, so a crash or a Ctrl-C between them still leaves
# production inert. Nothing here creates an autonomy grant or starts a shadow period: activation is a decision a
# person makes after reading the result, never a side effect of running the gate.
set -uo pipefail
cd "$(dirname "$0")/../.."
GATE=${GATE:-gate-4}
GATE_NAME=${GATE_NAME:-"Gate 4 (docs/66)"}
LOG=/tmp/${GATE}.log
C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
T() { $C exec -T -e GATE_ACTION="$1" -e GATE_NAME="$GATE_NAME" app php artisan tinker --execute="require '/tmp/gate-toggle.php';"; }

# One runner at a time. Two gates on the same agent interleave one log, and the first one's EXIT trap turns the
# runtime OFF under the second one's cases (13 Sep 2026, runs 8 to 11). A second launch refuses here, in one line.
LOCK=/tmp/${GATE}.lock
exec 9>"$LOCK"
flock -n 9 || { echo "another $GATE runner already holds $LOCK; refusing to start a second one"; exit 2; }

exec > >(tee -a "$LOG") 2>&1
echo "== gate start $(date -u +%FT%TZ) prod $(git rev-parse --short HEAD) ($GATE)"
$C cp deploy/gate/toggle.php app:/tmp/gate-toggle.php

echo "== 0. posture before"; T posture

echo "== 1. seed corpus"
$C exec -T app php artisan db:seed --class='Database\Seeders\SafetyCorpusSeeder' --force
$C exec -T app php artisan db:seed --class='Database\Seeders\ExecutiveBehaviourSuiteSeeder' --force
T posture | grep -A3 '"cases"'

echo "== 2. dry probe"; $C exec -T app php artisan hq:ai:probe

echo "== 3. bypass test"
BYPASS=$(T bypass | tail -1); echo "$BYPASS"
echo "$BYPASS" | grep -q '"http":200' || { echo "bypass test did not return 200; stopping before anything is enabled"; echo "== gate end $(date -u +%FT%TZ) ABORTED"; exit 1; }

echo "== 4. runtime ON + executive active"; T on
restore() { echo "== 6. runtime OFF + executive paused"; T off; echo "== gate end $(date -u +%FT%TZ)"; }
trap restore EXIT

echo "== 4b. live probe"; $C exec -T app php artisan hq:ai:probe --live

echo "== 5. evaluate"
$C exec -T app php artisan hq:agent:evaluate executive --triggered-by="$GATE"
echo "EVALUATE_EXIT=$?"
