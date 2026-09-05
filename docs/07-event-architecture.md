# 07 - Event Architecture

## Purpose

Events are how HQ learns that something happened in a system of record or a platform, and how one HQ subsystem triggers another without coupling. They are **facts about the past**, never commands.

## Canonical event catalogue (v1)

| Event | Producer | Carries | Triggers |
|---|---|---|---|
| `order.created` | shop webhook, Woo webhook, ERP (later) | business, order ref, customer identities, lines, totals, source, campaign hints | identity resolution, attribution, briefing facts, follow-up scheduling |
| `order.status_changed` | shop webhook (to add) | order ref, from, to, actor | notification decisions, cancellation analytics |
| `payment.completed` | shop webhook (to add) | order ref, method, amount | attribution, finance facts |
| `shipment.delivered` | shop webhook (to add), courier sync | order ref, courier, delivered_at | review request workflow, LTV update |
| `inventory.low` | shop (to add), ERP stock poll | sku, variant, qty, threshold | stock-protection policy, purchase recommendation |
| `customer.message_received` | Meta webhook (Messenger, Instagram), WhatsApp, web chat | channel, external ids, text, attachments | conversation engine |
| `facebook.comment_received` | Meta webhook | post id, comment id, text, author | comment agent |
| `campaign.performance_changed` | marketing monitor job | campaign, metric, before, after, window | marketing automation |
| `content.approved` / `content.published` | HQ | content item, platform, post id | scheduler, analytics collection |
| `human.handoff_requested` / `human.resolved` / `ai.resumed` | conversation engine | conversation, reason | notifications, SLA timers |
| `scheduled_job.due` | scheduler tick | job id, scheduled_for | job runner |
| `approval.requested` / `approval.decided` | approval engine | approval id, decision | notifications, continuation of the paused tool call |
| `ai.run_completed` / `tool.call_failed` | AI runtime | run id, cost, error | observability, alerting |

Naming: `noun.past_tense_verb`, lower snake case; `schema_version` lives in the payload, not the name.

## Mechanics

```
  producer  --HTTP webhook-->  /webhooks/{provider}  --verify signature-->  events (inbox row, dedupe_key)
  internal  --Laravel event-->  EventRecorder            ------------------->  events (outbox row)
                                                                                    |
                                                        DispatchEvents (queued)  <---+
                                                                |
                                            subscriptions: handlers run as queued jobs (Horizon)
```

- **Postgres inbox/outbox first, Redis second.** The `events` row is written in the same transaction as any state change, so nothing is lost if Redis is down; Horizon fans the row out. Redis Streams only if throughput ever demands it.
- **Dedupe**: `dedupe_key` = provider event id, or a hash of type + source + business + external ref + occurred_at. Duplicates are acknowledged and ignored.
- **Ordering** is not guaranteed across types; handlers must tolerate `shipment.delivered` arriving before `order.created` (park and retry).
- **Replay**: any event can be re-dispatched from the admin UI; handlers are idempotent by contract.
- **Retention**: raw payloads 180 days, then compacted to type and refs.

## Producers that need work

| Producer | Today | Change |
|---|---|---|
| Shops | 3 outbound events, zero subscribers | add 4 events (doc 06 section 4), subscribe HQ |
| Woo | webhooks supported natively | create subscriptions (needs admin access) |
| Meta | Messenger webhook goes to n8n | HQ becomes the receiver; n8n gets a forwarded copy during transition |
| ERP | no events | `inventory.low` and finance facts come from HQ polling the new ERP API; true ERP events are a later additive change |
| Pathao | callback URL owned by bizmation | decision pending (doc 31); the shop's 15-minute sync is the fallback |
