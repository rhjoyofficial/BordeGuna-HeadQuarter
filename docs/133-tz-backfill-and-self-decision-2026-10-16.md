# 133. Time-zone backfill and follow-up self-decision, 16 October 2026

Under the binding ruling `docs/131-chatgpt-ruling-2026-10-16.txt` section 2 items 4 and 8, Stage B (backfill dry runs write nothing) and Stage C (backfill commands, read-only against mutating). Branch `platform/tz-backfill-and-self-decision` from main `3e3d81a`, worktree `hq-wt/f`, test database `hq_test_f`. No production access; nothing was applied anywhere. Not merged.

## Plan (committed first, `1bc5375`)

1. **Item 8, `hq:facts:tz-backfill`.** A dry run by default, evidence only, ambiguous rows listed and never changed, a gated apply.
2. **Item 4 condition.** The item 22 playbook independence rule, reused for follow-up approval.
3. **Rehearsal and checklist.** The dry run as a no-write step; the mutating line labelled Joy-authorized.
4. **This record.**

## Item 8: the time-zone backfill

Think of each stored order timestamp as a copy of a note the shop sent. The shop's note is still in the `events` inbox, word for word, because the payload is kept as jsonb. Before the W5-TZ fix (`5466127`, docs/125) a note written as `23:30 +06:00` was copied as `23:30` and filed as UTC, six hours late; a note written as `...Z` was copied right. The backfill reads the note again and asks one question per timestamp: does the stored copy equal the note's wrong reading, and nothing else? Only then is it corrected.

- **Where the evidence is.** `facts_orders` keeps no payload. The evidence is the processed inbound `events` rows of the same store (`payload._store`) and order (`order_number`), read per column exactly as `ShopOrderEventHandler` and `FactsOrderUpserter` read them: `placed_at` (or `created_at`), `confirmed_at`, `delivered_at`, `cancelled_at` from `order.created` and `order.updated`; `at` or `changed_at` from `order.status_changed` (to confirmed, delivered or cancelled) and `shipment.status_updated`; `delivered_at` or the event time from `shipment.delivered`. A payload already compacted by retention (365 days) is not evidence.
- **Columns examined.** `placed_at`, `confirmed_at`, `delivered_at`, `cancelled_at` of every `facts_orders` row.
- **The test, to the second.** For each source string with an explicit offset (`Z`, `+06:00`, `+0600`, `+06`), two values: the instant it names (what the fixed code stores) and its wall clock read as UTC (what the old code stored).
- **Correctable.** The stored value equals the wrong reading of a non-zero-offset string, equals no correct reading, matches no offsetless string, and every such string agrees on one corrected instant.
- **Already correct.** The stored value equals a correct reading. Not listed, not touched.
- **Ambiguous, listed with a reason, never changed.** `no_source_evidence` (no event for the order: reconciliation, an imported row); `erp_read_back_digest_only` (an ERP read-back row: HQ keeps only the digest of what the ERP said, so `ordered_at` cannot be re-read); `source_string_has_no_offset`; `no_source_string_explains_the_stored_value` (for example a status event without a time, which used the processing time); `conflicting_source_strings`.
- **Dry-run output.** The totals line, the ambiguous count per reason, the batch key, the corrections grouped by business and the Dhaka day of the stored value (rows, timestamps, per column, how many move to another day), delivered sales per business and Dhaka day before and after (computed by `FactsOrdersDeliveredSales` itself for "before", adjusted by the moving rows for "after"), each correctable timestamp (row id, business, column, source event id, raw string, stored UTC, corrected UTC, both Dhaka days, the shift in seconds), and each ambiguous timestamp. `--json` prints the same plan. No name, phone, address or e-mail is printed: ids, slugs, columns, event ids and timestamp strings only.
- **Apply.** `--apply --key=<batch key> --by=<email> --reason="<why>"`, all three required (reason at least ten characters, user must exist and be enabled). It takes the advisory lock `133000133`, locks `facts_orders` against writers for the short transaction, recomputes the plan and refuses unless its key equals the reviewed key. Each correctable timestamp is updated only while it still holds the reviewed old value (exactly one row, else everything rolls back). Each change goes into `facts_tz_corrections` with old and new value, source event and raw string, batch key, person and reason. One `facts.tz_backfill.applied` audit row names the batch. Before commit it re-verifies: every corrected column holds its new value, the log has one row per change, and a fresh plan finds nothing left to correct.
- **Idempotent.** A second apply with the same key finds nothing correctable and the key already logged: `already_applied`, nothing changes.
- **Migration.** `2026_10_16_100000_create_facts_tz_corrections_table` (additive; PostgreSQL triggers refuse UPDATE and DELETE; `old_value <> new_value`; unique per batch, row and column; down refuses once a correction exists).
- **Tests** (`tests/Feature/Facts/TzBackfillTest.php`, 6): the dry run, text and JSON, leaves the row hashes of `facts_orders`, `facts_tz_corrections`, `audit_logs`, `events` and `customers` identical; a `+06:00` delivery written the old way is corrected by exactly six hours, with the log row and the audit row, and the log refuses an update; the `Z` row, the offsetless row (ambiguous), the row without evidence (ambiguous) and the ERP row (ambiguous) are untouched; a second apply changes nothing; apply without `--key`, `--by` or `--reason`, with a wrong key, an unknown person or a short reason is refused and writes nothing; a delivery at 19:00 Dhaka on 20 Sep stored as 21 Sep 01:00 moves from 21 to 20 Sep in the before and after report (20 Sep 0 to 1 order, 21 Sep 2 to 1), and the contribution source counts it on 20 Sep after the apply; conflicting strings are ambiguous.

