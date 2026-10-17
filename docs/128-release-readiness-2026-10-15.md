# 128. Release readiness record, waves 1 to 4 (15 to 16 October 2026)

The final readiness report the ruling asks for (`docs/120-chatgpt-ruling-2026-10-15.txt` section D.6), in its shape, filled from evidence on main and from the local rehearsal executed on 16 October 2026. Anything not yet evidenced is marked PENDING with what produces it. Workstream W7, branch `platform/release-readiness-waves-1-4`, worktree `hq-wt/j`, test database `hq_test_j`. Plan: `docs/129`. Release notes: `docs/127`.

Nothing in this record released anything, touched production, or ran a repair or a backfill. Joy alone releases.

Current verdict: **NOT READY** (section 14).

## 1. Commits

- Production: `b96a396` (released 9 October, 57 migrations, last `2026_10_04_100000_create_workflow_tables`).
- main: `fb910fc` (docs only since `357fabc`, the last combined code commit: `git diff --stat 357fabc..fb910fc` touches `docs/` alone).
- This branch: from `7fb5e71`, adds `deploy/rehearsal/`, `deploy/tools/` (structural searches, two read-only SQL files), `deploy/checklists/`, one Pest test (`tests/Feature/Release/StructuralSearchesTest.php`), two CI steps, docs 127 to 129. It changes no application code and no migration.
- Release candidate: PENDING. It is the merge commit after W2 (`p3/identity-repair`) and this branch are merged and the combined gate and CI are green on it. Until then there is no candidate to release.

## 2. Merged workstreams, migrations, permissions

- Workstreams on main since `b96a396`: `docs/127` section 2 (sixteen merges, `35246dd` S1 to `357fabc` W5).
- Migrations: ten, all new, listed with their `down()` verdicts in `docs/127` section 3. PENDING: W2 adds `2026_10_15_600000` (customer_identity_repairs, customer_merge_reversals, wider quarantine kind CHECK); its `down()` verdict and its place in the rollback chain must be added after the merge and rehearsed (section 9 must be re-run).
- Permissions: thirteen new (`docs/127` section 4), proven by the seeder in the rehearsal (section 9). PENDING: W2 adds `hq.customers.quarantine.repair` (or the name it settles on) and the human-only tool `customers.quarantine.repair`.
- Feature flags: none added, none switched (`docs/127` section 6); the rehearsal proved the posture identical before and after (section 9).

## 3. Test suite, Pint, TypeScript, Vite, CI

- main `357fabc` (combined waves 1 to 4 part 1): 2,125 Pest passed, 0 failed, 31,947 assertions; Pint, `tsc --noEmit`, Vite clean (local, real PostgreSQL, parallel 3). GitHub Actions run 38681310680 on `357fabc`: SUCCESS, all three jobs.
- This branch: full Pest suite on 16 October (`DB_DATABASE=hq_test_j php artisan test --parallel --processes=3`, at `b78de0d`): 2,127 passed, 1 failed, 31,960 assertions, 481 s. The one failure is a timing flake in existing main code, not touched here: `DecisionCorpusReaderTest` "derives every edit category" reads the corpus twice and compares, and the report's `window.until` is `now()` at whole-second precision, so the two reads differed when they straddled a second boundary (13:45:47 vs 13:45:48). The file passed 3 of 3 reruns (9 tests each). It is a real defect of the test (it should freeze time), recorded as a finding for the combined gate; CI on the candidate may hit it the same way. Pint `--test` clean (apps/hq and the new `deploy/tools/structural-searches.php`); `bash -n` clean on every script; no TypeScript or front-end file changed on this branch, so `tsc` and Vite were not re-run here.
- New on this branch: `tests/Feature/Release/StructuralSearchesTest.php` (3 tests: real tree passes, negative control, usage errors); `deploy/rehearsal/tests/rehearsal-guard-test.sh` (48 checks); two CI steps in `.github/workflows/hq-ci.yml` (structural searches in the test job, the rehearsal guard suite in the deploy-safety job).
- PENDING: the full suite, Pint, `tsc`, Vite and GitHub Actions on the exact release candidate (the merge of W2 and W7). A green individual branch is not a substitute (ruling Stage 6).

## 4. Identity containment, migration and repair evidence

