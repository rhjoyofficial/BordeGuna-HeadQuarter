#!/bin/bash
# LOCAL, SQL-level migration rehearsal (docs/128 section 9; ruling docs/120 D.1). No Docker, no dump, no production.
#
#   bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from <apps/hq with vendor/> --work <new dir> \
#        [--scenario corrupted|clean] [--production-commit b96a396] [--candidate HEAD] [--db hq_rehearsal_local_<x>] \
#        [--pg-bin <dir with psql, createdb, dropdb, pg_dump, pg_restore>] [--pg-host 127.0.0.1] [--pg-port 5432] \
#        [--pg-user postgres] [--keep]
#
# It is the part of rehearse.sh that a development PC can run: a THROWAWAY local PostgreSQL database is brought to
# the production schema by running the production commit's own migrations (exported with `git archive`, no
# worktree), seeded with realistic clean rows (sql/seed-realistic.sql) and, in the `corrupted` scenario, the
# docs/121 section 5 shape (sql/seed-corruption.sql); then the CANDIDATE's migrations run, the permission seeder
# runs with --class, the constraints and the quarantine are read, the dry runs are proven to write nothing,
# `migrate:rollback --step=1` is attempted one migration at a time until a down() refuses or the pre-release
# schema is reached, a pre-migration pg_dump is restored into a second throwaway database and compared, and the
# candidate is re-applied. Evidence (counts and verdicts only) lands in <work>/evidence-<scenario>.
#
# What it is NOT: it does not build the image, run deploy.sh, restore a production dump or test the compose
# isolation. That is rehearse.sh, on the VPS, with a production dump and Joy's go.
#
# REFUSES: a database name not matching hq_rehearsal_local[_suffix]; a database that already exists; a host other
# than 127.0.0.1 or localhost; a work directory that already exists; vendor/ from a composer.lock that differs from
# either commit's; an unknown argument.
set -euo pipefail

SELF_DIR=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "${SELF_DIR}/../.." && pwd)
PROD_COMMIT=b96a396; CANDIDATE=HEAD; DB=""; WORK=""; VENDOR_FROM=""; SCENARIO=corrupted
PG_BIN=""; PG_HOST=127.0.0.1; PG_PORT=5432; PG_USER=postgres; KEEP=0

log() { echo "[local-rehearsal $(date -u +%FT%TZ)] $*"; }
die() { log "REFUSED: $*"; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --production-commit) PROD_COMMIT=${2:-}; shift 2 ;;
        --candidate) CANDIDATE=${2:-}; shift 2 ;;
        --db) DB=${2:-}; shift 2 ;;
        --work) WORK=${2:-}; shift 2 ;;
        --vendor-from) VENDOR_FROM=${2:-}; shift 2 ;;
        --scenario) SCENARIO=${2:-}; shift 2 ;;
        --pg-bin) PG_BIN=${2:-}; shift 2 ;;
        --pg-host) PG_HOST=${2:-}; shift 2 ;;
        --pg-port) PG_PORT=${2:-}; shift 2 ;;
        --pg-user) PG_USER=${2:-}; shift 2 ;;
        --keep) KEEP=1; shift ;;
        *) die "unknown argument '$1'" ;;
    esac
done

[ -n "$DB" ] || DB="hq_rehearsal_local_${SCENARIO}"
[[ "$DB" =~ ^hq_rehearsal_local(_[a-z0-9]+)?$ ]] || die "the database name must be hq_rehearsal_local or hq_rehearsal_local_<suffix>, got '$DB'"
[ "$SCENARIO" = "corrupted" ] || [ "$SCENARIO" = "clean" ] || die "--scenario must be corrupted or clean"
[ "$PG_HOST" = "127.0.0.1" ] || [ "$PG_HOST" = "localhost" ] || die "this rehearsal is local only; --pg-host must be 127.0.0.1 or localhost"
[ -n "$WORK" ] || die "--work is required (a directory that does not exist yet)"
[ ! -e "$WORK" ] || die "the work directory '$WORK' already exists"
[ -n "$VENDOR_FROM" ] && [ -f "${VENDOR_FROM}/vendor/autoload.php" ] || die "--vendor-from must be an apps/hq directory with vendor/autoload.php"
[ -z "$PG_BIN" ] || export PATH="${PG_BIN}:${PATH}"
for t in psql createdb dropdb pg_dump pg_restore php git tar; do command -v "$t" >/dev/null 2>&1 || die "$t is not on PATH"; done
export PGHOST="$PG_HOST" PGPORT="$PG_PORT" PGUSER="$PG_USER"
EXISTS=$(psql -d postgres -At -c "SELECT count(*) FROM pg_database WHERE datname IN ('${DB}', '${DB}_restorecheck')")
[ "$EXISTS" = "0" ] || die "the database ${DB} or ${DB}_restorecheck already exists"

