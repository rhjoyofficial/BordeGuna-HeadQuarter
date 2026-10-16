# 127. Consolidated release notes, waves 1 to 4: main against production `b96a396` (15 October 2026, updated 16 October night)

Authority: `docs/120-chatgpt-ruling-2026-10-15.txt` section D ("Create consolidated release notes for Waves 1 to 4 and a candidate manifest") and `docs/131-chatgpt-ruling-2026-10-16.txt` section 3 Stage C (the go/no-go package list). This file describes what main carries that production does not. It is a description, not an authorization: Joy alone releases. The readiness verdict lives in `docs/128` (NOT READY, section 17); the plain-English package for Joy is `docs/134`.

Read against: production `b96a396` (released 9 October, inert, healthy). Originally written against main `7fb5e71` (15 October). Updated on 16 October night against main `5bb9337` (the merge of `platform/rollback-keep-scheduler-stopped` on top of `3755d27`): every statement was re-read from the diff `b96a396..5bb9337` and from the code on main; nothing was read from production. `5bb9337` changes no file under `apps/` relative to `3755d27` (only `deploy/` scripts, their safety test, checklists and docs), so the application gate on `3755d27` applies to its application code.

## 0. What changed in these notes on 16 October

- Two more migrations: `2026_10_15_600000_create_identity_repair_tables` (W2, docs/126) and `2026_10_16_100000_create_facts_tz_corrections_table` (docs/133). Twelve in all (section 3).
- Fourteen permissions, not thirteen: `hq.customers.quarantine.repair` arrived with W2 (section 4).
- Three permanently human-only tools, not two: `customers.quarantine.repair` arrived with W2 (section 5).
- New commands: `hq:identity:repair`, `hq:facts:tz-backfill`, `hq:events:export-since`, `hq:events:import`, each labelled READ-ONLY or MUTATING (section 5).
- New environment key `HQ_EVENTS_DRAIN_PAUSED` and the `--keep-scheduler-stopped` flag of `deploy.sh` and `rollback.sh` (section 7).
- Known limitations extended with the docs/132 and docs/133 findings (section 10); candidate manifest reset to PENDING FREEZE (section 11).

## 1. What the release is, in one paragraph

