#!/bin/bash
# Guard coverage for deploy/rehearsal (docs/128, ruling docs/120 D.1 and D.2).
#
#   bash deploy/rehearsal/tests/rehearsal-guard-test.sh
#
# Proves that the REAL rehearse.sh refuses, BEFORE any docker call and without writing a file, every
# input that could point it at production: the production or staging project name, the `hq` and
# `postgres` database names, the production DB_DATABASE, a root inside /opt/bordeguna-hq or inside the
# production backup directory, a relative root, `/`, a missing or empty dump, a dump inside the root, a
# non-hash candidate, a bad slug, an unknown argument, a project whose containers already exist and a
# root that already exists. `--plan` is proven to call nothing and write nothing. make-compose.sh is
# proven to refuse the production project name and backup directory and to rewrite the REAL
# deploy/docker-compose.yml into a file that names neither hq.bordeguna.com nor /root/backups.
#
# `docker` and `flock` are stubs first on PATH: every call is appended to a trace file, and a refusal
# test asserts the trace is EMPTY. Nothing here needs Docker, a database or the network.
set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
REHEARSAL_DIR=$(cd "${HERE}/.." && pwd)
REPO_ROOT=$(cd "${REHEARSAL_DIR}/../.." && pwd)
REHEARSE="${REHEARSAL_DIR}/rehearse.sh"
MAKE_COMPOSE="${REHEARSAL_DIR}/make-compose.sh"
PASS=0; FAIL=0

ok()  { PASS=$((PASS+1)); echo "  ok   $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }

WORLD=$(mktemp -d)
trap 'rm -rf "$WORLD"' EXIT
mkdir -p "${WORLD}/bin" "${WORLD}/dumps"
export STUB_TRACE="${WORLD}/docker-trace"
: > "$STUB_TRACE"

# The docker stub: records every call; `docker ps -a ... -q` answers with the ids in $STUB_PS_IDS.
cat > "${WORLD}/bin/docker" <<'STUB'
#!/bin/bash
echo "docker $*" >> "${STUB_TRACE}"
case "$*" in
    "compose version"*) echo "Docker Compose version v2.0.0-stub"; exit 0 ;;
    "ps -a"*) [ -n "${STUB_PS_IDS:-}" ] && printf '%s\n' ${STUB_PS_IDS}; exit 0 ;;
esac
exit 0
STUB
cat > "${WORLD}/bin/flock" <<'STUB'
#!/bin/bash
echo "flock $*" >> "${STUB_TRACE}"
exit 0
STUB
chmod +x "${WORLD}/bin/docker" "${WORLD}/bin/flock"
export PATH="${WORLD}/bin:${PATH}"

# Production paths point into the world so "inside production" is testable without /opt existing.
# The defaults in rehearse.sh are the real ones; these overrides keep the same SHAPE.
export PRODUCTION_ROOT="${WORLD}/opt/bordeguna-hq"
export PRODUCTION_BACKUP_DIR="${WORLD}/root/backups/bordeguna-hq"
export PRODUCTION_ENV_FILE="${PRODUCTION_ROOT}/deploy/.env"
mkdir -p "${PRODUCTION_ROOT}/deploy" "$PRODUCTION_BACKUP_DIR"
printf 'APP_ENV=production\nDB_DATABASE=hq_rehearsal_prodname\n' > "$PRODUCTION_ENV_FILE"

DUMP="${WORLD}/dumps/good.dump"; printf 'PGDMP-stub' > "$DUMP"
EMPTY_DUMP="${WORLD}/dumps/empty.dump"; : > "$EMPTY_DUMP"
CAND=0718c33
ROOT_OK="${WORLD}/scratch/run1"

files_in_world() { find "$WORLD" -type f ! -name docker-trace | sort; }
BASELINE=$(files_in_world)