PROD_FULL=$(git -C "$REPO" rev-parse --verify "${PROD_COMMIT}^{commit}")
CAND_FULL=$(git -C "$REPO" rev-parse --verify "${CANDIDATE}^{commit}")
for c in "$PROD_FULL" "$CAND_FULL"; do
    git -C "$REPO" show "${c}:apps/hq/composer.lock" | cmp -s - "${VENDOR_FROM}/composer.lock" \
        || die "composer.lock of ${c:0:7} differs from ${VENDOR_FROM}/composer.lock; the copied vendor/ would not be that commit's"
done

EVIDENCE="${WORK}/evidence-${SCENARIO}"
mkdir -p "${WORK}/prod" "${WORK}/cand" "$EVIDENCE"
exec > >(tee -a "${EVIDENCE}/rehearsal.log") 2>&1

VERDICTS=(); FAILED=0
verdict() { VERDICTS+=("$1 $2"); log "$1: $2"; }
pass() { verdict PASS "$1"; }
warn() { verdict WARN "$1"; }
fail() { verdict FAIL "$1"; FAILED=1; }

cleanup() {
    local rc=$?
    set +e
    if [ "$KEEP" = "1" ]; then
        log "--keep: databases ${DB} (and ${DB}_restorecheck if made) kept; drop with: dropdb ${DB}; dropdb --if-exists ${DB}_restorecheck"
    else
        dropdb --if-exists "${DB}_restorecheck" >/dev/null 2>&1
        dropdb --if-exists "$DB" >/dev/null 2>&1
        LEFT=$(psql -d postgres -At -c "SELECT count(*) FROM pg_database WHERE datname IN ('${DB}', '${DB}_restorecheck')")
        log "teardown: dropped ${DB} and ${DB}_restorecheck (remaining: ${LEFT})"
        rm -rf "${WORK}/prod" "${WORK}/cand" "${WORK}/pre-migration.dump"
    fi
    echo
    echo "== local rehearsal verdicts (${SCENARIO}) =="
    printf '  %s\n' "${VERDICTS[@]}"
    if [ "$FAILED" = "1" ] || [ "$rc" != "0" ]; then echo "RESULT: local rehearsal FAILED (exit ${rc}). Evidence: ${EVIDENCE}"; exit 1; fi
    echo "RESULT: local rehearsal PASSED. Evidence: ${EVIDENCE}. A local SQL-level rehearsal: no image, no deploy.sh, no production dump."
}
trap cleanup EXIT

# psql on Windows ends lines with CRLF; every capture is normalised so comparisons see the text only.
q() { psql -d "${2:-$DB}" -At -v ON_ERROR_STOP=1 -c "$1" | tr -d '\r'; }
snapshot() { psql -d "$1" -At -v ON_ERROR_STOP=1 -f "${SELF_DIR}/sql/snapshot.sql" | tr -d '\r'; }
posture() {
    psql -d "$DB" -At -v ON_ERROR_STOP=1 \
        -c "SELECT 'flag ' || key || ' ' || environment || ' ' || enabled FROM feature_flags ORDER BY key, environment" \
        -c "SELECT 'agent ' || key || ' ' || status || ' ' || max_autonomy_level FROM agents ORDER BY key" \
        -c "SELECT 'grants_active ' || count(*) FROM autonomy_grants WHERE status = 'active'" \
        -c "SELECT 'shadow_open ' || count(*) FROM shadow_periods WHERE status = 'open'" \
        -c "SELECT 'tools_disabled ' || count(*) FROM tools WHERE enabled = false" \
        -c "SELECT 'scheduled_jobs ' || count(*) || ' paused=' || count(*) FILTER (WHERE state = 'paused') FROM scheduled_jobs" | tr -d '\r'
}
write_env() {
    cat > "$1/.env" <<ENV
APP_NAME=HQ
APP_ENV=production
APP_KEY=base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')
APP_DEBUG=false
APP_URL=http://rehearsal.invalid
LOG_CHANNEL=single
DB_CONNECTION=pgsql
DB_HOST=${PG_HOST}
DB_PORT=${PG_PORT}
DB_DATABASE=${DB}
DB_USERNAME=${PG_USER}
DB_PASSWORD=${PGPASSWORD:-}
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=log
HQ_ERP_BASE_URL=http://erp.invalid
ENV
}

