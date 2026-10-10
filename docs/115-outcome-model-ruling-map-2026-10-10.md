# 115 - S3 follow-ups: the D1-D19 ruling map, Q6, Q11, Q3, Q7 (10 October 2026)

Status: branch `platform/s3-followups-hqscope-attribute-tool` (worktree `hq-wt/g`, from `main` `a8a6410`, which carries S3 = docs/107). Not merged, not deployed. Section 2 is the implementation record and is filled as the work lands.

Authority: ChatGPT's ruling of 10 Oct afternoon, verbatim `docs/110-chatgpt-ruling-2026-10-10.txt` §3 (Q1-Q11, the seven contract constraints, and the instruction on D1-D19), summarised in `docs/101` §14. The ruling's own words on D1-D19:

> The report references D1-D19, but their individual wording was not included in the summary supplied for this ruling. Map the decisions above to the corresponding documented items. Return any additional D1-D19 decisions that are not covered here as review-pending; do not silently interpret them as approved.

Section 1 is that map, and it goes back to ChatGPT verbatim. Labels: **APPROVED-BY** (a ruling line covers the decision as written), **CHANGED-BY** (a ruling line requires the decision or its implementation to change; what changed is stated), **REVIEW-PENDING** (no ruling line covers it; it stands as a proposal, not as approved).

---

## 1. D1-D19 mapped to the ruling

Ruling lines are cited as `Q1`..`Q11` (docs/110 §3 numbered list) and `C1`..`C7` (the "Additional contract constraints" bullets, in order: C1 business-scoped with brand/store belonging to the business; C2 cross-business attribution fails closed; C3 no duplicated ERP amount/currency/line/status fields; C4 append-only corrections through successor records with reason and audit trail; C5 retractions explicit and auditable; C6 only the documented confidence rules apply, `rule_window` and `model_inferred` unused in v1; C7 operator assertion is not independent deterministic proof).

