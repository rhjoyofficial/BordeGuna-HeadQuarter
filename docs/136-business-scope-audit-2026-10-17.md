# 136. Business scope audit of every screen, 17 October 2026

Branch `fix/business-scope-approvals-and-jobs` from main `c0db11e`, worktree `hq-wt/j`, test database `hq_test_j`. Nothing here touches production; Joy alone releases.

## Why

- Found during W-UI2 (`docs/135` (k) after the integration, (i) on its own branch `ui/w-ui2-console`): the approvals list and the scheduler list were not narrowed by business for a person whose access is limited to some businesses, while the dashboard counts already were.
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

- W-UI2 (`ui/w-ui2-console`, `docs/135` (k) after the integration) has the console show "their businesses' jobs and the group-level ones" to a restricted person. The rule found here hides group-level rows from a restricted person; when that branch is rebased, its jobs block should use `ScheduledJob::visibleTo()` and `JobRun::visibleTo()` so the console and the scheduler agree.
- Separate finding, not business scope and not changed here: before this branch the dashboard sent the last ten audit rows (with before/after) to every dashboard viewer, including roles without `hq.audit.view` such as `support_agent`. This branch hides them from a restricted person only; gating them by `hq.audit.view` for everyone is a one-line follow-up that needs Joy's yes, because it changes what an unrestricted role sees.

## Decisions, decided 17 Oct (lead), applied on `integration/ui-and-scope`

- Item 1, the console follows this rule (decided 17 Oct). `ConsoleReader` (W-UI2, on `/dashboard`) reads approvals and decided approvals through `Approval::visibleTo()`, jobs through `ScheduledJob::visibleTo()` and failed runs through `JobRun::visibleTo()`, so a restricted person sees no group-level job, run or approval there, exactly as on the scheduler and approvals screens. Its open alerts block is `null` for a restricted person, as the alerts screen refuses them. `BusinessScopeAuditStructureTest` now also lists every class under `app/Observe/Console` (`ConsoleReader.php`: `scoped`) and the new `Voice/VoiceModeController.php` (`no business rows`). Test: `ConsoleTest` "shows a restricted person no group-level row".
- Item 2, recent audit rows need `hq.audit.view` (decided 17 Oct). The dashboard's last ten audit rows go only to a group-wide person who holds `hq.audit.view`; `support_agent` (and any role without it) gets an empty list, restricted or not. `finance_viewer` and the owner keep them. The 24 hour audit count is unchanged (a count, no content). The console shows no audit rows. Test: `BusinessScopeScreensTest` "sends the recent audit rows only to a group-wide person who holds hq.audit.view".
- Item 3, a sentence that names no business is refused for a restricted person (decided 17 Oct). `NlScheduling::preview()` answers with one clear message ("HQ will not make this job: it names no business, so it would run for the whole group, and your access is limited to ... Say which of your businesses the job is for."), records `scheduler.nl_refused` with reason `group_level_for_restricted`, and issues no token; `confirm()` checks again, so a restriction added after a preview still stops a group-level job. The sentence screen's business picker lists only the person's businesses. An unrestricted person is unchanged. Test: `NlSchedulingTest` "refuses a restricted person a job that names no business".
- Item 4, flags and the tool switch: unchanged, for a ChatGPT ruling (decided 17 Oct). Today `hq.flags.manage` (`POST /flags`, `POST /flags/{flag}/toggle`) and `hq.tools.manage` (`POST /tools/{key}/toggle`) are held only by owner and operator through `*`; a business restriction does not stop a restricted operator from switching a flag or a tool, and either switch acts on every business. Question for the ruling: should a restricted person be refused these group-wide switches (403, like the audit, alerts and platform screens), keep them, or need a second, unrestricted person?

## Ruling 13, global switches: plan (docs/138 section 4 item 13 and section 3 "Business-scope fix")

Ruling 13 word for word: "Require explicit group-wide authority for any switch that affects all businesses, including `hq.flags.manage` and `hq.tools.manage` when they operate globally. Enforce this at the backend as well as in the UI. A restricted business operator cannot change group-wide posture, tool availability, AI activation or global kill switches. Ambiguous scope means refusal. Record the actor, scope, before/after state and reason."

