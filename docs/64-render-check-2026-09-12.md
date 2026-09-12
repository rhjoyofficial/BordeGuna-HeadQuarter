# 64 - The live render check after the operator UI pass (12 September 2026)

**Status: DONE. PR #19 merged as `9f9037f` and RELEASED to production at 05:28 UTC on 12 Sep 2026 (previous
`1eac822`), backup-first, verified on the VPS and in the browser; AI posture unchanged and re-verified.
Section G has the evidence. Production is `1eac822` (the
operator UI pass, `docs/63`), deployed 04:20 UTC; the fixes below are NOT deployed.**

## A. Why this document exists

`docs/63` ended with a promise that could not be kept in the same session: the operator UI pass was
deployed, but nobody had looked at the result on production with a real login. The suite was green,
`tsc` was clean, the image built, and none of that says whether a screen renders for the person it
was built for ([[reference_green_suite_lies]] on the ERP side records exactly this gap). This is the
record of the walk, done at 04:40 UTC on 12 Sep with Joy signed in as the owner in Chrome.

## B. What was walked and what was seen

Every page, in the order of the sidebar, plus the two detail screens and the four settings screens.

| page | result |
| --- | --- |
| Today (`/dashboard`) | Renders. "3 things need your attention": 64 shadow runs to read, 6 events dead, 1 tool switched off. Health strip: scheduler running, AI runtime off, event inbox 6 dead, connectors 4 of 4 ok. Five business cards. |
| Approvals | Renders. Empty state "Nothing is waiting for a decision", six status tabs. |
| Agents | Renders. Analytics and Executive cards, both paused, A0 shadow only; the 64-run banner; the run table with outcome badges. |
| Run detail (`/agents/runs/1`) | Renders. Shadow banner, the question, what the agent said, in prose. |
| Scheduler | Renders. Six jobs, cadence in words with the cron beneath, state, next and last run. |
| Businesses | Renders. Five cards, brands and stores, the ERP business id, folded technical detail. |
| Customers | Renders. 21 customers, Bangla names correct, lifetime value and last seen. |
| Customer detail (`/customers/1`) | Renders. Contact card, three orders with status badges, nine known identities. |
| Tools | Renders. 14 of 15 enabled, risk tier badges, last-24-hour counts, the one switched-off tool with its reason. |
| Credentials | Renders. Nine rows, provider and owner, status, no expiry, revoke buttons. |
| Feature flags | Renders. `ai.runtime` off, `scheduler.gateway` on. |
| Audit log | Renders. 393 entries, filters by actor class. **Defect 2 seen here.** |
| Queues (Horizon) | **Bare `403 FORBIDDEN`. Defect 1.** |
| Settings > Profile | Renders. Name and the new e-mail. A starter-kit "Delete account" block sits at the bottom (section D). |
| Settings > Password | Renders. Three password boxes with the eye toggle. |
| Settings > Appearance | Renders. Light, Dark, System. |
| Settings > Sessions | Renders but outside the settings frame, raw user-agent, browser date format. **Defect 3.** |
| Security (two-factor) | Renders. Enabled since 6 Sep, regenerate and disable forms. |
| Login | Renders (seen 04:25 UTC). Chrome's autofill paints the boxes light blue in dark mode. **Defect 4.** |

No console errors on any page.

## C. The four defects and what was done

**1. The sidebar offered a page production refused.** `Queues (Horizon)` is shown to whoever holds
`hq.horizon.view`. Behind it, `HorizonServiceProvider::gate()` was the starter kit's empty list of
e-mail addresses, so outside `local` nobody could pass it, ever. This is the ERP's "route + screen +
nav link" rule ([[reference_unreachable_engine_actions]]) failing in the one place the ERP never had:
a vendor-supplied gate. The gate now checks `hq.horizon.view`, the same permission that shows the
link, so the two cannot disagree again. `HorizonAccessTest` holds it: owner 200, `support_agent` 403,
guest 403.

**2. The audit log called a login `user:1`.** The row for "Auth login" carried `user:1` as who,
while "Auth two factor verified" a minute later carried the e-mail. Cause: Laravel's `Login` event
fires before the session guard has set its user, so `Auditor::record`, which labels the actor from
`Auth::user()`, had nobody to ask and fell back to the id. `record` now takes an optional
`actorLabel`; the listener passes the e-mail for both login and logout. `AuthenticationTest` asserts
the login and logout rows carry `actor_type=human`, the id and the e-mail.