# refuses <label> <expected message fragment> <args...>
refuses() {
    local label=$1 want=$2; shift 2
    : > "$STUB_TRACE"
    local out rc
    out=$(bash "$REHEARSE" "$@" 2>&1); rc=$?
    if [ "$rc" = "0" ]; then bad "${label}: exited 0, expected a refusal"; return; fi
    if ! grep -q 'REFUSED' <<<"$out"; then bad "${label}: no REFUSED line (got: $(head -2 <<<"$out" | tr '\n' ' '))"; return; fi
    if ! grep -qF -- "$want" <<<"$out"; then bad "${label}: refusal does not say '${want}' (got: $(grep REFUSED <<<"$out" | head -1))"; return; fi
    if [ -s "$STUB_TRACE" ]; then bad "${label}: docker or flock was called before the refusal: $(head -1 "$STUB_TRACE")"; return; fi
    if [ "$(files_in_world)" != "$BASELINE" ]; then bad "${label}: a file was written before the refusal"; return; fi
    ok "${label}"
}

echo "rehearse.sh refusals (before any docker call, nothing written)"
refuses "missing --dump" "--dump is required" --candidate "$CAND"
refuses "missing --candidate" "--candidate is required" --dump "$DUMP"
refuses "production project name bordeguna-hq" "is the PRODUCTION project" --dump "$DUMP" --candidate "$CAND" --project bordeguna-hq
refuses "staging project name" "is the staging project" --dump "$DUMP" --candidate "$CAND" --project bordeguna-hq-staging
refuses "project not prefixed bordeguna-hq-rehearsal" "must be bordeguna-hq-rehearsal" --dump "$DUMP" --candidate "$CAND" --project myproject
refuses "project prefix lookalike bordeguna-hq-rehearsalx" "must be bordeguna-hq-rehearsal" --dump "$DUMP" --candidate "$CAND" --project bordeguna-hq-rehearsalx
refuses "database hq" "the database name must be hq_rehearsal" --dump "$DUMP" --candidate "$CAND" --db hq
refuses "database postgres" "the database name must be hq_rehearsal" --dump "$DUMP" --candidate "$CAND" --db postgres
refuses "database not prefixed hq_rehearsal" "the database name must be hq_rehearsal" --dump "$DUMP" --candidate "$CAND" --db hq_test
refuses "database equal to production DB_DATABASE" "equals the production database name" --dump "$DUMP" --candidate "$CAND" --db hq_rehearsal_prodname
refuses "root is the production checkout" "is the production checkout or inside it" --dump "$DUMP" --candidate "$CAND" --root "$PRODUCTION_ROOT"
refuses "root inside the production checkout" "is the production checkout or inside it" --dump "$DUMP" --candidate "$CAND" --root "${PRODUCTION_ROOT}/rehearsal"
refuses "root inside the production checkout (trailing slash)" "is the production checkout or inside it" --dump "$DUMP" --candidate "$CAND" --root "${PRODUCTION_ROOT}/"
refuses "root inside the production backup directory" "production backup directory" --dump "$DUMP" --candidate "$CAND" --root "${PRODUCTION_BACKUP_DIR}/daily"
refuses "relative root" "--root must be an absolute path" --dump "$DUMP" --candidate "$CAND" --root scratch/run
refuses "root /" "the root cannot be /" --dump "$DUMP" --candidate "$CAND" --root /
refuses "dump does not exist" "does not exist or is not a regular file" --dump "${WORLD}/dumps/missing.dump" --candidate "$CAND" --root "$ROOT_OK"
refuses "dump is empty" "is empty" --dump "$EMPTY_DUMP" --candidate "$CAND" --root "$ROOT_OK"
refuses "dump inside the rehearsal root" "must not live inside the rehearsal root" --dump "$DUMP" --candidate "$CAND" --root "${WORLD}/dumps"
refuses "candidate is not a hash" "must be a commit hash" --dump "$DUMP" --candidate "main;rm" --root "$ROOT_OK"
refuses "candidate too short" "must be a commit hash" --dump "$DUMP" --candidate abc --root "$ROOT_OK"
refuses "slug with a quote under --seed-corruption" "a slug must be lower-case" --dump "$DUMP" --candidate "$CAND" --root "$ROOT_OK" --seed-corruption --bionic-store-slug "x';DROP"
refuses "unknown argument" "unknown argument '--force'" --dump "$DUMP" --candidate "$CAND" --force

