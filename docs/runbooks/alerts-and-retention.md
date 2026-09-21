# Alerts, dashboards and retention

What HQ watches every five minutes, where to look, and how old records are summarised
(`docs/78` C.2, `docs/48` N.3 and N.5, decision V.7).

## Alerts

HQ checks fifteen conditions every five minutes (`hq:alerts:check`, scheduled). An alert opens the
first time a condition is seen, counts every later sighting, and clears by itself once the condition
has been gone for two checks in a row. Nothing is acknowledged by hand; fix the cause and the alert
clears.

Where to look: **Observe, Alerts** in HQ (the badge counts the alerts HQ itself sends).

| Alert | What to do |
| --- | --- |
| Scheduler has not ticked | `docker compose logs --tail 100 scheduler`; restart the scheduler container |
| Horizon down or paused | `docker compose logs --tail 100 horizon`; `php artisan horizon:continue` if paused |
| Model provider failing | [provider-blocked](provider-blocked.md); HQ retries by itself after two minutes |
| AI spend at 80 or 100 percent | Nothing breaks: at 100 percent HQ uses a cheaper tier. Raise the cap in `ai_budgets` or accept it |
| Approvals past their deadline | HQ, Approvals. They never approve themselves |
| An agent repeatedly refused | Read the agent's recent runs on HQ, Agents |
| Order-fact discrepancy | `php artisan hq:facts:check`; decide which side is right. HQ reports, never repairs |
| Events could not be processed | Read `last_error` on the event; `php artisan hq:events:replay --event=<id>` (dry run first) |
| Credential expiring or expired | Store a replacement on HQ, Credentials; storing replaces |
| ERP snapshot not refreshed for 26 hours | The scheduler run for `erp.poll_overview` or `erp.poll_low_stock`; `php artisan hq:erp:probe` |
| A capability cannot be used | [readiness](readiness.md); a credit block is about credit, never the key |
| Shadow period stopped | [shadow-week](shadow-week.md); nothing restarts on its own |
| Disk or memory | The host monitor texts these already; `docker system df`, `docker stats --no-stream` |
| HQ scheduler failing (host monitor, `hq_scheduler`, "no tick recorded") | Nothing scheduled is running, this alert rule included. `docker stats --no-stream --format '{{.PIDs}} {{.Name}}' \| grep hq-scheduler`: thousands of processes is the zombie leak of docs/81 C.8 (the service must have `init: true`); `docker compose ... logs --tail 100 scheduler`; `docker compose ... restart scheduler` switches nothing on |
| Webhook signature failures | A changed secret on the shop or Meta side, or someone probing; nothing was accepted |

Alerts marked "paged by" on the page (the host monitor, the model runtime, the approvals sweep) are
sent by those components already, so HQ records them without a second message. Backups are watched by
the host monitor alone; the application cannot see the backup directory.

To run the checks now, or one rule only:

```
php artisan hq:alerts:check
php artisan hq:alerts:check --rule=readiness.incident
```

## Dashboards

**Observe** in the sidebar: AI health, Cost, Control plane, Platform. Each reads the last day, week or
month and changes nothing. They open for the roles that already hold the matching permission.

## Retention

Detail records of tool calls, model calls and connector calls older than 180 days are summarised into
one row per day and removed; event contents older than 365 days are reduced to a fingerprint (processed
events only). The audit trail, decision records, agent runs, approvals, grants, evaluations, shadow
periods, consent records, conversations and memories are never touched, and neither is any detail row
one of them points at. Nothing is old enough before March 2027.

It runs by itself every night at 08:15 Dhaka, after the backup. To see what it would do, which changes
nothing:

```
php artisan hq:retention:plan
```

To run a pass by hand (safe to repeat; a second pass finds nothing twice):

```
php artisan hq:retention:run
```

Every pass is a `retention_runs` row and an audit row (`retention.completed` or `retention.failed`);
every summary row in `daily_aggregates` names the pass that wrote it and the id range it stands for.
A pass that reaches a limit says which (`max_rows`, `max_days`, `max_seconds`) and the next night
continues. An event whose contents were reduced cannot be replayed, and the replay command says so.
