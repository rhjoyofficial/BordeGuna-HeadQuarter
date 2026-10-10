# 111 - Decision-corpus reader: owner-facing analysis of recurring human edits to AI proposals (10 October 2026)

Status: BUILT on branch `platform/s5-decision-corpus-reader` (worktree `hq-wt/j`, from `main` `a8a6410`). Not merged, not deployed. Production posture unchanged: no flag, no new permission, no migration, no new write path.
Asked by: `docs/103-chatgpt-ruling-2026-10-10.txt` §7 (S5) and §11; summary `docs/101` §13. Gap it closes: `docs/95` §3 row V18 and §5 ("`decision_records` has no consumer"), §7 Learn row ("no reader"). Rule it works under: `docs/96` §2 (build vs integrate: this is BordeGuna-owned governance reporting over BordeGuna-owned evidence, nothing external does it) and §22 (learning loop: this produces the *insight* step only; "proposed improvement, approval, controlled release" stay human).

## 1. The ruling, as constraints

1. READ-ONLY. The reader, the page and the command write no row anywhere. Computed on read; nothing persisted (no report table, no migration). A test snapshots the tables before and after, like `Customer360Test`.
2. REPORTING AND EVALUATION ONLY. Nothing here changes prompts, policies, playbooks, model behaviour, autonomy, evidence, gates or grants; the page has no action and links to none that changes anything. No model call anywhere: every category is a deterministic function of the stored rows.
3. BUSINESS-SCOPED through `BusinessScope`. `decision_records` carries no `business_id`; the business is read from the context row each record names (§3). A person restricted by `user_business_scopes` sees only records whose context resolves to one of their businesses; a record whose context row is gone or carries no business is counted for a group-wide viewer only, under "unscoped".
4. NO RAW CUSTOMER TEXT. The page shows counts, categories, derived measures (word counts, script, which keys changed) and *references* (record id, context type and id, a link to the approval / run / thread page the viewer is already gated on). It never ships `proposal`, `final`, `delta` or `reason` text. The link is rendered only when the viewer holds the permission of the target page (`hq.approvals.view`, `hq.agents.view`, `hq.inbox.view`).
5. ONE EXISTING PERMISSION. `hq.agents.view`: the corpus is the record of what people made of agent proposals, the same shelf as AI health, autonomy evidence and posture, which already use it. brand_manager has it; support_agent and finance_viewer do not. No new permission, no seeder change.
6. 2FA LIKE EVERY PAGE: inside the `['auth', '2fa.enabled', '2fa.verified']` group of `routes/web.php`.

## 2. What is in the corpus today (read before designing)

`decision_records` (migration `2026_09_05_100200`): `agent_key`, `user_id`, `context_type`, `context_id`, `proposal` jsonb, `final` jsonb, `delta` jsonb, `decision` (16), `reason`, `outcome` jsonb, `correlation_id`, `created_at`. No business, brand or store column. Three writers:

| Writer | `context_type` / `context_id` | `proposal` | `final` | `delta` | `decision` | `outcome` |
|---|---|---|---|---|---|---|
| `ApprovalService::recordDecision()` (approve via `ApprovalExecutor`, edit, reject) | `approval` / approvals.id | the approval payload (tool arguments) | payload (approved), edited payload (edited), null (rejected) | per key `{key: {from, to}}` for edited; null otherwise | `approved` / `edited` / `rejected` | `{tool_key, risk_tier}` |
| `AgentController::review()` (shadow review) | `agent_run` / agent_runs.uuid | `{answer, proposals[]}` | `{verdict, note}` | **never written** | `approved` / `edited` / `rejected` | `{status, stop_reason, autonomy_level}` |
| `InboxController::decide()` (support reply proposal) | `conversation` / conversations.uuid | `{run_id, text, why}` | `{decision, text, edited}` | `{from, to}` when the sent text differs | `send` / `discard` | `{status, error}` |