# The SHIPPED defaults, not the world overrides: /opt/bordeguna-hq and /root/backups/bordeguna-hq.
default_refuses() {
    local label=$1 want=$2 root=$3 out rc
    : > "$STUB_TRACE"
    out=$(env -u PRODUCTION_ROOT -u PRODUCTION_BACKUP_DIR -u PRODUCTION_ENV_FILE bash "$REHEARSE" --dump "$DUMP" --candidate "$CAND" --root "$root" 2>&1); rc=$?
    if [ "$rc" != "0" ] && grep -qF "$want" <<<"$out" && [ ! -s "$STUB_TRACE" ]; then ok "$label"; else bad "${label}: rc=${rc} out=$(grep REFUSED <<<"$out")"; fi
}
default_refuses "shipped default: root /opt/bordeguna-hq/rehearsal" "is the production checkout or inside it" /opt/bordeguna-hq/rehearsal
default_refuses "shipped default: root /root/backups/bordeguna-hq/x" "production backup directory" /root/backups/bordeguna-hq/x

echo "rehearse.sh refusals after the read-only docker probes (no container started, nothing written)"
: > "$STUB_TRACE"
out=$(STUB_PS_IDS="abc123" bash "$REHEARSE" --dump "$DUMP" --candidate "$CAND" --root "$ROOT_OK" 2>&1); rc=$?
if [ "$rc" != "0" ] && grep -q "containers of project 'bordeguna-hq-rehearsal' already exist" <<<"$out" \
    && ! grep -qE 'docker (compose .*(up|build|exec)|run|build)' "$STUB_TRACE" && [ ! -e "$ROOT_OK" ]; then
    ok "a project whose containers already exist is refused; only 'compose version' and 'ps' were called; no root created"
else
    bad "existing containers: rc=${rc}, trace=$(tr '\n' '|' < "$STUB_TRACE"), out=$(grep REFUSED <<<"$out")"
fi
mkdir -p "${WORLD}/scratch/exists"
BASELINE=$(files_in_world)
: > "$STUB_TRACE"
out=$(bash "$REHEARSE" --dump "$DUMP" --candidate "$CAND" --root "${WORLD}/scratch/exists" 2>&1); rc=$?
if [ "$rc" != "0" ] && grep -q "already exists; choose another --root" <<<"$out" && ! grep -qE 'compose .*(up|build|exec)' "$STUB_TRACE" && [ "$(files_in_world)" = "$BASELINE" ]; then
    ok "an existing root is refused and left untouched"
else
    bad "existing root: rc=${rc}, out=$(grep REFUSED <<<"$out")"
fi

echo "rehearse.sh --plan"
: > "$STUB_TRACE"
out=$(bash "$REHEARSE" --dump "$DUMP" --candidate "$CAND" --root "$ROOT_OK" --seed-corruption --plan 2>&1); rc=$?
if [ "$rc" = "0" ] && grep -q 'rehearsal plan (nothing has run)' <<<"$out" && [ ! -s "$STUB_TRACE" ] && [ ! -e "$ROOT_OK" ] && [ "$(files_in_world)" = "$BASELINE" ]; then
    ok "--plan prints the plan, calls no docker, creates no root, writes no file"
else
    bad "--plan: rc=${rc}, trace=$(tr '\n' '|' < "$STUB_TRACE")"
fi
grep -q 'project         bordeguna-hq-rehearsal ' <<<"$out" && grep -q 'database        hq_rehearsal ' <<<"$out" \
    && ok "--plan defaults: project bordeguna-hq-rehearsal, database hq_rehearsal" || bad "--plan defaults not as documented"
grep -q "never           the production project" <<<"$out" && ok "--plan states what is never touched" || bad "--plan does not state the never-list"

echo "make-compose.sh"
SRC="${REPO_ROOT}/deploy/docker-compose.yml"
DEST="${WORLD}/mc/docker-compose.yml"
for pair in "bordeguna-hq|${WORLD}/mc/backups|refusing the project name 'bordeguna-hq'" \
            "bordeguna-hq-staging|${WORLD}/mc/backups|refusing the project name 'bordeguna-hq-staging'" \
            "bordeguna-hq-rehearsal|/root/backups/bordeguna-hq|refusing the production backup directory" \
            "bordeguna-hq-rehearsal|/root/backups/bordeguna-hq/daily|refusing the production backup directory" \
            "bordeguna-hq-rehearsal|relative/backups|must be absolute"; do
    IFS='|' read -r p b want <<<"$pair"
    out=$(bash "$MAKE_COMPOSE" "$SRC" "$DEST" "$p" "$b" 2>&1); rc=$?
    if [ "$rc" != "0" ] && grep -qF "$want" <<<"$out" && [ ! -e "$DEST" ]; then ok "make-compose refuses project '${p}' / backup dir '${b}'"; else bad "make-compose project '${p}' / '${b}': rc=${rc} out=${out}"; fi
