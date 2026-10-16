# Post-release read-only checklist (ruling docs/120 D.4)

For Joy, on the VPS, AFTER he has released waves 1 to 4 with `./deploy/deploy.sh --verify-only && ./deploy/deploy.sh` (under containment Option 2: docs/132 section 5 B, with `HQ_EVENTS_DRAIN_PAUSED=true` and `--keep-scheduler-stopped`), and BEFORE any optional repair or backfill. Every command below only reads. The readiness record is `docs/128-release-readiness-2026-10-15.md`; the pre-release values to compare with are in `/root/hq-posture-before-release.txt` (pre-release checklist section 3).

## 0. Conventions

```
ssh vps
cd /opt/bordeguna-hq
C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
```

Why these cannot write: identical to the pre-release checklist section 0. Every `psql` and every `artisan` runs with `-e PGOPTIONS=-cdefault_transaction_read_only=on`, so PostgreSQL refuses any write of that session (SQLSTATE 25006); every SQL file is `BEGIN READ ONLY ... ROLLBACK`; the structural searches read source files only. No step here passes `--flag`, `--apply`, `migrate`, `db:seed`, `tools:toggle` or `shadow:open`; the one `--apply` line in section 8 is labelled as Joy's separate, authorized mutation and is not a step of this checklist.

## 1. The deployed commit and the release record

```
git rev-parse --short HEAD
docker inspect -f '{{ index .Config.Labels "com.bordeguna.commit" }}' $($C ps -q app)
cat deploy/release-state/release.json
tail -5 deploy/RELEASES.md
$C exec -T app php -r 'echo @file_get_contents("http://web/api/health", false, stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 10]])) ?: "{}";'; echo
```

Expected: the candidate commit from git and from the image label; `release.json` names it with `"verification":{"status":"passed"`; `RELEASES.md` ends with the release and names `b96a396` as its predecessor; `/api/health` overall `ok` with `"release":"<candidate>"`. A pre-deploy dump line (`verified dump: ...`) was printed by `deploy.sh` before `releasing`: note its file name, it is the rollback (section 9).

If the release ran with `--keep-scheduler-stopped` (docs/132 section 5 B2), expect instead `"verification":{"status":"failed"` with the scheduler as the ONLY failing component of `/api/health` (no tick, the scheduler is left stopped on purpose), the candidate still named by `release.json` and the health answer, and `$C ps scheduler` showing no running scheduler. Any other failing component is a real failure. The release is verified later, after Stage D step 10 starts the scheduler (docs/132 B5).

## 2. Migration and seed outcomes

```
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan migrate:status --no-ansi | tail -11
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1 -f -' < deploy/rehearsal/sql/verify-constraints.sql
```

Expected: the ten wave 1 to 4 migrations `Ran` in one batch (`2026_10_10_100000_add_cost_attribution_to_evaluation` to `2026_10_15_510000_add_business_id_to_decision_records`, docs/127 section 3) and no `Pending`; `verify-constraints.sql` prints 21 `PASS` lines and no `FAIL` (the business-scoped unique key NULLS NOT DISTINCT over live rows, both composite foreign keys, the `(id, business_id)` keys they rest on, `business_id NOT NULL`, the old group-wide key gone, no duplicate live key, every identity carrying its owner's business, no live identity naming another business's store, every quarantined identity with a conflict row, the attribution composite FK and typed-action CHECK, the consent scope index, the append-only triggers, no cascading FK on a ledger, the ten migrations recorded).