1. One guard, `App\Support\Authorization\GroupWideAuthority`: a person may throw a switch that acts on every business only when they exist, are enabled, hold the switch's permission and have no business restriction (`BusinessScope::isRestricted()` false, the existing "no rows means group-wide" notion). No person, or a scope that cannot be read, is refused (ambiguous scope means refusal). A refusal is an `AuthorizationException`, so HTTP answers 403 with the reason.
2. Routes: a `group.wide:<permission>` middleware on `POST /flags`, `POST /flags/{flag}/toggle` and `POST /tools/{key}/toggle`, and the same guard inside the controllers and the services they call (`ToolSwitch`), so a second caller of the service cannot skip it.
3. Console commands that act for a named person: `hq:tools:toggle --as`, `hq:ai:provider --by`, `hq:shadow:open --by`, `hq:shadow:close --by`, `hq:autonomy-policy:activate|retire --by`, `hq:language-policy:activate --by`. Each refuses a restricted person through the services they call (`ToolSwitch`, `ProviderSelection`, `ShadowPeriods`, `EvidencePolicies`, `LanguagePolicies`). A terminal run with no person named keeps today's behaviour (the terminal kill switch, off-production shadow), recorded as the system.
4. Audit: every global switch row carries actor, `context.scope = group`, the permission, before, after and reason. Flags gain a required reason (create and toggle); tool disable already requires one; tool enable gains a required reason on the screen.
5. UI: the flags and tools pages get `switch` props (`allowed`, `refusal`); a restricted operator sees no switch button, only the reason, in English and Bangla.
6. Tests: restricted operator with the permission gets 403 on each route and no control in the props; owner still can; audit row carries actor, scope, before, after, reason; console commands refuse a restricted `--by`; a structural test pins the global-switch routes so a new one without the guard fails.

## Ruling 13 implemented (branch `ui/global-switch-scope`, from `integration/ui-and-scope`)

Item 4 above is decided by docs/138 ruling 13: a restricted person is refused every switch that acts on every business, with 403 on the web and a refusal on the terminal.

- The guard: `App\Support\Authorization\GroupWideAuthority`. Authority means a named person, an enabled account, the switch's permission and no business restriction (`BusinessScope::isRestricted()` false). Nobody named is refused, and a scope or permission read that throws is refused (fail closed). A refusal is an `AuthorizationException` carrying the reason.
- Web routes, each with the new `group.wide:<permission>` middleware after `permission:`: `POST /flags` and `POST /flags/{flag}/toggle` (`hq.flags.manage`), `POST /tools/{key}/toggle` (`hq.tools.manage`).
- Controllers check again: `AdminController::storeFlag`, `AdminController::toggleFlag`, `ToolRegistryController::toggle` each call `GroupWideAuthority::assert()` first, so losing the middleware still refuses.
- Services check again, whoever calls them: `ToolSwitch::disable|enable` (`hq.tools.manage`), `ProviderSelection` (`hq.flags.manage`, the `ai.runtime` authority, since the serving provider is AI activation), `ShadowPeriods::assertOpener|close` (`hq.agents.manage`; a shadow switches `ai.runtime` and the agent's status), `EvidencePolicies` activate and retire, `LanguagePolicies` activate and retire (`hq.policies.manage`, which they already required).
- Console commands that act for a named person refuse a restricted one through those services: `hq:tools:toggle --as`, `hq:ai:provider --by`, `hq:shadow:open --by`, `hq:shadow:close --by`, `hq:autonomy-policy:activate|retire --by`, `hq:language-policy:activate --by`. A terminal run of `hq:tools:toggle` with nobody named stays allowed (the kill switch for when the application is broken) and is audited as the system with `authority: system`. Inside a web request nobody named is refused.
- Reasons: a flag create and a flag toggle now require a reason (422 without one); turning a tool back on now requires a reason, on the screen and on the terminal (`--reason`), as turning one off already did.
- Audit: every global switch row (`feature_flag.saved`, `feature_flag.toggled`, `tool.disabled`, `tool.enabled`, `ai.provider_selected`, the shadow period rows, the autonomy and language policy activated and retired rows) carries the actor, `context.scope = group`, `context.permission`, `context.authority`, before, after and the reason. `feature_flag.saved` now records the row as it was before (null for a new flag). No migration: `audit_logs.context` already existed.
- UI: `/flags` and `/tools` receive `switch: {allowed, refusal}` from the server. The switch buttons and the new flag button show only when the person holds the permission and `allowed` is true; a restricted operator sees a "Group-wide switch" notice with the reason instead. Flag toggles and tool re-enables now go through a confirm dialog with a required reason. New strings are in `bn.json` with Bangla.
- Credentials, unchanged and already enforced by docs/136: a restricted person cannot store a credential that names no business (group-level) and cannot see or revoke one (404).
- No other global switch exists yet: there is no agent pause/resume or autonomy route and no Pause All AI switch (docs/138 P3-W4 builds it; it must be added to the pinned list when it lands).
- Behaviour change in an existing test: `ShadowPeriodTest` opened a production shadow under a LaLaDia-only person. Under ruling 13 that person is refused; the test now proves the refusal and opens the period under an unrestricted person, whose probes run with no business restriction.
- Tests: `tests/Feature/Security/GlobalSwitchScopeTest.php` (403 for a restricted operator holding the permission on each route with nothing changed and no audit row; no control in the page props; owner still switches flags and tools; audit rows carry actor, scope, before, after, reason; services and console refuse a restricted person; nobody named, disabled and no permission are no authority; a structural pin of the global switch routes, their middleware, the controller check and the service check, so a new write route under `hq.flags.manage` or `hq.tools.manage`, or a guarded route not in the list, fails). Updated: `ToolRegistryScreenTest`, `ProviderSelectionTest`, `ShadowPeriodTest`.
