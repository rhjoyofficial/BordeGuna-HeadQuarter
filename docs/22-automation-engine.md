# 22 - Automation Engine (Workflows)

## Why a workflow engine and not only jobs

A job runs one tool once. Automations are sequences with waits, branches, approvals and compensation: "monitor -> detect -> diagnose -> recommend -> approve -> change -> monitor -> evaluate". They need checkpoints so a restart, a rejected approval or a failed step does not lose position.

## Model

`workflow_definitions` (versioned, code-backed classes) and `workflow_runs(id, key, version, business_id, state running|waiting_approval|waiting_event|waiting_timer|completed|failed|cancelled, context jsonb, current_step, started_by, started_at, finished_at)` with `workflow_steps(run_id, name, status, input, output, attempt, started_at, finished_at)`.

Definitions are PHP classes (typed, tested) using a small step API: `step('name', fn)`, `waitForApproval(...)`, `waitForEvent('shipment.delivered', match)`, `sleepUntil(...)`, `compensate(fn)`. Each step runs as a Horizon job; the run persists after every step. This is the saga pattern on top of the queue, not a separate engine.

## First workflows

| Key | Trigger | Steps |
|---|---|---|
| `messenger_conversation` | `customer.message_received` | resolve identity -> agent turn -> tool actions -> reply -> update state; escalation branch |
| `order_lifecycle_followup` | `order.created` | wait for `shipment.delivered` (or 7 days) -> consent check -> review request -> wait 30 days -> reorder nudge (approval for first-time customers) |
| `campaign_guardian` | hourly timer per live experiment | sync -> anomaly -> diagnosis -> recommendation -> policy -> apply or approval -> evaluate after window -> record decision |
| `content_publish` | `content.approved` | adapt per platform -> schedule -> publish -> collect metrics at +1h/+24h/+7d -> learning note |
| `stock_protection` | `inventory.low` | pause product ads (policy) -> purchase recommendation -> notify -> resume ads when `inventory.restocked` |
| `daily_briefing` | 09:00 Asia/Dhaka | collect from ERP/shops/ads/conversations -> executive agent -> Telegram + in-app |
| `skill_proposal` | weekly | mine ai_runs -> propose skills -> owner review |

## Replacing old automations (n8n, Hermes, shop schedulers)

Nothing is migrated automatically. Each replacement follows: old workflow -> **shadow mode** (HQ receives the same events and writes what it would do to `shadow_actions`) -> **compare** results for at least a week -> **cut over** (repoint the trigger) -> **deactivate** the old workflow (never delete) -> **observe** for a month -> **delete only after explicit approval** from Joy. n8n stays for the Bionic Messenger bot and content stages until then. Hermes's health-check cron is replaced by HQ's own monitoring before Hermes is retired; Hermes as Joy's personal Telegram brain is his call.

## Failure handling

Step retry with backoff (idempotent tools), then run `failed` with the step named; a compensation step where money or messages were involved (cancel a draft order, delete a half-published post); dead-letter inbox in the UI; alerts to Telegram. Cancelling a run executes compensations for completed steps.
