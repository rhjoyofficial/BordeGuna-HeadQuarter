# 21 - Scheduling System (first-class, mandatory)

## Requirements met

cron-style recurring, one-time, daily/weekly/monthly, interval, event-triggered, delayed, recurring workflows, timezone-aware, retry, failure recovery, execution history, cancel, modify, pause/resume, natural-language entry.

## Design

```
 scheduled_jobs  (durable definition, Postgres)
      ^  natural language -> JobSpec (structured) -> validation -> approval state -> row
      |
 schedule:work tick (every minute, one process, Redis lock)
      -> SELECT due jobs (next_run_at <= now, state=active, approval_state=approved) FOR UPDATE SKIP LOCKED
      -> insert job_runs(scheduled_for) [unique on (job_id, scheduled_for) => exactly-once per slot]
      -> dispatch RunScheduledJob to Horizon (queue by priority)
      -> compute next_run_at in the job's timezone (cron expression via a tested library) or mark done for one-time
 RunScheduledJob -> ToolGateway.execute(job.tool_key, payload) under the job's owner + agent identity -> policy engine applies
      -> job_runs.status, output, error; retry per retry_policy (exponential backoff, max attempts, dead-letter to inbox)
```

- Linux cron is used for nothing business-critical. The only host-level timers are Docker restarts and infrastructure maintenance.
- `schedule:work` itself is the existing pattern from the ERP (its own container); the queue is Horizon on Redis, not the database driver.
- Event-triggered jobs are `event_subscriptions` rows whose handler is "run scheduled job X", so the same runner, history and retry apply.
- Missed slots (downtime) are handled by policy per job: `catch_up` (run once for the missed slot), `skip`, or `run_latest_only`. Default `skip` for publishing, `catch_up` for reports.

## Job record

`scheduled_jobs(id, business_id, brand_id, name, kind, cron_expression, run_at, interval_seconds, timezone (default Asia/Dhaka), payload jsonb, tool_key, agent_id, owner_user_id, approval_state pending|approved|rejected, retry_policy jsonb, missed_policy, state active|paused|cancelled|done, next_run_at, last_run_at, last_status, created_from nl|ui|skill|api, nl_source_text)`.

A scheduled content task's payload carries exactly: brand, platform, asset ids, caption, hashtags, target account, scheduled time, timezone, approval state, required credential ids, workflow state, retry policy; its execution log is `job_runs` plus the `publications` row.

## Natural language to job

```
"Post this image to LaLaDia Facebook tomorrow at 5 PM."
 -> low-tier model with a strict JSON schema: {kind: once, run_at: 2026-09-06T17:00 Asia/Dhaka, tool: publish_content,
    payload: {brand: laladia, platform: facebook, asset: <attached>, caption: <text>}}
 -> resolver fills ids (brand, page account, asset) from context; anything unresolved becomes a question back to the user
 -> preview card: "Will publish <asset> to La La Dia (Facebook) on Sat 6 Sep 2026 17:00 Dhaka. Approve?"
 -> row created with approval_state per the tool's action class
```
Recurring: "Every morning at 9 AM send me the business briefing" -> `cron 0 9 * * *`, tz Asia/Dhaka, tool `run_skill:daily_briefing`, delivery Telegram. Relative and Bangla phrasing are supported by the same schema; ambiguity always produces a confirmation, never a guess.

## UI

Jobs list (filters: business, kind, state, next run), run history with output and error, pause/resume/cancel/edit, "run now", calendar view for content. Every change is audited.

## Reliability

Exactly-once per slot by the unique run row; at-least-once execution with idempotent tools; dead-letter after max attempts with a notification; a watchdog job every 10 minutes checks that the tick ran in the last 3 minutes and alerts if not (the ERP verifies its scheduler the same way, by looking for rows it should have produced).