Then the permission seeder, which `deploy.sh` does NOT run and which is the one WRITE of the release procedure (it is additive and idempotent; it is listed here so the read below has something to verify, and it is Joy's step, not a check):

```
$C exec -T app php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force
```

Read back:

```
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -At -c \"SELECT count(*) FROM permissions WHERE name IN ('hq.consent.view','hq.consent.restrict','hq.consent.grant','hq.playbooks.view','hq.playbooks.draft','hq.playbooks.review','hq.playbooks.activate','hq.followups.view','hq.followups.propose','hq.followups.decide','hq.consent.lift_restriction','hq.outcomes.attribute','hq.customers.quarantine.review')\""
```

Expected: `13`. The total permission count is the pre-release count plus 13.

## 3. Identity lookups are business-scoped; no known leak remains in the verified scope; unresolved identities fail closed

```
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1 -f -' < deploy/tools/post-release-scope-readonly.sql
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1 -f -' < deploy/rehearsal/sql/show-quarantine.sql
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:identity:cross-business --dry-run --no-ansi
```

Expected on production data (docs/121 section 5; the same shape the local rehearsal reproduced, docs/128 section 9):

- `identity_owner_business_mismatch 0`, `identity_live_store_other_business 0`, `identity_live_duplicate_keys 0`, `quarantined_without_open_conflict 0`;
- `identities_quarantined reason=store_business_mismatch 2` (the `shop_order` and `shop_user` of Bionic store 2 under LaLaDia customer #1), `identity_conflicts_open kind=quarantined reason=store_business_mismatch 2`; no other reason unless the pre-release scope count showed it;
- `participants_on_quarantined_identity 0`, `consent_events_on_quarantined_identity 0`: nothing can send through a withheld identity;
- `cross_business_orders 1` (order #16: NOT repaired by the release; that is W2's repair); `cross_business_conversations`, `_participants`, `_participant_identities`, `_messages`, `_consent_events`, `_consent_state`, `_merges` all `0`;
- `cross_business_quarantine_flags 0` (the release writes no flag);
- `show-quarantine.sql`: `store_mismatch_total 2 quarantined=2`, `cross_business_orders_remaining 1`;
- detector dry run: the findings for order #16 and its two store-scoped identities (the same three the local rehearsal's seeded copy of this shape produced, plus a conversation line only if one exists), ending `Dry run: nothing written.`

A detector with findings, and quarantine rows, are NOT a clean-data certificate (ruling D.4). Report the remaining quarantine and the containment applied, exactly as printed.

## 4. Customer 360, inbox and support do not reveal other businesses' records

The projections read the rows counted in section 3: with `cross_business_conversations`, `_participants`, `_participant_identities`, `_messages` and `_consent_*` at 0 and every live identity in its owner's business, no screen has another business's row to show except order #16 on customer #1, and Customer 360 lists a customer's orders only in the customer's own business (`Customer360Reader`: `FactsOrder` where `customer_id` and `business_id` = the customer's), so LaLaDia customer #1's page cannot show Bionic order #16. In a browser, as a user scoped to LaLaDia only: open customer #1's Customer 360 and the inbox. Expected: order #16 (Bionic) is not listed; the two quarantined identities are not shown (`hides quarantined identities from Customer 360 and the consent screen`, pinned in `IdentityBusinessScopeTest`). Viewing is a read; do not edit anything on the page.

## 5. Consent restrictions are not converted to grants; cost reporting is truthful; posture unchanged

```
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -v ON_ERROR_STOP=1 -f -' < deploy/tools/release-posture-readonly.sql > /root/hq-posture-after-release.txt
diff <(grep -vE '^(migrations|evaluation_cost_attribution|release_db|BEGIN|SET|ROLLBACK)' /root/hq-posture-before-release.txt) <(grep -vE '^(migrations|evaluation_cost_attribution|release_db|BEGIN|SET|ROLLBACK)' /root/hq-posture-after-release.txt) && echo POSTURE UNCHANGED
grep -E '^(migrations|evaluation_cost_attribution|scheduler_gateway_row) ' /root/hq-posture-after-release.txt
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:evaluation:attribute-cost --no-ansi
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:decisions:report --unassigned --no-ansi
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:ai:cost --no-ansi
$C exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:facts:tz-backfill --no-ansi
```

Expected:

- `POSTURE UNCHANGED`: flags (`ai.runtime`, `channels.meta.inbound`, `channels.meta.forwarded` off), every agent `paused` at `a0`, `autonomy_grants_active 0`, `shadow_periods_open 0`, scheduled jobs and their states, tool switches, pending approvals, consent events and consent state by state, `consent_projection_drift 0`, evaluation and AI run counts: all as before. The local rehearsal proved the same diff empty across migrate, seeder, dry runs, rollback and re-apply (docs/128 section 9).
- consent: the counts by state are unchanged, so no restriction became a grant (the release writes no consent row; migrations 4 and 5 only widen the projection's scope key, docs/127).
- `migrations 67 last=2026_10_15_510000_add_business_id_to_decision_records`; `evaluation_cost_attribution attributed=0 unattributed=<every run>`.
- `hq:evaluation:attribute-cost` (dry run by default): a ` DRY RUN ` banner, one row per finished evaluation run with recorded and computed cost and `results linked/no-call/unaccounted`, ending `<n> run(s) read; none written (dry run); <m> incomplete (never written).` Unaccounted results are shown as unaccounted, never as zero cost. These are the backfill dry-run figures for D.6.
- `hq:decisions:report --unassigned`: `<n> decision record(s) without a business. None is guessed`, each with its reason.
- `hq:ai:cost`: the month's AI spend, unchanged by the release.
- `hq:facts:tz-backfill` (dry run by default, docs/133; only when the release carries it): a ` DRY RUN ` banner, the totals line `facts_orders rows <n>; timestamps examined <n>; already correct by evidence <n>; correctable <n>; ambiguous <n>.`, the ambiguous count per reason, the batch key, the corrections by business and Dhaka day, delivered sales per day before and after, each correctable timestamp (source string, stored and corrected UTC), every ambiguous timestamp, and `Dry run: nothing written.` In the read-only session it cannot write; it is the evidence Joy reads for item 8.

## 6. Scheduler and Gateway: no effectful execution bypass in the DEPLOYED code

```
$C exec -T app php -- --root /var/www/html < deploy/tools/structural-searches.php
```

Expected: `PASS S1 identity-scope`, `PASS S2 tool-gateway`, `PASS S3 consent-reader`, `PASS S4 playbook-reader`, `RESULT: 4 passed, 0 failed` (on `b96a396` the same command printed `2 passed, 2 failed`, pre-release checklist section 5). Read-only: the script reads source files and prints.

## 7. The obsolete `scheduler.gateway` row

Read only here:

```
grep '^scheduler_gateway_row ' /root/hq-posture-after-release.txt
```

Removing the row is a separate, deliberate write and only after BOTH: section 6 printed `PASS S2 tool-gateway` on the deployed code (no consumer reads it: the search fails on any `scheduler.gateway` or `scheduler_gateway_flag` in `app/`), and the release identity in section 1 is verified. It is not part of the release, not part of this checklist, and not done by any script here.

## 8. What stays gated (ruling D.5)

None runs as part of the release or of this checklist: the W2 quarantine repair (dry run, human review, Joy's explicit authorization, Tool Gateway authorization, then the idempotent mutation), the cost backfill (`hq:evaluation:attribute-cost` reviewed, Joy's explicit authorization, then `--apply`) and the time-zone backfill (docs/133).

**MUTATING, JOY-AUTHORIZED ONLY, NOT PART OF THIS CHECKLIST.** After Joy has read the section 5 tz dry run and explicitly authorized that exact batch, and only then, with a fresh backup first:

```
# JOY-AUTHORIZED MUTATION. Writes facts_orders, facts_tz_corrections and audit_logs. Do not run from this checklist.
$C exec -T app php artisan hq:facts:tz-backfill --apply --key=<batch key the dry run printed> --by=<Joy's HQ e-mail> --reason="<why>" --no-ansi
```

It refuses a key that no longer matches the live data, never changes an ambiguous timestamp, and a second run changes nothing.

## 9. Rollback, if section 1, 2 or 3 fails

Migration `2026_10_15_100000_scope_customer_identities_to_business` REFUSES its `down()` once any `customer_identity_conflicts` row exists, and on production two will exist (section 3). `migrate:rollback` is therefore not the way back. The way back is code first, then data, both WRITES and both outside this read-only checklist, on Joy's decision:

0. Before anything: the event export (docs/132 section 5 C1 step 1), while the candidate still runs.
1. `./deploy/rollback.sh --keep-scheduler-stopped b96a396`: code only, no migration runs, `RUN_MIGRATIONS=0` on `app` (deploy/README.md section "Rollback"). Code first, because the candidate's `app` container migrates on start (`RUN_MIGRATIONS=1`): restoring the database under it and then starting it would re-apply the ten migrations. `--keep-scheduler-stopped` because `b96a396` has no `HQ_EVENTS_DRAIN_PAUSED`: a started scheduler would drain every pending event through the OLD resolver (docs/131 section 1.1, docs/132 section 5 C2). The script ends with "The scheduler is LEFT STOPPED" and the command that starts it: do not run it.
2. The database from the pre-deploy dump `deploy.sh` took and verified before the checkout moved (its name is in the `deploy.sh` output, section 1): deploy/README.md section "Rollback", block "Database, only if a migration must be undone": stop `app horizon` (the scheduler is already stopped), rehearse the restore into `hq_restore_check`, restore into the production database, then `$C start app horizon`. Never start the scheduler here: it waits for a fixed release (docs/132 section 5 C2 step 5).

The local rehearsal proved the pre-migration dump restores to exactly the pre-migration snapshot (docs/128 section 9). The Docker rehearsal on the VPS (`deploy/rehearsal/rehearse.sh`) proves the same with a production dump and the real `deploy/restore.sh`; until it has run, this path is rehearsed at SQL level only.