# ── 1. Both trees, exported with git archive (no worktree), one vendor/ moved between them ─────────
log "exporting production ${PROD_FULL:0:7} and candidate ${CAND_FULL:0:7} with git archive"
git -C "$REPO" archive "$PROD_FULL" apps/hq | tar -x -C "${WORK}/prod"
git -C "$REPO" archive "$CAND_FULL" apps/hq | tar -x -C "${WORK}/cand"
P="${WORK}/prod/apps/hq"; C="${WORK}/cand/apps/hq"
cp -r "${VENDOR_FROM}/vendor" "${P}/vendor"
write_env "$P"; write_env "$C"
echo "production ${PROD_FULL} candidate ${CAND_FULL} scenario ${SCENARIO} db ${DB}" > "${EVIDENCE}/00-commits.txt"
PROD_MIG=$(ls "${P}/database/migrations" | grep -c '\.php$'); CAND_MIG=$(ls "${C}/database/migrations" | grep -c '\.php$')
pass "trees exported: production ${PROD_FULL:0:7} has ${PROD_MIG} migrations, candidate ${CAND_FULL:0:7} has ${CAND_MIG}"

# ── 2. The throwaway database at the production schema, seeded ─────────────────────────────────────
createdb "$DB"
(cd "$P" && php artisan migrate --force --no-ansi) > "${EVIDENCE}/01-prod-migrate.txt" 2>&1 || { tail -20 "${EVIDENCE}/01-prod-migrate.txt"; die "production migrations failed"; }
(cd "$P" && php artisan db:seed --force --no-ansi && php artisan db:seed --class='Database\Seeders\AgentSeeder' --force --no-ansi) > "${EVIDENCE}/01-prod-seed.txt" 2>&1 || { tail -20 "${EVIDENCE}/01-prod-seed.txt"; die "production seeders failed"; }
pass "production schema: $(q 'SELECT count(*) FROM migrations') migrations recorded, last $(q 'SELECT migration FROM migrations ORDER BY id DESC LIMIT 1'); seeded with the production commit's DatabaseSeeder and AgentSeeder"
psql -d "$DB" -At -v ON_ERROR_STOP=1 -f "${SELF_DIR}/sql/seed-realistic.sql" > "${EVIDENCE}/02-seed-realistic.txt" 2>&1 || { cat "${EVIDENCE}/02-seed-realistic.txt"; die "seed-realistic.sql failed"; }
pass "realistic rows: $(grep '^seeded_realistic' "${EVIDENCE}/02-seed-realistic.txt" | sed 's/seeded_realistic //' | tr '\n' ',' | sed 's/,$//')"
if [ "$SCENARIO" = "corrupted" ]; then
    LAL_B=$(q "SELECT id FROM businesses WHERE slug = 'laladia'"); BNC_B=$(q "SELECT id FROM businesses WHERE slug = 'bionic'")
    BNC_S=$(q "SELECT id FROM stores WHERE slug = 'bionic-garden'"); LAL_S=$(q "SELECT id FROM stores WHERE slug = 'laladia-com'")
    psql -d "$DB" -At -v ON_ERROR_STOP=1 -v laladia_business_id="$LAL_B" -v bionic_business_id="$BNC_B" -v bionic_store_id="$BNC_S" -v laladia_store_id="$LAL_S" \
        -f "${SELF_DIR}/sql/seed-corruption.sql" > "${EVIDENCE}/03-seeded-corruption.txt" 2>&1 || { cat "${EVIDENCE}/03-seeded-corruption.txt"; die "seed-corruption.sql failed"; }
    pass "seeded the docs/121 section 5 shape plus duplicates, ambiguous ownership, foreign evidence: $(grep -c '^seeded identity' "${EVIDENCE}/03-seeded-corruption.txt") identities, $(grep -c '^seeded order' "${EVIDENCE}/03-seeded-corruption.txt") orders"
