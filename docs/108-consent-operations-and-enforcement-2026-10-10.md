# 108 - Consent operations and runtime enforcement, stream S7 (10 October 2026)

Status: PLAN, then BUILT on branch `platform/s7-consent-enforcement` (worktree `hq-wt/h`, from `main` `c12792f`). Not merged, not deployed. Production posture unchanged: no outbound provider, no flag switched, nothing activated.
Authority: `docs/103-chatgpt-ruling-2026-10-10.txt` §9 (S7: "APPROVED. Prioritize the enforcement semantics", "purpose- and scope-specific", "enforced at runtime before communication; a UI-only status is insufficient", "does not authorize outbound activation") and §11 (non-goals); summary `docs/101` §13; gap named in `docs/95` §5 (Consent bullet: "only inbound service consent (24 h) is ever written; no UI/command for withdrawal, do-not-contact, marketing opt-in, operator-recorded consent").

## 1. What exists today (read before designing)

- `consent_events`: append-only ledger (DB triggers refuse UPDATE/DELETE), columns customer, business, brand, channel (null = all), purpose (null = all), state (`granted|withdrawn|expired|do_not_contact`), basis (`explicit|checkout|inbound|contract|imported|operator`), occurred/recorded/expires, evidence, actor, reason, correlation, tool_call_id.
- `consent_state`: derived projection, unique on (customer, business, channel, purpose). **Ignores brand.**
- `ConsentLedger::permittedAt()`: replays the ledger to an instant; latest event per scope (channel x purpose, 4 scopes); **any denial wins**; silence is never consent.
- `ConsentGate` (gateway step 5b, `ToolGateway::authorizeSteps`): reads the descriptor's `ConsentRequirement`, resolves the subject from args or the thread, asks the ledger. Refusal = `OutcomeClass::ConsentDenied` with a free-text reason.
- `OutboundPolicy` asks the ledger for Service consent on the thread's channel before any reply.
- The only writer is inbound ingestion (Service, channel-scoped, 24 h, basis inbound, brand of the receiving account).

## 2. Gaps this stream closes

1. **Brand is recorded but never enforced.** A LaLaDia grant would permit a Bionic send for the same business. Scope must be business x brand x channel x purpose.
2. **Marketing is not opt-in specific.** A wildcard grant (purpose null) or a grant whose basis is `inbound` would satisfy Marketing. A customer's message must never become permission to advertise.
3. **No refusal class.** Every consent refusal is one class with prose; nobody can count "refused because do-not-contact" vs "never opted in".
4. **No dedicated audit of a refused send** beyond the generic gateway denial row.
5. **No way to record** withdrawal, do-not-contact, marketing opt-in, operator-recorded consent, or to lift a restriction; no screen to see a customer's consent history.

## 3. Enforcement semantics (the rule, stated once)

A send is asked as (customer, business, brand|unknown, channel, purpose) at an instant.

1. **Applicable events**: same customer and business, `occurred_at <= instant`, and each of brand/channel/purpose either null on the event (= all) or equal to the request. A brand-scoped event when the request's brand is unknown: a **denial applies** (fail closed), a **grant does not**.
2. **Latest per scope**: up to 8 scopes (brand x channel x purpose, each specific or `*`); within one scope the latest event wins (so a re-opt-in at the same scope replaces a withdrawal, and `lifted` neutralises a restriction at that scope).
3. **Order of decision**, first match wins:
   - any `do_not_contact` -> refuse `consent_do_not_contact` (beats every grant, every purpose, every basis, a human operator included);
   - any `withdrawn` -> refuse `consent_withdrawn`;
   - any `expired` state, or the only grant has passed `expires_at` -> refuse `consent_expired`;
   - a **qualifying grant** -> permit, naming the event.
   - otherwise -> refuse `consent_opt_in_missing` for an opt-in purpose, `consent_not_recorded` for service.
4. **Qualifying grant**: for **Service**, any live grant whose purpose is service or null. For an **opt-in purpose** (Marketing, Research, Recording, SyntheticIdentity: `ConsentPurpose::requiresOptIn()`), only a grant **of exactly that purpose** (never a wildcard) whose basis can evidence an opt-in (`explicit`, `checkout`, `operator`, `imported`; never `inbound` or `contract`: `ConsentBasis::evidencesOptIn()`). The ledger also refuses to *record* an opt-in-purpose grant on a basis that cannot evidence it (defence in depth).
5. **Purpose specificity, the consequence the ruling asks for**: a marketing withdrawal leaves **service** replies allowed (there is no purpose-scoped DNC: DNC is person-level, optionally narrowed by brand or channel); a withdrawal with purpose null (all purposes) stops service too; DNC stops everything in its scope.
6. `lifted` (new state): neutral at its exact scope; it removes a DNC or withdrawal recorded at that same scope and grants nothing. Lifting needs evidence and a reason and the widening permission (section 5).

