#!/bin/bash
# The safety gate, run end to end on the VPS (docs/66 §E, the same sequence gates 1 to 3 used by hand):
#
#   cd /opt/bordeguna-hq && GATE=gate-4 nohup ./deploy/gate/run.sh > /dev/null 2>&1 &
#   until grep -q "== gate end" /tmp/gate-4.log; do sleep 30; done; cat /tmp/gate-4.log
#
# GATE_AGENT (default executive) names the agent measured. P3-G2 (docs/77 C.4) is the same runner with
# GATE_AGENT=support: the support suite is seeded too, and `on` refuses an agent that names no enabling user.
#
#   0a. refuse if an A0 shadow period is open (docs/77 C.2): this runner's exit trap would end it unrecorded
#   0. posture before, from the database
#   1. corpus seeded, so every case in the release exists on production
#   2. dry probe (credential configured, breaker closed, budget)
#   3. the model provider's BYPASS TEST with the vault's key, on the provider that serves (docs/81 C.7) - stops
#      here if it is not HTTP 200, and when more than one provider serves
#   4. ai.runtime ON, the gate's agent ACTIVE, both audited (refused when it names no enabling user); live probe;
#      a structured-reply probe under the agent schema, which stops the run before any case if it fails
#   5. hq:agent:evaluate $GATE_AGENT
#   6. the agent PAUSED, ai.runtime OFF, both audited; posture after
#
# ⛔ Step 6 runs from an EXIT trap the moment step 4 has happened, so a crash or a Ctrl-C between them still leaves
# production inert. Nothing here creates an autonomy grant or starts a shadow period: activation is a decision a
# person makes after reading the result, never a side effect of running the gate.
set -uo pipefail
cd "$(dirname "$0")/../.."
GATE=${GATE:-gate-4}
GATE_NAME=${GATE_NAME:-"Gate 4 (docs/66)"}
GATE_AGENT=${GATE_AGENT:-executive}
LOG=/tmp/${GATE}.log
C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
T() { $C exec -T -e GATE_ACTION="$1" -e GATE_NAME="$GATE_NAME" -e GATE_AGENT="$GATE_AGENT" app php artisan tinker --execute="require '/tmp/gate-toggle.php';" < /dev/null; }

# One runner at a time. Two gates on the same agent interleave one log, and the first one's EXIT trap turns the
# runtime OFF under the second one's cases (13 Sep 2026, runs 8 to 11). A second launch refuses here, in one line.
LOCK=/tmp/${GATE}.lock
exec 9>"$LOCK"
flock -n 9 || { echo "another $GATE runner already holds $LOCK; refusing to start a second one"; exit 2; }

exec > >(tee -a "$LOG") 2>&1
echo "== gate start $(date -u +%FT%TZ) prod $(git rev-parse --short HEAD) ($GATE, agent $GATE_AGENT)"
$C cp deploy/gate/toggle.php app:/tmp/gate-toggle.php

echo "== 0a. shadow periods"
SHADOW=$(T shadow | tail -1); echo "$SHADOW"
echo "$SHADOW" | grep -q '"open_shadow_periods":\[\]' || { echo "an A0 shadow period is open (or the check failed); a gate would end it unrecorded. Stopping before anything is touched."; echo "== gate end $(date -u +%FT%TZ) ABORTED"; exit 1; }

echo "== 0. posture before"; T posture

# Readiness under which the run is measured (ADR-043): which stores and ERP businesses HQ could
# execute against, and which credentials are missing, rejected or unverified. No secrets. A
# non-zero exit here is reported, not fatal: a broken shop token is readiness evidence for the
# record, and the gate measures behaviour whatever the shops answer.
echo "== 0b. readiness before"; $C exec -T app php artisan hq:credentials:health || echo "readiness: incidents present (see rows above)"

echo "== 1. seed corpus"
$C exec -T app php artisan db:seed --class='Database\Seeders\SafetyCorpusSeeder' --force
$C exec -T app php artisan db:seed --class='Database\Seeders\ExecutiveBehaviourSuiteSeeder' --force
# docs/86: the role-boundary regression controls (Gate 12). Scoped to the executive role, reported on their own line.
$C exec -T app php artisan db:seed --class='Database\Seeders\RoleBoundaryRegressionSuiteSeeder' --force
# docs/88 point 16: the Gate 13 controls (role expansion, Bangla and Banglish no-task, negative controls, mixed requests,
# the pre- and post-model boundaries). Executive-scoped like the one above, each suite on its own line; P3-G2 never takes it.
$C exec -T app php artisan db:seed --class='Database\Seeders\RuntimeBoundariesRegressionSuiteSeeder' --force
# docs/90 A.2: the Executive Execution-Integrity Check (the argument path), its own suite and its own line;
# run alone with GATE=exec-integrity GATE_SUITE=regression.execution_integrity. P3-G2 never takes it.
$C exec -T app php artisan db:seed --class='Database\Seeders\ExecutionIntegritySuiteSeeder' --force
if [ "$GATE_AGENT" = support ]; then
    $C exec -T app php artisan db:seed --class='Database\Seeders\SupportBehaviourSuiteSeeder' --force
fi
T posture | grep -A5 '"cases"'

echo "== 2. dry probe"; $C exec -T app php artisan hq:ai:probe

echo "== 3. bypass test"
BYPASS=$(T bypass | tail -1); echo "$BYPASS"
echo "$BYPASS" | grep -q '"http":200' || { echo "bypass test did not return 200; stopping before anything is enabled"; echo "== gate end $(date -u +%FT%TZ) ABORTED"; exit 1; }

echo "== 4. runtime ON + $GATE_AGENT active"
ON=$(T on); echo "$ON"
echo "$ON" | grep -q '^REFUSED' && { echo "the switch refused; nothing was enabled."; echo "== gate end $(date -u +%FT%TZ) ABORTED"; exit 1; }
restore() { echo "== 6. runtime OFF + $GATE_AGENT paused"; T off; echo "== gate end $(date -u +%FT%TZ)"; }
trap restore EXIT

echo "== 4b. live probe"; $C exec -T app php artisan hq:ai:probe --live

# docs/81 C.7: one reply under the agent response schema, on the tier the agent's turns use, before any
# case runs. A provider that refuses HQ's schema would otherwise turn every case into a provider error;
# stopping here leaves no measurement at all, and the EXIT trap turns everything off again.
echo "== 4c. structured-reply probe"
$C exec -T app php artisan hq:ai:probe --live --schema --task=operator_question \
    || { echo "the structured-reply probe failed; stopping before the evaluation, nothing was measured"; exit 1; }

echo "== 5. evaluate"
# GATE_SUITE (docs/90 A.2) runs ONE named suite instead of every suite that applies to the agent's role,
# so a targeted check is its own evidence set and is never mixed into a gate's score.
if [ -n "${GATE_SUITE:-}" ]; then
    $C exec -T app php artisan hq:agent:evaluate "$GATE_AGENT" --suite="$GATE_SUITE" --triggered-by="$GATE"
else
    $C exec -T app php artisan hq:agent:evaluate "$GATE_AGENT" --triggered-by="$GATE"
fi
echo "EVALUATE_EXIT=$?"
