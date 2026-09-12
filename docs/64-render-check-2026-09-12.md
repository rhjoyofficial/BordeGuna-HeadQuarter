# 64 - The live render check after the operator UI pass (12 September 2026)

**Status: fixes on PR #19 (`ui/render-check-fixes`); merge and deploy authorised by Joy on 12 Sep via the
ChatGPT thread's instruction, with the account-deletion removal (section D) added first. Final CI, merge
commit and release are recorded in section G. Production is `1eac822` (the
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

Filled in after the deploy. See the end of this file.
