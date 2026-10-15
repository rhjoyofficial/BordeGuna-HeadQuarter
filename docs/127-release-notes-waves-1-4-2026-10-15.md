# 127. Consolidated release notes, waves 1 to 4: main against production `b96a396` (15 October 2026)

Authority: `docs/120-chatgpt-ruling-2026-10-15.txt` section D ("Create consolidated release notes for Waves 1 to 4 and a candidate manifest"). This file describes what main carries that production does not. It is a description, not an authorization: Joy alone releases, and the ruling's own words apply: the cross-business identity leak blocks release of waves 1 to 3 until the repair (W2) is built, rehearsed and verified; the readiness verdict lives in `docs/128`.

Read against: production `b96a396` (released 9 October, inert, healthy), main `7fb5e71` on 15 October (differs from the CI-green `357fabc` by three docs commits only: `git diff --stat 357fabc..7fb5e71` touches `docs/` alone). Every statement below was read from the diff `b96a396..7fb5e71`; nothing was read from production.

## 1. What the release is, in one paragraph

Think of HQ as a building where, until now, every business shared one key board. This release gives each business its own board bolted to the wall (business-scoped identities, W1), adds four locked filing cabinets that nothing in the building can open yet (the outcome ledger, the playbook store, follow-ups, consent operations), hangs new "do not enter" signs on the doors agents are not allowed through (structural pins), and leaves every switch exactly where it was: AI runtime off, every agent paused at A0, zero grants, Meta inbound off, no shadow period. It also writes two numbers it never wrote before (evaluation cost, a decision's business) and corrects how the shops' timestamps are read. The identity fix is one-way once it quarantines a row; the repair of what the leak already mixed is NOT in this release (W2).

## 2. Merged workstreams (merge commits, newest first)

- `357fabc` W5 small items + W5-TZ (docs/125): ruling items 1 to 7, 18 to 35, 40 to 42; shop timestamps to UTC.
- `873e372` W4 S4 outcome follow-ups (docs/123): follow-ups on the S3 ledger, Customer 360 section, overdue alert, retention v3, no-tool-step pin.
- `e5e9da0` W0 + W1 identity business scope (docs/121, docs/122): containment evidence, business-scoped identities, resolver, defence in depth, item 38.
- `fa5dd03` W3 whole-second audit (docs/124): nine readers SAFE, five pin tests, no code change.
- `5812d42` S2 quarantine of cross-business merges (docs/117): detector, flags, review screen, suppression.
- `2bb62a7` cost pre-throw + scheduler bypass retirement (docs/116): no migration.
- `e0a492a` S3 follow-ups, HQ scope, `outcomes.attribute` tool (docs/115).
- `3f3f117` S7 identity scope + two-person lift (docs/114).
- `574b062` S4 part 1 follow-up and opportunity contracts (docs/113).
- `89cdd19` S6 playbook store (docs/112).
- `cee3440` S5 decision corpus reader (docs/111).
- `5628496` S2 Customer 360 (docs/109).
- `4d0e8e9` S7 consent enforcement (docs/108).
- `d6eaf66` S3 outcome model (docs/107).
- `48fbe7e` evaluation cost attribution (docs/106).
- `35246dd` S1 safety preconditions (docs/105).

## 3. Migrations since `b96a396`, in execution order

Ten files, all new (none modified), listed by `git diff --name-only b96a396..HEAD -- apps/hq/database/migrations`. Laravel runs them in filename order, in ONE batch, from the `app` container's entrypoint (`migrate --force`) during `deploy.sh`. "Additive" means: existing rows are not changed and nothing the old code reads is removed. Each `down()` was read.

1. `2026_10_10_100000_add_cost_attribution_to_evaluation` (docs/106)
   - Additive: `evaluation_results.correlation_id` (nullable, index); `evaluation_runs.cost_estimated_usd`, `cost_provider_usd`, `cost_attribution` (nullable).
   - Backfill: none. Historical runs keep `cost_usd = 0` until `hq:evaluation:attribute-cost --apply` (separately gated, D.5).
   - `down()`: REAL, unconditional. Drops the four columns and the index. A rollback loses attributed costs written after the release (none exist until `--apply`).
2. `2026_10_10_100000_create_outcome_attribution_tables` (docs/107)
   - Additive: tables `outcomes`, `outcome_result_refs`, `outcome_attributions`; CHECKs; function `hq_outcome_ledger_immutable()` and six BEFORE UPDATE/DELETE triggers (append-only at the database); every FK RESTRICT; composite FK `(outcome_id, business_id) -> outcomes (id, business_id)`.
   - Backfill: none. The ledger ships empty and inert (nothing on main writes it except the human-only tool, nothing reads it for decisions).
   - `down()`: REAL, unconditional. Drops the triggers, the function and the three tables. Rows written by `outcomes.attribute` would be lost; on production there are none.
3. `2026_10_10_300000_create_playbook_versions` (docs/112)
   - Additive: tables `playbook_versions`, `playbook_version_events`; `agent_runs.playbooks` (jsonb, nullable); unique indexes `playbook_versions_key_version`, `playbook_versions_one_active`; CHECK `playbook_versions_body_size`; guard and append-only triggers.
   - Backfill: none; nothing is seeded (the store ships empty).
   - `down()`: REAL, unconditional. Drops triggers, functions, indexes, the column and both tables.
4. `2026_10_10_700000_add_brand_to_consent_state` (docs/108 section 6)
   - Additive column `consent_state.brand_id` (nullable FK RESTRICT); unique index `consent_state_identity_unique` REPLACED (dropped and re-created wider, with `coalesce(brand_id, 0)`).
   - Backfill: `UPDATE consent_state SET brand_id = e.brand_id FROM consent_events e WHERE e.id = s.last_event_id` (derived projection only; production holds no consent rows, docs/121 section 5).
   - `down()`: REAL with a derived-data deletion: deletes `consent_state` rows whose `brand_id` is not null (the projection is rebuildable with `ConsentLedger::rebuild()`), drops the column, restores the narrower index. `consent_events` (the ledger) is never touched.
5. `2026_10_10_800000_add_identity_to_consent_scope` (docs/114 section 2)
   - Additive: `consent_events.identity_id` (nullable FK to `customer_identities`, RESTRICT, index); `consent_state.identity_id` (nullable FK, CASCADE, derived); the unique index REPLACED again, with `coalesce(identity_id, 0)`.
   - Backfill: none (a null identity reads as "every identity of the customer", which is what every existing row meant).
   - `down()`: REAL with a derived-data deletion: deletes `consent_state` rows with an identity, drops both columns, restores the previous index. The ledger keeps its identity-scoped events.
6. `2026_10_10_800000_create_follow_up_tables` (docs/113 section 9)
   - Additive: tables `opportunities`, `follow_ups`; proposer CHECKs; `follow_ups_kind_check`, `follow_ups_channel_check`; every FK RESTRICT.
   - Backfill: none.
   - `down()`: REAL, unconditional. Drops both tables (and any follow-up a person recorded after the release).
7. `2026_10_11_100000_create_cross_business_quarantine_tables` (docs/117)
   - Additive: tables `cross_business_quarantines`, `cross_business_quarantine_reviews`; CHECKs; append-only triggers.
   - Backfill: none. Flags are written only by `hq:identity:cross-business --flag` (never by the migration or the deploy).
   - `down()`: REAL, unconditional. Drops triggers, function and both tables (flags and reviews would be lost).
8. `2026_10_15_100000_scope_customer_identities_to_business` (docs/122; ruling A2)
   - NOT purely additive, and PostgreSQL only (throws on any other driver). `customers` and `stores` gain unique `(id, business_id)`; `customer_identities` gains `business_id` (NOT NULL after backfill), `quarantined_at`, `quarantine_reason`, the stored generated column `live_store_id`; composite FKs `(customer_id, business_id) -> customers` and `(live_store_id, business_id) -> stores`; CHECKs on the quarantine pair; the old unique constraint `customer_identities_channel_unique` is DROPPED and replaced by the partial unique index `customer_identities_business_key (business_id, channel, external_id, source_store_id) NULLS NOT DISTINCT WHERE quarantined_at IS NULL`; index `customer_identities_quarantined_index`; new table `customer_identity_conflicts` with its partial unique index.
   - Backfill, in the migration, from reliable evidence only: every identity takes its owner customer's business; then rows are QUARANTINED (not moved, not deleted) with a reason: `store_business_mismatch`, `contradicted_by_evidence`, `duplicate_same_customer`, `duplicate_ambiguous_owner`; one open `customer_identity_conflicts` row per quarantined identity (`source = migration.2026_10_15_100000`).
   - Expected on production data (docs/121 section 5, counted by Joy on 15 Oct): the `shop_user` and `shop_order` identities of Bionic store 2 held by LaLaDia customer #1 are quarantined as `store_business_mismatch` (two conflict rows); the phone identity stays live under LaLaDia (its own business's orders use it); order fact #16 keeps pointing at customer #1 (moving it is the W2 repair's job). Nothing else is expected to change.
   - `down()`: CONDITIONAL. Refuses with a message when any `customer_identity_conflicts` row exists ("dropping them loses audit evidence") or when a store-scoped key now exists in more than one row. Otherwise real: drops the conflicts table, the indexes, the keys, the generated column, the three columns, and restores the old unique constraint. ON PRODUCTION IT WILL REFUSE (two conflict rows are expected), so this migration is a one-way door: the way back is `deploy/restore.sh` from the pre-deploy dump that `deploy.sh` takes before the checkout moves. The rehearsal proves that path (docs/128 section D.1).
   - Preflight note: `deploy.sh --verify-only` looks for `dropColumn`, `->drop(`, `DROP TABLE`, `DROP COLUMN`, `rename`, `->change(` and `TRUNCATE` in an `up()`; it does NOT match `DROP CONSTRAINT` or `DROP INDEX`, so it will report "no pending migration's up() drops, renames or changes a column or table" for this release. That is a gap in the preflight's regex, not a property of the migration; the person reading the preflight should know this file replaces a unique key.
9. `2026_10_15_400000_s4_outcome_followups` (docs/123)
   - Additive: `outcome_attributions.follow_up_id` (nullable FK RESTRICT, index); the typed-action CHECK `outcome_attributions_typed_action` is DROPPED and re-added over nine columns; five vocabulary CHECKs (`follow_ups_origin_check`, `follow_ups_state_check`, `opportunities_kind_check`, `opportunities_state_check`, `opportunities_close_reason_check`); `opportunities_closed_has_reason`; `SET NOT NULL` on `follow_ups.origin`, `follow_ups.state`, `opportunities.kind`, `opportunities.state` (already NOT NULL from migration 6; a no-op).
   - Backfill: none.
   - `down()`: CONDITIONAL. Drops the vocabulary CHECKs, then REFUSES ("outcome_attributions credits follow-ups; rolling back would drop that evidence") when any attribution has a `follow_up_id`; otherwise drops the column and restores the eight-column CHECK. On production nothing credits a follow-up, so it rolls back.
10. `2026_10_15_510000_add_business_id_to_decision_records` (docs/125, items 21 and 34)
    - Additive: `decision_records.business_id` (nullable FK RESTRICT) with index `(business_id, created_at)`.
    - Backfill, in the migration, by `App\Decisions\DecisionBusinessBackfill`: only from a reliable context row (an approval by id, an agent run or a conversation by uuid) that exists and carries a business; everything else stays NULL and is listed by `hq:decisions:report --unassigned`. Never guessed.
    - `down()`: REAL, unconditional. Drops the index and the column; a re-run derives the same values.

Rollback order (`migrate:rollback --step=1`, newest first): 10 real, 9 real on production (conditional), 8 REFUSES on production, 7 to 1 real but unreachable by `migrate:rollback` once 8 has refused. The whole-batch `migrate:rollback` (no `--step`) stops at the first refusal, which is 8. A code rollback (`deploy/rollback.sh <sha>`) never runs a migration, so `b96a396` code would run against this newer schema: the old resolver's `updateOrCreate` would then hit the new unique index and the composite FKs, which refuse a cross-business write loudly instead of silently moving a row. That is safer than before, but it is not "the old behaviour": a code rollback after this release must be followed by the restore path if the old schema is wanted.

## 4. Seeder changes

Only `RolesAndPermissionsSeeder` changed. It is additive (`findOrCreate`, `givePermissionTo`, never `syncPermissions`), so re-running it on production cannot strip a customised role. `deploy.sh` does NOT run seeders; the seeder is a separate line after the deploy (section 9). Until it runs, a person opening a new screen gets Spatie's "permission does not exist" error (an HTTP 500), because the routes name permissions that have no row yet.

New permissions (thirteen), in the order the seeder lists them:
- `hq.consent.view`, `hq.consent.restrict`, `hq.consent.grant` (docs/108, S7)
- `hq.playbooks.view`, `hq.playbooks.draft`, `hq.playbooks.review`, `hq.playbooks.activate` (docs/112, S6)
- `hq.followups.view`, `hq.followups.propose`, `hq.followups.decide` (docs/113, S4)
- `hq.consent.lift_restriction` (docs/114, two-person lift)
- `hq.outcomes.attribute` (docs/115, the operator-asserted attribution, human-only)
- `hq.customers.quarantine.review` (docs/117, reviewing quarantine flags)

Role changes:
- `owner` and `operator` (`*`): every new permission.
- `brand_manager`: + `hq.playbooks.view`, `hq.playbooks.draft`, `hq.followups.view`, `hq.followups.propose` (may write guidance and propose; never reviews, activates or decides).
- `support_agent`: + `hq.consent.view`, `hq.consent.restrict`, `hq.followups.view`, `hq.followups.propose` (may record "stop"; never starts contact, never decides).
- `finance_viewer`: unchanged.

Not on main yet: `hq.customers.quarantine.repair` (W2, ruling item 36). It arrives with the repair branch and is NOT part of this manifest until W2 merges.

## 5. Tools, pins and commands

New gateway tools, both R3 writes of HQ's own ledgers, both PERMANENTLY human-only, held by no agent, registered in `config/hq.php` `tools` (docs/105 section 1.2 rows 9 and 10):
- `consent.lift_restriction` (`LiftRestrictionTool`, policy `LiftRestrictionPolicy`): parks every call for a second person; the requester never decides it.
- `outcomes.attribute` (`AttributeOutcomeTool`): the only writer of an `operator_asserted` attribution; INERT (nothing reads the rows it writes).
- The `tools` table gains no row by itself: `hq:tools:sync` (manual) creates the switch rows. A tool with no row is available by default to the gateway's policy, which for these two still refuses every agent and parks or requires a person. Run the sync after the deploy so the rows exist on the registry screen.

New artisan commands: `hq:identity:cross-business` (dry run by default, `--flag` writes append-only flags), `hq:evaluation:attribute-cost` (dry run by default, `--apply` writes), `hq:decisions:report` (reads only; `--unassigned` lists decision records with no business and why). No new scheduled command (`routes/console.php` unchanged).

Structural pins added (each a Pest file that reads the source and fails on a new offender): `GatewayCoverageStructureTest` (only the gateway invokes a tool; eleven effectful tools exactly; no reader of `scheduler.gateway`), `IdentityBusinessScopeTest` structural pin (every `customer_identities` lookup names a business; proven on a planted offender), `PlaybookStructureTest` (one loader reader; deciding code ignorant of playbooks; no seeded content; no raw reader), `OutcomeWritePathStructureTest` (one writer; readers business-restricted), `FollowUpStructureTest` and `ListedWorkflowDefinitionsStructureTest` (no listed workflow has a tool step, tested on the definitions), `IdentityScopeReadonlySqlTest` (the W0 file is SELECT-only), the five whole-second pins (docs/124). The Stage 6 runner `deploy/tools/structural-searches.sh` runs them together (docs/128).

## 6. Feature flags: expected before and after

No migration, seeder or deploy step creates, changes or deletes a `feature_flags` row. Expected rows, before = after:
- `ai.runtime`: OFF (the AI runtime; also read by the shadow and autonomy evidence code).
- `channels.meta.inbound`: OFF. `channels.meta.forwarded`: OFF (both webhook routes answer 404 while off).
- `scheduler.gateway`: ON, present, and INERT after this release: no code reads it (docs/116; `GatewayCoverageStructureTest` pins that). Its deletion is DEFERRED per ruling item 40 until Joy's release is verified and the deployed code is confirmed to have no consumer; the procedure is in docs/125 "Item 40" and in `deploy/checklists/post-release-readonly.md` (the delete itself is a write and is not in the read-only checklist).
- `ai.freeze`, `ask_hq`, `briefing.narration`, `content.drafting`, `publishing.write`, `research.engine`: whatever they are today; untouched.

Agent posture, before = after: every agent `status = paused`, `max_autonomy_level = a0`; `autonomy_grants` zero active; `shadow_periods` none open (and ruling A1 says none may open before containment and scoped-read verification).

## 7. Configuration keys (all in `config/hq.php`; no new environment key in `apps/hq/.env.example` or `deploy/.env.example`)

- `hq.playbooks.max_chars_per_version` 16000 (`HQ_PLAYBOOK_MAX_CHARS`), `hq.playbooks.max_chars_per_run` 40000 (`HQ_PLAYBOOK_RUN_MAX_CHARS`).
- `hq.tools`: + `LiftRestrictionTool`, `AttributeOutcomeTool`.
- `hq.scheduler_gateway_flag`: REMOVED (docs/116). `hq.scheduler.*` unchanged.
- `hq.workflows.definitions`: `[FollowUpWorkflow::class]` (was empty). It has no tool step; listing it schedules nothing.
- `hq.followups.overdue_after_hours` 24 (our number; the ruling approved the rule).
- `hq.events.handlers`: + `conversation.reply_sent` -> `ConversationEventHandler` (recorded only).
- `hq.retention.version` `retention.v3`; + `hq.retention.followup_refused_keep_days` 365.
- `hq.alerts.rules`: + `GuardGatewayDisagreement`, `FollowUpsOverdue`; `hq.alerts.thresholds.guard_gateway_disagreement` `{count: 3, window_hours: 24}`.

## 8. Visible changes (screens and behaviour)

- New screens: `/customers/{customer}/consent` (consent history and operator acts), `/customers/quarantine` (flags and audited review), `/follow-ups` (proposal-only, sidebar badge), `/observe/decision-corpus` (read-only), `/playbooks` and `/playbooks/{uuid}` (draft, review, activate, retire). All behind the new permissions.
- Customer 360: a read-only, business-scoped follow-ups section; a consent history button; identities and links are live rows of the customer's business only (quarantined rows hidden); phone and e-mail WITHHELD when only another business's rows explain them; a merge pointer is never followed into another business.
- Customer list and search: the same withholding; a search by a withheld value finds nobody (item 38); optional `business` filter.
- Inbox: orders, memory, the customer card and `customer_id` come only from a customer of the thread's business; decisions recorded as `approved`, `edited`, `rejected` (legacy `send`/`discard` mapped on read).
- Shadow review: a small, labelled, non-executing "what I would have sent" box (item 18).
- Alerts: two new rules, expected silent on production.
- Behaviour: an order or message whose only identity value is quarantined fails closed (the event retries, then goes dead and stays replayable) instead of attaching to a guessed customer (docs/122 open question 1). A within-business ownership conflict refuses the move, writes a `link_refused` conflict row and audits it once; the order still resolves to the customer its stronger key named.
- Shop timestamps: every `+06:00` or `Z` timestamp is stored as UTC; the contribution report reads Dhaka day bounds correctly (W5-TZ).

## 9. Post-deploy lines that are part of this release (Joy, on the VPS, after `deploy.sh` verified the release)

- `$C exec -T app php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force` (the thirteen permissions; additive).
- `$C exec -T app php artisan hq:tools:sync` (switch rows for the two new tools).
- Then the read-only post-release checklist (`deploy/checklists/post-release-readonly.md`).
- NOT part of the release (separately gated, ruling D.5): `hq:identity:cross-business --flag`, the W2 repair, `hq:evaluation:attribute-cost --apply`, the `scheduler.gateway` row delete.

## 10. Known limitations and open items carried by this candidate

- The identity leak's EFFECTS are not repaired by this release: order #16 stays under customer #1, the two quarantined identities stay quarantined with open conflict rows, until W2's audited repair runs under Joy's authorization. The detector is read-only by default; a detector with unresolved findings is not a clean-data certificate.
- Migration 8's `down()` refuses on production data; recovery is the pre-deploy dump + `deploy/restore.sh`.
- W5-TZ: existing production `facts_orders` rows written from `+06:00` strings (status events, ERP read-back) are six hours late and are NOT corrected; a repair needs its own ruled, dry-run-first backfill.
- The `scheduler.gateway` row delete is deferred (item 40).
- `hq:evaluation:attribute-cost` historical runs that threw before docs/116 stay `complete = false` and are never written.
- `conversations` unique `(channel, external_thread_ref)` is still global (docs/122 open question 2).
- The follow-ups screens show `primary_phone_canonical` without withholding (docs/122 open question 3).
- No dedicated staging exists; the rehearsal is a disposable isolated environment, not a staging deployment.
- Production's own copy of `deploy.sh` is `b96a396`'s (host lock and `RUN_MIGRATIONS` refusal present, docs/81 C.9); `deploy/` itself gains only two read-only tool files in this release.

## 11. Candidate manifest (filled at freeze; placeholders until then)

- Production before: `b96a396`.
- main at the time of these notes: `7fb5e71`.
- Release candidate commit: `<candidate>` (PENDING: W2 `p3/identity-repair` must merge first, then the combined gate; the candidate is the merge commit CI was green on).
- GitHub Actions run on the exact candidate: `<run id>` (PENDING). Last green run on main: 38681310680 on `357fabc` (all three jobs).
- Local combined gate at `357fabc`: 2,125 Pest passed, 0 failed, 31,947 assertions; Pint, `tsc --noEmit`, Vite clean.
- Migrations: the ten files of section 3 (PENDING: plus W2's).
- Seed changes: section 4 (PENDING: plus `hq.customers.quarantine.repair`).
- Permission changes: section 4.
- Expected feature flags: section 6 (before = after).
- Post-release verification evidence: `deploy/checklists/post-release-readonly.md`, output pasted into docs/128 by Joy after the release (PENDING).
- Rehearsal evidence: `deploy/rehearsal/` evidence directory from the VPS run (PENDING: not executed; docs/128 section D.1 names the command).
