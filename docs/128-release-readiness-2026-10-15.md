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

1. W2 identity repair is not merged and partly unexecuted (`p3/identity-repair`). Clears with: W2 finished, gated, merged; its migration's `down()` verdict added to docs/127; the repair dry run exercised against the corruption shapes.
2. No release candidate exists. Clears with: W2 and W7 merged `--no-ff`; the combined full Pest suite, Pint, `tsc`, Vite and GitHub Actions green on that exact merge commit, investigated across streams.
3. The Docker deployment and migration rehearsal (D.1 on a production dump, D.2) has not been executed. Clears with: `rehearse.sh` on the VPS against the candidate and a verified production dump, Joy's go, `RESULT: rehearsal PASSED`, evidence pasted into section 10.
4. The restore path has been proven at SQL level only. Clears with: the restore proof inside the Docker rehearsal (real `restore.sh`, production dump).
5. The local SQL rehearsal must be re-run on the candidate once W2's migration is in (the rollback chain changes). Clears with: `local-sql-rehearsal.sh` on the candidate, both scenarios PASS.
6. Containment choice open (docs/121 section 5, Option 1 or 2). Clears with: Joy's decision recorded.
7. Post-release verification (D.4) cannot precede a release. After Joy independently releases: the post-release checklist, pasted here, before any repair or backfill; the remaining quarantine reported as found.

8. CLEARED 16 Oct: the `DecisionCorpusReaderTest` second-boundary flake (section 3) is fixed by a `travelTo` two whole seconds ahead before the two reads (3 of 3 runs green). Was: clears with: the test freezes time (a one-line `travelTo`/`freezeTime` fix in that test), so the combined gate cannot go red or green by chance.

Until 1 to 6 and 8 are cleared, this release is not ready, and it must not be described as ready because CI is green.