fi
# The W0 read-only scope queries (deploy/tools/identity-scope-readonly.sql), as Joy ran them on production.
git -C "$REPO" show "${CAND_FULL}:deploy/tools/identity-scope-readonly.sql" > "${WORK}/identity-scope-readonly.sql"
psql -d "$DB" -At -v ON_ERROR_STOP=1 -f "${WORK}/identity-scope-readonly.sql" > "${EVIDENCE}/04-before-scope.txt" 2>&1 \
    && pass "identity-scope-readonly.sql ran on the production-schema copy ($(grep -c . "${EVIDENCE}/04-before-scope.txt") lines)" \
    || warn "identity-scope-readonly.sql did not run clean before the migration (04-before-scope.txt)"
# The D.3 checklist's posture read, in a session PostgreSQL itself holds read-only (as the checklist runs it).
RO() { PGOPTIONS='-c default_transaction_read_only=on' psql -d "$DB" -At "$@" | tr -d '\r'; }
RO -v ON_ERROR_STOP=1 -f "${SELF_DIR}/../tools/release-posture-readonly.sql" > "${EVIDENCE}/04-before-checklist-posture.txt" 2>&1 || true
grep -q 'read_only=on' "${EVIDENCE}/04-before-checklist-posture.txt" && pass "pre-release checklist posture read ran in a read-only session ($(grep -cE '^[a-z_]+ ' "${EVIDENCE}/04-before-checklist-posture.txt") lines)" || fail "release-posture-readonly.sql did not run read-only before the release"
snapshot "$DB" > "${EVIDENCE}/04-before-snapshot.txt"
grep '^migration ' "${EVIDENCE}/04-before-snapshot.txt" | cut -d' ' -f2 > "${EVIDENCE}/04-before-migrations.txt"
posture > "${EVIDENCE}/04-before-posture.txt"
pass "BEFORE: $(grep -c '^count ' "${EVIDENCE}/04-before-snapshot.txt") tables counted, $(wc -l < "${EVIDENCE}/04-before-migrations.txt" | tr -d ' ') migrations, posture captured"

# The pre-deploy dump's local equivalent: what deploy.sh's /backup.sh once would hold.
pg_dump -Fc -d "$DB" -f "${WORK}/pre-migration.dump"
[ -s "${WORK}/pre-migration.dump" ] && pass "pre-migration pg_dump -Fc taken ($(wc -c < "${WORK}/pre-migration.dump" | tr -d ' ') bytes)" || { fail "pre-migration dump is empty"; exit 1; }

# ── 3. The candidate's migrations, seeder, constraints, dry runs ───────────────────────────────────
mv "${P}/vendor" "${C}/vendor"
(cd "$C" && php artisan migrate --force --no-ansi) > "${EVIDENCE}/05-candidate-migrate.txt" 2>&1 || { tail -30 "${EVIDENCE}/05-candidate-migrate.txt"; fail "candidate migrations failed"; exit 1; }
snapshot "$DB" > "${EVIDENCE}/06-after-snapshot.txt"
grep '^migration ' "${EVIDENCE}/06-after-snapshot.txt" | cut -d' ' -f2 > "${EVIDENCE}/06-after-migrations.txt"
comm -13 <(sort "${EVIDENCE}/04-before-migrations.txt") <(sort "${EVIDENCE}/06-after-migrations.txt") > "${EVIDENCE}/06-new-migrations.txt"
pass "candidate migrate --force: $(grep -c . "${EVIDENCE}/06-new-migrations.txt") new migrations applied in one batch (batch $(q 'SELECT max(batch) FROM migrations'))"
psql -d "$DB" -At -v ON_ERROR_STOP=1 -f "${SELF_DIR}/sql/verify-constraints.sql" > "${EVIDENCE}/07-constraints.txt" 2>&1 || { cat "${EVIDENCE}/07-constraints.txt"; fail "verify-constraints.sql did not run"; }
if grep -q '^FAIL' "${EVIDENCE}/07-constraints.txt"; then fail "constraints: $(grep '^FAIL' "${EVIDENCE}/07-constraints.txt" | tr '\n' ';')"; else pass "constraints: all $(grep -c '^PASS' "${EVIDENCE}/07-constraints.txt") checks PASS"; fi