### The production dry run

On the VPS, after the release that carries this command, read-only (PostgreSQL refuses any write of that session):

```
cd /opt/bordeguna-hq
docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T -e PGOPTIONS=-cdefault_transaction_read_only=on app php artisan hq:facts:tz-backfill --no-ansi
```

What the output means:

- `correctable N`: N timestamps the evidence proves were stored six hours late (or by whatever non-zero offset the shop sent). Each is listed with the shop's own string. These are the only ones an apply would touch.
- `already correct by evidence N`: timestamps whose stored value is exactly what the shop's string names. Expected to be most of them, because the shops send `Z` for orders.
- `ambiguous N` with reasons: timestamps nobody can prove either way from what HQ holds. They stay as they are. Expect `erp_read_back_digest_only` for every ERP read-back `placed_at`, and `no_source_string_explains_the_stored_value` for reconciled rows and status events without a time.
- The by-day block and the before and after block say which Dhaka days of the contribution report would change, and by how many delivered orders and how much net revenue. If both blocks say none, the backfill would change nothing the report shows.
- `Dry run: nothing written.` and, only when something is correctable, the exact apply line with the batch key. Running that line is Joy's separate decision (post-release checklist section 8), with a backup first.

## Item 4: follow-up self-decision

- **Built.** `FollowUpProposalService::approveFollowUp` refuses when the deciding person proposed the follow-up and another eligible decider exists: enabled, holds `hq.followups.decide`, and may act on the follow-up's business (`BusinessScope::allows`). The message names the count and "Decider independence". Through the screen it is a form error.
- **The bootstrap rule.** When the proposer is the only eligible decider, approval is allowed, the existing `self_decided` flag goes on the `follow_up.approved` audit row and the existing "decided by its proposer" label (Bangla already in `bn.json`) shows on the screen, and a `follow_up.self_decision_bootstrap` audit row records the rule text and the count of other eligible deciders (0). The moment a second eligible decider exists, the same call is refused.
- **Not refused.** Declining one's own proposal, as sending a playbook back is never refused under item 22. Opportunity open/decline, completion and cancellation keep the docs/123 behaviour (allowed by role, recorded `self_decided`); the ruling names follow-up proposals.
- **Tests** (`tests/Feature/FollowUps/FollowUpSelfDecisionTest.php`, 3): sole decider allowed with `self_decided`, the bootstrap audit row and the label, a disabled holder and a holder scoped to another business not counting; refusal with a second eligible decider, nothing written, form error on the screen, the other person approves without `self_decided`; a decider without business access does not count and the same person given access does. The docs/123 item 11 test now approves its own proposal before the operator exists.
- **No new UI text.** The refusal is a server message in English, like the item 22 refusal.

## Rehearsal and checklist

- `deploy/rehearsal/local-sql-rehearsal.sh`: the tz dry run runs between the before and after row hashes of the dry runs step, only when `hq:facts:tz-backfill` is listed (otherwise a WARN), and again in the read-only session.
- `deploy/rehearsal/rehearse.sh`: the same, with its own row hashes (`sql/row-hashes.sql`) before and after the tz dry run; PASS only when identical.
- `deploy/checklists/post-release-readonly.md`: the dry run in section 5 (read-only session) with what to expect; section 8 carries the apply line in a block labelled JOY-AUTHORIZED MUTATION, not a checklist step.

## Findings, not changed here

- `ShopWebhookController` still stores `events.occurred_at` from `CarbonImmutable::parse($body['occurred_at'])` without `->utc()`. If a shop sends `occurred_at` with `+06:00`, the event time and so `facts_orders.last_event_at` are six hours late. The envelope's `occurred_at` is not kept in the payload, so there is no evidence to repair existing rows, and fixing the code alone would make a correct new event look older than a late stored one. Needs its own decision.
- `IdentityResolver` passes the raw `placed_at` string into `customers.first_seen_at` and `last_seen_at`; a `+06:00` string lands six hours late there too. Orders from the shops send `Z`, so the effect should be limited to ERP read-backs.

## Gate and commits

- Pest parallel 2152 passed (32943 assertions), Pint, `bash -n` on every rehearsal and tool script, the rehearsal guard suite (48 passed), `structural-searches.sh` all PASS. No TypeScript touched.
- `1bc5375` plan; `86b99b3` item 4; `b011e69` item 8 command, planner, applier, migration, tests; `80a448c` rehearsal and checklist; this record.
