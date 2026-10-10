# 114 - Consent: identity scope and two-person lift of a do-not-contact, S7 follow-ups (10 October 2026)

Status: PLAN, then built in labelled checkpoints on branch `platform/s7-identity-scope-and-two-person-lift` (worktree `hq-wt/h`, from `main` `a8a6410`). Not merged, not deployed. Production posture unchanged: no outbound provider, no flag switched, nothing activated.

Authority: ChatGPT ruling of 10 Oct afternoon, `docs/110-chatgpt-ruling-2026-10-10.txt` §4, items (h), (i), (j), (k); summarised in `docs/101` §14. S7 as built: `docs/108` §11.

## 1. What the ruling asks, item by item

| Item | Ruling text (short) | What this stream does |
|---|---|---|
| (h) | No automatic reopen: a new inbound never lifts a business-wide withdrawal or DNC | Already the behaviour (any denial wins over the inbound grant). One explicit test added for the withdrawal case; the DNC case is already pinned. |
| (i) | Identity-specific scope beside customer, channel, purpose, brand. A restriction on one identity is never defeated by a grant on another. Unresolved identity fails closed. No cross-business expansion. | `identity_id` on the ledger, the projection and the resolution rule; resolved from the thread row at both chokepoints; recordable per identity or customer-wide on the screen. |
| (j) | support_agent = view + restrict, never grant | Confirmed by a role-matrix test, extended to "never lift_restriction". |
| (k) | Two-person control to lift a DNC: evidence, reason, distinct permission `hq.consent.lift_restriction`, a second independent approver; fail closed without one; an ordinary grant is insufficient | New permission; the DNC lift becomes a Tool Gateway tool whose policy always requires approval and whose requester never decides it, so the existing Approval Centre is the second person. |

## 2. Identity scope (i): the model

An identity is one row of `customer_identities` (a phone, a Messenger PSID, an IG id, an email), already the thing a thread is linked through (`conversation_participants.identity_id`).

Scope becomes **business x brand x channel x purpose x identity**, each of the last four either specific or "all" (null on the event). The resolution rule in `ConsentLedger::permittedAt()` gains one dimension and keeps its shape:

1. **Applicable events** (same customer and business, at or before the instant): brand/channel/purpose as before (docs/108 §3.1), and identity either null on the event (= every identity of the customer) or equal to the request's identity.
2. **Unresolved identity** (the request names none): an identity-scoped **denial still applies** (fail closed), an identity-scoped **grant does not**. Identical to the brand rule ChatGPT approved in docs/108 §3.1, so the two dimensions read alike.
3. **Latest per scope**, then **any denial wins**: unchanged. Consequences the ruling names:
   - a restriction on identity A is never defeated by a grant on identity B: B's grant is not an applicable event for a send to A at all, and A's restriction is;
   - a customer-wide restriction (identity null) covers every identity: it is applicable to every request;
   - an identity-scoped grant covers only that identity: it is applicable only when the request names it;
   - no cross-business expansion: an identity must belong to the customer (`customer_identities.customer_id`), checked when an act is recorded and when a thread's identity is resolved; a mismatch is treated as unresolved.

**Where the identity comes from** (never a typed id): `App\Consent\ThreadIdentity::resolve(Conversation)` reads the thread's customer participant (`identity_id`) and, failing that, the latest inbound message's `metadata.sender_external_id` against `customer_identities` on the thread channel's identity channel. Both results must belong to the thread's customer, else null. Used by:
- `ConsentGate::check()` for `conversation:` subjects (gateway step 5b);
- `OutboundPolicy::refusal()`.
An `args.customer_id` subject (no thread) carries no identity: the fail-closed reading above applies.

**Schema (additive):** one migration adds `consent_events.identity_id` (nullable FK to `customer_identities`, RESTRICT like every FK on that table) and `consent_state.identity_id` (nullable FK, cascade: derived), and widens the projection's unique index to `coalesce(identity_id, 0)`. No existing row changes. Down: delete identity-scoped projection rows (rebuildable), restore the index, drop both columns. Apply, roll back, re-apply on PostgreSQL before the checkpoint commit.

**Recording:** `ConsentOperations::record()` accepts `identity_id` for every act; asserts the identity belongs to the customer; when both an identity and a channel are named, the channel's identity channel must be the identity's channel (a WhatsApp opt-in is not recorded against a Messenger PSID). The screen gets an identity selector ("whole customer" or one identity) on the act form, an identity column in position and history, and a per-identity row in the "what a send would get now" grid.

## 3. Two-person lift (k): built on the approval machinery

**Decision: the existing approval machinery is the right fit, and the lift is a Tool Gateway tool.** The approvals table already keeps requester, approver, payload hash, single use and the separation-of-duties rule (`Approval::separationOfDutiesReason`, absolute when the descriptor says `requesterMayDecide: false`), and `ApprovalExecutor` re-runs the approved call through the gateway under the APPROVER's authority, so the approver's permission is checked by the same step that checked the requester's. Nothing new is invented.