Think of HQ as a building where, until now, every business shared one key board. This release gives each business its own board bolted to the wall (business-scoped identities, W1), adds four locked filing cabinets that nothing in the building can open yet (the outcome ledger, the playbook store, follow-ups, consent operations), hangs new "do not enter" signs on the doors agents are not allowed through (structural pins), and leaves every switch exactly where it was: AI runtime off, every agent paused at A0, zero grants, Meta inbound off, no shadow period. It also writes two numbers it never wrote before (evaluation cost, a decision's business) and corrects how the shops' timestamps are read. The identity fix is one-way once it quarantines a row; the repair of what the leak already mixed is NOT in this release (W2).

## 2. Merged workstreams (merge commits, newest first)

- `5bb9337` rollback flag (docs/132 section 9): opt-in `--keep-scheduler-stopped` on `deploy.sh` and `rollback.sh`; defaults unchanged. No application code.
- `3755d27` time-zone backfill and follow-up self-decision (docs/133, ruling docs/131 items 4 and 8).
- `69a1bb1` event preservation (docs/132, ruling docs/131 section 1.1, Stages B and D): export/import bridge, drain pause flag, rehearsal section 10.
- `d2bea57` local SQL rehearsal on the earlier candidate `a7bb85e` (docs/128 section 16).
- `a109007` W7 release readiness (docs/127, docs/128, docs/129, rehearsal scripts, checklists, structural searches).
- `e13ab87` W2 identity repair (docs/126): planner, applier, verifier, recount, human-only `customers.quarantine.repair` tool, wider detector.
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

Twelve files, all new (none modified), listed by `git diff --name-status b96a396..5bb9337 -- apps/hq/database/migrations` (every line `A`). After the release production holds 57 + 12 = 69 migrations, the last `2026_10_16_100000_create_facts_tz_corrections_table`. Laravel runs them in filename order, in ONE batch, from the `app` container's entrypoint (`migrate --force`) during `deploy.sh`. "Additive" means: existing rows are not changed and nothing the old code reads is removed. Each `down()` was read.

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
   - `down()`: CONDITIONAL. Refuses with a message when any `customer_identity_conflicts` row exists ("dropping them loses audit evidence") or when a store-scoped key now exists in more than one row. Otherwise real: drops the conflicts table, the indexes, the keys, the generated column, the three columns, and restores the old unique constraint. ON PRODUCTION IT WILL REFUSE (two conflict rows are expected), so this migration is a one-way door: the way back is `deploy/restore.sh` from the pre-deploy dump that `deploy.sh` takes before the checkout moves. The rehearsal proves that path (docs/128 sections 9 and 10).
   - Preflight note: `deploy.sh --verify-only` looks for `dropColumn`, `->drop(`, `DROP TABLE`, `DROP COLUMN`, `rename`, `->change(` and `TRUNCATE` in an `up()`; it does NOT match `DROP CONSTRAINT` or `DROP INDEX`, so it will report "no pending migration's up() drops, renames or changes a column or table" for this release. That is a gap in the preflight's regex, not a property of the migration; the person reading the preflight should know this file replaces a unique key.
9. `2026_10_15_400000_s4_outcome_followups` (docs/123)
   - Additive: `outcome_attributions.follow_up_id` (nullable FK RESTRICT, index); the typed-action CHECK `outcome_attributions_typed_action` is DROPPED and re-added over nine columns; five vocabulary CHECKs (`follow_ups_origin_check`, `follow_ups_state_check`, `opportunities_kind_check`, `opportunities_state_check`, `opportunities_close_reason_check`); `opportunities_closed_has_reason`; `SET NOT NULL` on `follow_ups.origin`, `follow_ups.state`, `opportunities.kind`, `opportunities.state` (already NOT NULL from migration 6; a no-op).
   - Backfill: none.
   - `down()`: CONDITIONAL. Drops the vocabulary CHECKs, then REFUSES ("outcome_attributions credits follow-ups; rolling back would drop that evidence") when any attribution has a `follow_up_id`; otherwise drops the column and restores the eight-column CHECK. On production nothing credits a follow-up, so it rolls back.
10. `2026_10_15_510000_add_business_id_to_decision_records` (docs/125, items 21 and 34)
    - Additive: `decision_records.business_id` (nullable FK RESTRICT) with index `(business_id, created_at)`.
    - Backfill, in the migration, by `App\Decisions\DecisionBusinessBackfill`: only from a reliable context row (an approval by id, an agent run or a conversation by uuid) that exists and carries a business; everything else stays NULL and is listed by `hq:decisions:report --unassigned`. Never guessed.
    - `down()`: REAL, unconditional. Drops the index and the column; a re-run derives the same values.
11. `2026_10_15_600000_create_identity_repair_tables` (docs/126, W2)
    - Additive: tables `customer_identity_repairs` (one row per repair batch; status CHECK `applying`, `partial`, `applied`, `verified`, `verification_failed`; `verified_at` set exactly when verified) and `customer_merge_reversals` (append-only lineage, triggers refuse UPDATE and DELETE; the original `customer_merges` row is never touched; CHECK that the two businesses differ). The `cross_business_quarantines_kind_check` CHECK is DROPPED and re-added wider (adds `participant`, `message`, `identity_conflict`).
    - Backfill: none. Both tables ship empty. Only the human-only tool `customers.quarantine.repair` writes them.
    - `down()`: CONDITIONAL. Refuses when any `customer_merge_reversals` row exists ("dropping them loses lineage") or when any quarantine flag carries one of the three new kinds. Otherwise real: restores the narrower kind CHECK, drops the triggers, the function and both tables. Right after the release it rolls back (no reversal, no flag; proven in docs/128 section 16). It becomes a one-way door as soon as Joy authorizes `hq:identity:cross-business --flag` (which can write a new-kind flag) or a repair apply.
12. `2026_10_16_100000_create_facts_tz_corrections_table` (docs/133, ruling docs/131 item 8)
    - Additive: table `facts_tz_corrections` (old and new value per corrected timestamp, source event, raw string, batch key, person, reason); CHECK on the column name (`placed_at`, `confirmed_at`, `delivered_at`, `cancelled_at`); CHECK `old_value <> new_value`; unique per batch, row and column; triggers refuse UPDATE and DELETE.
    - Backfill: none. The migration corrects no timestamp. Only `hq:facts:tz-backfill --apply` writes it.
    - `down()`: CONDITIONAL. Refuses once any correction row exists ("dropping them loses the evidence"). Otherwise real. Right after the release it rolls back; after an authorized tz apply it is a one-way door.

Rollback order (`migrate:rollback --step=1`, newest first): 12 real right after the release (conditional), 11 real right after the release (conditional), 10 real, 9 real on production (conditional), 8 REFUSES on production, 7 to 1 real but unreachable by `migrate:rollback` once 8 has refused. Once Joy has authorized a flag write, a repair or a tz apply, 11 or 12 refuse first. The whole-batch `migrate:rollback` (no `--step`) stops at the first refusal, which is 8. A code rollback (`deploy/rollback.sh <sha>`) never runs a migration, so `b96a396` code would run against this newer schema: the old resolver's `updateOrCreate` would then hit the new unique index and the composite FKs, which refuse a cross-business write loudly instead of silently moving a row. That is safer than before, but it is not "the old behaviour": a code rollback after this release must be followed by the restore path if the old schema is wanted.

## 4. Seeder changes

Only `RolesAndPermissionsSeeder` changed. It is additive (`findOrCreate`, `givePermissionTo`, never `syncPermissions`), so re-running it on production cannot strip a customised role. `deploy.sh` does NOT run seeders; the seeder is a separate line after the deploy (section 9). Until it runs, a person opening a new screen gets Spatie's "permission does not exist" error (an HTTP 500), because the routes name permissions that have no row yet.

New permissions (fourteen), in the order the seeder lists them (read from `RolesAndPermissionsSeeder` on main):
- `hq.consent.view`, `hq.consent.restrict`, `hq.consent.grant` (docs/108, S7)
- `hq.playbooks.view`, `hq.playbooks.draft`, `hq.playbooks.review`, `hq.playbooks.activate` (docs/112, S6)
- `hq.followups.view`, `hq.followups.propose`, `hq.followups.decide` (docs/113, S4)
- `hq.consent.lift_restriction` (docs/114, two-person lift)
- `hq.outcomes.attribute` (docs/115, the operator-asserted attribution, human-only)
- `hq.customers.quarantine.review` (docs/117, reviewing quarantine flags)
- `hq.customers.quarantine.repair` (docs/126, W2: applying a reviewed repair batch through the human-only tool, and assigning a business to a memory the repair withheld)

Role changes:
- `owner` and `operator` (`*`): every new permission.
- `brand_manager`: + `hq.playbooks.view`, `hq.playbooks.draft`, `hq.followups.view`, `hq.followups.propose` (may write guidance and propose; never reviews, activates or decides).
- `support_agent`: + `hq.consent.view`, `hq.consent.restrict`, `hq.followups.view`, `hq.followups.propose` (may record "stop"; never starts contact, never decides).
- `finance_viewer`: unchanged.

- `hq.customers.quarantine.repair`: no role is given it by name; owner and operator hold it through `*`.

The rehearsal on `a7bb85e` proved all fourteen present after the seeder (49 permissions in total on the rehearsal copy) and a second run clean (docs/128 section 16).

## 5. Tools, pins and commands

New gateway tools, all three R3 writes of HQ's own rows, all three PERMANENTLY human-only (`permanentlyHumanOnly: true` in each descriptor), held by no agent, registered in `config/hq.php` `tools` (docs/105 section 1.2 rows 9 to 11). Docs/131 Stage C names "the two human-only tools"; W2 added a third, so there are three:
- `consent.lift_restriction` (`LiftRestrictionTool`, permission `hq.consent.lift_restriction`, policy `LiftRestrictionPolicy`): parks every call for a second person who also holds the permission; the requester never decides it.
- `outcomes.attribute` (`AttributeOutcomeTool`, permission `hq.outcomes.attribute`): the only writer of an `operator_asserted` attribution; INERT (nothing reads the rows it writes).
- `customers.quarantine.repair` (`QuarantineRepairTool`, permission `hq.customers.quarantine.repair`): the only caller of the repair applier; executes only a batch the dry run proposed whose every row a person reviewed (at least one `needs_repair`, none `disputed`). Reached from `/customers/quarantine` or from `hq:identity:repair --apply`, both through the Tool Gateway.
- Also human-only but not a tool: assigning a business to a memory the repair withheld (`HeldMemoryAssigner`, route `POST customers/quarantine/memories/{memory}/assign`, permission `hq.customers.quarantine.repair`, audited, not agent-callable; ruling docs/131 item 9 allows this).
- The `tools` table gains no row by itself: `hq:tools:sync` (manual) creates the switch rows. A tool with no row is available by default to the gateway's policy, which for these three still refuses every agent and parks or requires a person. Run the sync after the deploy so the rows exist on the registry screen.

New artisan commands, each labelled. READ-ONLY means it writes no business row (the checklists also run it in a session PostgreSQL holds read-only). MUTATING means the named option writes; each mutation needs Joy's explicit authorization after he has read the dry run (ruling docs/131 Stage D).
- `hq:identity:cross-business`: READ-ONLY by default (`--dry-run`, lists findings). MUTATING with `--flag` (append-only quarantine flags). Stage D step 6.
- `hq:identity:repair`: READ-ONLY by default (the plan; `--recount` adds the business-scoped recount; `--json`). MUTATING with `--apply --key=<batch key> --by=<email> --reason="<why>"` (one reviewed batch, through the human-only tool). Stage D steps 7 and 8.
- `hq:facts:tz-backfill`: READ-ONLY by default (evidence, ambiguous rows, before and after by Dhaka day; `--json`). MUTATING with `--apply --key=<batch key> --by=<email> --reason="<why>"` (writes `facts_orders`, `facts_tz_corrections`, `audit_logs`). Stage D step 9.
- `hq:evaluation:attribute-cost` (the cost backfill): READ-ONLY by default (`--run=<id>` to narrow). MUTATING with `--apply` (writes complete runs only). Stage D step 9.
- `hq:events:export-since`: READ-ONLY for the database (SELECTs). It writes one new file (mode 600, never overwrites; it holds payloads, so it is personal data). Needs exactly one of `--since-id=` or `--since=`; `--out=` names the file. Used only before a restore (docs/132 section 5 C).
- `hq:events:import <file>`: READ-ONLY by default (`--dry-run`). MUTATING with `--apply --by=<email> --reason="<why>"` (inserts absent rows into `events` as pending, never processes them, one `events.imported` audit row). Used only after a restore.
- `hq:decisions:report`: READ-ONLY always (`--unassigned` lists decision records with no business and why; `--window`, `--business`, `--json`).
- Unchanged but used in the sequences: `hq:events:replay` (READ-ONLY without `--apply`; MUTATING with it) and `hq:tools:sync` (MUTATING: switch rows; `--list` only lists).

No new scheduled command (`routes/console.php` unchanged).

Structural pins added (each a Pest file that reads the source and fails on a new offender): `GatewayCoverageStructureTest` (only the gateway invokes a tool; eleven effectful tools exactly; no reader of `scheduler.gateway`), `IdentityBusinessScopeTest` structural pin (every `customer_identities` lookup names a business; proven on a planted offender), `PlaybookStructureTest` (one loader reader; deciding code ignorant of playbooks; no seeded content; no raw reader), `OutcomeWritePathStructureTest` (one writer; readers business-restricted), `FollowUpStructureTest` and `ListedWorkflowDefinitionsStructureTest` (no listed workflow has a tool step, tested on the definitions), `IdentityScopeReadonlySqlTest` (the W0 file is SELECT-only), the five whole-second pins (docs/124). The Stage 6 runner `deploy/tools/structural-searches.sh` runs them together (docs/128).

## 6. Feature flags: expected before and after

No migration, seeder or deploy step creates, changes or deletes a `feature_flags` row. Expected rows, before = after:
- `ai.runtime`: OFF (the AI runtime; also read by the shadow and autonomy evidence code).
- `channels.meta.inbound`: OFF. `channels.meta.forwarded`: OFF (both webhook routes answer 404 while off).
- `scheduler.gateway`: ON, present, and INERT after this release: no code reads it (docs/116; `GatewayCoverageStructureTest` pins that). Its deletion is DEFERRED per ruling item 40 until Joy's release is verified and the deployed code is confirmed to have no consumer; the procedure is in docs/125 "Item 40" and in `deploy/checklists/post-release-readonly.md` (the delete itself is a write and is not in the read-only checklist).
- `ai.freeze`, `ask_hq`, `briefing.narration`, `content.drafting`, `publishing.write`, `research.engine`: whatever they are today; untouched.

Agent posture, before = after: every agent `status = paused`, `max_autonomy_level = a0`; `autonomy_grants` zero active; `shadow_periods` none open (and ruling A1 says none may open before containment and scoped-read verification).

## 7. Configuration keys and deploy flags

New environment key (docs/132), the only one in this release:

- `HQ_EVENTS_DRAIN_PAUSED` (`config('hq.events.drain_paused')`, default false). While true, `hq:events:process` claims nothing: webhooks are still recorded as `pending` rows and wait. Listed commented out in `deploy/.env.example`; NOT enabled anywhere. Set it in `deploy/.env` BEFORE the release under Option 2 (docs/132 section 5 B1). Removing it takes effect only when the scheduler container is recreated (the entrypoint caches config). It pauses only the event drain: the scheduler tick, alerts, retention and shadow ticks keep running if the scheduler runs.

New deploy script flags (docs/132 section 9), opt-in, defaults unchanged:

- `./deploy/deploy.sh [--no-pull] --keep-scheduler-stopped`: stops the scheduler, then `up -d --remove-orphans --scale scheduler=0`; the release still migrates; ends saying the scheduler is LEFT STOPPED and the release may be recorded UNVERIFIED. `--verify-only` ignores it.
- `./deploy/rollback.sh --keep-scheduler-stopped <sha>`: stops the scheduler and starts only `app horizon web`, with migrations still off.
- The variable `HQ_KEEP_SCHEDULER_STOPPED=1` does the same in both scripts; any value other than empty, `0` or `1` is refused before anything moves. Use the flag, not the variable: production's `b96a396` scripts refuse the unknown flag (nothing moves) but silently ignore the variable.
- Without the flag both scripts start a stopped scheduler, as before, after six WARNING lines and 10 seconds to abort.

Configuration keys in `config/hq.php`:

- `hq.events.drain_paused` (above).
- `hq.tools`: + `QuarantineRepairTool` (W2).
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

## 9. Release sequence and post-deploy lines (Joy, on the VPS)

The release under containment Option 2 is docs/132 section 5 B; the plain-English version with every line is docs/134 section 5. In short: `HQ_EVENTS_DRAIN_PAUSED=true` in `deploy/.env`; move the checkout by hand with `git fetch -q origin main && git rev-parse --short origin/main` (must print the candidate) and `git merge --ff-only origin/main`; then `./deploy/deploy.sh --verify-only --no-pull` and `./deploy/deploy.sh --no-pull --keep-scheduler-stopped`. The first run must be the candidate's script, because production's `b96a396` `deploy.sh` refuses the flag.

After `deploy.sh` has run:

- `$C exec -T app php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force` (the fourteen permissions; additive; a WRITE, Joy's step).
- `$C exec -T app php artisan hq:tools:sync` (switch rows for the three new tools; a WRITE of switch rows only).
- Then the read-only post-release checklist (`deploy/checklists/post-release-readonly.md`).
- NOT part of the release (separately gated, ruling docs/131 Stage D): `hq:identity:cross-business --flag`, the W2 repair apply, `hq:evaluation:attribute-cost --apply`, `hq:facts:tz-backfill --apply`, resuming the drain (removing `HQ_EVENTS_DRAIN_PAUSED`), the `scheduler.gateway` row delete.

## 10. Known limitations and open items carried by this candidate

- The identity leak's EFFECTS are not repaired by the release itself: order #16 stays under customer #1, the two quarantined identities stay quarantined with open conflict rows, until the W2 repair runs under Joy's authorization (Stage D steps 6 to 8). The detector is read-only by default; a detector with unresolved findings is not a clean-data certificate.
- Non-reversible migrations and the restore-based recovery: migration 8 (W1) refuses its `down()` on production data the moment it runs (two conflict rows). Migrations 11 and 12 refuse theirs once a new-kind flag, a repair reversal or a tz correction exists. The way back is therefore not `migrate:rollback`: it is `./deploy/rollback.sh --keep-scheduler-stopped b96a396` (code only), then `deploy/restore.sh` from the verified pre-deploy dump `deploy.sh` took before the checkout moved, with the event bridge (`hq:events:export-since` before, `hq:events:import` after; docs/132 section 5 C). Proven at SQL level and row level locally (docs/128 sections 9 and 16); the real `restore.sh` and section 10 of `rehearse.sh` are NOT yet proven (the VPS Docker rehearsal is pending).
- Residual quarantined records and ambiguous cases expected on production after the release and before the repair: 2 identities quarantined `store_business_mismatch`, 2 open conflict rows, 1 cross-business order (#16). The repair dry run is expected to propose one split with 0 ambiguous cases (docs/126). The local rehearsal's seeded shapes also showed what stays held when the business cannot be established (docs/128 section 16: 4 identities stay quarantined, 1 ambiguous participant). Production's real figures are unknown until the post-release checklist.
- W5-TZ: existing production `facts_orders` rows written from `+06:00` strings are six hours late. `hq:facts:tz-backfill` now finds and, on Joy's separate authorization, corrects only the ones the stored shop payload proves (docs/133). Rows without evidence stay as they are and are listed as ambiguous.
- Two TZ findings NOT fixed (docs/133 "Findings, not changed here"): `ShopWebhookController` stores `events.occurred_at` without `->utc()`, so a shop sending `+06:00` in the envelope makes `events.occurred_at` and `facts_orders.last_event_at` six hours late, and there is no evidence to repair existing rows; `IdentityResolver` passes the raw `placed_at` string into `customers.first_seen_at` and `last_seen_at`, so a `+06:00` string lands six hours late there (expected to affect ERP read-backs only, since the shops send `Z`). Both need their own decision.
- The shops' retry behaviour is an assumption, not confirmed (docs/132 finding c): HQ's records say LaLaDia's `SendWebhookJob` retries a non-2xx a few times and treats 202 as final; neither deployed shop commit has been read. While HQ's `app` is down (a restore), the shops' retry window is the only protection for deliveries.
- Rows stuck at `processing` after a hard stop: a drain killed mid-batch (for example by `docker compose stop scheduler`) leaves its claimed rows at `processing`. Nothing reclaims them automatically; each is reviewed by a person (docs/132 finding b and section 5 A2).
- `--scale scheduler=0` is untested on real Docker: the safety suite proves it with a stubbed `docker` only. The first real use is followed by `$C ps scheduler`, which must show no running scheduler (docs/132 section 9).
- While the scheduler is stopped, the release shows UNVERIFIED: `/api/health` reports the scheduler failing after 180 seconds, so `release.json` records `"verification":{"status":"failed"` with the scheduler as the only failing component. Expected; it is verified by `./deploy/deploy.sh --no-pull` after Stage D step 10 (docs/132 section 5 B3 and B5).
- While Option 2 is in force, alerts, the approvals sweep, retention, shadow ticks and delivery reconciliation are paused too (docs/121 section 3, docs/132 section 5 A3). That is a monitoring loss Joy accepts by choosing Option 2.
- The `scheduler.gateway` row delete is deferred (item 40).
- `hq:evaluation:attribute-cost` historical runs that threw before docs/116 stay `complete = false` and are never written.
- `conversations` unique `(channel, external_thread_ref)` is still global (docs/122 open question 2).
- RESOLVED by W2: the follow-ups screens now withhold a phone the way Customer 360 does (docs/122 open question 3, docs/126).
- No screen settles an open identity conflict yet (docs/126 open question 1; ruling docs/131 item 3 approves a human-only resolution flow for the next wave).
- No dedicated staging exists; the rehearsal is a disposable isolated environment, not a staging deployment.
- Production's own copy of `deploy.sh` is `b96a396`'s (host lock and `RUN_MIGRATIONS` refusal present, docs/81 C.9). It does not know `--keep-scheduler-stopped`, which is why the release moves the checkout by hand first (section 9). This release changes `deploy.sh`, `rollback.sh` and `backup-first-lib.sh` (the opt-in flag only, defaults unchanged) and adds read-only tool files and the rehearsal scripts.
- `deploy.sh --verify-only` does not match `DROP CONSTRAINT` or `DROP INDEX` (section 3, migration 8); migrations 9 and 11 also drop and re-add a CHECK.

## 11. Candidate manifest (filled at freeze; placeholders until then)

- Production before: `b96a396`.
- main at the time of this update: `5bb9337` (16 October night).
- Release candidate commit: PENDING FREEZE. The earlier candidate `a7bb85e` is withdrawn: it lacks event preservation (docs/132), the tz backfill and item 4 (docs/133) and the rollback flag (docs/132 section 9), so sequence B and the restore bridge cannot run on it. The new candidate is a commit at or after `5bb9337`, frozen only when CI can run on it.
- GitHub Actions run on the exact candidate: PENDING. GitHub Actions is blocked by billing since run 38912755286 (on `a7bb85e`, never started: "recent account payments have failed or your spending limit needs to be increased"). Last green run on main: 38681310680 on `357fabc` (all three jobs). Neither substitutes for CI on the exact candidate (ruling docs/131 Stage A.2).
- Local combined gate: 2,166 Pest passed, 0 failed, on `3755d27` (the merge of docs/133; `5bb9337` adds no application code). Earlier: 2,143 on `a7bb85e`, 2,157 on the event-preservation branch, 2,152 on the tz branch, 2,125 on `357fabc`. Structural searches S1 to S4 PASS (docs/132 section 8, docs/133). Deploy safety suite with the rollback flag: sections 28 to 29e, 56 assertions (commit `e45d075`). Rehearsal guard suite: 48 passed.
- Migrations: the twelve files of section 3.
- Seed changes: section 4 (`RolesAndPermissionsSeeder` only, fourteen permissions).
- Permission changes: section 4.
- Human-only tools: section 5 (three).
- New commands, READ-ONLY versus MUTATING: section 5.
- New environment key and deploy flags: section 7.
- Expected feature flags: section 6 (before = after).
- Post-release verification evidence: `deploy/checklists/post-release-readonly.md`, output pasted into docs/128 by Joy after the release (PENDING).
- Rehearsal evidence: the LOCAL SQL-level migration rehearsal was executed on 16 October (docs/128 section 9: corrupted and clean scenarios PASS; W1's `down()` refused on the production shape; the pre-migration dump restored exactly), and again on `a7bb85e` with eleven migrations and the repair dry run (docs/128 section 16: 31 PASS each scenario, dry runs proven write-free at row level, restore equal row for row). It ran again on `ac03cff` (main HEAD), which carries migration 12 and the tz dry run (docs/128 section 18): corrupted 32 PASS, 1 WARN (W1's refusal), 0 FAIL; clean 32 PASS, 0 WARN, 0 FAIL; constraints 29 of 29 (`verify-constraints.sql` now also checks migrations 11 and 12); the tz dry run wrote nothing at row level; 2026_10_16_100000 and 2026_10_15_600000 rolled back (no correction, reversal or new-kind flag existed); restore equal row for row. The Docker deployment rehearsal on the VPS with a production dump, the real `restore.sh` and `rehearse.sh` section 10 (the event bridge) is PENDING (docs/128 section 10 names the command; it needs Joy's go and a fresh dump).
- Containment status: NOT applied. Joy's decision is pending; ChatGPT recommends Option 2 (docs/131 section 1.1). The incident is not claimed contained.
- Pre-release and post-release checklists: `deploy/checklists/pre-release-readonly.md`, `deploy/checklists/post-release-readonly.md` (expected counts updated on 16 October night to twelve migrations, 69 in total, and fourteen permissions).