Conservative choices kept for a ruling (section 8): a new inbound message does **not** reopen service after a business-wide service withdrawal or DNC (any denial wins over the inbound grant).

## 4. Runtime enforcement points

- `ConsentLedger::permittedAt(..., ?int $brandId)`: the single resolution rule above.
- `ConsentGate::check()`: resolves subject **and brand** (from the thread row for `conversation:` subjects, else the request's brand), returns a `ConsentDecision` carrying a `ConsentRefusal` class.
- `ToolGateway::authorizeSteps` step 5b: refusal reason becomes `consent refused: [<class>] <reason>`; on the executing path (not the read-only `authorize()`), an additional audit row `consent.refused` with tool, subject, brand, channel, purpose, class, deciding event id and tool_call_id, attributed to the acting person or agent.
- `OutboundPolicy::refusal()`: passes the thread's brand, so the inbox reply path and the gateway answer identically.

Refusal classes (`App\Consent\ConsentRefusal`): `consent_do_not_contact`, `consent_withdrawn`, `consent_expired`, `consent_opt_in_missing`, `consent_not_recorded`, `consent_subject_unresolved`. The gateway's outcome class stays `consent_denied` (side: control plane) so every existing reader keeps working; the consent class is the finer reason.

## 5. Recording (operations) and permissions

`App\Consent\ConsentOperations`, the only writer for operator acts, all basis `operator`, actor = the signed-in person, evidence reference and reason **required**, `occurred_at` optional and never in the future:

| Act | State | Scope rules |
|---|---|---|
| Withdraw consent | withdrawn | purpose one or all; channel and brand optional |
| Do not contact | do_not_contact | purpose always all; channel and brand optional (default: whole business) |
| Marketing opt-in | granted, purpose marketing | channel required; brand required when the business has brands |
| Operator-recorded consent | granted, purpose service | channel required; brand optional; optional expiry |
| Lift a restriction | lifted | only where the latest event at that exact scope is a withdrawal or DNC |

Every act writes the ledger row (append-only) and the existing `consent.recorded` audit row as the acting person; an additional `consent.operator_recorded` audit row names the act.

Permissions (additive, `RolesAndPermissionsSeeder`): `hq.consent.view`; `hq.consent.restrict` (withdraw, DNC: protective, safe to give support staff); `hq.consent.grant` (opt-in, operator-recorded consent, lift: widening). owner/operator get all (`*`); support_agent gets view + restrict. Production needs `db:seed --class=RolesAndPermissionsSeeder` at release (additive, never sync).

## 6. Schema (additive)

One migration: `consent_state.brand_id` (nullable FK, restrict) and the projection's unique index widened to include `coalesce(brand_id, 0)`; up backfills brand from `last_event_id`. Down deletes brand-scoped projection rows (derived; rebuildable), restores the old index, drops the column. `consent_events` is untouched (no CHECK constraint on `state`, so `lifted` needs no DDL).

## 7. Screens

`/customers/{customer}/consent` (Inertia `customers/consent`), behind `auth + 2fa.enabled + 2fa.verified` like every page, `permission:hq.consent.view`, and business scope (`BusinessScope::allows`). Shows:
- **What a send would get now**: the same `ConsentLedger::permits` answer the gateway uses, per brand x purpose (service, marketing) x channel, with the refusal class;
- current position (projection rows, brand-aware) with a Lift action where a restriction stands;
- full history newest first (state, purpose, channel, brand, basis, actor, evidence, reason, occurred vs recorded);
- one form for the five acts; POST `customers/{customer}/consent` gated per act by `hq.consent.restrict` or `hq.consent.grant`.
Bangla through `t()` and `i18n/bn.json`; English digits. Linked from the customer page.

## 8. Tests (gateway level first)

Through `ToolGateway::execute()` with the real `messaging.send_reply` and a test marketing tool: send after withdrawal refused (class, audit row, tool never executed); marketing without opt-in refused even with a live inbound service grant; marketing with an operator opt-in passes consent; marketing withdrawal leaves service allowed; all-purpose withdrawal stops service; DNC beats a later opt-in, a later inbound grant and a human operator; brand isolation (LaLaDia opt-in does not permit Bionic); lift restores; the A0 `authorize()` path refuses identically and writes nothing. Ledger units for the resolution rule; HTTP tests for the screen (permission, 2FA, business scope, per-act permission, audit as acting person, validation).

## 9. Non-goals

No outbound activation, no provider registration, no flag changes, no change to what is live, no cross-brand merging (ruling §11).

## 10. Questions for a ruling

1. Should a customer's own new inbound message reopen **service** replies after a business-wide service withdrawal (today: no, any denial wins)? DNC certainly not.
2. Identity-level scope (one phone of a customer with two) is expressed by channel today; is a `customer_identity_id` scope needed?
3. Role grants: support_agent gets view + restrict, not grant; confirm.
4. Lifting a DNC: is the operator's evidence + `hq.consent.grant` enough, or should a lift need a second approver?
