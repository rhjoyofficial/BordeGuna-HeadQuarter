# 107 - The canonical outcome and attribution model (S3), contract v1 (10 October 2026)

Status: CONTRACT FOR REVIEW, implemented inert on branch `platform/s3-outcome-model` (worktree `hq-wt/g`, from `main` `c12792f`). Not merged, not deployed. Nothing in HQ calls the recording service; no flag, no schedule, no listener, no tool. The only new behaviour on a live path is a retention keep-clause that can only keep MORE rows (section 9), and it matches nothing while the new tables are empty.

Authority: ChatGPT ruling of 10 Oct (`docs/103-chatgpt-ruling-2026-10-10.txt` §4, §6, §10, §11; summary `docs/101` §13). Context: `docs/95` §10 ("outcome + attribution primitive", duplication list) and §11 ("Outcome: partial (technical only)"), `docs/96` §3 (HQ owns business outcomes and attribution), `docs/102` (WorkflowEngine, whose runs are one kind of action an outcome can be credited to).

This document is the contract ChatGPT reviews before S4 builds anything outcome-dependent. Every decision is numbered (D1...) so the review can accept, change or reject it by number. Open questions are in section 12.

---

## 1. The problem in one paragraph

HQ takes actions (an agent run proposes, a person approves, the gateway executes a tool call, a reply is sent, a post is published, a workflow runs). The business cares about RESULTS (an order placed, delivered or cancelled; a customer who answered; a publication that is verifiably live). Today nothing links the two: `tool_calls.outcome_class` is a technical success class, `evaluation_runs.external_outcomes` is a gate tally, `decision_records.outcome` is free jsonb, `facts_orders.campaign_ref` is a shop hint. If each feature (follow-ups, ads, content, support) adds its own `attributed_to_*` columns, HQ ends with five incompatible attribution systems that cannot be compared or audited. The ruling: ONE model, used by every domain.

## 2. The shape: three ledgers, one vocabulary

```
               outcomes  (what happened, when, how HQ knows: one row per business result event)
              /        \
outcome_result_refs     outcome_attributions
(which records in a      (which HQ action is credited, in what role,
 system of record the     by which method, with what confidence)
 outcome is about)
```

- **`outcomes`**: an observed business result event of a registered type (`order.placed`, `conversation.customer_responded`, ...), scoped to a business (and optionally a brand and store), with occurred / source-as-of / recorded timestamps, source, provenance, evidence digest, schema version and idempotency key.
- **`outcome_result_refs`**: typed references from an outcome to the business records it is about. A reference carries the system-of-record's own natural key (always) and a foreign key to HQ's read-model row (when HQ mirrors it). It never copies amounts, statuses or lines.
- **`outcome_attributions`**: a claim that one HQ action (agent run, tool call, model call, approval, decision record, content publication, workflow run, sent message) played a named role in the outcome, made by a named method, with a confidence, evidence and its own idempotency key.

**D1. Outcome and attribution are separate rows.** One outcome can be credited to several actions (the agent run that proposed, the approval that allowed, the tool call that executed), the same action can be credited with many outcomes, and an attribution can be re-judged (a better method, an operator correction) without touching the fact that the outcome happened. Folding attribution into the outcome row would make every re-judgement a correction of the fact itself.

**D2. No oversized table.** The three tables hold only cross-domain columns. Domain semantics go in (a) the outcome-type registry (section 5) and (b) a per-domain detail table when a domain needs columns of its own (section 7). v1 ships no detail table, because no domain yet needs one that the references and the registry do not cover.

## 3. Systems of record stay authoritative

**D3. HQ stores references and attribution, never the business fact.** There is no `amount`, `currency`, `revenue`, `status`, `quantity` or `lines` column on any of the three tables, and the recorder does not copy them into `evidence` either. "How much revenue did these outcomes bring" is answered at read time by joining `outcome_result_refs` to the record that owns the number (`facts_orders` for a shop or ERP-read-back order, the ERP itself for ERP-only documents). If the ERP corrects an order total next week, the attribution report shows the corrected number; HQ never holds a second, stale one.