So the corpus holds two shapes of delta (per-key map for tool arguments; one from/to pair for a reply text) and one writer that records a verdict but not what the person would have changed (the shadow review has no edit box). Business: `approvals.business_id` / `brand_id`, `agent_runs.business_id` / `brand_id`, `conversations.business_id` / `brand_id`. Role: `agents.role_key` by `agent_key` (executive / analytics / support).

Vocabulary note (docs/95 §5 names it): the inbox writes `send|discard` where the other two write `approved|edited|rejected`. The reader maps to one verdict axis for reporting only, without rewriting anything: `send` + `edited=false` = kept as proposed; `send` + `edited=true` = edited; `discard` = rejected. The stored words are shown beside the mapped verdict.

## 3. Read model

`App\Decisions\DecisionCorpusReader::read(DecisionCorpusWindow $window, ?User $viewer, ?int $businessId = null): array` - final class, `select` only, no events, no audit, no cache. Deterministic: same rows in, same array out (stable ordering by count desc then key asc; examples by record id asc).

Resolution of each record's context in three batched lookups (approvals by id, agent_runs by uuid, conversations by uuid), then `agents` for role, `businesses` and `brands` for names. Scope: `BusinessScope::all($viewer)`; null = group-wide. Optional `$businessId` narrows further (and for a restricted viewer must be one of theirs, else nothing).

Returned shape:

```
window      : {key, since, until, options[]}
scope       : {restricted: bool, business_id: int|null, businesses: [{id, name, slug}], unscoped_records: int}
totals      : {records, kept, edited, rejected, edit_rate, rejection_rate, by_stored_decision: {approved, edited, rejected, send, discard}}
by_agent    : [{agent_key, role_key, agent_name, records, kept, edited, rejected, edit_rate}]
by_tool     : [{tool, records, kept, edited, rejected, edit_rate}]            tool = outcome.tool_key (approval), 'messaging.send_reply' (conversation), '(answer)' (agent_run)
by_kind     : [{kind, records, kept, edited, rejected, edit_rate}]            kind = tool_arguments | reply_text | shadow_answer
by_business : [{business_id, business, slug, brand, records, kept, edited, rejected, edit_rate}]   keyed by business slug and brand name; the unscoped row sorts last among equals
categories  : [{category, label, edits, share, examples[]}]                   multi-label over EDITED records only
fields      : [{tool, key, edits, examples[]}]                                 which argument keys people change, per tool (approval kind)
patterns    : [{agent_key, tool, category, count, examples[]}]                 count >= 2, the recurring ones
gaps        : {edits_without_delta: int, shadow_reviews_edited_without_text: int, contexts_missing: int, edits_with_reason: int}
can         : {approvals, agents, inbox}
```

An example reference is `{id, context_type, context_id, href|null, at}` and nothing else.

## 4. Edit categories, derived deterministically from the deltas

Applied to edited records only (a kept or rejected proposal has no edit to classify). A record can carry several labels; `patterns` uses the first label in this precedence order.

For a reply text (`conversation` kind, delta `{from, to}`) and for any text-valued argument key (`text`, `message`, `body`, `caption`, `reply`, `note`, `subject`; approval kind):

| Category | Rule (all on NFC-normalised text, `App\Conversation\Language::detect` for script) |
|---|---|
| `language_or_script` | `Language::detect(from) !== Language::detect(to)` (bn / en / banglish) |
| `register` | `from` contains an informal Bangla pronoun (তুমি, তোমার, তোমাকে, তুই, তোর, তোকে) and `to` does not (the আপনি rule) |
| `refusal_added` | `to` contains a decline marker and `from` does not; markers (closed list, documented in code): দুঃখিত, সম্ভব নয়, পারছি না, পারব না, sorry, unfortunately, cannot, can't, not possible, unable |
| `refusal_removed` | the reverse |
| `fact_corrected` | the set of numeric / reference tokens differs (digits, amounts, order refs, phone numbers, URLs, e-mails), after dropping tokens the other side also has |
| `shortened` | word count of `to` <= 60% of `from` |
| `expanded` | word count of `to` >= 150% of `from` |
| `wording` | none of the above fired, word-set Jaccard similarity >= 0.5 (same message, different words) |
| `rewritten` | none of the above fired, Jaccard < 0.5 (a different reply) |