| D | docs/107 decision (short) | Verdict | By | Note |
|---|---|---|---|---|
| D1 | Outcome and attribution are separate rows | APPROVED-BY | Q1, Q4 | Q1 approves the three-table shape and "keep result references separate from outcome records"; Q4 "multiple attribution rows/roles are supported". |
| D2 | No oversized table: cross-domain columns only; per-domain detail tables when a domain needs one; none in v1 | APPROVED-BY | Q5, Q1 | Q5 "do not add a speculative detail table without a concrete need". |
| D3 | HQ stores references and attribution, never the business fact (no amount, currency, status, lines) | APPROVED-BY | C3, Q10 | Q10 "authoritative financial facts remain in their system of record". |
| D4 | Every reference carries the system of record's natural key (`source_system` + `external_ref`, mandatory, unique per outcome); the typed FK is a convenience | REVIEW-PENDING | - | Consistent with Q2 (typed FKs) and Q10 (evidence references) but the natural-key requirement itself is not ruled on. |
| D5 | `evidence` holds identifiers and digests, bounded (16 KB), never a data store | APPROVED-BY | Q10, C3 | Q10 "keep rule/version metadata, evidence references and relevant timestamps". The 16 KB bound is our implementation choice under that line. |
| D6 | Typed foreign-key columns with a CHECK, not a polymorphic `(type, id)` pair | APPROVED-BY | Q2 | "Use typed foreign keys and business-scope constraints; avoid unconstrained polymorphic references." |
| D7 | A "proposal" is an agent run plus `proposal_ref`; the human verdict is a `decision_record` or an `approval`; no proposal entity invented | REVIEW-PENDING | - | Not ruled. ⚠ Interaction with Q6: `decision_records` has no business column, so under Q6 (this branch) a decision record cannot be credited in v1 until an explicit, validated path resolves its business (section 3.3). |
| D8 | Methods are a closed vocabulary with a confidence rule each; `rule_window` and `model_inferred` in the vocabulary but unused | CHANGED-BY | Q3, C6, C7 | Vocabulary and confidence rules approved as written (C6). Changed: "unused" was by absence of a caller; it is now enforced, the recorder refuses `rule_window` and `model_inferred` at write time (section 5). C7 adds a distinction D8 did not draw: `operator_asserted` is evidence-grade (D13) but never independent deterministic proof; the code now says so (`AttributionMethod::isDeterministicProof()`). |
| D9 | No `credit_share` column in v1; reports count, they do not divide money | APPROVED-BY | Q4 | "Defer numerical multi-touch credit allocation ... v1 does not split revenue automatically." |
| D10 | Types name business events, not HQ actions | REVIEW-PENDING | - | Not ruled. Related only: Q5 places conversation-resolution vocabulary (the categories) in the Conversation/Support domain; it does not rule on the `conversation.resolved` outcome TYPE living in the S3 registry. Question for ChatGPT in section 6. |
| D11 | A type is versioned with the schema (`schema_version` on every row; a changed meaning is a new type or version) | REVIEW-PENDING | - | Q10's "rule/version metadata" is about attribution rules, not outcome-type versioning. |
| D12 | The idempotency key is composed by the caller from the SOURCE identity, never HQ's clock or row ids | REVIEW-PENDING | - | Not ruled. |
| D13 | Recording grants nothing; only `deterministic_key`, `correlation`, `operator_asserted` may ever count as evidence for an autonomy or money decision | CHANGED-BY | C7 | "Grants nothing" is not ruled (review-pending half). Changed half: C7 narrows the evidence-grade statement, an operator assertion may count as evidence but is never independent deterministic proof, so a future autonomy or money decision may not rest on an `operator_asserted` row alone. Encoded as `isDeterministicProof()` beside `isEvidenceGrade()`; nothing reads either in v1. |
| D14 | Corrections and retractions are audited (`audit_logs`); a first `record()`/`attribute()` is not | APPROVED-BY | C4, C5 | "Append-only corrections through successor records with reason and audit trail"; "retractions are explicit and auditable". ⚠ The second half (first write not audited) is not ruled; the ledger row is itself the record. Flagged in section 6. |
| D15 | Every foreign key is RESTRICT | APPROVED-BY | Q9 | "Keep RESTRICT foreign keys. Future erasure through reviewed redaction/tombstoning ... not casual cascade deletion." |
| D16 | Business integrity enforced twice (composite FK `(outcome_id, business_id)` + recorder checks every referenced row's business) | APPROVED-BY | Q2, C1, C2 | "Business-scope constraints"; "cross-business attribution must fail closed". |
| D17 | Current view = asserted rows with no successor | REVIEW-PENDING | - | Follows from C4/C5 but is not itself ruled. |
| D18 | Retention keep-clauses on `tool_calls`, `ai_runs`, `workflow_instances` for attributed rows: the only live-path change | REVIEW-PENDING | - | Necessary under Q9 (RESTRICT) but not ruled. |
| D19 | No Eloquent global scope; explicit `BusinessScope::restrict()` on every read | APPROVED-BY | Q7 | "Require explicit `BusinessScope::restrict()` at service/query boundaries. A global scope can supplement but never replace explicit enforcement." Pinned by a structural test on this branch (section 7). |

**Counts: 10 APPROVED-BY (D1, D2, D3, D5, D6, D9, D14, D15, D16, D19) · 2 CHANGED-BY (D8, D13) · 7 REVIEW-PENDING (D4, D7, D10, D11, D12, D17, D18).**

Ruling lines that change docs/107 outside the numbered decisions:

| Ruling | docs/107 text affected | Change on this branch |
|---|---|---|
| Q6 | §6 validation list: "a null action business, such as an HQ-scope tool call, is accepted: Q6" | Reversed. A null action business is refused, typed exception, fail closed (section 3). |
| Q11 | §11 "Does not ship: ... any tool"; Q11 proposal | The operator-asserted attribution tool now ships, inert, registered, through the gateway only (section 4). |
| Q3 | §12 Q3 | `rule_window` and `model_inferred` refused at write time; extension point for the future versioned policy registry documented, registry not built (section 5). |
| Q8 | §12 Q8 | History starts at activation; no backfill. Nothing to change: nothing on this branch reads order history. |
| Q10 | §12 Q10 | Snapshots deferred. Nothing to change. |

---

## 2. Implementation record

Filled as the work lands (commits, tests, gate). See the end of this document.

---

## 3. Q6: implicit null business scope is refused

### 3.1 Before

`OutcomeRecorder::validateAttribution()` looked up the credited action's business and accepted `null` ("an HQ-scope action", docs/107 §6, Q6). `tool_calls.business_id` and `ai_runs.business_id` are nullable (an HQ-wide tool call or model call), `decision_records` has no business column at all, so three of the eight action kinds could be credited to a business-scoped outcome with no proof that the action had anything to do with that business.

### 3.2 After

A credited action whose business cannot be established, or is null, is refused with `App\Outcomes\ImplicitHqScopeRefused` (an `InvalidArgumentException`, so every existing fail-closed path and test contract holds), nothing written. The message names the action and says what would be needed (an explicit, validated HQ-scope representation, section 3.3). A different business is still refused as before.

### 3.3 The explicit HQ-scope representation: not built, and why

Q6: "Introduce an explicit, validated HQ-scope representation when needed." The branch audited every concrete v1 recorder for the need:

- `ErpOrderPlacedRecorder` credits the `commerce.take_order` call, its approval and the proposing agent run. All three rows carry the outcome's `business_id` (the call's scope is resolved by the gateway before execution; the approval and the run are business-scoped by construction). It never needs a null-business action.
- The new `outcomes.attribute` tool (section 4) credits an action an operator names. It inherits the same validation; an operator naming an HQ-scope action is refused with the same typed message.