- Containment (docs/121): production scope counted by Joy on 15 October (docs/121 section 5): one order fact in another business than its customer (`facts_orders` #16, Bionic, on LaLaDia customer #1); one `shop_order` and one `shop_user` identity of Bionic store 2 under that customer; one phone matched to another business's orders; three identities with multi-business evidence; no conversation, participant, message, consent, memory or merge affected. Webhooks live; every other path closed. Joy's containment choice (Option 1 accept, Option 2 stop the scheduler) is OPEN. The incident is not claimed contained.
- W1 (business-scoped identities, docs/122): on main. The local rehearsal applied it to a production-schema copy carrying exactly that shape plus duplicates, ambiguous ownership and foreign evidence, and it quarantined what docs/122 says it must (section 9): the store mismatch (`shop_user`, `shop_order`), the duplicate phone, both ambiguous e-mails, the foreign PSID; the phone with evidence in its own business stayed live; the cross-business order stayed on its customer (the repair's work).
- W2 repair (docs/126 on its branch): NOT merged. Code written after the applier, tool and command has never been executed (RESUME-HERE section 9). PENDING: merge, gate, the repair dry run on the rehearsal copy (`rehearse.sh` auto-detects `hq:identity:repair` and runs its bare form; its help must name `--apply`), and the D.1 requirement "exercise the identity backfill and repair against representative cross-business corruptions, null-store identities, duplicate rows and ambiguous ownership".

## 5. Remaining quarantined rows and ambiguous cases

- Expected on production after the release and before the repair (docs/127 section 3, docs/121 section 5): 2 identities quarantined `store_business_mismatch`, 2 open conflict rows, 1 cross-business order (#16). Read with `deploy/tools/post-release-scope-readonly.sql` (post-release checklist section 3).
- In the local corrupted rehearsal (section 9): 6 quarantined (`store_business_mismatch` 2, `duplicate_ambiguous_owner` 2, `duplicate_same_customer` 1, `contradicted_by_evidence` 1), 6 open conflicts, 1 cross-business order, 1 cross-business conversation and its participant (the seeded foreign PSID), 0 messages, 0 consent rows across businesses.
- PENDING: the real numbers, from Joy's post-release checklist, pasted here after the release. A detector with findings is not a clean-data certificate.

## 6. Stage 6 structural searches

`deploy/tools/structural-searches.php` (framework-free, read-only; runs on a checkout or inside the production image with `php -- --root /var/www/html < file`), wrapper `deploy/tools/structural-searches.sh` (`--with-pins` also runs the Pest twins, `--container` runs on the deployed code), Pest test with a negative control, CI step.

- S1 identity-scope: no `customer_identities` lookup by channel or external id that does not name a business (twin of W1's pin in `IdentityBusinessScopeTest`).
- S2 tool-gateway: only the Tool Gateway invokes `execute`/`reconcile`/`preview` on a tool; no tool built outside `app/Tools`; no effectful service reached around its tool; no reader of the retired `scheduler.gateway` control (twin of `GatewayCoverageStructureTest`).
- S3 consent-reader: the consent ledger, projection and tables named only by sixteen reviewed files or directories, each with its reason. NEW: no Pest pin existed for consent readers; the search and its Pest test are now that pin.
- S4 playbook-reader: loader only from the context assembler; writer, models and tables only in the store, its screen and the models; the deciding code never says "playbook" (twin of `PlaybookStructureTest`).

Results:

- On this branch (16 October, same `app/` as main): `PASS` S1, S2, S3, S4, `RESULT: 4 passed, 0 failed`. With `--with-pins`: the four Pest twins also PASS.
- Negative control (Pest): one planted offender per search in a copy of `app/`; each search FAILs and names exactly its planted file; no other search names it.
- On the source of production `b96a396` (`git archive b96a396 apps/hq/app`): `FAIL S1` with five offenders (the unscoped lookups in `ConversationService` and `IdentityResolver`: the leak W1 fixes), `FAIL S2` with five offenders (`RunScheduledJob` calls `$tool->execute(`/`$tool->reconcile(` directly behind `scheduler.gateway`; `FactsReconcile` reaches the reconciler around its tool: the bypass docs/116 retired), `PASS` S3 and S4. The searches find, on the real old code, exactly the defects this release removes: a positive control on real history.
- PENDING: the searches on the final candidate (CI runs them), and on the deployed code after the release (post-release checklist section 6). If W2 adds a consent reader, S3 fails at merge and the file must be reviewed and added with its reason in the same change; that is the point of the pin.

## 7. Timestamp audit findings

docs/124 (W3, merged `fa5dd03`): nine named readers that bound a `*_at` column at "now" classified SAFE, none AFFECTED; five pin tests hold the assumptions the SAFE verdicts rest on; no code change. W5-TZ (merged in `357fabc`, docs/125): the shops' timestamps are read as UTC. Nothing pending here.

## 8. Outcome and follow-up implementation; structural no-outbound proof

docs/107 (S3 outcome ledger), docs/113 and docs/115 (follow-ups, `outcomes.attribute`), docs/123 (W4 S4 outcome follow-ups, merged `873e372`): the outcome ledger is append-only at the database (six triggers, verified in section 9), follow-ups and opportunities carry vocabulary CHECKs, deciding needs `hq.followups.decide`, retention v3. No-outbound proof: `ListedWorkflowDefinitionsStructureTest` allows only sleep and signal steps in every definition production would list (no tool step), `OutcomeWritePathStructureTest` pins the writers and readers of the ledger, and S2 (section 6) proves no effectful call outside the gateway. `outcomes.attribute` is human-only and held by no agent (docs/115). Gate of that branch: 2,066 passed. Nothing pending here beyond the combined CI.

## 9. Migration rehearsal (D.1), LOCAL and SQL-level: EXECUTED

What it is: `deploy/rehearsal/local-sql-rehearsal.sh`, run on 16 October 2026 on this PC against Laragon PostgreSQL 17.2 (no Docker: Docker is not available here). Production commit `b96a396` and the candidate tree (this branch, `fdaa839`, whose `apps/hq` equals main's except the new Pest test file; the same ten migrations) were exported with `git archive` (no worktree), one `vendor/` (identical `composer.lock` in both commits, checked by the script) moved between them. Two throwaway databases, `hq_rehearsal_local_corrupted` and `hq_rehearsal_local_clean`, each with a `_restorecheck` twin, all dropped at the end (`pg_database` count of `hq_rehearsal%` afterwards: 0).

What it is not: no image built, no `deploy.sh`, no production dump, no compose isolation. Those are D.2 (section 10).

Commands (both runs):

```
bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from apps/hq --work <scratch>/lr-corrupted --scenario corrupted --pg-bin /c/laragon/bin/postgresql/postgresql/bin
bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from apps/hq --work <scratch>/lr-clean --scenario clean --pg-bin /c/laragon/bin/postgresql/postgresql/bin
```

Steps and evidence (counts only), corrupted scenario (production shape):

- Production schema: `b96a396`'s own 57 migrations, last `2026_10_04_100000_create_workflow_tables`; its `DatabaseSeeder` and `AgentSeeder` (35 permissions, 3 agents paused at a0, Meta flags off).
- Realistic rows (`sql/seed-realistic.sql`): 65 customers (40 LaLaDia, 25 Bionic), 193 identities, 90 orders, 6 conversations with participants and 18 messages, 2 consent events and projection rows, 12 decision records, 4 agent runs, 1 evaluation run with 2 results.
- Corruption (`sql/seed-corruption.sql`, the docs/121 section 5 shape plus the ruling's other cases): 8 identities, 2 orders, 1 conversation.
- `identity-scope-readonly.sql` ran on the copy (34 lines); the pre-release posture read ran in a read-only session (`read_only=on`).
- BEFORE snapshot: 90 tables, 57 migrations, 398 snapshot lines. Pre-migration `pg_dump -Fc` taken (about 419 KB).
- Candidate `migrate --force`: 10 new migrations in one batch (batch 2).
- Constraints (`sql/verify-constraints.sql`): 21 of 21 PASS.
- Permission seeder `--class`: the 13 new permissions exist (48 in total); a second run is clean (idempotent).
- Dry runs: detector `4 finding(s)` (identities #195, #196 store of another business; order #91; conversation #7), cost attribution `1 run(s) read; none written (dry run); 1 incomplete (never written)` (2 results unaccounted: no `ai_runs` evidence, so never written, never guessed), decisions `4 decision record(s) without a business` (8 assigned from reliable context: 6 conversations, 2 agent runs; 4 left unassigned with reasons). Every table count identical before and after the dry runs. The same three commands also completed in a session PostgreSQL held read-only (`PGOPTIONS=-cdefault_transaction_read_only=on`): they do not write.
- Quarantine (`sql/show-quarantine.sql`): as section 5; the phone with evidence in its own business stayed live; `store_mismatch_total 2 quarantined=2`; `cross_business_orders_remaining 1`.
- Post-release scope read (`deploy/tools/post-release-scope-readonly.sql`, read-only session): `identity_owner_business_mismatch 0`, `identity_live_store_other_business 0`, `identity_live_duplicate_keys 0`, `quarantined_without_open_conflict 0`, `cross_business_orders 1`, `cross_business_conversations 1`, `participants_on_quarantined_identity 1` (the seeded PSID's thread; production has no such thread, docs/121 section 5).
- Posture (`deploy/tools/release-posture-readonly.sql`, read-only session): identical before and after the release.
- ROLLBACK, one step at a time:
  - `2026_10_15_510000_add_business_id_to_decision_records`: ROLLED BACK.
  - `2026_10_15_400000_s4_outcome_followups`: ROLLED BACK.
  - `2026_10_15_100000_scope_customer_identities_to_business`: REFUSED, exit 1, still recorded: "Refusing to roll back: 6 identity conflict(s) are recorded, and dropping them loses audit evidence. Restore the pre-migration backup instead". The refusal left the schema whole (PostgreSQL runs the migration's `down()` in a transaction).
  - Migrations 1 to 7 were not reached (`migrate:rollback --step=1` stops at the refusal).
  - After the partial rollback every pre-existing table has its BEFORE count except the seeder's additive permission rows (permissions 35 to 48, role_has_permissions 91 to 125: no `down()` removes permissions, by design).
- RESTORE PROOF: the pre-migration dump restored into a second throwaway database reproduces the BEFORE snapshot EXACTLY (398 lines: counts, migrations, constraints, indexes, triggers, columns).
- RE-APPLY: `migrate --force` ran the 2 rolled-back migrations clean; constraints 21 of 21 PASS again; the schema and counts equal the first application.
- Verdicts: 28 PASS, 2 WARN (no repair command on the candidate; the W1 refusal), 0 FAIL. `RESULT: local rehearsal PASSED`.

Clean scenario (realistic rows, no corruption): W1 quarantined nothing; the detector reported `No relationship crosses a business boundary`; ALL TEN migrations rolled back in order (510000, 400000, 100000 W1, 11_100000, 800000 follow-ups, 800000 consent identity, 700000 consent brand, 300000 playbooks, 100000 outcomes, 100000 cost) and stopped at `2026_10_04_100000`, which production already had; the fully rolled-back database equals the BEFORE snapshot exactly (permission rows aside); the restore proof and re-apply of all ten PASS. Verdicts: 28 PASS, 1 WARN (no repair command), 0 FAIL.

What this proves, and what it does not:

- Proves: the ten migrations apply cleanly to the `b96a396` schema with realistic data; W1 quarantines the docs/121 shape as designed; constraints hold; the seeder is additive and idempotent; the dry runs write nothing; the posture is untouched; every `down()` is real on clean data; on production-shaped data W1's `down()` REFUSES, so the release is a one-way door; a `pg_dump -Fc` taken before the migration restores exactly.
- Does not prove: anything about production's real rows beyond the docs/121 shape; the real `deploy/backup.sh` and `deploy/restore.sh`; the image, the entrypoint's `migrate --force`, compose isolation; W2's migration and repair. Those are section 10 and section 4.
- Found and fixed on the way: `rehearse.sh`'s check for the quarantined `shop_user` expected a double space and would have failed on correct data; corrected in both scripts (commit `0a00c8c`).

## 10. Deployment rehearsal (D.2): PENDING, not executed

- There is NO dedicated staging environment. `deploy/staging/` is a definition that has never been started. No literal staging deployment has occurred, and none is claimed.
- The disposable isolated environment the ruling allows is `deploy/rehearsal/rehearse.sh`: an isolated compose project `bordeguna-hq-rehearsal` built from the candidate's own deploy method (its `deploy.sh`, Dockerfile, entrypoint, `backup.sh`, `restore.sh`), Traefik disabled, its own volumes and backup directory, a fresh `APP_KEY` so restored credentials cannot be decrypted. It covers every D.2 item: backup before checkout and migration (the verified dump line precedes `releasing`), release identity (image label and `/api/health` name the commit), migration through the entrypoint and the permission seeder, health, read-only evidence, rollback until refusal and the restore proof with the real `restore.sh`, the posture unchanged (no agent, provider, flag or grant activation).
- Guards proven (`deploy/rehearsal/tests/rehearsal-guard-test.sh`, 48 checks, also in CI): refuses the production and staging project names, the `hq` and `postgres` databases, the production `DB_DATABASE`, a root inside `/opt/bordeguna-hq` or `/root/backups/bordeguna-hq`, before any docker call and without writing a file; `make-compose.sh` turns the REAL `deploy/docker-compose.yml` into a file naming neither `hq.bordeguna.com` nor `/root/backups`.
- NOT executed: Docker is not available on this PC, and the rehearsal needs a production dump. Exact command, on the VPS, as root, from a clone that is not `/opt/bordeguna-hq`, after the candidate exists:

```
git clone /opt/bordeguna-hq /root/hq-rehearsal-src && cd /root/hq-rehearsal-src && git checkout <candidate>
bash deploy/rehearsal/rehearse.sh --plan --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption
bash deploy/rehearsal/rehearse.sh        --dump /root/backups/bordeguna-hq/daily/<newest>.dump --candidate <candidate> --seed-corruption
```

- It needs: the candidate commit (after the W2 merge and green CI), a verified production dump, and Joy's explicit go (it runs on the production host, beside production, and reads a production dump).

## 11. Cost-attribution coverage and backfill dry-run figures

- Mechanism (docs/106): `evaluation_runs.cost_attribution` with linked, no-call and unaccounted results; `hq:evaluation:attribute-cost` dry run by default, `--apply` writes only complete runs.
- Rehearsal (section 9): 1 run read, 0 written, 1 incomplete (2 results unaccounted, never written); no estimate, no guess. The command completed in a read-only session.
- PENDING: production figures. The command does not exist on `b96a396`; it runs on the rehearsal copy of the production dump (section 10) and after the release (post-release checklist section 5). The backfill itself (`--apply`) stays separately gated (ruling D.5).

## 12. Checklists and rollback/restore

- `deploy/checklists/pre-release-readonly.md` (D.3) and `deploy/checklists/post-release-readonly.md` (D.4): exact commands, expected outputs, and why each cannot write. Every `psql` and every `artisan` runs with `PGOPTIONS=-cdefault_transaction_read_only=on` (PostgreSQL refuses any write of that session; libpq applies it to the application's own connection too, proven locally), every SQL file is `BEGIN READ ONLY ... ROLLBACK`, and the structural searches read source only. New read-only SQL: `deploy/tools/release-posture-readonly.sql` (runs on both schemas) and `deploy/tools/post-release-scope-readonly.sql` (candidate schema); both were exercised by the local rehearsal.
- Rollback: migration `2026_10_15_100000_scope_customer_identities_to_business` REFUSES `down()` once a conflict row exists, and production will have two. Rollback of this release is therefore NOT `migrate:rollback`: it is `./deploy/rollback.sh b96a396` (code only, no migration) followed by restoring the pre-deploy dump `deploy.sh` takes and verifies before the checkout moves (deploy/README.md section "Rollback"). Rehearsed at SQL level (section 9: exact restore); PENDING with the real `restore.sh` and a production dump (section 10).

## 13. Deviations and new decisions

- The local SQL-level rehearsal is an addition, not a substitute: the ruling's D.1 asks for a production-shaped isolated copy; this run used the production schema with realistic and seeded rows, not a production dump. Stated plainly in section 9.
- S3 (consent readers) is a new structural pin: its allow-list is the current set of sixteen files or directories, each reviewed and annotated. Widening it is a reviewed change, not a way to turn a red search green.
- The checklists use `PGOPTIONS=-cdefault_transaction_read_only=on` as the mechanism that makes read-only demonstrable. It is a PostgreSQL session setting, not a new architecture.
- The permission seeder is run by Joy after `deploy.sh` (it is not in `deploy.sh`); the post-release checklist lists it as the one write of the release procedure, separate from the reads.
- No new architecture decision.

## 14. Verdict: NOT READY

Release readiness requires code, schema, data-isolation and operational recovery evidence (ruling D.6). Blocking conditions, each with what clears it:

1. CLEARED 16 Oct (sections 15 and 16): W2 merged on `a7bb85e`; its repair dry run exercised against the corruption shapes; its migration's rollback verdict recorded in section 16. Was: W2 identity repair is not merged and partly unexecuted (`p3/identity-repair`). Clears with: W2 finished, gated, merged; its migration's `down()` verdict added to docs/127; the repair dry run exercised against the corruption shapes.
2. No release candidate exists. Clears with: W2 and W7 merged `--no-ff`; the combined full Pest suite, Pint, `tsc`, Vite and GitHub Actions green on that exact merge commit, investigated across streams.
3. The Docker deployment and migration rehearsal (D.1 on a production dump, D.2) has not been executed. Clears with: `rehearse.sh` on the VPS against the candidate and a verified production dump, Joy's go, `RESULT: rehearsal PASSED`, evidence pasted into section 10.
4. The restore path has been proven at SQL level only. Clears with: the restore proof inside the Docker rehearsal (real `restore.sh`, production dump).
5. CLEARED 16 Oct (section 16): the local SQL rehearsal re-run on the candidate `a7bb85e` with W2's migration `2026_10_15_600000` and the repair dry run, both scenarios PASS. Was: clears with: `local-sql-rehearsal.sh` on the candidate, both scenarios PASS.
6. Containment choice open (docs/121 section 5, Option 1 or 2). Clears with: Joy's decision recorded.
7. Post-release verification (D.4) cannot precede a release. After Joy independently releases: the post-release checklist, pasted here, before any repair or backfill; the remaining quarantine reported as found.

8. CLEARED 16 Oct: the `DecisionCorpusReaderTest` second-boundary flake (section 3) is fixed by a `travelTo` two whole seconds ahead before the two reads (3 of 3 runs green). Was: clears with: the test freezes time (a one-line `travelTo`/`freezeTime` fix in that test), so the combined gate cannot go red or green by chance.

Until 1 to 6 and 8 are cleared, this release is not ready, and it must not be described as ready because CI is green.

## 15. Update 16 Oct: W2 and W7 merged on main a7bb85e
- Combined gate on the full tree: 2,143 Pest passed, 0 failed; Pint, tsc, Vite clean; structural searches 4/4 PASS (W2's two gateway call sites and two consent readers reviewed and allowed with reasons). Blocker 1 (W2 not merged) cleared; blocker 8 (flake) cleared.
- NEW BLOCKER: GitHub Actions run 38912755286 on a7bb85e was not started: "recent account payments have failed or your spending limit needs to be increased". CI on the exact candidate (ruling D, C stage 6) cannot be produced until billing allows it. The last green CI is 38681310680 on 357fabc.
- Still blocking: the local SQL rehearsal re-run on the candidate (now including 2026_10_15_600000), the VPS Docker rehearsal with real restore.sh (production dump + Joy's go), Joy's containment choice, post-release checks. Verdict: NOT READY.

## 16. Local rehearsal on the candidate a7bb85e (16 Oct)

What it is: the same local, SQL-level rehearsal as section 9 (Laragon PostgreSQL 17, no Docker, no production dump, nothing on the VPS), now on the release candidate `a7bb85e`, which carries W2's migration `2026_10_15_600000_create_identity_repair_tables` (eleven release migrations). Production commit `b96a396`, candidate `a7bb85e`, both exported with `git archive`; `composer.lock` identical in both (checked by the script).

Script changes (small, in this commit):

- `deploy/rehearsal/sql/row-hashes.sql` (new, `BEGIN READ ONLY ... ROLLBACK`): per table, the row count and the md5 of every row's text in a deterministic order. It prints no row content.
- `local-sql-rehearsal.sh`: runs `php artisan hq:identity:repair --recount` (dry run, never `--apply`, `--key` or `--by`) after the detector, cost and decisions dry runs, and again in a session PostgreSQL holds read-only; FAILS if the candidate has no repair command (was a WARN "no repair command"); proves at row level that the four dry runs wrote nothing (row hashes before and after); proves at row level that the restored dump equals BEFORE; the permission check now expects fourteen permissions (W2's `hq.customers.quarantine.repair`).
- `deploy/rehearsal/README.md`: the new SQL file and the extended dry runs. The guard suite still passes: `bash deploy/rehearsal/tests/rehearsal-guard-test.sh`, 48 passed, 0 failed.

Commands (from the worktree on `platform/rehearsal-candidate`, scratch directories that did not exist):

```
bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from apps/hq --work <scratch>/lr16b-corrupted --scenario corrupted --candidate a7bb85e --pg-bin /c/laragon/bin/postgresql/postgresql/bin
bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from apps/hq --work <scratch>/lr16b-clean --scenario clean --candidate a7bb85e --pg-bin /c/laragon/bin/postgresql/postgresql/bin
```

Corrupted scenario (docs/121 section 5 shape plus duplicates, ambiguous ownership, foreign evidence): 31 PASS, 1 WARN, 0 FAIL, `RESULT: local rehearsal PASSED`.

- Production schema: 57 migrations; realistic rows 65 customers, 193 identities, 90 orders, 6 conversations, 12 decision records; seeded corruption 8 identities, 2 orders.
- BEFORE: 90 tables, 57 migrations, 398 snapshot lines, row hashes of all 90 tables; pre-migration `pg_dump -Fc` 419,064 bytes.
- Candidate `migrate --force`: 11 new migrations in one batch (batch 2). Constraints 21 of 21 PASS.
- Permission seeder `--class`: the fourteen wave 1 to 4 permissions exist, `hq.customers.quarantine.repair` included (49 in total); second run clean.
- `php artisan hq:identity:cross-business --dry-run`: 18 finding(s) (W2 widened the detector, item 39): identities #195 and #196 (quarantined, and store of another business), #198, #199, #200, #201 (quarantined); conflicts #1 to #6 (`identity_conflict_quarantined`); order #91 (`order_other_business`); conversation #7 (`conversation_other_business`); participant #7 (`participant_identity_other_business`, `participant_other_business`). Every finding: "no merge lineage". Ends "Dry run: nothing written."
- `php artisan hq:identity:repair --recount` (dry run), the known case, as printed except the hashes shortened:
  - Batch for business [bionic] (#3), key `cb1672f5...`: 1 split(s); split `9b03cc46...`, from customer #66 (laladia) to a NEW customer of [bionic].
  - Lineage: no merge lineage (an unscoped lookup attached the rows).
  - Rows: 1 order (#91), 1 conversation (#7), 1 participant, 0 messages, 0 memories with a business.
  - Identities: 2 back live under the target (#195, #196), 0 superseded, 1 copied (phone), 2 conflicts resolved.
  - New customer: "phone from the order, nothing else (no name or e-mail of the other business is copied)".
  - Withheld for a person: 0 business-less memories, 0 consent rows to re-record.
  - Review: NOT YET (needs_repair 0, confirmed_wrong 0, disputed 0, not reviewed 5; flags none yet). Not reviewed: conversation_participants:7, conversations:7, customer_identities:195, customer_identities:196, facts_orders:91.
  - 1 ambiguous case: conversation_participants#7 under customer #66 ("the participant carries an identity of another business than its thread; which customer it names is a person's call").
  - 4 identities stay quarantined: #198 (phone, duplicate_same_customer), #199 and #200 (email, duplicate_ambiguous_owner), #201 (messenger_psid, contradicted_by_evidence), each "its business cannot be established from the rows without guessing". 4 conflicts stay open (#3 to #6).
  - Recount by business: business 2 (laladia) 42 customers, 61 orders all under own customer, 126 live and 6 held identities; business 3 (bionic) 25 customers, 31 orders, 30 under own customer (the 31st is #91, still crossing), 69 live identities.
  - Customer #66: orders stored 0, from rows 1; value stored 0.00, from rows 1.00; 2 foreign rows still under; "MISMATCH: a stored figure differs from the rows". Cause: `sql/seed-corruption.sql` inserts order #91 without updating the customer's stored aggregates (`seed-realistic.sql` does update them). The recount found exactly that. Production's stored figures are unknown until the D.2 rehearsal on a dump.
  - "Dry run: nothing written."
  - Personal values: none in either output (the new customer's phone is printed as "from the order"; no phone digits, name or e-mail appears; checked by search). The seeded rows are synthetic in any case.
  - The batch key differs from an earlier run on a separately seeded copy (`2dbcdb94...`) while the split key is identical: the key includes each split's fingerprint, which covers row state, by design ("the dry run a person read is the only one that applies").
- Nothing written by the dry runs: every table count unchanged, and at row level all 102 tables hold identical rows (count and md5 of every row) before and after the detector, cost, decisions and repair dry runs. All four also completed in a session with `default_transaction_read_only=on`.
- Cost dry run: 1 run read, none written, 1 incomplete. Decisions report: 4 records without a business, with reasons.
- W1 quarantine as section 9; the phone with evidence in its own business stayed live. Post-release scope read: identity_owner_business_mismatch 0, identity_live_store_other_business 0, participants_on_quarantined_identity 1, cross_business_orders 1, cross_business_conversations 1. Checklist posture identical before and after.
- ROLLBACK, one step at a time:
  - `2026_10_15_600000_create_identity_repair_tables`: down() RAN (rolled back). Its refusal guards (a merge reversal recorded, or a quarantine flag of a new kind) were not triggered: the rehearsal only runs dry runs, so no reversal and no flag exists. It refuses only after `--flag` or `--apply` have written evidence.
  - `2026_10_15_510000_add_business_id_to_decision_records`: ROLLED BACK.
  - `2026_10_15_400000_s4_outcome_followups`: ROLLED BACK.
  - `2026_10_15_100000_scope_customer_identities_to_business`: REFUSED, exit 1, still recorded: "Refusing to roll back: 6 identity conflict(s) are recorded, and dropping them loses audit evidence. Restore the pre-migration backup instead". The schema stayed whole.
  - After the partial rollback every pre-existing table has its BEFORE count except the seeder's additive permission rows (permissions 35 to 49, role_has_permissions 91 to 127).
- RESTORE PROOF: the pre-migration dump restored into a second throwaway database equals the BEFORE snapshot exactly (398 lines: counts, migrations, constraints, indexes, triggers, columns) AND row for row (all 90 tables, count and md5 of every row).
- RE-APPLY: the 3 rolled-back migrations ran clean; constraints 21 of 21 PASS; schema and counts equal the first application; posture unchanged (Meta flags false, three agents paused at a0, 0 active grants, 0 open shadow periods, 0 scheduled jobs).
- The one WARN is the W1 refusal: the release is a one-way door; recovery is the dump restore.

Clean scenario (realistic rows, no corruption): 31 PASS, 0 WARN, 0 FAIL, `RESULT: local rehearsal PASSED`.

- Detector: "No relationship crosses a business boundary. Nothing to flag." Repair dry run: 0 splits, 0 ambiguous, 0 identities held, 0 conflicts open, "Dry run: nothing written." Row-level no-write proof over 102 tables PASS; read-only session PASS.
- ALL ELEVEN migrations rolled back in order: 600000 (W2), 510000, 400000, 100000 (W1), 11_100000, 800000 follow-ups, 800000 consent identity, 700000 consent brand, 300000 playbooks, 100000 outcomes, 100000 cost; stopped at `2026_10_04_100000`, which production already had. The fully rolled-back database equals BEFORE (permission rows aside).
- Restore proof (snapshot and row level) PASS; re-apply of all 11 clean; constraints 21 of 21; posture unchanged.

Teardown: the script dropped `hq_rehearsal_local_corrupted`, `hq_rehearsal_local_clean` and both `_restorecheck` twins (teardown "remaining: 0" each); afterwards `SELECT count(*) FROM pg_database WHERE datname LIKE 'hq_rehearsal%'` returned 0.

What this does not prove: production's real rows and stored aggregates, the real `backup.sh` and `restore.sh`, the image and entrypoint, compose isolation (D.2, section 10); the `--flag` and `--apply` paths and therefore W2's own `down()` refusal on real evidence (both write, and are gated on Joy).

Blockers after this section (section 14 numbering):

- 1 CLEARED (W2 merged, repair dry run exercised, rollback verdict recorded).
- 2 OPEN in part: the candidate `a7bb85e` exists and its local gate is green (section 15), but CI on that exact commit has not run (GitHub billing, section 15).
- 3 OPEN: the Docker rehearsal `rehearse.sh` on the VPS with a production dump and Joy's go.
- 4 OPEN: restore proven at SQL level only (now row level too); the real `restore.sh` on a production dump is still section 10.
- 5 CLEARED (this section).
- 6 OPEN: Joy's containment choice (docs/121 section 5, Option 1 or 2).
- 7 OPEN by nature: post-release verification after Joy releases.
- 8 CLEARED (section 3, flake).

Verdict unchanged: NOT READY.

## 17. Status 16 Oct night

Main is `5bb9337` (the rollback-flag merge on top of `3755d27`). Nothing was released, nothing touched production, no repair or backfill ran. The plain-English package for Joy is `docs/134`; the release notes are updated in `docs/127`.

Cleared since section 16:

- Event preservation (docs/132, merged `69a1bb1`): a webhook is committed before the 202; nothing but the drain moves a pending row; replay is idempotent; `hq:events:export-since` and `hq:events:import` bridge a restore; `HQ_EVENTS_DRAIN_PAUSED` (off by default) keeps the drain from resuming on release. 14 tests in `EventPreservationTest`; `rehearse.sh` section 10 written (not yet run).
- Time-zone backfill dry run (docs/133, merged `3755d27`, ruling item 8): `hq:facts:tz-backfill`, evidence only, dry run by default, ambiguous rows listed and never changed, apply gated on `--key --by --reason`; migration `2026_10_16_100000_create_facts_tz_corrections_table`; wired into both rehearsal scripts and the post-release checklist (apply line labelled JOY-AUTHORIZED).
- Item 4, follow-up self-decision (docs/133, merged `3755d27`): refused once a second eligible decider exists; the sole decider's approval is recorded `self_decided` with a bootstrap audit row.
- Rollback flag (docs/132 section 9, merged `5bb9337`): opt-in `--keep-scheduler-stopped` on `deploy.sh` and `rollback.sh`, defaults unchanged, 56 safety-suite assertions with a stubbed Docker.
- Local gate: 2,166 Pest passed, 0 failed, on `3755d27`; `5bb9337` adds no application code. Structural searches S1 to S4 PASS.
- Checklists: expected counts updated to twelve migrations (69 in total, last `2026_10_16_100000_create_facts_tz_corrections_table`) and fourteen permissions.

Open blockers:

1. CI billing. GitHub Actions has not run since run 38912755286 ("recent account payments have failed or your spending limit needs to be increased"). Clears with: Joy fixes billing; CI green on the exact frozen candidate, run id recorded.
2. Candidate freeze. `a7bb85e` is withdrawn (it lacks docs/132, docs/133 and the rollback flag). Clears with: a commit at or after `5bb9337` frozen, the full gate (Pest, Pint, `tsc`, Vite, the four structural searches with negative controls) and CI green on it.
3. VPS Docker rehearsal with the real `restore.sh`, including `rehearse.sh` section 10 (the event bridge across a restore). Clears with: Joy's go, a fresh production dump, `RESULT: rehearsal PASSED` on the frozen candidate, evidence pasted into section 10. The local SQL rehearsal has since run on `ac03cff`, which carries migration 12 and the tz dry run (section 18); the VPS rehearsal still has to cover both on a production dump.
4. Containment decision. Clears with: Joy's choice recorded (Option 2 recommended by docs/131 section 1.1; the exact command is in docs/134 section 5 item 2). The incident is not claimed contained.
5. Post-release checks. Open by nature: after Joy releases, the post-release checklist output pasted here before any flag write, repair or backfill.

Known limitations carried (docs/127 section 10): the two unfixed TZ findings (`events.occurred_at`, `customers.first_seen_at` and `last_seen_at`), shop retry behaviour unconfirmed, rows stranded at `processing` after a hard stop, `--scale scheduler=0` untested on real Docker, the release recorded UNVERIFIED while the scheduler is stopped.

Verdict: **NOT READY**.

## 18. Local rehearsal on ac03cff (16 Oct night)

What it is: the same local, SQL-level rehearsal as sections 9 and 16 (Laragon PostgreSQL 17, no Docker, no production dump, nothing on the VPS), now on the candidate `ac03cff` (main HEAD), which carries all twelve release migrations, migration 12 `2026_10_16_100000_create_facts_tz_corrections_table` included, and `hq:facts:tz-backfill`. Production commit `b96a396` (57 migrations), candidate `ac03cff` (69), both exported with `git archive`; `composer.lock` identical in both (checked by the script).

Script change (branch `platform/rehearsal-latest`): `deploy/rehearsal/sql/verify-constraints.sql` grew from 21 to 29 checks, still read only (catalogue SELECTs in a `BEGIN READ ONLY ... ROLLBACK`; `to_regclass` keeps a missing table a FAIL line, not an error):

- migration 11: `customer_identity_repairs` unique `key`, status CHECK, `customer_identity_repairs_verified_check`;
- migration 11: `customer_merge_reversals_split_unique (repair_id, split_key)` and `customer_merge_reversals_businesses_differ`;
- migration 11: `customer_merge_reversals_no_update` (BEFORE UPDATE FOR EACH ROW) and `customer_merge_reversals_no_delete` (BEFORE DELETE FOR EACH ROW), enabled, both on `hq_customer_merge_reversal_immutable()`;
- migration 11: `cross_business_quarantines_kind_check` admits `participant`, `message` and `identity_conflict` (item 39) and still the old kinds;
- migration 12: `facts_tz_corrections_once (batch_key, facts_order_id, column_name)`, `facts_tz_corrections_changes (old_value <> new_value)`, the `column_name` CHECK;
- migration 12: `facts_tz_corrections_no_update` and `facts_tz_corrections_no_delete`, BEFORE ROW, enabled, on `hq_facts_tz_correction_immutable()`;
- all three new ledgers exist and every foreign key on them is ON DELETE RESTRICT;
- migrations 11 and 12 are recorded in `migrations`.

Expected count updated to 29 in `rehearse.sh`'s verdict text, `deploy/checklists/post-release-readonly.md` and `deploy/rehearsal/README.md`. `local-sql-rehearsal.sh` already reports the count it reads.

Commands (worktree on `platform/rehearsal-latest`, scratch directories that did not exist):

```
bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from apps/hq --work <scratch>/reh-ac03cff/corrupted --scenario corrupted --candidate ac03cff --pg-bin /c/laragon/bin/postgresql/postgresql/bin
bash deploy/rehearsal/local-sql-rehearsal.sh --vendor-from apps/hq --work <scratch>/reh-ac03cff/clean --scenario clean --candidate ac03cff --pg-bin /c/laragon/bin/postgresql/postgresql/bin
```

Corrupted scenario (docs/121 section 5 shape plus duplicates, ambiguous ownership, foreign evidence): 32 PASS, 1 WARN, 0 FAIL, `RESULT: local rehearsal PASSED`.

- Production schema 57 migrations; realistic rows 65 customers, 193 identities, 90 orders, 6 conversations, 12 decision records; seeded corruption 8 identities, 2 orders. BEFORE: 90 tables; pre-migration `pg_dump -Fc` 419,069 bytes.
- Candidate `migrate --force`: 12 new migrations in one batch (batch 2). Constraints 29 of 29 PASS.
- Permission seeder `--class`: the fourteen permissions exist (49 in total); second run clean.
- Dry runs: detector 18 findings (as section 16); cost attribution and decisions report ran; repair `--recount` 1 split, 1 ambiguous, 4 identities held, 4 conflicts open, and the same stored-figure MISMATCH on customer #66 that section 16 explains (the corruption seed does not update aggregates).
- TZ backfill dry run (`hq:facts:tz-backfill`, never `--apply`, `--key` or `--by`): facts_orders rows 92, timestamps examined 92, already correct by evidence 0, correctable 0, ambiguous 92 (all `no_source_evidence`: the seeds write no source events, so nothing can be proven and nothing is proposed); "no delivered sale changes day"; "DRY RUN nothing is written."
- Nothing written by the five dry runs: row hashes of all 103 tables identical before and after (count and md5 of every row), every count unchanged. All five also completed in a session with `default_transaction_read_only=on`.
- W1 quarantine as section 9; the phone with evidence in its own business stayed live; post-release scope read and checklist posture as section 16, posture identical before and after.
- ROLLBACK, one step at a time:
  - `2026_10_16_100000_create_facts_tz_corrections_table`: ROLLED BACK. Its guard (a correction recorded) was not triggered: the dry run writes no `facts_tz_corrections` row. It refuses only after an `--apply` has written a correction.
  - `2026_10_15_600000_create_identity_repair_tables`: ROLLED BACK. No merge reversal and no new-kind quarantine flag existed (the rehearsal writes neither), so its refusal guards were not triggered.
  - `2026_10_15_510000_add_business_id_to_decision_records`: ROLLED BACK.
  - `2026_10_15_400000_s4_outcome_followups`: ROLLED BACK.
  - `2026_10_15_100000_scope_customer_identities_to_business`: REFUSED, exit 1, still recorded: "Refusing to roll back: 6 identity conflict(s) are recorded, and dropping them loses audit evidence. Restore the pre-migration backup instead". This is the one WARN (one-way door).
  - After the partial rollback every pre-existing table has its BEFORE count except the seeder's additive permission rows (permissions 35 to 49, role_has_permissions 91 to 127).
- RESTORE PROOF: the pre-migration dump restored into a second throwaway database equals BEFORE exactly (398 snapshot lines) and row for row (all 90 tables).
- RE-APPLY: the 4 rolled-back migrations ran clean; constraints 29 of 29 PASS; schema and counts equal the first application; posture unchanged (Meta flags false, three agents paused at a0, 0 active grants, 0 open shadow periods, 0 scheduled jobs).

Clean scenario (realistic rows, no corruption): 32 PASS, 0 WARN, 0 FAIL, `RESULT: local rehearsal PASSED`.

- Constraints 29 of 29 PASS. Detector "No relationship crosses a business boundary. Nothing to flag."; repair dry run 0 splits, 0 ambiguous; tz dry run 90 rows, 90 timestamps, 0 correctable, 90 ambiguous (`no_source_evidence`), nothing written. Row-level no-write proof over 103 tables PASS; read-only session PASS.
- ALL TWELVE migrations rolled back in order: 16_100000 (tz), 600000 (W2), 510000, 400000, 100000 (W1), 11_100000, 800000 follow-ups, 800000 consent identity, 700000 consent brand, 300000 playbooks, 100000 outcomes, 100000 cost; stopped at `2026_10_04_100000`, which production already had. The fully rolled-back database equals BEFORE exactly (permission rows aside).
- Restore proof (snapshot and row level) PASS; re-apply of all 12 clean; constraints 29 of 29; posture unchanged.

Teardown: the script dropped `hq_rehearsal_local_corrupted`, `hq_rehearsal_local_clean` and both `_restorecheck` twins ("remaining: 0" each); afterwards `SELECT count(*) FROM pg_database WHERE datname LIKE 'hq_rehearsal%'` returned 0.

Gate: `bash -n` on every rehearsal script; rehearsal guard suite 48 passed, 0 failed; `deploy/tools/structural-searches.sh` S1 to S4 PASS. No application code changed.

What this does not prove: the tz backfill on rows that carry source evidence (the seeds have none; production's figures come from the VPS rehearsal on a dump and the post-release checklist), the `--apply` paths and therefore the evidence-triggered refusals of migrations 11 and 12, production's real rows, the real `backup.sh` and `restore.sh`, the image, entrypoint and compose isolation (section 10).

Blockers: section 17's list is unchanged except that the local rehearsal now covers migration 12 and the tz dry run. Verdict unchanged: NOT READY.

## 19. FINAL: candidate c0db11e, 17 Oct 2026

**Verdict: READY FOR JOY'S RELEASE DECISION.**

Evidence, gate by gate (ruling docs/131):
- Stage A, CI on the exact candidate: GitHub Actions run 39000064674 on `c0db11e` (tag `hq-rc-2026-10-17`), all three jobs green: Pest + Pint + TypeScript + Vite on PostgreSQL 17 / Redis 7 (11m53s), deployment/backup safety invariant, production image builds. The repository was made public by Joy on 17 Oct so Actions run without billing. Also green: run 38995695048 on `4e09793`.
- Containment: Option 2 applied by Joy 17 Oct 03:35 UTC (docs/121 §6): scheduler stopped, 0 processing, 0 pending, max event 592.
- Stage B, VPS Docker rehearsal on a fresh production dump (`hq-20261017-062904.dump`, 90 tables), isolated project `bordeguna-hq-rehearsal`, candidate `c0db11e`:
  - Run 1 (06:29 UTC): every gate passed except one harness verdict, which misread its own evidence ("( of 4)"); the evidence line itself read `store_mismatch_total 4 quarantined=4`. Harness fixed on `platform/rehearsal-harness-fix` (`5408694`); the candidate code is unchanged.
  - Run 2 (06:37 UTC): `RESULT: rehearsal PASSED`, 0 FAIL, 3 expected WARN. Evidence `/root/hq-rehearsal/20261017-063754/evidence` on the VPS.
  - Proven there: the real `deploy/restore.sh` restored the dump; deploy.sh took and verified a pre-deploy dump before releasing; 12 migrations applied; constraints 29/29; seeder idempotent; detector, cost, repair and tz dry runs wrote nothing (row level, all 103 tables); W1 quarantined 4 of 4 store-scoped mismatches and the other seeded corruption, the own-business phone stayed live; rollback ran 4 migrations then the W1 migration REFUSED its down() (the documented one-way door); the pre-deploy dump restores to EXACTLY the BEFORE snapshot; re-apply clean; posture unchanged; event bridge: three deliveries after the dump survived a real restore with event_id, dedupe key and payload unchanged, pending, unprocessed, audited, second import idempotent.
  - Warnings, all expected: the W1 down() refusal; permission and role_has_permissions counts after rollback and on re-apply (seeder rows are additive).
- Seeder on production data adds 15 permissions, not 14: the 14 of waves 1-4 plus `hq.tools.ads.read`, a reserved Phase 5 permission present in production's code since 26 Sep (`1275482`) that production's seeder has not been run for since. No ads tool is registered; nothing checks it.
- Time-zone dry run on the production copy: 46 order facts, 111 timestamps, 18 already correct by evidence, 0 correctable, 93 ambiguous (no source evidence). The tz backfill would change nothing on today's data.
- Detector dry run on the production copy plus the seeded rows: 25 findings.

Residual items that do not block the decision but stay open: shop webhook retry behaviour unconfirmed (keep restore windows short); `--scale scheduler=0` proven only by stub (check `ps scheduler` at release, docs/134 §5 step 4g); `events.occurred_at` and `customers.first/last_seen_at` stored without `->utc()` (docs/133, needs its own ruling); rows stuck at `processing` after a hard stop have no relabel path (there were 0 at containment).

The release itself, the repair, both backfills, the drain resume and the shadow week each remain Joy's explicit, separate decisions (docs/134 §5).
