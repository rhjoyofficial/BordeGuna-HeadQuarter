# 113 - Follow-up and Opportunity: contracts and the inert proposal interfaces (S4, part 1) (10 October 2026)

Status: CONTRACT, with the inert part built on branch `p4/s4-followup-opportunity-contracts` (worktree `hq-wt/e`, from `main` `a8a6410`). Not merged, not deployed. Production posture unchanged: no outbound provider, no tool registered for an agent, no flag switched, nothing activated.

Authority: ChatGPT ruling of 10 Oct (`docs/103-chatgpt-ruling-2026-10-10.txt` §6: "Preliminary contracts and inert proposal interfaces may be developed in parallel. Complete outcome-dependent implementation only after the S3 contract is agreed. Consent enforcement must use S7's applicable checks. Keep S4 proposal-only"; §10 order; §11 non-goals), summary `docs/101` §13. Context: `docs/95` §3 rows V7 (proactive follow-up) and V8 (next-best-action), §5 ("No Lead, Opportunity, Follow-up, Sales Activity, Quote ... entities"), §9 item 18 (communication policies are the owner's decisions, still open); `docs/96` §3 (HQ owns opportunities, follow-ups, consent, workflow state), §10 (HQ owns "Customer follow-up workflow for an opportunity"; n8n or a provider executes the transport), §19 (separate channel, identity, conversation, opportunity, follow-up, consent, workflow, outcome, provider), §20 (follow-up is a step of the sales flow, after fulfilment); `docs/102` (WorkflowEngine: every scheduled thing is a run of a definition); `docs/107` (S3 contract D1-D19, Q1-Q11, NOT yet ruled on); `docs/108` (S7 consent semantics, merged); `docs/109` (Customer 360: "promises: nothing recorded", question 1 "where do promises come from"); `docs/110` §8 (l).

Every decision is numbered (F1...) so the review can accept, change or reject it by number. Section 12 lists what is deliberately not built; section 13 the questions.

---

## 1. The problem in one paragraph

Today HQ forgets. A customer who says "I will order again next month", a quotation nobody closed, a wholesale enquiry, a cart abandoned at the delivery-charge question, a promise an operator made ("we will call you Monday"): none of it has a row. docs/95 §5 names the missing entities and docs/95 §3 V7 needs "something that records this customer was interested in X on date Y and nobody closed it". The ruling allows the record and the proposal interfaces now, and forbids everything that would act on them: no autonomous outbound, no automatic contact, no sales commitment. This contract therefore defines two durable, business-scoped records (an Opportunity and a Follow-up), who may create one, how a person decides on one, how a decided follow-up is scheduled (a WorkflowEngine run that ends at a person), and how consent is checked before any of it. It defines the *names* of what S3 will later connect (outcomes) without touching S3's tables.

## 2. Vocabulary

| Term | Meaning here |
|---|---|
| **Opportunity** | A recorded commercial interest of one customer of one business: "this customer wanted X around date Y, and it is not closed." Not a quote, not an order, not a forecast. Owned by HQ (docs/96 §3). |
| **Follow-up** | A planned future contact with one customer, for one purpose (service or marketing), on one channel, at one due time, with an intent in words. It is a plan a person owns; it never contains or sends a customer-facing message. |
| **Promise** | A follow-up whose origin is `promise`: the business told the customer it would get back to them. This is what docs/109's "promises" section will read (Q-S2). Not a separate entity. |
| **Proposal** | A follow-up or opportunity in state `proposed`: recorded by a person, or by an agent run (which may ONLY propose). A proposal has no effect until a person with the deciding permission approves it. |
| **Sales activity** | Not an entity in part 1. The only activity record is a done follow-up's completion note written by the person who did it. A proper activity ledger is outcome-dependent (what happened after the contact) and waits for S3 (section 10). |

**F1. A follow-up is a plan, not a message.** The `intent` field says what the contact is for ("ask whether the 5 kg honey order should be repeated"). There is no message body, no template, no draft. Composing anything a customer reads is the Tool Gateway's job at execution time (section 8), under S7's runtime consent check, S1's gateway coverage and whatever claims policy applies. Part 1 holds no path to it.

**F2. An opportunity is a reference and a sentence, never a number the ERP owns.** It carries kind, subject (words), an expected date and evidence references (a conversation, a message uuid). It has no amount, price, quantity or stock column (docs/96 §20: no second order system; docs/107 D3 for the same reason on outcomes).

## 3. States

### 3.1 Follow-up

```
proposed  -> approved | declined | refused
approved  -> scheduled            (same transaction as the approval: a WorkflowEngine run was started)
scheduled -> done | cancelled
refused, declined, done, cancelled: final
```

- `proposed`: recorded; consent pre-check passed (section 6). Nothing is scheduled.
- `refused`: the consent pre-check at proposal time (or the re-check at approval time) said no. The row is kept with the refusal class and the deciding consent event id. **Never silently dropped** (F7).
- `approved`: a person with `hq.followups.decide` accepted it. Transient: the same service call starts the WorkflowEngine run and moves to `scheduled`; if the start fails the transaction rolls back and the follow-up stays `proposed`.
- `scheduled`: a run of `followup.await_person` exists (section 7). The run sleeps until the due time and then waits for a person. Nothing is sent at any point.
- `done`: a person recorded that they acted on it (what they did, in words). The run receives the `followup.person_decided` signal and completes. What came of it is S3's territory (section 10).
- `declined`, `cancelled`: a person said no (before or after scheduling). A cancelled follow-up's run is cancelled through the engine.

**F3. Transitions are enforced by one conditional `UPDATE ... WHERE state IN (allowed sources)`** (the WorkflowInstance pattern, docs/102 §4): two operators deciding at once cannot both win. An illegal transition throws; it is never coerced.

### 3.2 Opportunity

```
proposed -> open | declined
open     -> closed
declined, closed: final
```

`open` = a person accepted that the interest is real and worth tracking. `closed` carries a `close_reason` the person chose (`converted`, `lost`, `no_response`, `duplicate`, `other`) and a note. **F4. A close reason is a person's word, not an attributed outcome.** Nothing moves an opportunity to `closed` automatically, and no outcome row is written or read by part 1 (section 10). When S3 is agreed, "converted" will be *checked against* the outcome ledger, not replaced by it.

## 4. Who may create, who may decide

| Act | Person | Agent run |
|---|---|---|
| Propose a follow-up or an opportunity | yes, with `hq.followups.propose` | yes, **as a proposal only**, through the service with a `Proposer::agentRun(run, proposal_ref)`; today no tool exposes this to any agent (section 12) |
| Approve, decline, complete, cancel, close | yes, with `hq.followups.decide` | never: the service refuses an agent proposer on every deciding method, and there is no gateway tool |
| Read | `hq.followups.view`, business-scoped | no |

**F5. The decider is a person, named on the row, and audited.** `decided_by_user_id`, `decided_at`, `decision_reason` (required for decline and cancel) on both entities; `closed_by_user_id` / `closed_at` / `close_note` on completion, cancellation and close. Every transition writes an `audit_logs` row as the acting person (`follow_up.proposed`, `follow_up.refused`, `follow_up.approved`, `follow_up.scheduled`, `follow_up.declined`, `follow_up.done`, `follow_up.cancelled`; `opportunity.proposed`, `opportunity.opened`, `opportunity.declined`, `opportunity.closed`). An agent run's proposal is audited as the agent (`Auditor::recordAgent`).

Permissions (additive, `RolesAndPermissionsSeeder`): `hq.followups.view`, `hq.followups.propose`, `hq.followups.decide`. owner and operator get all; brand_manager and support_agent get view + propose (the person who hears a promise can record it); deciding stays with owner/operator until the roles are ruled (Q4).

## 5. Business and brand scope

- `business_id` NOT NULL on both tables; `brand_id` nullable and, when set, must belong to the business; `customer_id` must belong to the business; a follow-up's `opportunity_id`, when set, must be the same customer; a `conversation_id`, when set, must belong to the business. All checked by the service before any write (fail closed, nothing written).
- Reads use `BusinessScope::restrict()` through `scopeVisibleTo` (docs/107 D19: explicit, no global scope). A restricted operator does not see, and cannot decide on, another business's rows (404 on the screen, as docs/109 does for customers).
- **F6. No cross-brand or cross-business follow-up.** One customer row = one business (docs/109); a LaLaDia interest never becomes a Bionic follow-up. Ruling §11.

## 6. Consent: purpose per follow-up kind, resolved through S7

| Follow-up kind | Consent purpose asked | What satisfies it (docs/108 §3) |
|---|---|---|
| `service` | `ConsentPurpose::Service` | any live grant whose purpose is service or all, not beaten by a denial |
| `marketing` | `ConsentPurpose::Marketing` | only an opt-in **of exactly that purpose** on an opt-in basis; a customer's message never does |

The check is `ConsentLedger::permits(customer, business, channel, purpose, brand)`: the same method, the same answer, as the Tool Gateway's step 5b and the inbox `OutboundPolicy`. No second rule is written here.

**F7. A proposal the ledger forbids is recorded as `refused` with the class, never dropped.** `consent_refusal` holds the `ConsentRefusal` value (`consent_do_not_contact`, `consent_withdrawn`, `consent_expired`, `consent_opt_in_missing`, `consent_not_recorded`), `consent_event_id` the deciding event when there is one, `consent_checked_at` the instant. The refusal is audited (`follow_up.refused`). An operator sees *why* on the list and can take the person to the consent screen; an agent that keeps proposing contact to a do-not-contact customer becomes countable.

**F8. The check runs twice and is still not the enforcement.** At proposal time (so a forbidden plan is never queued for a person's attention as if it were allowed) and again at approval time (the ledger may have changed; a withdrawal between proposal and approval moves the row to `refused`, not `scheduled`). Neither is the runtime check the ruling requires "before communication": that stays S7's gateway step 5b at the moment a message would be sent, which part 1 never reaches. Nothing here caches a consent answer onto the row as permission; the columns are a record of what was asked and answered.

**F9. The channel is required on a follow-up.** The ledger's scope is channel-specific (a do-not-contact on WhatsApp does not stop a phone call, docs/108 §3.1), so a follow-up with no channel cannot be checked exactly. `channel` is one of the gateway's `Channel` values other than `internal`.

A `service` follow-up for a customer with no consent event at all is `refused consent_not_recorded`. That is the S7 rule ("silence is never consent"), and the remedy is the operator-recorded consent act on the consent screen, before proposing again. See Q5 for whether a promise should be treated as its own basis.

## 7. Scheduling: a WorkflowEngine definition that ends at a person

**F10. Approval starts one run of `followup.await_person`; nothing else schedules anything.** No queued job, no `scheduled_jobs` row, no cron. `App\FollowUps\Workflow\FollowUpWorkflow extends WorkflowDefinition`:

```
type            followup.await_person   version 1
correlationKey  follow_up:{uuid}
steps
  1. sleep       until_due        seconds = input seconds_until_due (computed by the service at approval; a definition never reads the clock)
  2. awaitSignal person_decision  signal followup.person_decided, timeout 180 days
```

- The run is started by the engine's `start(type, "follow_up:{uuid}", input, requestedBy = the approving person, business)`, so the requester is the human who approved (docs/102 §11 Q3e: no agent-started runs). The instance key is deterministic, so a second approval attempt is the same run.
- There is **no tool step**. The definition declares no `Step::tool`, imports only the workflow seam (held by `WorkflowStructureTest`), and a new structural test holds that it never gains a tool step without this document changing (section 11).
- When the sleep ends, the run waits on the signal. The follow-up list shows it as due. A person completes or cancels the follow-up, which sends `followup.person_decided` (completion) or cancels the run (cancellation). If nobody acts for 180 days the step times out and the run completes, with the follow-up still `scheduled`, visible as overdue; the row is the record, the run is only the timer.
- The follow-up stores `workflow_instance_key` and `workflow_run_uuid` as plain columns, **no foreign key** to `workflow_instances`: runs are operational state pruned 180 days after they finish (docs/102 §11 Q3c); the follow-up outlives its timer.
- The type is listed in `config('hq.workflows.definitions')`: the first listed type. Listing it is what lets an approval schedule; it does not switch any flag and the run has no effect by construction. Q1 asks the review to confirm.

## 8. Reaching the customer later (NOT built)

When the owner activates proactive follow-up (a separate ruling and release), the "due" moment becomes a step in a later version of the definition, or in a successor definition, that calls a **Tool Gateway** tool as the follow-up's owner, under the gateway's policy, EEC, permission, S7 consent, idempotency key `wf:{run}:{step}`, approval where the policy requires it, and audit. The provider behind that tool (WhatsApp, voice, SMS) is a connector reached through the registry (docs/97, docs/98); HQ owns the plan, the provider carries the message (docs/96 §10). Part 1 contains no such step, tool, descriptor, adapter or provider, and `FollowUpStructureTest` holds that nothing under `App\FollowUps` references a tool, the gateway, a connector, a platform adapter, the conversation outbound path or an HTTP client.

## 9. Schema (migration `2026_10_10_800000_create_follow_up_tables`, additive)

### 9.1 `opportunities`

| Column | Type | Meaning |
|---|---|---|
| `id`, `uuid` | bigint, uuid unique | identity |
| `business_id` | FK businesses, NOT NULL, restrict | scope key |
| `brand_id` | FK brands, null, restrict | brand, when the interest is brand-specific |
| `customer_id` | FK customers, NOT NULL, restrict | whose interest |
| `conversation_id` | FK conversations, null, restrict | where it was heard, when it was a conversation |
| `kind` | string(32) | `reorder`, `quotation`, `wholesale`, `product_interest`, `other` |
| `subject` | string(500) | what, in words |
| `expected_at` | date, null | "next month" |
| `state` | string(16) | section 3.2 |
| `proposer_type` | string(16) | `person` or `agent_run` |
| `proposed_by_user_id` | FK users, null | the person, when a person |
| `proposed_by_agent_run_id` | FK agent_runs, null, restrict | the run, when an agent (`agent_runs` is permanent, so restrict is safe) |
| `proposal_ref` | string(64), null | which proposal inside the run |
| `evidence` | jsonb, null | identifiers only (message uuids, order numbers); bounded |
| `decided_by_user_id`, `decided_at`, `decision_reason` | | the open/decline decision |
| `closed_by_user_id`, `closed_at`, `close_reason`, `close_note` | | section 3.2 |
| `created_at`, `updated_at` | timestamptz | |

Indexes: `(business_id, state, created_at)`, `(customer_id)`. CHECK: `proposer_type = 'person'` implies `proposed_by_user_id` set and run null, and the reverse.

### 9.2 `follow_ups`

| Column | Type | Meaning |
|---|---|---|
| `id`, `uuid` | | |
| `business_id`, `brand_id`, `customer_id`, `conversation_id` | as above | |
| `opportunity_id` | FK opportunities, null, restrict | the interest it serves |
| `origin` | string(16) | `promise`, `opportunity`, `operator` |
| `kind` | string(16) | `service` or `marketing` (section 6) |
| `channel` | string(16) | gateway `Channel` value, never `internal` (F9) |
| `due_at` | timestamptz NOT NULL | when a person should act |
| `intent` | string(500) | what the contact is for (F1) |
| `state` | string(16) | section 3.1 |
| proposer columns | as on opportunities | |
| `consent_checked_at`, `consent_permitted`, `consent_refusal`, `consent_event_id` | timestamptz, bool, string(32) null, FK consent_events null restrict | section 6 |
| `decided_by_user_id`, `decided_at`, `decision_reason` | | |
| `workflow_type`, `workflow_instance_key`, `workflow_run_uuid` | string(64) null, string(191) null unique, uuid null | section 7; no FK |
| `closed_by_user_id`, `closed_at`, `close_note` | | done or cancelled |
| `created_at`, `updated_at` | | |

Indexes: `(business_id, state, due_at)`, `(customer_id)`, `(opportunity_id)`. CHECK on proposer as above; CHECK `kind IN ('service','marketing')`.

**F11. Mutable rows, append-only history in `audit_logs`.** Unlike the consent and outcome ledgers these are working records with a state, so they are updated in place (through the conditional update, F3) and every transition is an audit row with before/after. Nothing deletes a row; there is no delete route and the models refuse `deleting`.

**F12. Every foreign key is RESTRICT.** A customer, conversation, brand, business, agent run or consent event a follow-up points at cannot be deleted from under it (docs/107 D15 reasoning). Erasure (docs/107 Q9) is the same open question here.

## 10. The S3 linkage, as an interface with open questions (no table dependency)

`App\FollowUps\Outcomes\FollowUpOutcomeLink` (interface) with one binding today, `NoFollowUpOutcomeLink`, which records nothing and reports nothing:

```
afterCompleted(FollowUp $followUp, User $by): void      // would record the follow-up's run (workflow_instance) or the person's decision
                                                        // as an action credited `contributed` to a later outcome, by a `rule_window` method (docs/107 D8)
outcomesFor(Opportunity $opportunity): array            // would read docs/107 outcomes whose result refs name this customer's later orders
```

Nothing in part 1 calls these with any effect; the null binding exists so the call sites are visible and the interface is the only seam S3 will ever need to fill. Open questions the S3 review decides first: which action type credits a follow-up (`workflow_instance` of its run, a new `follow_up` action type under docs/107 D7 "one more column", or the `decision_record` of the person's completion); the `rule_window` for "an order after a follow-up" (docs/107 Q3, who owns it); whether an opportunity `closed converted` must be supported by an outcome row to count in any report (F4). Until then: no outcome row is read, written or displayed by S4, and the follow-ups page carries no revenue, conversion or "worked" figure.

## 11. Non-goals (ruling §6 and §11), and how each is held

| Non-goal | Held by |
|---|---|
| No autonomous outbound, no automatic customer contact | no tool step in the definition; no send path under `App\FollowUps` (structural test); no provider; `intent` is not a message (F1) |
| No autonomous sales commitment | an opportunity holds no price or quantity (F2); no quote entity; a close reason is a person's word (F4) |
| Proposal-only for agents | `Proposer::agentRun` can reach `proposed` only; every deciding method refuses it; no gateway tool is registered, so no agent can even propose today |
| Every scheduled workflow uses the WorkflowEngine | `follow_ups` has no `scheduled_jobs` row, queue job or cron; approval = `WorkflowEngine::start` (F10) |
| Every consequential effect subject to the Tool Gateway | there is no effect; the day one exists it is a tool step (section 8) |
| No cross-brand merging | one customer, one business, one brand or none (F6) |
| Nothing released without Joy | branch only; release notes in section 14 |

## 12. Deliberately NOT built in part 1 (pending the S3 ruling or a later activation ruling)

- Any outcome read or write (section 10); any "did it work" column, report or badge.
- Any tool, descriptor, agent permission or flag that lets an agent propose; the next-best-action evaluator (V8) that would produce proposals.
- Any step, tool, adapter or provider that reaches a customer (section 8); any message draft or template.
- Any automatic creation of a follow-up from a conversation, an order, a quotation or a shop event.
- Detection rules (reorder timing, abandoned checkout) and communication policies (quiet hours, who may be contacted: docs/95 §9 item 18, the owner's).
- A retention rule (Q6).
- Customer 360 "promises" section wiring (docs/109 question 1): part 1 makes the rows exist; showing them on the customer page is a one-query addition once this contract is accepted (Q7).

## 13. Questions for the ruling

1. **Q1 Listed type.** Accept that `followup.await_person` is listed in `hq.workflows.definitions` now (a run with no tool step, started only by an approving person), or keep it unlisted until the activation ruling, in which case approval records `approved` and schedules nothing?
2. **Q2 Service consent for a promise.** A `service` follow-up for a customer with no consent event is refused (`consent_not_recorded`). Accept (remedy: operator-recorded consent first), or should an operator-recorded promise count as the service basis in one act?
3. **Q3 Two kinds only.** `service` and `marketing` map to the two consent purposes that exist for contact. Is a third kind needed (for example `transactional` for order-lifecycle follow-ups that the ERP flow in docs/96 §20 implies), and would it be service consent?
4. **Q4 Roles.** brand_manager and support_agent: view + propose; decide = owner/operator only. Confirm.
5. **Q5 Opportunity kinds and close reasons.** `reorder`, `quotation`, `wholesale`, `product_interest`, `other`; `converted`, `lost`, `no_response`, `duplicate`, `other`. Accept, or name the business's own list.
6. **Q6 Retention.** Both tables are ungoverned (kept) in part 1. Proposal for the ruling: `refused` and `declined` rows pruned after 365 days; `done`, `cancelled` and `closed` kept as relationship history; open rows never. Audit rows are permanent either way.
7. **Q7 Customer 360.** Show open follow-ups and opportunities (and promises) on the customer page now, read-only, or after the S3 linkage so the section is complete?
8. **Q8 Conversation foreign key.** RESTRICT to `conversations` means a future conversation erasure must first deal with these rows (docs/107 Q9). Accept the same answer as S3.
9. **Q9 Due-time timeout.** 180 days of waiting for a person, then the run completes and the row stays overdue. Accept, or alert on overdue follow-ups through the existing alerts (a later, small change).

## 14. Implementation record (filled in as built)

*(see the final section of this document after the build)*