No v1 recorder needs it, so per the brief it is not built. The shape we would propose when a recorder does need it:

1. `AttributionSpec` gains an optional `actionScope: ActionScope` (`Business` default, `Hq` explicit). `Hq` is only valid when the action row's business IS null (an action that has a business is never allowed to be declared HQ-scope) and when the action kind is on a reviewed allow-list of kinds that can legitimately act HQ-wide (today: `tool_call` of a `ToolScope::Hq` tool, `ai_run` of an HQ-wide task). The allow-list is code, reviewed like `OutcomeTypes`.
2. The row records the declaration: `outcome_attributions.action_scope` (string(16), NOT NULL, default `business`, additive migration, CHECK `in ('business','hq')`), so a reader can tell a business-scoped credit from an HQ-scope one without re-deriving it.
3. The recorder validates the declaration (not the absence): declared `Hq` + null business + allowed kind → accepted; anything else → `ImplicitHqScopeRefused`.
4. `decision_records` is a separate case: its business is derivable through its context (`context_type`/`context_id` → agent run / conversation → business) and should be resolved, not declared HQ-scope. That is a reviewed change to `actionBusiness()` when a recorder first needs to credit a decision record (D7, review-pending).

---

## 4. Q11: `outcomes.attribute`, the operator-asserted attribution tool

### 4.1 Descriptor

| Field | Value | Why |
|---|---|---|
| key | `outcomes.attribute` | provisional per the ruling; `hq.outcomes.attribute` is the permission, the tool key follows the `domain.verb` convention of every other tool |
| version | `1.0.0` | |
| riskTier | **R3** | section 4.2 |
| permission | `hq.outcomes.attribute` | new, additive in `RolesAndPermissionsSeeder`; `owner` and `operator` hold it through `*`, no other role is given it |
| auditAction | `tool.outcomes_attribute` | the gateway's audit row for every call |
| scope | `Business`, `scopeFrom: business:args.business_slug` | the gateway resolves and enforces the caller's `BusinessScope` for that business before the tool runs |
| readWrite | `Write` | `hasEffect()` true: it is an effectful tool, so it is pinned in `GatewayCoverageStructureTest` and docs/105 §1.2 |
| agentExecutable | `false` | |
| permanentlyHumanOnly | **`true`** | an operator assertion is by definition a human's claim; an agent's claim would be `model_inferred`, which v1 refuses. A DECISION, not a stage (ADR-025) |
| channel / customerFacing / consent | Internal / false / none | reaches nobody outside HQ |
| reversibility | `Reversible` | the row is withdrawn by a retraction successor row, never edited or deleted (section 4.4); `NoEffect` would be false and `Compensable` needs a compensating tool, which v1 has not (section 6, question 7) |
| idempotent | true, key from `business_slug, outcome_uuid, action_type, action_id, role, confidence, reason, evidence_ref` | the recorder has its own idempotency on the attribution key underneath |
| held by | no agent (`agents.allowed_tools` names it nowhere; test pins it) | |

Inputs (JSON schema, `additionalProperties: false`): `business_slug`, `outcome_uuid`, `action_type` (enum of the eight `ActionType` values), `action_id` (integer), `role` (enum of the four `AttributionRole` values), `confidence` (number 0..1, required, no default), `reason` (string, 10..2000), `evidence_ref` (string, 1..191), optional `proposal_ref` (agent runs only). Method is not an input: it is always `operator_asserted`; the actor is not an input: it is the gateway-authenticated human (`ToolContext::actorUserId`). A call with no human actor (a system or service identity) is refused by the tool itself, fail closed, before the recorder is reached.

Output: `attribution_uuid`, `outcome_uuid`, `method`, `method_version`, `confidence`, `actor`, `replayed`.

### 4.2 Why R3, and why no approval at this tier