For tool arguments (`approval` kind, delta `{key: {from, to}}`), per changed key:

| Category | Rule |
|---|---|
| `scope_narrowed` | a numeric value decreased, a list lost items, or a key was cleared (to null / removed) |
| `scope_widened` | a numeric value increased, a list gained items, or a key was added |
| `target_changed` | the key names the counterparty or thread (`customer`, `phone`, `recipient`, `to`, `conversation`, `order`, `sku`, `product`) and its value changed |
| `fact_corrected` | any other non-text key changed |
| text categories above | for text-valued keys |

For a shadow review (`agent_run` kind): no delta is stored, so an `edited` verdict is counted under `gaps.shadow_reviews_edited_without_text` and labelled `verdict_only`. The page says plainly that the shadow review screen records a verdict and a note, not the correction.

No category reads `reason` (free text by a person; may quote a customer). The report counts how many edits carry a reason; it never shows one.

## 5. Page, route, command

- Route `GET /observe/decision-corpus` -> `observe.decision-corpus`, controller `App\Http\Controllers\Observe\DecisionCorpusController@index`, middleware `permission:hq.agents.view`, query `?window=30d|90d|365d|all` (default `90d`) and `?business=<id>`.
- Page `resources/js/pages/observe/decision-corpus.tsx`: header with the standing sentence (what this is and is not), period and business pickers, totals, "where the edits recur" (patterns), categories with examples, by agent / tool / kind / business tables, fields people change, gaps. Registered in `BANGLA_SCREENS`; every literal in `bn.json`. Sidebar: "Decision corpus" under Observe, `hq.agents.view`.
- Window: own `App\Decisions\DecisionCorpusWindow` (30d / 90d / 365d / all). `DashboardWindow` is left alone: its options are a property the other dashboards rely on (they must stay inside the 180-day retention detail window); `decision_records` is `PERMANENT`, so a longer window is correct here and wrong there.
- Command `hq:decisions:report {--window=90d} {--business=} {--json}` prints the same report (group-wide, no viewer), for the terminal and for pasting into a record.

## 6. Tests (`tests/Feature/Decisions/DecisionCorpusReaderTest.php`)

1. Route is a GET behind auth, both 2FA gates and `hq.agents.view` (already in `RolesAndPermissionsSeeder::PERMISSIONS`); no non-GET route under `observe/decision-corpus`.
2. Roles: owner, operator, brand_manager open it; support_agent, finance_viewer are refused.
3. Categories: one planted record per rule, each classified as the table says, and the classification is identical on a second read.
4. Grouping and patterns: by agent / tool / kind / business counts; a (agent, tool, category) seen twice appears in `patterns` with both references; the stored decision words are counted beside the mapped verdict.
5. No raw text: secret strings planted in `proposal`, `final`, `delta` and `reason` never appear in the page props or the command output.
6. Business scope: a viewer restricted to LaLaDia sees only LaLaDia-context records, no Bionic business row, and no unscoped record; group-wide sees all; `?business=` outside the restriction yields nothing.
7. No-write snapshot: `decision_records`, `approvals`, `agent_runs`, `conversations`, `audit_logs`, `events`, `tool_calls`, `ai_runs` unchanged after two renders and one command run.
8. Command: `--json` equals the reader's array for the same window; plain output names the totals.

## 7. Non-goals and open questions for a ruling

Non-goals (ruling §7, §11): no auto-applied change, no new tool, no new permission, no persistence, no model call, no outbound, no autonomy effect.