done
printf 'name: something-else\nservices: {}\n' > "${WORLD}/mc-unknown.yml"
out=$(bash "$MAKE_COMPOSE" "${WORLD}/mc-unknown.yml" "$DEST" bordeguna-hq-rehearsal "${WORLD}/mc/backups" 2>&1); rc=$?
[ "$rc" = "67" ] && [ ! -e "$DEST" ] && ok "make-compose refuses a compose file of an unknown shape (exit 67), writes nothing" || bad "unknown shape: rc=${rc} out=${out}"
out=$(bash "$MAKE_COMPOSE" "$SRC" "$DEST" bordeguna-hq-rehearsal "${WORLD}/mc/backups" 2>&1); rc=$?
if [ "$rc" = "0" ] && [ -f "$DEST" ]; then
    ok "make-compose rewrites the REAL deploy/docker-compose.yml"
    ! grep -q 'hq\.bordeguna\.com' "$DEST" && ok "  the rewrite names no hq.bordeguna.com" || bad "  the rewrite still names hq.bordeguna.com"
    ! grep -q '/root/backups' "$DEST" && ok "  the rewrite mounts nothing under /root/backups" || bad "  the rewrite still mounts /root/backups"
    grep -qE '^name:[[:space:]]*bordeguna-hq-rehearsal[[:space:]]*$' "$DEST" && ok "  the rewrite is project bordeguna-hq-rehearsal" || bad "  project name not rewritten"
    ! grep -qE 'image:[[:space:]]*bordeguna-hq(-backup)?:latest' "$DEST" && ok "  the rewrite tags no production image" || bad "  a production image tag survived"
    [ "$(grep -cE '^[[:space:]]*-[[:space:]]*traefik\.' "$DEST")" = "1" ] && grep -q 'traefik.enable=false' "$DEST" && ok "  the only Traefik label is traefik.enable=false" || bad "  Traefik labels survived"
    grep -q 'RUN_MIGRATIONS' "$DEST" && ok "  the candidate's own RUN_MIGRATIONS wiring is kept" || bad "  RUN_MIGRATIONS lost in the rewrite"
else
    bad "make-compose on the real compose file: rc=${rc} out=${out}"
fi

echo "local-sql-rehearsal.sh refusals (before any database or file is touched)"
LOCAL="${REHEARSAL_DIR}/local-sql-rehearsal.sh"
local_refuses() {
    local label=$1 want=$2 out rc; shift 2
    BASELINE=$(files_in_world)
    out=$(bash "$LOCAL" "$@" 2>&1); rc=$?
    if [ "$rc" != "0" ] && grep -q REFUSED <<<"$out" && grep -qF -- "$want" <<<"$out" && [ "$(files_in_world)" = "$BASELINE" ]; then ok "$label"; else bad "${label}: rc=${rc} out=$(head -1 <<<"$out")"; fi
}
local_refuses "local: database hq" "must be hq_rehearsal_local" --db hq --work "${WORLD}/lw1" --vendor-from "$REPO_ROOT/apps/hq"
local_refuses "local: database hq_rehearsal (the VPS name, not a local one)" "must be hq_rehearsal_local" --db hq_rehearsal --work "${WORLD}/lw1" --vendor-from "$REPO_ROOT/apps/hq"
local_refuses "local: a remote host" "local only" --pg-host 200.97.162.40 --work "${WORLD}/lw1" --vendor-from "$REPO_ROOT/apps/hq"
local_refuses "local: an existing work directory" "already exists" --work "${WORLD}/scratch/exists" --vendor-from "$REPO_ROOT/apps/hq"
local_refuses "local: an unknown argument" "unknown argument '--apply'" --apply

echo
echo "rehearsal guard suite: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" = "0" ]
