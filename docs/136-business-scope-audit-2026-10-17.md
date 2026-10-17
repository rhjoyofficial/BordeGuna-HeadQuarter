# 136. Business scope audit of every screen, 17 October 2026

Branch `fix/business-scope-approvals-and-jobs` from main `c0db11e`, worktree `hq-wt/j`, test database `hq_test_j`. Nothing here touches production; Joy alone releases.

## Why

- Found during W-UI2 (`docs/135` (i), branch `ui/w-ui2-console`): the approvals list and the scheduler list were not narrowed by business for a person whose access is limited to some businesses, while the dashboard counts already were.
- Rulings `docs/120` A4 and `docs/131` §1: every query enforces business scope independently. A corrected reader elsewhere is not enough.

## The rule applied (found, not invented)

- `BusinessScope` (`app/Support/Authorization/BusinessScope.php`): no `user_business_scopes` rows means group-wide; rows mean "only these businesses". `restrict()` is the dashboard's mechanism.
- Rows with no business are hidden from a restricted person, fail closed. Sources: ADR-035 §4 ("a run with no business is cross-business by nature and is hidden from a restricted user; fail closed"), `docs/111` and `docs/125` item 34 (a record resolving to no business is counted for a group-wide viewer only), `BriefingRequest::restrict()` ("rows with no business drop out for a restricted caller"), and `restrict()` itself (`whereIn` drops NULL).
- An id from another business answers 404, as `AgentController`, `InboxController`, `CustomerController` and `FollowUpController` already do (learning that a row exists is a disclosure).

## Plan

1. Audit every controller under `app/Http/Controllers` and the readers under `app/Observe` that list or show business-owned rows; record the verdict per endpoint, before and after.
2. Fix every gap with `BusinessScope::restrict()` (through a `visibleTo` model scope where a model needs one, as `FollowUp` does) and a 404 on an out-of-scope id for show and every action.
3. Screens whose rows carry no business at all (audit log, alerts) are group-wide by nature: a restricted person is refused the screen (403 with the reason) and gets no count or row of them on the dashboard or the sidebar.
4. Tests per fixed endpoint: restricted person sees only their businesses; another business's id (and a group-level one) answers 404; an unrestricted owner still sees everything. A structural test listing every audited controller and its verdict, so a new controller fails until it is audited.
5. Record the verdicts below; note that production `b96a396` carries the same gaps.

## Verdicts per endpoint (before, then after)

### Approvals (`ApprovalController`)

- `GET /approvals` list and its `pending`/`overdue` counts: before, every business and the group-level rows; after, `Approval::visibleTo()` (`BusinessScope::restrict`). The sidebar badge was already scoped.
- `GET /approvals/{id}`: before, any id rendered; after, 404 for another business's or a group-level approval.
- `POST approve`, `reject`, `cancel`, `delegate`: before, no scope check (approve was re-checked later by the gateway at execution; reject, cancel and delegate were not); after, 404 first. A delegate who could not open the approval is refused with a form error.

### Scheduler (`ScheduledJobController`)