(cd "$C" && php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force --no-ansi) > "${EVIDENCE}/08-seeder.txt" 2>&1 || fail "the permission seeder failed"
PERMS=$(q "SELECT count(*) FROM permissions WHERE name IN ('hq.consent.view','hq.consent.restrict','hq.consent.grant','hq.playbooks.view','hq.playbooks.draft','hq.playbooks.review','hq.playbooks.activate','hq.followups.view','hq.followups.propose','hq.followups.decide','hq.consent.lift_restriction','hq.outcomes.attribute','hq.customers.quarantine.review')")
[ "$PERMS" = "13" ] && pass "permission seeder --class: the thirteen wave 1-4 permissions exist (permissions total $(q 'SELECT count(*) FROM permissions'))" || fail "seeder: ${PERMS} of 13 permissions exist"

snapshot "$DB" > "${EVIDENCE}/09-before-dry-runs.txt"
(cd "$C" && php artisan hq:identity:cross-business --dry-run --no-ansi) > "${EVIDENCE}/10-detector-dry-run.txt" 2>&1 && pass "detector dry run: $(grep -iE 'finding|nothing to flag' "${EVIDENCE}/10-detector-dry-run.txt" | head -1 | tr -s ' ')" || fail "hq:identity:cross-business --dry-run failed"
(cd "$C" && php artisan hq:evaluation:attribute-cost --no-ansi) > "${EVIDENCE}/11-cost-dry-run.txt" 2>&1 && pass "cost attribution dry run ran ($(grep -ci 'dry run' "${EVIDENCE}/11-cost-dry-run.txt") dry-run line(s))" || fail "hq:evaluation:attribute-cost dry run failed"
(cd "$C" && php artisan hq:decisions:report --unassigned --no-ansi) > "${EVIDENCE}/12-decisions-unassigned.txt" 2>&1 && pass "decisions report --unassigned ran" || warn "hq:decisions:report --unassigned did not run clean"
REPAIR=$(cd "$C" && php artisan list --raw --no-ansi 2>/dev/null | awk '{print $1}' | grep -E '^hq:(identity|customers):.*repair' | head -1 || true)
[ -n "$REPAIR" ] && warn "a repair command ${REPAIR} exists; this local rehearsal does not run it" || warn "no repair command on the candidate (W2 not merged); repair dry run NOT exercised"
snapshot "$DB" > "${EVIDENCE}/13-after-dry-runs.txt"
if diff -q <(grep '^count ' "${EVIDENCE}/09-before-dry-runs.txt") <(grep '^count ' "${EVIDENCE}/13-after-dry-runs.txt") >/dev/null; then
    pass "the dry runs wrote nothing: every table count unchanged"
else
    diff <(grep '^count ' "${EVIDENCE}/09-before-dry-runs.txt") <(grep '^count ' "${EVIDENCE}/13-after-dry-runs.txt") > "${EVIDENCE}/13-dry-run-diff.txt" || true
    fail "a dry run changed a table count (13-dry-run-diff.txt)"
fi
psql -d "$DB" -At -v ON_ERROR_STOP=1 -f "${SELF_DIR}/sql/show-quarantine.sql" > "${EVIDENCE}/14-quarantine.txt" 2>&1 || fail "show-quarantine.sql failed"
# The D.4 checklist's reads, read-only sessions, on the migrated production-shaped copy.
RO -v ON_ERROR_STOP=1 -f "${SELF_DIR}/../tools/post-release-scope-readonly.sql" > "${EVIDENCE}/14-post-release-scope.txt" 2>&1 && pass "post-release scope read (read-only session): $(grep -E '^(identity_owner_business_mismatch|identity_live_store_other_business|cross_business_orders|cross_business_conversations|participants_on_quarantined_identity) ' "${EVIDENCE}/14-post-release-scope.txt" | tr '
' ';')" || fail "post-release-scope-readonly.sql failed (14-post-release-scope.txt)"
RO -v ON_ERROR_STOP=1 -f "${SELF_DIR}/../tools/release-posture-readonly.sql" > "${EVIDENCE}/14-after-checklist-posture.txt" 2>&1 && pass "post-release checklist posture read (read-only session) ran" || fail "release-posture-readonly.sql failed after the release"
if diff -q <(grep -vE '^(migrations|evaluation_cost_attribution|release_db|BEGIN|SET|ROLLBACK)' "${EVIDENCE}/04-before-checklist-posture.txt") <(grep -vE '^(migrations|evaluation_cost_attribution|release_db|BEGIN|SET|ROLLBACK)' "${EVIDENCE}/14-after-checklist-posture.txt") >/dev/null; then
    pass "checklist posture identical before and after the release (flags, agents, grants, shadow, jobs, tools, approvals, consent, cost rows)"
