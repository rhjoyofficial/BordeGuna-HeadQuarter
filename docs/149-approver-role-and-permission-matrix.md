# 149. The approver role and the approval permission matrix (1 Nov 2026)

Architecture ruling (ChatGPT, 1 Nov 2026, binding): two managers may act as second approvers without the full-access
`operator` role (`operator` = `*`). This document records the least-privilege `approver` role, how each approval
category in the code is decided, and what an approver may and may not be given.

Code: `RolesAndPermissionsSeeder::ROLES['approver']`, `App\Support\Authorization\ApproverRole`,
`Approval::decisionRefusalReason()`, `hq:user:create --role=approver --business=<slug>`.
Tests: `tests/Feature/Security/ApproverRoleTest.php`.

## 1. What the role holds

Exactly three permissions, asserted by a structural test:

| Permission | Why |
|---|---|
| `hq.dashboard.view` | the landing page after sign-in (business-scoped counts only) |
| `hq.approvals.view` | the Approval Center list and one approval with its payload, reason, evidence, preview and cost |
| `hq.approvals.decide` | approve (optionally edited) or reject with a reason |

Not held, and never bundled: user or role admin (`hq.users.manage`), credentials, flags, tools (view, manage, toggle),
autonomy (view, grant, revoke), connectors, policies, agents, audit log, customers, consent of any kind, delegation of
approvals, inbox, playbooks, content, ingestion, follow-ups, quarantine and identity work.

## 2. The rules an approver decides under

These apply to a person holding the `approver` role, on top of every rule that applies to anybody. Nothing here
applies to owner, operator or the other roles, so every existing two-person and independent-approval rule is unchanged.

1. **Individual accounts only.** One person, one account, created with `hq:user:create`; never shared.
2. **Two-factor authentication is mandatory.** Every route already requires confirmed 2FA and a verified challenge in
   the session (`2fa.enabled`, `2fa.verified`). The decision itself also refuses an approver without confirmed 2FA, so a
   caller that skips the routes meets the same rule.
3. **Business scope is required.** An approver must have `user_business_scopes` rows (no rows would mean group-wide).
   The command refuses to create an approver without `--business`. An approver with no rows decides nothing. An item of
   another business is a 404 on the screens (existing `visibleTo` rule) and a refusal at the decision; an item with no
   business is not an approver's.
4. **Never their own proposal.** An approver never decides what they asked for, what an agent asked under their
   authority, a Do plan they proposed or started, a watch they proposed, a draft they wrote, a playbook version they
   drafted or a follow-up they proposed. The single-reviewer bootstrap exceptions (content, playbooks, follow-ups) are
   not available to an approver.