- **Permission** `hq.consent.lift_restriction`, additive in `RolesAndPermissionsSeeder`; owner/operator hold it through `*`; support_agent's list is untouched (view + restrict).
- **Tool** `consent.lift_restriction` (`App\Tools\Consent\LiftRestrictionTool`): R3 internal write, `permanentlyHumanOnly: true`, `requesterMayDecide: false`, permission `hq.consent.lift_restriction`, business-scoped (`scopeFrom: business:args.business_slug`), previewable. Arguments: `business_slug`, `customer_id`, optional `identity_id`, `brand_id`, `channel`, required `evidence_ref` and `reason`. Its policy class `LiftRestrictionPolicy` always answers `requireApproval` (a tool policy may only tighten; R3's baseline is allow), so **every call parks** and nothing executes until a human decides.
- **Who asks**: the consent screen's "Lift a restriction" act, when the restriction standing at the chosen scope is a DNC, runs the gateway tool as the signed-in person (`ToolRequest::forOperator`), exactly as the inbox runs `messaging.send_reply`. The screen requires `hq.consent.lift_restriction` for that act; the gateway requires it again. The outcome is `awaiting_approval`; the screen shows the pending lift and the DNC stays in force.
- **Who decides**: a second person, in the Approval Centre, holding `hq.approvals.decide`. The requester is barred by `requesterMayDecide: false` (owner included). The approver must also hold `hq.consent.lift_restriction`: the executor rebuilds the call under the approver's identity and the gateway's permission step refuses otherwise (the approval is spent and the DNC stays; nothing is lifted).
- **What executes**: `LiftRestrictionTool::execute()` re-reads the standing restriction at the exact scope (it may have changed while the approval waited: fail closed if nothing stands or it is no longer a DNC), then calls `ConsentOperations::liftDoNotContact()`, which writes the `lifted` event with basis `operator`, actor = the approver, evidence naming the approval uuid, the requester and the approver, and audits `consent.restriction_lifted`. That method is reachable from the tool only (pinned in `GatewayCoverageStructureTest`'s services list).
- **No single-person door**: `ConsentOperations::record(ACT_LIFT)` refuses when the standing restriction is a DNC. Lifting a plain withdrawal keeps today's single-person rule under `hq.consent.grant` (the ruling text says nothing else).
- **An ordinary grant never lifts a DNC**: already true (any denial wins); pinned by an explicit test.

Effectful-tool count: the structural pin in `GatewayCoverageStructureTest` grows from nine to ten and `docs/105` §1.2 gets the tenth row, in the same change, as that test demands.

## 4. Enforcement points (to be filled with file:line as built)

- `ConsentLedger::permittedAt()`: identity dimension of the resolution rule.
- `ConsentGate::check()`: thread identity via `ThreadIdentity`, handed to the ledger; gateway step 5b unchanged in position.
- `OutboundPolicy::refusal()`: the same identity, the same answer.
- `ConsentOperations::record()`: DNC lift refused on the direct path; `liftDoNotContact()` the only writer of a DNC lift.
- `LiftRestrictionTool` + `LiftRestrictionPolicy`: always parks; executes only under an approval, under the approver.
- `CustomerConsentController::store()`: DNC lift routed through the gateway, behind `hq.consent.lift_restriction`.

## 5. Tests

- (i) `ConsentLedgerTest`: identity A restricted + identity B granted → A refused, B permitted; customer-wide DNC covers both; identity grant covers only its identity; unresolved identity: identity denial applies, identity grant does not; identity of another customer refused on record; projection per identity. `ConsentEnforcementTest`: a thread on identity A is refused at the gateway (and by `OutboundPolicy`) after a DNC on A, while the same customer's thread on identity B passes consent. `ConsentScreenTest`: an act recorded for one identity carries it; a foreign identity is refused.
- (k) `ConsentLiftTwoPersonTest`: direct lift of a DNC refused; request parks and the DNC still refuses sends; the requester cannot approve; an approver without `hq.consent.lift_restriction` cannot lift; a second holder approves → `lifted` event names both people, sends pass consent again; evidence and reason required; an ordinary grant does not lift; a plain withdrawal still lifts single-person; the structural pin.
- (j) role matrix: support_agent has view + restrict, lacks grant and lift_restriction; owner/operator hold lift_restriction; support_agent is refused the lift tool at the gateway and the DNC lift on the screen.
- (h) withdrawal of every purpose, then a new inbound → service still refused.

## 6. Non-goals

No outbound activation, no provider, no flag changes, nothing live (ruling §6). No change to `consent_events`' append-only triggers. No new approval mechanism.

## 7. Questions for a ruling

1. Unresolved identity: this stream reads "fails closed" as the brand rule (identity denials apply, identity grants do not, customer-wide events apply). Should a send whose identity cannot be resolved be refused outright instead (`consent_identity_unresolved`)? Today that would refuse every thread without a linked identity row, which includes threads opened before identity linking.
2. Lifting a plain withdrawal stays single-person (`hq.consent.grant`). Confirm, or extend two-person control to any lift.
3. An approver who holds `hq.approvals.decide` but not `hq.consent.lift_restriction` spends the approval (gateway refusal at execution) rather than being refused before the claim. Acceptable, or should the approval machinery check the tool's permission before claiming for every tool?

## 8. As built

(filled in at the final checkpoint: commits, enforcement file:line, tests, pint/tsc/vite, release notes)