The ladder (`RiskTier`) is intrinsic to what the code does: R3 is "internal write: HQ state only, reversible in HQ, invisible outside HQ"; R4 is "external side effect that can be corrected without loss". This tool writes one row in `outcome_attributions` and nothing else: no system of record, no provider, no customer, no money moves. That is R3 by the ladder's own words, and declaring R4 to obtain an approval would be the defect the ladder exists to prevent (a tier that says something other than what the code does), in the other direction.

Two things make R3 safe here today:

1. **D13: recording grants nothing.** No permission, approval, gate or autonomy level reads `outcome_attributions` in v1. The row is evidence that may one day be read; the day it is read is a separate ruling, and that ruling can raise the bar on this tool (a tier is code; a tier change is a reviewed change).
2. **C7 is encoded.** An `operator_asserted` row is never independent deterministic proof (`isDeterministicProof()` false), so a future consumer cannot mistake it for one.

Approval is still available without a tier change: a human-governed `ToolPolicy` row can require approval for `outcomes.attribute` (`PolicyRules::requiresApproval()`), and `requesterMayDecide: false` can be declared the day HQ has a second approver. With one operator, approval-required with `requesterMayDecide: true` would be the same person approving himself, which is a formality rather than a control; and with `requesterMayDecide: false` nobody could use the tool. Open question for ChatGPT in section 6.

### 4.3 No direct write path (structural)

`tests/Feature/Outcomes/OutcomeWritePathStructureTest.php` reads the source and pins, so a new write path fails the suite before anybody has to remember to review it:

- the three tables are written (`create`, `forceCreate`, `insert`, `upsert`, `DB::table(...)`) only in `app/Outcomes/OutcomeRecorder.php`;
- `OutcomeRecorder` is referenced only from `app/Outcomes/**` and `app/Tools/Outcomes/AttributeOutcomeTool.php`;
- nothing under `app/Http/`, `routes/` or `app/Console/` references the three models, the recorder or the tables;
- `GatewayCoverageStructureTest`'s effectful-tool pin gains `outcomes.attribute` deliberately (ten tools), and docs/105 §1.2 gains row 9.

The existing `ErpOrderPlacedRecorderTest` "inert" pin (nothing outside `app/Outcomes` references the recorder) is extended to name the tool file as the one reviewed exception.

### 4.4 Reversibility

The row cannot be edited or deleted (append-only trigger); it is withdrawn by `retractAttribution()`, a successor row. The descriptor says `Reversibility::Reversible` with that meaning. There is no retract tool in v1 (section 6).

---

## 5. Q3: `rule_window` and `model_inferred` cannot be used in v1

`App\Outcomes\AttributionPolicy` is the single place that says which methods the running contract version admits (`VERSION = attribution_policy.v1`, `activeMethods()` = `deterministic_key`, `correlation`, `operator_asserted`). `OutcomeRecorder` asks it before the confidence rule and refuses the other two with `App\Outcomes\AttributionMethodInactive` (an `InvalidArgumentException`), nothing written. The two methods stay in the vocabulary (C6: the contract is complete) with their confidence rules intact.

This class is the extension point, documented in its docblock, for the versioned, human-governed attribution policy registry Q3 names: when a window rule or a model judgement is first admitted, the registry replaces the constant with a versioned policy row (rule id, version, method, confidence ceiling, who approved it, when active), `method_version` on each attribution names the policy version that made it, and `activeMethods()` becomes a read of the active policy. The registry is not built on this branch (the brief and Q3: do not activate `rule_window` in v1).

---

## 6. Open questions for ChatGPT (return with section 1)

1. The seven REVIEW-PENDING decisions (D4, D7, D10, D11, D12, D17, D18): accept as written, change, or reject, by number.
2. D14's second half: a first `record()`/`attribute()` writes no `audit_logs` row (the ledger row is the record; corrections and retractions are audited). Accept?
3. D10 against Q5: the `conversation.resolved` outcome TYPE is in the S3 registry while its resolution VOCABULARY (categories) belongs to Conversation/Support. Keep the type in S3 with the categories in a Conversation-owned detail table later, or move the type out of S3?
4. `outcomes.attribute` at R3 without approval (section 4.2): accept, or require a `ToolPolicy` approval rule / two-person control now, knowing HQ has one operator?
5. The HQ-scope representation shape in section 3.3: accept the shape in advance so the first recorder that needs it builds exactly that, or review again when the need arrives?
6. Under Q6, `decision_records` cannot be credited in v1 (no business column). Accept resolving its business through its context when first needed (3.3 item 4)?
7. The tool creates assertions only. Retracting or re-judging an operator assertion is today only possible in server code (`retractAttribution`). Should a `outcomes.retract_attribution` tool follow under the same permission, or a distinct one?
