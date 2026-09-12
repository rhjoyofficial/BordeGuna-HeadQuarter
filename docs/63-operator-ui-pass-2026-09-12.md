# 63 - The operator UI pass (12 September 2026)

**Status: in progress on branch `ui/operator-friendly-pass`.** Joy asked, resuming HQ on 12 Sep,
to "focus on the UI part, make it more user friendly". This document is the plan that was written
before the first component was touched, and the record of what each slice did.

## A. What is wrong with the screens today, in one paragraph

Every HQ screen is a faithful rendering of its database row. Tool keys such as `erp.poll_overview`
are shown in monospace where a person expects "ERP overview poll"; a scheduled job's cadence is
shown as `0 9 * * *`; an approval's payload is a JSON block; every decision goes through the
browser's own `window.prompt` and `window.confirm`, which look like an error and cannot be styled;
the navigation is eleven equal items with no grouping and no sign of what is waiting; the dashboard
is eleven counters and an audit table, and it does not say what Joy should do next. None of that is
wrong for an engineer who built the thing. All of it is wrong for the person it was built for.

## B. The rules this pass follows

1. **Nothing changes what a screen DOES.** Same routes, same controller actions, same permissions,
   same props (props are only added, never renamed). The Inertia tests that name components and
   props stay green unchanged.
2. **Every control is as wide as its content** (Joy, 30 Aug and 3 Sep, on the ERP). A date box is
   date-wide, a number box is `w-36`, only a textarea is full width. The ERP's `Field` and `FormRow`
   helpers are ported so that the rule is a component, not a memory.
3. **Plain language first, the key second.** A tool shows its title from the registry, with the key
   underneath in small type. A cron expression is shown as "Daily at 09:00 Dhaka", with the
   expression underneath. JSON is never the first thing on a card; it sits behind "Technical detail".
4. **No browser dialogs.** `window.prompt`, `window.confirm` and `window.alert` are replaced by one
   `ConfirmDialog` component that carries the title, the consequence, and an optional required
   reason.
5. **Status is a badge, not a coloured word.** One `StatusBadge` with six tones, used everywhere, so
   "pending" looks the same on approvals, runs and jobs.
6. **The navigation says what is waiting.** Grouped into Work / Operations / Control / Observe, with
   counts on Approvals (pending) and Agents (shadow runs to read). Counts are computed inside the
   same visibility scope the screens use, because a count is a disclosure.
7. **The dashboard answers "what needs me".** Attention items first, then health, then the
   businesses, then the counters.
8. **No em dashes in UI copy** (standing rule from the ERP, `NoEmDashTest`).

## C. The slices, each its own commit

| slice | what | files |
| --- | --- | --- |
| A | Shared foundation: `format.ts` (relative time, schedule words, key words), `StatusBadge`, `PageHeader`, `EmptyState`, `ConfirmDialog`, `Field`/`FormRow`, Input width defaults | `resources/js/lib`, `resources/js/components` |
| B | Grouped navigation with badges; shared `nav` prop from the Inertia middleware | `app-sidebar.tsx`, `nav-main.tsx`, `HandleInertiaRequests.php` |
| C | Dashboard as "Today": attention list, health strip, businesses, counters | `dashboard.tsx`, `AdminController::dashboard`, `DashboardTest` |
| D | Approvals index and detail: plain-language cards, dialogs, technical detail folded | `approvals/*.tsx` |
| E | Agents index and detail: badges, review form, less monospace | `agents/*.tsx` |
| F | Scheduler index and detail: "New job" in a dialog, schedule in words, badges, run history readable | `scheduler/*.tsx` |
| G | Tools, feature flags, credentials, audit log, businesses: forms in dialogs, badges, consistent headers | remaining pages |

## D. Verification path

There is no PostgreSQL and no Docker on the Windows box, so Pest runs on GitHub Actions. Locally,
every slice runs `npx tsc --noEmit`, `npx eslint`, `npx prettier --check` and `npx vite build`
before it is committed. The browser render check that the ERP practice calls for is done after the
merge is deployed, which is a separate decision Joy makes; nothing in this pass is deployed by it.

## E. Record of what each slice did

Filled in as each slice lands.