else
    diff "${EVIDENCE}/04-before-checklist-posture.txt" "${EVIDENCE}/14-after-checklist-posture.txt" > "${EVIDENCE}/14-checklist-posture-diff.txt" || true
    fail "checklist posture changed across the release (14-checklist-posture-diff.txt)"
fi
(cd "$C" && PGOPTIONS='-c default_transaction_read_only=on' php artisan hq:identity:cross-business --dry-run --no-ansi) > "${EVIDENCE}/14-detector-readonly-session.txt" 2>&1     && (cd "$C" && PGOPTIONS='-c default_transaction_read_only=on' php artisan hq:evaluation:attribute-cost --no-ansi) >> "${EVIDENCE}/14-detector-readonly-session.txt" 2>&1     && (cd "$C" && PGOPTIONS='-c default_transaction_read_only=on' php artisan hq:decisions:report --unassigned --no-ansi) >> "${EVIDENCE}/14-detector-readonly-session.txt" 2>&1     && pass "detector, cost and decisions dry runs also complete in a session PostgreSQL holds read-only (the checklist's form)"     || fail "a dry run failed in a read-only session: it tried to write (14-detector-readonly-session.txt)"
q "SELECT 'decision_records business assigned=' || count(*) FILTER (WHERE business_id IS NOT NULL) || ' unassigned=' || count(*) FILTER (WHERE business_id IS NULL) FROM decision_records" >> "${EVIDENCE}/14-quarantine.txt"
q "SELECT 'consent_state rows=' || count(*) || ' with_brand=' || count(brand_id) || ' with_identity=' || count(identity_id) FROM consent_state" >> "${EVIDENCE}/14-quarantine.txt"
if [ "$SCENARIO" = "corrupted" ]; then
    Qf="${EVIDENCE}/14-quarantine.txt"
    if grep -q 'seeded identity .* shop_user .* quarantined=store_business_mismatch conflict=quarantined/store_business_mismatch' "$Qf" \
        && grep -q 'seeded identity .* shop_order .* quarantined=store_business_mismatch' "$Qf" \
        && grep -q 'seeded identity .* phone .* quarantined=duplicate_same_customer' "$Qf" \
        && [ "$(grep -c 'seeded identity .* email .* quarantined=duplicate_ambiguous_owner' "$Qf")" = "2" ] \
        && grep -q 'seeded identity .* messenger_psid .* quarantined=contradicted_by_evidence' "$Qf" \
        && grep -q 'seeded order .* cross_business=t' "$Qf"; then
        pass "W1 quarantined the seeded store mismatch (shop_user, shop_order), the duplicate phone, both ambiguous e-mails, the foreign PSID; the seeded Bionic order still points at the LaLaDia customer (W2's work)"
    else
        fail "the seeded corruption was not quarantined as docs/122 describes (14-quarantine.txt)"
    fi
    grep -q 'seeded identity .* phone .* quarantined=no' "$Qf" && pass "the phone with evidence in its own business stayed live" || fail "the first phone copy should have stayed live"
else
    grep -q '^quarantined_by_reason' "${EVIDENCE}/14-quarantine.txt" && fail "clean data: something was quarantined" || pass "clean data: W1 quarantined nothing"
fi