5. **Category by person.** An Approval Center item is decided only by an approver who also holds that tool's own domain
   permission (the deployed descriptor's `permission`). The category permission is granted to the named person
   (`givePermissionTo`), never to the role. Outside the Approval Center, the category's own permission is required as
   before (for example `hq.content.review`).
6. **Never-assignable categories.** `ApproverRole::NEVER_ASSIGNABLE` lists domain permissions an approver never decides
   under, even when granted by mistake: user, credential, flag, tool, policy, agent and autonomy-grant authority,
   connectors, consent grant and consent lift, quarantine repair, identity-conflict resolution, outcome attribution,
   media budget and resuming AI.
7. **Audit.** Every decision is audited by the existing paths with actor, decision, reason and timestamp
   (`approval.approved`, `approval.rejected`, `content.calendar.reviewed`, `event_watch.enabled`, and so on), and an
   Approval Center decision also writes a `decision_records` row.

## 3. The matrix

Columns: the permission that makes a person eligible; the business scope of the item; the evidence a decider needs to
see; whether 2FA is required (it is everywhere, by route); the self-approval restriction; whether the decision is itself
the second approval (a different person from the one who asked). "Approver" says whether the category may be assigned
to an approver person.

| Category (code) | Eligible permission | Business scope | Evidence the decider sees | 2FA | Self-approval restriction | Second approval required | Approver |
|---|---|---|---|---|---|---|---|
| Approval Center, any tool (`/approvals/{id}/approve, /reject`, `ApprovalExecutor`) | `hq.approvals.decide` + the tool's own permission at execution | the approval's business (`Approval::visibleTo`, 404 outside) | tool title and purpose, stored payload (hash checked), reason, evidence, preview, estimated cost, who asked and who enabled | yes | separation of duties (agent under another person's authority); tools with `requesterMayDecide: false` bar the requester; approver: never own | yes for `requesterMayDecide: false` tools and Do steps; otherwise the owner may decide own | yes, per tool permission |
| Do plan step (`DoPlanStep.approval_id`, flag `console.do_mode`, dark) | as Approval Center | plan's business | the step's own payload and summary | yes | starter, and the person an agent proposed under, never decide; approver: also never a plan they proposed | always | yes (tool permission) |
| Customer reply send (`conversation.send_reply`, `conversation.send_whatsapp_reply`) | `hq.approvals.decide` + `hq.inbox.manage` | conversation's business | draft text, thread context | yes | approver: never own | no (single human approval) | possible; assign `hq.inbox.manage` only on the owner's word, as it also opens the inbox |
| Order write (`commerce.take_order`, `commerce.cancel_sales_order`) | `hq.approvals.decide` + `hq.tools.commerce.write` | order's business | order lines, totals, customer reference | yes | requester never decides (`requesterMayDecide: false`) | always | yes |
| ERP purchase bill draft (`erp.create_draft_bill`, from `/ingestion/{uuid}/send-to-erp`) | `hq.approvals.decide` + `hq.tools.erp.draft_bill` | document's business | the reviewed bill fields, deterministic checks | yes | requester never decides | always; the ERP keeps its own approval too | yes |
| Ingested document review (`/ingestion/{uuid}/decide`) | `hq.ingestion.review` | document's business (404 outside) | every review field confirmed one by one, checks passing | yes | no human proposer (mail poll); approver: business and category enforced | no | yes |
| Owner Telegram brief (`briefing.send_owner_telegram`) | `hq.approvals.decide` + `hq.briefing.deliver` | the brief's business | the composed brief | yes | approver: never own | no | no (the owner's own channel; keep with the owner) |
| Avatar generation (`avatar.generate`) | `hq.approvals.decide` + `hq.avatar.generate` | the business of the consent | consent record, script | yes | requester never decides | always | yes, on the owner's word |
| Demonstration (`demo.request_approval`) | `hq.approvals.decide` + `hq.tools.diagnostics` | as placed | payload | yes | approver: never own | no | test only |
| Consent lift (`consent.lift_restriction`) | `hq.approvals.decide` + `hq.consent.lift_restriction` | customer's business | the restriction and the request | yes | requester never decides (two holders) | always | **never** (consent-grant authority) |
| Quarantine repair (`customers.quarantine.repair`) | `hq.customers.quarantine.repair` | group-wide review | the reviewed batch | yes | as built (human-only tool) | per docs/126 | **never** |
| Identity conflict resolve (`customers.identity_conflicts.resolve`) | `hq.customers.identity_conflicts.resolve` | conflict's businesses | evidence of both identities | yes | as built | per docs/140 | **never** |
| Outcome attribution (`outcomes.attribute`) | `hq.outcomes.attribute` | outcome's business | the outcome and the claim | yes | as built | no | **never** |
| Action watch enable / reject (`/watches/{uuid}/enable, /reject`, flag `console.event_watches`) | `hq.watches.enable` | watch's business (404 outside) | conditions and the proposed action | yes | proposer never enables an action watch; approver: never any watch they proposed | yes for an action watch | yes |
| Content draft review (`/content/calendar/{entry}/review`) | `hq.content.review` | draft's business | the draft text, assets, claims checks, testimonial digest when edited | yes | the writer only as sole eligible reviewer, with a reason; an approver holding the permission in that business counts as a second eligible reviewer; approver: never own | yes whenever a second eligible reviewer exists | yes |
| Media draft decide (`/media-drafts/{uuid}/decide`) | `hq.tools.media.draft` | draft's business | the rendered video | yes | none today | no | no: the permission also requests drafts and spends media budget |
| Playbook review (`/playbooks/{version}/review`) | `hq.playbooks.review` | version's business | the version and its digest | yes | drafter only under the single-reviewer bootstrap; approver: never own | yes once a second reviewer exists | yes |
| Playbook activate / retire | `hq.playbooks.activate` | version's business | reviewed version | yes | as built | the review is the second look | **no** (puts guidance in front of an agent: policy authority) |
| Follow-up / opportunity decide (`/follow-ups/...`) | `hq.followups.decide` | row's business | the proposal | yes | proposer only under the single-decider bootstrap, recorded; approver: never own | yes once a second decider exists | yes |
| Inbox agent proposal decide (`/inbox/{c}/proposals/{run}/decide`) | `hq.inbox.manage` | conversation's business | the agent's proposal | yes | none | no | only with the inbox, on the owner's word |
| Scheduled job approve (`/scheduler/{job}/approve`) | `hq.jobs.manage` | job's business | the job definition | yes | as built | no | **no** (the permission also creates, runs and cancels jobs) |
| Skill approve / activate (`/skills/{skill}/{action}`) | `hq.skills.activate` | skill's business | the skill definition | yes | no self-approval (docs/145) | yes | **no** (changes what agents may do) |
| Autonomy grants and thresholds (`/autonomy/...`) | `hq.autonomy.grant` + group-wide | group-wide | evidence ladder | yes | as built | as built | **never** |
| Media budget (`/connectors/media-budget/...`) | `hq.media.budget.manage` + owner role | business | budget and billed cost | yes | owner only | no | **never** |
| Quarantine flag review (`/customers/quarantine/{q}/review`) | `hq.customers.quarantine.review` | group-wide list | the flagged relationship | yes | as built | no | **no** (unrestricted customer data) |

## 4. Creating an approver

```
php artisan hq:user:create manager-a@example.test --name="Manager A" --role=approver --business=laladia --generate-password
```

`--business` is repeatable and required for this role. Then grant only the categories that person decides, for example:

```
php artisan tinker --execute="App\Models\User::where('email','manager-a@example.test')->sole()->givePermissionTo(['hq.tools.erp.draft_bill','hq.content.review']);"
```

The person sets up 2FA at first sign-in; nothing is decided before that. The role is seeded by
`php artisan db:seed --class=RolesAndPermissionsSeeder --force` (additive and idempotent).