**3. Sessions was a stranger to Settings.** It rendered outside the settings frame, was absent from
the Profile / Password / Appearance sub-navigation, used the browser's own `toLocaleString()` where
every other screen uses `lib/format.ts`, and showed the raw user-agent string. It now sits under
Settings > Sessions in the same frame, reads "Chrome on Windows" with the raw string in the tooltip,
"3 days ago" with the Dhaka date beneath, and the form is titled for what it does: sign out every
other device.

**4. Autofill.** A single `input:-webkit-autofill` rule in the CSS base layer covers Chrome's fill
with the theme's background and pins the text colour. Cosmetic, but it was the first thing Joy saw.

Nothing else changed: no route, permission, validation or prop.

## D. The fifth change, decided after the walk: no self-service account deletion

**"Delete account" on Settings > Profile** was a starter-kit block. On a control plane with one
owner it would lock HQ out entirely. Removing it changes what a screen does, which `docs/63`'s rule
B1 forbids without a decision, so it was left for Joy. He took it to the ChatGPT thread, which
called it a control-plane safety policy rather than a UI cleanup, and relayed the instruction on
12 Sep: remove it at every layer, replace it with nothing, and hold it absent by test.

Done in `060d19d`: the DELETE route and its name, `ProfileController::destroy`, the `DeleteUser`
component and the block on the screen are gone. No console or API path existed (`hq:user:create`
and `hq:user:email` are the only user commands). `ProfileUpdateTest` now asserts there is no route
name, no action, that a DELETE with the correct password answers 405 and changes nothing, that the
component file does not exist and that the screen no longer mentions it.

Still Joy's: **Chrome's saved password** autofills the old address on the login page; he updates
the saved login once.

## E. The ChatGPT thread

Read on 12 Sep 04:50 UTC ([[reference_chatgpt_hq_design_thread]]). Its newest exchange is the
10 Sep "Safety gate decision": fix the Executive role boundary AND make evaluation role-scoped, never
tune-and-stop, keep production inert until a fresh gate passes. That is exactly what `docs/62`
implemented the same day. The thread contains **no UI discussion at all**; the operator UI pass
was Joy's own ask, made directly. Nothing in the thread is outstanding.

## F. CI

PR #19 run 34673991767: Pest **459 passed, 2365 assertions** (455 before this slice), Pint, TypeScript
strict, Vite, production image, deploy-safety suite all green. Pint and `tsc --noEmit` were also clean
locally; Pest runs only in CI because there is still no local Postgres.

## G. Merge, release and the checks after it

**Final CI** on the PR head `6efcdeb` (run 34675254672): Pest **458 passed, 2365 assertions** (459 minus
the two starter-kit deletion tests, plus the one guard that replaces them), Pint, TypeScript strict, Vite,
production image, deploy-safety suite. **Merge commit `9f9037f`** (PR #19 into `main`, 05:2x UTC).

**Deploy** (`./deploy/deploy.sh` on the VPS, 05:26:10 to 05:28:27 UTC):
- pre-deploy dump taken and verified BEFORE any checkout change: `hq-20260912-052611.dump`, 620,681
  bytes, 59 tables;
- predecessor read from the running image label: `1eac822`; released `9f9037f`;
- after: checkout = app label = horizon label = scheduler label = `9f9037f`; seven containers up, the
  five with health checks healthy; `/api/health` ok on application, database, redis, queue, scheduler;
  **0** error/exception/fatal lines in app, horizon, scheduler and web logs since the deploy.

**Browser, signed in as the owner, after the deploy**: the same 18 pages walked again, all render,
0 console errors. Horizon now opens (Laravel Horizon dashboard, six queues, status Active). Settings >
Profile carries no deletion block. Settings > Sessions sits in the settings frame and reads "Chrome on
Windows / this device / in a moment / 12 Sept 2026, 11:29". The audit login row from before the deploy
still reads `user:1`; rows written from now on carry the e-mail. The autofill fix is only visible
signed out; Joy sees it at his next login.

**AI posture, read from the production database after the deploy**: `ai.runtime` OFF ·
`scheduler.gateway` ON · agents `analytics` paused, `executive` paused, both A0 shadow only on the
screen · `autonomy_grants` 0 · `consent_events` 0 · `agent_runs` 66 · `evaluation_runs` 5, untouched.
No customer-facing or outbound capability, no new R4/R5 tool, no shadow period started. The safety
corpus and both gate results (`docs/61`, `docs/62`) were not modified.

**The one remaining blocker before A0 shadow activation** is unchanged from `docs/62`: the fresh
gate has not passed. Attempt 2 scored 24/27 with `must_not_call` 27/27; the genuine failure is the
self-grant paraphrase that was answered instead of refused, plus two `loop_bound` runs. Next
milestone: that Executive role-boundary correction, then a fresh gate, then and only then activation.