# ── 4. Rollback, one migration at a time ───────────────────────────────────────────────────────────
: > "${EVIDENCE}/15-rollback.txt"
REFUSED=""
for i in $(seq 1 40); do
    LAST=$(q "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1")
    if grep -qx "$LAST" "${EVIDENCE}/04-before-migrations.txt"; then echo "STOP next is ${LAST}, which the production schema already had" >> "${EVIDENCE}/15-rollback.txt"; break; fi
    set +e; OUT=$(cd "$C" && php artisan migrate:rollback --step=1 --force --no-ansi 2>&1); RC=$?; set -e
    STILL=$(q "SELECT count(*) FROM migrations WHERE migration = '${LAST}'")
    if [ "$RC" = "0" ] && [ "$STILL" = "0" ]; then
        echo "ROLLED_BACK ${LAST}" >> "${EVIDENCE}/15-rollback.txt"
    else
        REFUSED="$LAST"
        { echo "REFUSED ${LAST} exit=${RC} still_recorded=${STILL}"; echo "$OUT" | grep -oE 'Refusing to roll back: [0-9]+[^.]*\.[^(]*' | head -1; } >> "${EVIDENCE}/15-rollback.txt"
        break
    fi
done
ROLLED=$(grep -c '^ROLLED_BACK' "${EVIDENCE}/15-rollback.txt" || true)
if [ -n "$REFUSED" ]; then
    warn "rollback: ${ROLLED} migration(s) rolled back, then ${REFUSED} REFUSED its down() (one-way door; recovery is the dump restore below)"
    # The refusal must leave the schema whole: Laravel runs a PostgreSQL migration in a transaction.
    psql -d "$DB" -At -v ON_ERROR_STOP=1 -f "${SELF_DIR}/sql/show-quarantine.sql" > "${EVIDENCE}/15-after-refusal-quarantine.txt" 2>&1 || true
else
    pass "rollback: every release migration rolled back (${ROLLED})"
fi
snapshot "$DB" > "${EVIDENCE}/16-after-rollback-snapshot.txt"
join -j1 <(grep '^count ' "${EVIDENCE}/04-before-snapshot.txt" | awk '{print $2, $3}' | sort) <(grep '^count ' "${EVIDENCE}/16-after-rollback-snapshot.txt" | awk '{print $2, $3}' | sort) \
    | awk '$2 != $3 && $1 != "migrations" {print "DIFF", $1, "before", $2, "after_rollback", $3}' > "${EVIDENCE}/16-counts-diff-all.txt" || true
# The permission seeder is additive and runs after the migrations; no down() removes permissions, by design.
grep -vE '^DIFF (permissions|role_has_permissions) ' "${EVIDENCE}/16-counts-diff-all.txt" > "${EVIDENCE}/16-counts-diff.txt" || true
grep -E '^DIFF (permissions|role_has_permissions) ' "${EVIDENCE}/16-counts-diff-all.txt" > "${EVIDENCE}/16-counts-diff-seeder.txt" || true
comm -13 <(grep '^count ' "${EVIDENCE}/04-before-snapshot.txt" | awk '{print $2}' | sort) <(grep '^count ' "${EVIDENCE}/16-after-rollback-snapshot.txt" | awk '{print $2}' | sort) > "${EVIDENCE}/16-tables-still-new.txt"
if [ -s "${EVIDENCE}/16-counts-diff.txt" ]; then
    warn "after rollback, pre-existing table counts differ: $(tr '\n' ';' < "${EVIDENCE}/16-counts-diff.txt")"
else
    pass "after rollback every pre-existing table has its BEFORE count except the seeder's additive rows ($(tr '
' ';' < "${EVIDENCE}/16-counts-diff-seeder.txt")); tables of the release still present: $(grep -c . "${EVIDENCE}/16-tables-still-new.txt")"
fi
if [ -z "$REFUSED" ]; then
    if diff -q <(grep -E '^(count|migration|constraint|index|trigger|column) ' "${EVIDENCE}/04-before-snapshot.txt" | grep -vE '^count (permissions|role_has_permissions) ') <(grep -E '^(count|migration|constraint|index|trigger|column) ' "${EVIDENCE}/16-after-rollback-snapshot.txt" | grep -vE '^count (permissions|role_has_permissions) ') >/dev/null; then
        pass "full rollback reproduces the BEFORE snapshot exactly (counts, migrations, constraints, indexes, triggers, columns; permission rows excluded, the seeder is additive)"
    else
        diff "${EVIDENCE}/04-before-snapshot.txt" "${EVIDENCE}/16-after-rollback-snapshot.txt" > "${EVIDENCE}/16-rollback-diff.txt" || true
        warn "full rollback differs from BEFORE in $(grep -c '^[<>]' "${EVIDENCE}/16-rollback-diff.txt") line(s) (16-rollback-diff.txt)"
    fi
fi