**D4. Every reference carries the system of record's natural key.** `source_system` + `external_ref` (for example `erp` + `erp:SO-2026-0042`, or `shop` + `laladia-com:LD-1234`) is mandatory and unique per outcome. The typed foreign key (`facts_order_id`, `conversation_id`, ...) is a convenience into HQ's read model, which is rebuildable (ADR-007); the natural key is what still means the same thing after a rebuild, and what an auditor can take to the ERP. Result types HQ does not mirror (an ERP invoice or payment, an ad on Meta) are `external` references with no foreign key.

**D5. `evidence` holds identifiers and digests, not copies.** For an ERP order the evidence is the HQ operation key and the read-back digest, which are enough to prove the link and to find the exact read-back again. Evidence is bounded in size by the recorder (16 KB canonical JSON) and is never used as a data store.

## 4. Schema (migration `2026_10_10_100000_create_outcome_attribution_tables`)

All three tables are append-only and immutable at the database level (section 8). Every foreign key is `RESTRICT` (section 8 explains why never `CASCADE` or `SET NULL`). Timestamps are `timestamptz`.

### 4.1 `outcomes`

| Column | Type | Meaning |
|---|---|---|
| `id`, `uuid` | bigint, uuid unique | identity |
| `schema_version` | string(16) | contract version the row was written under, `outcome.v1` |
| `business_id` | FK businesses, NOT NULL | the business the result belongs to (the scope key) |
| `brand_id` | FK brands, null | brand, when the result is brand-specific; must belong to `business_id` |
| `store_id` | FK stores, null | store, for commerce results; must belong to `business_id` |
| `outcome_type` | string(64) | a registered type, `domain.event` (section 5) |
| `state` | string(16) | `asserted` or `retracted` (a retraction is a successor row, section 6) |
| `occurred_at` | timestamptz NOT NULL | when it happened in the world, by the source's clock |
| `source_as_of` | timestamptz null | when the source said the observation was true (the ERP's `as_of`) |
| `recorded_at` | timestamptz default now | when HQ wrote the row |
| `source` | string(32) | how HQ learned it: `erp_read_back`, `shop_webhook`, `shop_reconcile`, `hq_internal`, `operator`, `platform_read` |
| `source_system` | string(16) | `erp`, `shop`, `hq`, `meta`, ... |
| `source_ref` | string(191) null | the observation's identity in the source (event id, order number) |
| `provenance` | string(16) | ADR-024 vocabulary (`business`, `customer`, `third_party`, `generated`); `system` is refused |
| `evidence` | jsonb null | identifiers and digests (D5) |
| `content_digest` | char(64) | `CanonicalPayload::hash` of the row's meaning plus its result refs (section 6) |
| `actor_type`, `actor_id`, `actor_label` | | who recorded it (`system`, `human`, `agent`) |
| `correlation_id` | string(128) null | the HQ trace that observed it |
| `idempotency_key` | string(191) unique | caller-composed, deterministic from the source identity (section 6) |
| `supersedes_outcome_id` | FK outcomes, null, UNIQUE | the row this one corrects or retracts; unique = one successor, a linear chain |
| `reason` | text null | why a correction or retraction was made (required for both) |

Also `UNIQUE (id, business_id)`, the target of the composite foreign key in 4.3. Indexes: `(business_id, outcome_type, occurred_at)`, `(business_id, recorded_at)`, `(correlation_id)`. CHECK: `state IN ('asserted','retracted')`; `supersedes_outcome_id <> id`; a `retracted` row must supersede something.

### 4.2 `outcome_result_refs`

| Column | Type | Meaning |
|---|---|---|
| `id` | | |
| `outcome_id` | FK outcomes | |
| `result_type` | string(32) | `shop_order`, `erp_order`, `conversation`, `conversation_message`, `content_publication`, `external` |
| `role` | string(16) | `subject` (the outcome is about it) or `context` (relevant, not the subject) |
| `source_system`, `external_ref` | string(16), string(191) NOT NULL | the natural key (D4) |
| `facts_order_id` | FK facts_orders, null | for `shop_order` and `erp_order` |
| `conversation_id` | FK conversations, null | for `conversation` |
| `conversation_message_id` | FK conversation_messages, null | for `conversation_message` |
| `content_publication_id` | FK content_publications, null | for `content_publication` |
| `created_at` | timestamptz | |

`UNIQUE (outcome_id, source_system, external_ref)`. CHECK: the foreign key column that matches `result_type` is set and every other one is null; `external` sets none. (`erp_order` may have a null `facts_order_id` when HQ has the ERP number but no mirror row.) Every outcome type declares which result types it needs as `subject` (section 5).

**D6. Typed foreign-key columns with a CHECK, not a polymorphic `(type, id)` pair.** A polymorphic pair cannot carry a foreign key, so the database could not stop a reference to a row that does not exist or to a row of the wrong table. The typed columns cost one nullable bigint per target and give real referential integrity, which is what the ruling's "typed relationship tables" asks for. Adding a target type is an additive migration (one nullable column, one CHECK replaced).

### 4.3 `outcome_attributions`

| Column | Type | Meaning |
|---|---|---|
| `id`, `uuid` | | |
| `schema_version` | string(16) | `attribution.v1` |
| `outcome_id`, `business_id` | composite FK to `outcomes (id, business_id)` | the outcome, and its business repeated so the database itself refuses a cross-business attribution row and scoped reads need no join |
| `action_type` | string(32) | `agent_run`, `tool_call`, `ai_run`, `approval`, `decision_record`, `content_publication`, `workflow_instance`, `conversation_message` |
| `agent_run_id` ... `conversation_message_id` | eight FK columns, null | exactly one set, the one matching `action_type` (CHECK) |
| `action_ref` | string(191) NOT NULL | durable identity of the action (`agent_run:{uuid}`, `tool_call:{tool_key}:{idempotency_key}`, `approval:{uuid}`), readable without a join |
| `proposal_ref` | string(64) null | which proposal inside `agent_runs.proposals`, when the credit is for one proposal of a run |
| `role` | string(16) | `produced` (the action made the result happen: the tool call that placed the order), `proposed` (the run that proposed it), `approved` (the human decision that allowed it), `contributed` (influenced without producing: a reply before an order) |
| `method` | string(32) | `deterministic_key`, `correlation`, `operator_asserted`, `rule_window`, `model_inferred` |
| `method_version` | string(32) | the version of the rule that made the claim, `deterministic_key.v1` |
| `confidence` | numeric(5,4) | 0 to 1, bounded by the method (D8) |
| `state` | string(16) | `asserted` or `retracted` |
| `evidence`, `content_digest` | | as on outcomes |
| `actor_type`, `actor_id`, `actor_label`, `correlation_id` | | |
| `idempotency_key` | string(191) unique | |
| `supersedes_attribution_id` | FK self, null, UNIQUE | linear correction chain |
| `reason` | text null | |
| `recorded_at` | timestamptz | |

Indexes: `(business_id, method, recorded_at)`, `(outcome_id)`, one index per action FK column.

**D7. "Proposal" is an agent run plus `proposal_ref`, and the human verdict on it is a `decision_record` or an `approval`.** HQ has no proposals table: proposals live in `agent_runs.proposals` (jsonb) and the human edit/verdict in `decision_records` (docs/95 §10 item 3). The contract credits whichever exists, and does not invent a proposal entity. If S4 introduces one, it is one more action type (one column).

**D8. Methods are a closed vocabulary with a confidence rule each.**

| Method | Meaning | Confidence |
|---|---|---|
| `deterministic_key` | proven by key arithmetic: the result carries a key only this action could have produced (ERP `hq_key` = `ErpOperationKey::for(call key, business, step)`) | must be exactly 1 |
| `correlation` | joined by a shared HQ correlation id or a stored foreign key on the action | must be exactly 1 |
| `operator_asserted` | a person said so, with a reason | any, reason required, actor must be human |
| `rule_window` | a declared rule (for example "a reply in the 72 h before the order") | must be < 1 |
| `model_inferred` | a model's judgement | must be < 1, and never the only basis for an autonomy or money decision (D13) |

`rule_window` and `model_inferred` are in the vocabulary so the contract is complete, but v1 ships no rule and no model that uses them. Defining a window rule or a multi-touch credit split is an S4-era decision (section 12, Q3 and Q4).

**D9. No credit share in v1.** A `credit_share` column (multi-touch split of one outcome across actions) is deliberately absent. Splitting revenue credit is a business-policy decision (last touch? linear? by role?) that nobody has taken, and a column with no agreed meaning is how incompatible attribution systems start. Reports in v1 count outcomes per action and role; they do not divide money. Q4.

## 5. The outcome-type registry

`App\Outcomes\OutcomeTypes` is a closed PHP registry, reviewed like code (not configuration, so a type cannot be added on a server). Each type declares: its domain, the `result_type`s required as `subject`, the `source`s allowed, the `provenance` allowed, and its schema version.

v1 types:

| Type | Subject result types | Sources |
|---|---|---|
| `order.placed`, `order.confirmed`, `order.delivered`, `order.cancelled` | `shop_order` or `erp_order` | `erp_read_back`, `shop_webhook`, `shop_reconcile` |
| `conversation.customer_responded` | `conversation` (and optionally `conversation_message` as context) | `hq_internal` |
| `conversation.resolved` | `conversation` | `hq_internal`, `operator` |
| `publication.verified` | `content_publication` | `hq_internal`, `platform_read` |

**D10. Types name business events, not HQ actions.** "Reply sent" is an action (it is in `conversation_messages` and `tool_calls` already); "customer responded" is the outcome. Keeping actions out of the outcome vocabulary is what stops the outcome ledger from becoming a second activity log.

**D11. A type is versioned with the schema.** `schema_version` on every row lets a reader interpret an old row by the contract it was written under. Changing what a type means is a new type or a new schema version, never a silent change of an existing one.

## 6. Recording, idempotency and correction

`App\Outcomes\OutcomeRecorder` is the only writer.

- `record(OutcomeSpec)` writes one outcome and its result refs in one transaction, `INSERT ... ON CONFLICT (idempotency_key) DO NOTHING`. If the key already exists it compares `content_digest`: equal means the same observation again, and the existing row is returned (`replayed`); different means the caller is reporting a different fact under the same identity, and the recorder throws `OutcomeConflict`. It never overwrites.
- `attribute(Outcome, AttributionSpec)` writes one attribution the same way, with its own key and digest.
- `supersede(Outcome, OutcomeSpec, reason, actor)` and `retract(Outcome, reason, actor)` write a successor row. The unique index on `supersedes_outcome_id` makes a second, concurrent correction of the same row fail (`OutcomeConflict`) rather than fork the history. A correction keeps the business and the type (a wrong type is a retraction plus a new outcome); a retraction copies the prior's references, uses the key `retract:{prior key}`, and is final (only an `asserted` row can be corrected or retracted; reinstating is a new record). `supersedeAttribution` / `retractAttribution` likewise, and a re-judged attribution keeps its action.
- `content_digest` is taken over the meaning (type, business, brand, store, state, occurred_at, source_as_of, source, source_system, source_ref, provenance, evidence, sorted result refs; for an attribution: outcome, action, proposal ref, role, method, method version, confidence, state, evidence), with `App\Gateway\Approval\CanonicalPayload`, the single canonical encoder HQ already has (docs/95 §10 item 8: no third encoder). `recorded_at`, actor and correlation are not part of the meaning, so a retry from another worker is still a replay.

**D12. The idempotency key is composed by the caller from the SOURCE identity, never from HQ's clock or row ids.** For example `order.placed:erp:{business_id}:{erp_order_number}`. Two workers observing the same ERP order therefore collide on one key, whichever reads first. The key is namespaced by outcome type so `order.placed` and `order.delivered` of one order never collide.

Validation, all fail-closed (`InvalidArgumentException`, nothing written): registered type; allowed source and provenance; required subject result present; brand and store belong to the business; every referenced read-model row exists and belongs to the same business (`facts_orders.business_id`, `conversations.business_id`); for an attribution, the action exists and, where the action table has a `business_id`, it equals the outcome's (a null action business, such as an HQ-scope tool call, is accepted: Q6); the method's confidence rule; `operator_asserted` needs a human actor and a reason; evidence within 16 KB.

**D13. Recording an outcome or attribution grants nothing.** No autonomy level, permission, approval or gate reads these tables in v1. When S4 or an autonomy policy wants to use them, that use is a separate ruling, and only `deterministic_key`, `correlation` and `operator_asserted` rows may count as evidence for an autonomy or money decision.

**D14. Corrections and retractions are audited.** `outcome.superseded`, `outcome.retracted`, `outcome_attribution.superseded`, `outcome_attribution.retracted` go to `audit_logs`, because they are someone changing what HQ believes about the past. A first `record()` or `attribute()` is not audited: the ledger row is itself the record, and auditing every observation would double the write volume of the busiest future path for no new information.

## 7. Domain detail tables (the rule; none in v1)

When a domain needs columns that only it understands (for example a conversation outcome's resolution category, or a publication's verified reach), it adds `outcome_<domain>_details`:

- primary key = `outcome_id` (FK `outcomes`, RESTRICT), one detail row per outcome at most;
- written in the same transaction as the outcome, by that domain's recorder through `OutcomeRecorder`;
- part of the outcome's `content_digest` (the recorder gains a detail-payload argument when the first detail table is added; v1 has none);
- append-only with the same trigger;
- holds HQ-owned semantics only; a number owned by a system of record is still a reference, never a column.

v1 does not create one: every v1 type is fully described by its type, references and evidence. The first candidate is a conversation outcome's resolution vocabulary, which belongs with S2/S4 (Q5).

## 8. Integrity and immutability

- One trigger function, `hq_outcome_ledger_immutable()`, refuses `UPDATE` and `DELETE` on all three tables (the existing per-table pattern of `audit_logs`, `consent_events`, `research_artifacts`, generalised over the three with `TG_TABLE_NAME`). Models refuse the same in `updating`/`deleting` with a sentence naming the rule.
- **D15. Every foreign key is RESTRICT.** `CASCADE` deletes rows and `SET NULL` updates them; both are writes the triggers refuse, so offering them would only turn a parent deletion into a confusing trigger error (the consent ledger made the same choice). The consequence is deliberate: a parent row that an outcome points at cannot be deleted. That is right for permanent evidence, and section 9 makes retention respect it.
- **D16. Business integrity is enforced twice.** The composite FK `(outcome_id, business_id)` makes a cross-business attribution row impossible at the database; the recorder checks every referenced row's business before writing.
- **D17. Current view = rows with no successor.** `Outcome::current()` / `OutcomeAttribution::current()` filter `NOT EXISTS (successor)` and `state = 'asserted'`. History is the chain.

## 9. Retention

The three tables join `RetentionPolicy::PERMANENT`. Two of the action tables they point at are retention targets (`tool_calls`, `ai_runs`: 180 days) and one is pruned operational state (`workflow_instances`). Because the foreign keys are RESTRICT, a referenced row must never be selected for deletion, so:

- `tool_calls` and `ai_runs` keep-conditions gain `OR EXISTS (SELECT 1 FROM outcome_attributions o WHERE o.tool_call_id = t.id)` (and `ai_run_id`);
- `WORKFLOW_KEEP_CONDITION` gains the same for `workflow_instance_id`.

**D18. This is the only change to a live code path, and it can only keep more rows.** With the tables empty it matches nothing. It is in this branch, not deferred, because without it the first attribution to a tool call would make the next retention pass fail on a foreign key.

## 10. Business scope

- `Outcome::scopeVisibleTo($query, User)` and `OutcomeAttribution::scopeVisibleTo` delegate to `App\Support\Authorization\BusinessScope::restrict()` (no rows = group-wide, nothing cached), the one existing rule; no new scope logic.
- `outcome_result_refs` has no business column: it is read through its outcome, and `OutcomeResultRef::scopeVisibleTo` restricts through `whereHas('outcome')`.
- **D19. No Eloquent global scope.** docs/95 §10 item 9 notes HQ has none yet and recommends one fail-closed scope for the whole app; introducing a global scope on three tables alone would be a fourth altitude of scope enforcement. These models use the same explicit `restrict()` call as every current reader. Q7.

## 11. What ships in this branch, and what does not

Ships (inert): the migration; models `Outcome`, `OutcomeResultRef`, `OutcomeAttribution`; `OutcomeTypes`, `AttributionMethod`, `AttributionRole`, `ActionType`, `ResultType` vocabularies; `OutcomeSpec`, `ResultRefSpec`, `AttributionSpec` value objects; `OutcomeRecorder`; `OutcomeConflict`; one illustrative recorder, `App\Outcomes\Recorders\ErpOrderPlacedRecorder`, which, given an ERP read-back fact and the `commerce.take_order` tool call, records `order.placed` with an `erp_order` reference and credits the tool call (`produced`, `deterministic_key`, proven by recomputing `ErpOperationKey`), the approval named by the call's `approval:{uuid}` key (`approved`, `deterministic_key`) and the agent run sharing the approval's correlation id (`proposed`, `correlation`). It is called by nothing but its tests. Tests; the retention keep-clauses.

Does not ship: any caller in a live path, any listener, schedule, workflow or tool; any screen or report; any S4 follow-up, opportunity or task entity; any multi-touch or window rule; any autonomy or gate use of outcomes; any backfill of history (Q8).

## 12. Open questions for the ChatGPT contract review

1. **Q1 Shape.** Accept the three-ledger shape (outcome / result refs / attributions, D1, D2), or should result references be merged into the outcome row (one subject per outcome)?
2. **Q2 Typed FK columns** (D6) versus a polymorphic reference with no foreign key. We chose integrity; the cost is one additive migration per new target type. Accept?
3. **Q3 Window rules.** Who defines `rule_window` rules (for example "a reply within 72 h before an order is `contributed`"), where do they live (code registry vs governed versioned policy like the autonomy evidence policy), and are they S3 or S4?
4. **Q4 Credit split.** Leave multi-touch credit (`credit_share`) out until a business policy exists (D9), or define one now (last touch, linear, role-weighted)?
5. **Q5 Detail tables.** Is the first domain detail table (conversation resolution vocabulary) part of S2/S4 rather than S3, as proposed in section 7?
6. **Q6 Business-less actions.** Accept an attribution to an action whose own `business_id` is null (an HQ-scope tool call or model call), or require every credited action to carry the outcome's business?
7. **Q7 Global scope.** Keep explicit `BusinessScope::restrict()` (D19) until the app-wide fail-closed global scope from docs/95 §10 item 9 is ruled, or introduce a global scope on these models now?
8. **Q8 Backfill.** Should historical ERP read-back orders (`facts_orders.source_system = 'erp'`) be backfilled as `order.placed` outcomes once S4 needs them, or does history start at activation? A backfill would use `recorded_at` = now and the original `occurred_at`, which the schema already distinguishes.
9. **Q9 Personal-data erasure.** RESTRICT foreign keys to `conversations` and `conversation_messages` mean a future erasure of a conversation must first deal with the outcome rows pointing at it (the consent ledger has the same property toward `customers`). Accept, and decide the erasure rule when conversation retention itself is ruled (it is UNGOVERNED today)?
10. **Q10 Value snapshot.** D3 forbids storing any amount. Should an attribution report ever freeze "the value as attributed at the time" (for example for a monthly close), and if so, as a separate report snapshot rather than in this ledger?
11. **Q11 Who may record.** v1 has no gateway tool and no permission for recording: only server code (recorders) writes. When an operator needs to assert an attribution by hand (`operator_asserted`), should that be a gateway tool with its own permission and audit (our proposal), not a direct screen write?

## 13. Implementation record (branch `platform/s3-outcome-model`)

- Migration `apps/hq/database/migrations/2026_10_10_100000_create_outcome_attribution_tables.php`: applied, rolled back and re-applied twice on Postgres 17 (`hq_test_g`), clean each time.
- Code: `app/Outcomes/` (`OutcomeRecorder`, `OutcomeTypes` + `OutcomeTypeDefinition`, `OutcomeSpec`, `ResultRefSpec`, `AttributionSpec`, `OutcomeActor`, `OutcomeConflict`, enums `ActionType`, `ResultType`, `AttributionMethod`, `AttributionRole`, `OutcomeSource`, `OutcomeState`), `app/Outcomes/Recorders/ErpOrderPlacedRecorder.php`; models `Outcome`, `OutcomeResultRef`, `OutcomeAttribution`; `RetentionPolicy` keep-clauses and `PERMANENT` entries.
- The recorder names `commerce.take_order` by value, not by class: `TakeOrderTest` holds that only the gateway reaches that tool, and a test pins the two constants to the tool's own.
- Tests: `tests/Feature/Outcomes/OutcomeLedgerTest.php` (scope, references not copies, idempotency and conflict, eleven fail-closed refusals, immutability in model and database on all three tables, RESTRICT, typed-target and composite-business CHECKs in the database, method confidence rules, operator assertions, supersede/retract with audit and single-successor, BusinessScope reads, conversation outcome); `tests/Feature/Outcomes/ErpOrderPlacedRecorderTest.php` (proven credits, idempotent, refusals, direct call without approval, inertness, constant pin); `tests/Feature/Observe/RetentionTest.php` (new reference columns known; attributed tool call, model call and workflow run kept, unattributed ones pruned).
- Gate: full Pest suite 1,875 passed; `vendor/bin/pint --test` passed; `npx tsc --noEmit` clean.