Questions:
- Q1. The shadow review (`AgentController::review`) records `edited` without the edit. Should the review screen gain an optional "what I would have sent" box so the corpus holds the correction (a separate, small change to a write path; not done here)?
- Q2. `reason` is the richest signal and the most likely to quote a customer. Show recurring reasons (exact text seen >= 2 times) to `hq.inbox.view` holders only, or keep reasons out of the page entirely (as built)?
- Q3. One decision vocabulary: should the inbox writer move to `approved|edited|rejected` (docs/95 §5 asks for one corpus), with the mapping in this reader retired? Historical rows would be left as written.
- Q4. Should `decision_records` gain a denormalised `business_id` (additive migration, backfilled from the context rows) so future readers and S3 attributions can scope without the context join? Not needed for this reader.

## 8. Record of the build

### Files
- `app/Decisions/DecisionCorpusWindow.php` (30d / 90d / 365d / all; default 90d), `app/Decisions/EditClassifier.php` (the rules of §4, constants, labels, precedence), `app/Decisions/DecisionCorpusReader.php` (the read model of §3).
- `app/Http/Controllers/Observe/DecisionCorpusController.php`; route `GET /observe/decision-corpus` = `observe.decision-corpus` inside the auth + 2FA group, `permission:hq.agents.view`.
- `resources/js/pages/observe/decision-corpus.tsx`; sidebar item "Decision corpus" under Observe; registered in `BANGLA_SCREENS`; 61 entries added to `bn.json` (English digits, NFC, no em dash).
- `app/Console/Commands/DecisionsReport.php` = `hq:decisions:report {--window=90d} {--business=} {--json}`.
- `tests/Feature/Decisions/DecisionCorpusReaderTest.php`, 8 tests (§6 one to one): route and middleware pin; roles; categories derived and identical on a second read; grouping and a recurring pattern with references; no raw text and links only where permitted; business scope from the context row (restricted viewer, out-of-scope `?business=`, unscoped rows for group-wide only); window bounds and console `--json` equal to the reader; no-write snapshot over 12 tables after two renders and one command run.

### Decisions made while building
- `created_at` is `timestamptz`; the window bounds are bound as ISO strings with their offset, because a zone-less Carbon binding is read in the session time zone and moved the 90-day edge (caught by the window test).
- The classifier's refusal and pronoun lists are closed and live in code (`EditClassifier`), so a label can be explained by pointing at a line; widening a list is a reviewed change, not a tuning knob.
- `by_business` is keyed by business slug and brand name (readable order; "unscoped" last among equals), not by id.
- A reference link is rendered only when the viewer holds the permission of the page it opens (`hq.approvals.view`, `hq.agents.view`, `hq.inbox.view`); the reference text (record id, kind) is shown either way. The console (no viewer) prints references only, never links.

### Commits
- `2ff0812` docs/111 plan.
- `d9d0d72` WIP checkpoint 1: reader, classifier, window, page, command, tests, dictionary.
- `8c5b334` WIP checkpoint 2: this record.
- Final commit: this record completed with the gate results (the commit that carries this line; hash in the stream report).

### Gate
- New tests: `DecisionCorpusReaderTest` 8 passed (126 assertions); `BanglaDictionaryTest` 7 passed with the new screen registered.
- Full Pest suite, run 1 (`--parallel --processes=3`, real Postgres `hq_test_j`): **1,948 passed, 1 failed** (25,948 assertions, 398 s). The failure was `WorkflowEngineTest` "retries a failed step with backoff" at `expect($engine->reconcile())->toBe(0)` after `travel(29)->seconds()` against a 30 s backoff: a wall-clock boundary one second wide, in a file S5 does not touch (branch diff: 12 files, none under `app/Workflow` or `tests/Feature/Workflow`). Re-run alone: 14 passed (153 assertions). A second full parallel run was made for the record: **1,949 passed, 0 failed (25,962 assertions, 388 s)**. The branch is green on the full suite.
- `vendor/bin/pint --test` on the changed PHP files: passed. `npx tsc --noEmit`: clean. `npx vite build`: built. `npx prettier --check` on the changed frontend files: clean after one `--write` on the new page.
- Note for whoever merges: the three Python-made edits (routes, sidebar, dictionary registry) were written with CRLF on this Windows checkout and converted back to LF before the checkpoint commit; `.gitattributes` would have normalised them at commit anyway, and Pint confirmed LF.