- `GET /scheduler` list: before, every job; after, `ScheduledJob::visibleTo()`.
- `GET /scheduler/{job}` and `approve`, `reject`, `pause`, `resume`, `cancel`, `run-now`: before, any id; after, 404 out of scope, nothing changes and no run is queued.
- `POST /scheduler/runs/{run}/retry`: before, any run; after, `JobRun::visibleTo()` (through its job, since `job_runs` has no business), 404 out of scope.
- `POST /scheduler` (create): before, any business or none; after, a restricted person must name one of their businesses (a job with none, or another's, is one they could not see afterwards), and a named brand must belong to it. Unrestricted: unchanged.
- `NlScheduleController`: already scoped through `EntityResolver` (a business or store outside the person's rows becomes a question). Residual: a restricted person who names no business still gets a group-level job they then cannot see; left for a ruling.

### Dashboard and sidebar (`AdminController::dashboard`, `NavBadges`)

- Before: approvals, agent runs and businesses were scoped; failed runs, active jobs, runs in 24 h, events, credentials, brands, stores, platform accounts and the 24 h audit count were group totals, and the last ten audit rows went to every dashboard viewer.
- After: every count of business-owned rows goes through `BusinessScope::restrict` (jobs, runs through their job, events, credentials, brands, stores, platform accounts through their brand). The audit count and rows are 0 and empty for a restricted person. Sidebar: the scheduler badge counts visible runs; the alerts badge is not computed for a restricted person.
- Left group-level on purpose: the connector health count (`connector_calls` has no business and no content), the scheduler heartbeat, agents and their levels, tools switched off. These are machinery, not business rows.

### Group-wide screens (no business column)

- Audit log (`GET /audit`), alerts (`GET /observe/alerts`), platform (`GET /observe/platform`): before, shown to any holder of the permission (`finance_viewer` holds `hq.audit.view`); after, 403 with the reason for a restricted person, unchanged for a group-wide one. Alert subjects name businesses (a stale snapshot names its business slug), and audit rows carry before/after of any business.

### Other screens fixed

- Tools (`ToolRegistryController::index`): the per-tool 24 h counts and the five recent calls were group-wide (`brand_manager` holds `hq.tools.view`); now `BusinessScope::restrict` on `tool_calls`.
- Credentials (`AdminController::credentials`, `storeCredential`, `revokeCredential`): before, every credential, every business and brand in the pickers, and every readiness row; after, `IntegrationCredential::visibleTo()`, pickers and readiness rows narrowed (the model provider row belongs to no business and is left out), a restricted person may store only for one of their businesses, and revoking another's is 404.
- Businesses (`AdminController::businesses`): before, every business; after, narrowed by id as the dashboard cards already were. Residual: a store's product owners still name the owning businesses.
- Posture (`PostureDashboard`): the consent event count and the dead event count are now narrowed. Residual: the open alert count stays a group-wide number (count only, no content).
- List tools (`hq.list_jobs`, `hq.list_approvals`): the lists were already narrowed by the gateway's permitted businesses, but `failing_count`, `pending_count` and `recent_failures` (job names and errors) were not; now they are. `recent_failures` also eager-loads its job (it lazy-loaded before, which strict mode refuses).

### Already scoped, verified, unchanged

- Agent runs (`AgentController` index, show, review): scoped, 404, group-level runs hidden (ADR-035).
- Tool calls and model calls on a run page: read through the run's correlation id after the run's own check.
- Inbox (`InboxController`, every action): scoped, 404.
- Customers, merge, dismiss (`CustomerController`): scoped, 404. Consent (`CustomerConsentController`): scoped, 403. Quarantine (`CustomerQuarantineController`): scoped, 404.
- Follow-ups and opportunities (`FollowUpController`): `visibleTo`, 404.
- Playbooks (`PlaybookController`): businesses narrowed, 403 by business on every version action.
- Briefings (`BriefingController`): scoped by business and by the briefing's own recorded restriction.
- Decision corpus (`DecisionCorpusController` through `DecisionCorpusReader`): scoped; a record with no business only for a group-wide viewer.
- Ask (`AskController`): conversations are the person's own; the business list is narrowed.
- AI health, cost and control plane dashboards: scoped through `BusinessScope`.
- Autonomy evidence: evaluation suites and grants, no business-owned rows (the corpus is synthetic).
- Flags, the tool switch, credential kinds: group configuration, not business rows. Residual for a ruling: a restricted operator could still toggle a flag or a tool, which acts on every business.
- Auth, 2FA, sessions, profile, password, health and the three inbound webhooks: no business rows for a person to read.

## Tests

- `tests/Feature/Security/BusinessScopeScreensTest.php` (12): approvals list and counts; 404 on show, approve, reject, cancel and delegate for another business and a group-level row; delegation to an outsider refused; scheduler list and badge; 404 on show, all six actions and the run retry; create only for one's own business and brand; dashboard counts, empty audit rows and no alerts badge for a restricted person, full numbers for the owner; audit, alerts and platform 403 for a restricted operator and a restricted `finance_viewer`, 200 for the owner and an unrestricted `finance_viewer`; businesses; credentials list, pickers, readiness, revoke 404, store refused; tools screen counts and recent calls; list tool counts and failures. All twelve fail on `c0db11e` and pass here.
- `tests/Feature/Security/BusinessScopeAuditStructureTest.php` (4): every controller under `app/Http/Controllers` and every class under `app/Observe/Dashboards` is listed with its verdict, so a new file fails until audited (proven on a planted file in a copied tree, never under `app/`); a `scoped` verdict must name `BusinessScope` or `visibleTo`, a `through:` verdict must point at a reader that does; the `visibleTo` scopes are pinned on the models.

## Production

- Production `b96a396` has the same gaps: its `ApprovalController` and `ScheduledJobController` name no business scope, and the dashboard, tools, credentials, businesses, audit, alerts and platform screens read as `c0db11e` did.
- Impact today: none in practice. HQ has a single operator with no `user_business_scopes` rows, so he is group-wide and every screen shows him what it showed before. The gaps open the moment the first restricted person is created, which is why they are closed before that.

## Notes for the other branches

- W-UI2 (`ui/w-ui2-console`, `docs/135` (i)) has the console show "their businesses' jobs and the group-level ones" to a restricted person. The rule found here hides group-level rows from a restricted person; when that branch is rebased, its jobs block should use `ScheduledJob::visibleTo()` and `JobRun::visibleTo()` so the console and the scheduler agree.
- Separate finding, not business scope and not changed here: before this branch the dashboard sent the last ten audit rows (with before/after) to every dashboard viewer, including roles without `hq.audit.view` such as `support_agent`. This branch hides them from a restricted person only; gating them by `hq.audit.view` for everyone is a one-line follow-up that needs Joy's yes, because it changes what an unrestricted role sees.