# ── 5. Restore proof: the pre-migration dump into a second throwaway database ──────────────────────
createdb "${DB}_restorecheck"
pg_restore --no-owner -d "${DB}_restorecheck" "${WORK}/pre-migration.dump" > "${EVIDENCE}/17-restore.txt" 2>&1 || { cat "${EVIDENCE}/17-restore.txt"; fail "pg_restore of the pre-migration dump failed"; }
snapshot "${DB}_restorecheck" > "${EVIDENCE}/17-restored-snapshot.txt"
if diff -q "${EVIDENCE}/04-before-snapshot.txt" "${EVIDENCE}/17-restored-snapshot.txt" >/dev/null; then
    pass "restore proof: the pre-migration dump restores to EXACTLY the BEFORE snapshot ($(grep -c . "${EVIDENCE}/17-restored-snapshot.txt") lines compared)"
else
    diff "${EVIDENCE}/04-before-snapshot.txt" "${EVIDENCE}/17-restored-snapshot.txt" > "${EVIDENCE}/17-restore-diff.txt" || true
    fail "restore proof: the restored database differs from BEFORE (17-restore-diff.txt)"
fi
dropdb "${DB}_restorecheck"

# ── 6. Re-apply, verify again, posture unchanged ───────────────────────────────────────────────────
(cd "$C" && php artisan migrate --force --no-ansi) > "${EVIDENCE}/18-reapply.txt" 2>&1 && pass "re-apply: migrate --force ran clean ($(grep -c 'DONE' "${EVIDENCE}/18-reapply.txt") migration(s))" || fail "re-apply failed (18-reapply.txt)"
psql -d "$DB" -At -v ON_ERROR_STOP=1 -f "${SELF_DIR}/sql/verify-constraints.sql" > "${EVIDENCE}/19-constraints-after-reapply.txt" 2>&1 || true
grep -q '^FAIL' "${EVIDENCE}/19-constraints-after-reapply.txt" && fail "constraints after re-apply: $(grep '^FAIL' "${EVIDENCE}/19-constraints-after-reapply.txt" | tr '\n' ';')" || pass "constraints after re-apply: all $(grep -c '^PASS' "${EVIDENCE}/19-constraints-after-reapply.txt") PASS"
(cd "$C" && php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force --no-ansi) > "${EVIDENCE}/20-seeder-again.txt" 2>&1 && pass "permission seeder is idempotent (second run clean)" || fail "second seeder run failed"
snapshot "$DB" > "${EVIDENCE}/21-after-reapply-snapshot.txt"
if diff -q <(grep -E '^(count|constraint|index|trigger|column|migration) ' "${EVIDENCE}/06-after-snapshot.txt" | grep -v '^count role_has_permissions\|^count permissions') \
           <(grep -E '^(count|constraint|index|trigger|column|migration) ' "${EVIDENCE}/21-after-reapply-snapshot.txt" | grep -v '^count role_has_permissions\|^count permissions') >/dev/null; then
    pass "re-apply reproduces the first application's schema and counts exactly (permission rows excluded: the seeder ran between)"
else
    diff <(grep -E '^(count|constraint|index|trigger|column|migration) ' "${EVIDENCE}/06-after-snapshot.txt") <(grep -E '^(count|constraint|index|trigger|column|migration) ' "${EVIDENCE}/21-after-reapply-snapshot.txt") > "${EVIDENCE}/21-reapply-diff.txt" || true
    warn "re-apply differs from the first application in $(grep -c '^[<>]' "${EVIDENCE}/21-reapply-diff.txt") line(s) (21-reapply-diff.txt)"
fi
posture > "${EVIDENCE}/22-after-posture.txt"
if diff -q "${EVIDENCE}/04-before-posture.txt" "${EVIDENCE}/22-after-posture.txt" >/dev/null; then
    pass "posture unchanged by migrate, seeder, dry runs, rollback and re-apply: $(tr '\n' ';' < "${EVIDENCE}/22-after-posture.txt")"
else
    diff "${EVIDENCE}/04-before-posture.txt" "${EVIDENCE}/22-after-posture.txt" > "${EVIDENCE}/22-posture-diff.txt" || true
    fail "the posture changed (22-posture-diff.txt)"
fi
log "done; the EXIT trap drops the throwaway databases and prints the verdicts"
