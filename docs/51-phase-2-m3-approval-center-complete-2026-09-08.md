# 51 - Phase 2 M3: the Approval Center (8 September 2026)

**Status: built, tested, deployed, and proven on production against the live control plane.**

M3 of the Phase 2 Command Center (`docs/48`), following M1 (`docs/49`) and M2 (`docs/50`). M2 made
the gateway the only door and gave it a real policy engine. That engine could already return
`require_approval`, and until today that verdict simply refused the call. M3 makes it do what it
says: park the call, put a card in front of a human, and then execute **exactly** the action that
human agreed to, once, or not at all.

| | |
| --- | --- |
| Starting release | **`16919f6`** (M2) |
| Ending release | **`e25152f`** (PR #4, merged) |
| Rollback target | **`16919f6`** |
| CI | **237 passed / 1217 assertions**, from 204 / 1041 at M2 |
| Migrations | 1, additive, reversible |
| Shop or ERP code changed | **none** |
| Model provider connected | **none** |
| Tools enabled at R4 or above, in production, at the end of M3 | **none** |

---

## A. The one sentence M3 had to make true

> The payload is immutable. The authority is re-evaluated at execution time.

Those two halves pull in opposite directions on purpose. The **arguments** must not move between the
moment a person reads a card and the moment the action runs, or they approved one thing and
authorised another. The **permission** to run those arguments must be re-checked at execution, or an
approval becomes a stored bearer token that survives a revoked role, a switched-off tool or a freeze.

Everything in this milestone is one of those two halves.

---

## B. Why the guarantees are in the database, not in the code

An approval that is single use "because the button disables itself" is not single use. Two browser
tabs, a retried request and a queue redelivery all defeat it. So the invariants live in Postgres:

```sql
CREATE UNIQUE INDEX approvals_resulting_tool_call_unique
  ON approvals (resulting_tool_call_id) WHERE resulting_tool_call_id IS NOT NULL;

ALTER TABLE approvals ADD CONSTRAINT approvals_consumed_has_result
  CHECK ((status = 'consumed') = (consumed_at IS NOT NULL));
```

The first says one execution belongs to at most one approval. The second says a consumed approval
must name what it produced and an unconsumed one must not, so the two columns can never drift into a
state where the audit trail is ambiguous about whether something ran.

The claim itself is a **conditional update**, not a read-then-write: of any number of simultaneous
callers, exactly one gets a row back, and the rest are told somebody else decided first. No lock is
held across the tool execution, which is the part that can take a second.

This migration also closed a gap left open since Phase 0: `tool_calls.approval_id` had been an
unconstrained bigint pointing at a table that did not exist. It is now a real foreign key. Before
running it, production was checked for rows that would fail the constraint: **0 of 22 `tool_calls`
had a non-null `approval_id`**, so the constraint applied cleanly.

---

## C. The canonical payload encoder, and the defect it caught

The hash is only meaningful if the encoder that writes it and the encoder that verifies it agree
about everything, forever. There is one function, used by both.

Writing it naively produced the most instructive defect of the milestone. The first version encoded
values with plain string concatenation. A payload containing `250.0` was hashed at write time from a
PHP float. It was then stored in `jsonb`, and Postgres returned it as `250`. The verifier hashed the
integer, got a different digest, and concluded the row had been tampered with.

That is worse than an ordinary bug. A tamper check that accuses untouched rows trains people to
click past it, which is precisely the moment the real one stops working. The encoder now tags every
scalar with its kind and normalises numbers to a fixed precision before trimming:

```php
return match (true) {
    is_int($value), is_float($value) => 'n:'.self::number($value),
    is_bool($value) => 'b:'.($value ? '1' : '0'),
    is_string($value) => 's:'.$value,
    $value === null => 'z',
    default => 'o:'.gettype($value),
};
```

Eight properties are pinned by test, and they are the ones that matter in both directions:

| property | result |
| --- | --- |
| `250` and `250.0` | **same** hash, so it survives the jsonb round trip |
| `0.1 + 0.2` and `0.3` | **same** hash, so float representation does not leak in |
| `1` and `"1"` | **different**, because a type change is a payload change |
| `true`, `1` and `"true"` | **different** from each other |
| key order in an object | **same**, because keys are sorted |
| item order in a list | **different**, because order is meaning in a list |
| `null` and an absent key | **different** |
| nested structures | encoded recursively by the same rules |

This is the same class of mistake `reference_green_suite_lies` records: a fixture written from the
design agrees with code written from the same design, and both disagree with the database. The
fixture now round-trips through Postgres.

---

## D. The guard, and why it has two stages

`ApprovalGuard` answers two different questions, and the first implementation conflated them:

- `preClaimReason()` asks whether this may still be **decided**. It requires the approval to be open.
- `blockingReason()` asks whether this may now **execute**. It requires the approval to be claimed.

Written as one method it demanded `approved` at a point where the approval was still `pending`, so
every approval refused itself on the first attempt. Splitting it fixed that and made the intent
legible: the two stages legitimately expect different states.

Both share `sharedReason()`, which is everything true at either stage, in this order: single use,
expiry, hash integrity, still registered, still enabled at runtime, and still the same **major**
tool version. The last one refuses rather than migrates. If the contract changed, the human agreed
arguments for a different tool, and rewriting their decision into the new shape is not something
software gets to do on their behalf.

---

## E. What the browser is allowed to say

The approval endpoints accept, in total: a `note`, a `reason`, a `user_id` to delegate to, and an
optional `edited_payload`. That is the entire request surface. The tool key, the version, the risk
tier, the permission, the business, the credentials and the payload are all read from the stored row
that the route model bound.

There is no code path that executes a tool key supplied by a client, and there is no privileged
"approval bypass" execution route. Approval execution goes through `ToolGateway::execute()`, the
same door as everything else, with the approval passed alongside so that `require_approval` is
satisfied **and nothing else is**. Every other check in the gateway still runs.

An `edited_payload` is validated against the tool's own input schema before it is accepted, and it
never mutates the original approval. It creates a **successor** in `pending`, with its own hash, and
that successor is claimed through the ordinary atomic path. The original survives exactly as the
person first read it, and the delta between the two is written to `decision_records`, which is the
highest value signal the Behavior Alignment Layer will receive in M5.

---

## F. Notifications, and a correction to the record

`docs/48`, `docs/49` and the master dossier all state that the Telegram bot token and chat id are
not configured. **They are, and have been.** Read off the production host on 8 September 2026:
`ALERT_TELEGRAM_BOT_TOKEN` is set (46 characters) and `ALERT_TELEGRAM_CHAT_ID` is set (10
characters).

The earlier statement was written from the configuration file's defaults rather than from the
deployed environment. That is exactly the mistake `docs/45` recorded about the ERP hostname, and the
standing rule it produced: **never infer an environment value from a config default.** Corrected in
this document, in `TelegramChannel`'s own docblock, and in memory.

The channel logs failures by **status code and exception class only**, never the response body and
never the exception message. Telegram echoes the request URL, and the request URL contains the bot
token.

---

## G. The demo tool, and why an R4 tool exists at all

M3 had to prove on production that a gated action parks, waits, and runs once. Doing that with a
real R4 tool would mean creating or cancelling a customer's order to test a mechanism, which is not
an acceptable way to test anything.

`demo.request_approval` is therefore classified **R4 in earnest**, because policy has to treat it
exactly as it treats a real recoverable external side effect or the demonstration proves nothing
about the real ones, while its only effect is one row in HQ's own append-only audit log. It is not
agent-executable, and no approval can lift that.

**It was switched off at runtime immediately after the acceptance run.** Production ships with
nothing enabled at R4 or above.

---

## H. Production acceptance, 8 September 2026

Run against the live system, on production data, with the real policy engine.

### The path that should work

| step | observed |
| --- | --- |
| gateway request, policy returns `require_approval` | `awaiting_approval`, approval 1 created |
| nothing executed | audit rows for the action: **0** |
| hash verified | `payloadIntact() = true`, status `pending` |
| requesting call parked | `tool_calls.status = awaiting_approval` |
| human approves | `succeeded`, tool call 11 |
| approval consumed | status `consumed`, `consumed_at` set, `resulting_tool_call_id = 11` |
| the action ran | audit rows for the action: **1** |
| second approval attempt | **denied**, "already approved and executed" |
| after the second attempt | audit rows for the action: still **1** |

### The paths that should not

| case | approval status | execution attempt | executed |
| --- | --- | --- | --- |
| rejected | `rejected`, parked call `denied` | denied | no |
| cancelled | `cancelled` | denied | no |
| expired, via the sweep | `expired` | denied | no |
| payload edited in the database | integrity failure recorded, refused | denied | no |
| tool version no longer matches | refused on major version | denied | no |
| approver lacks the permission | denied at the gateway | denied | no |
| global freeze on | denied at the kill switch | denied | no |
| tool disabled after approval | refused by the guard | denied | no |
| new request for a disabled tool | never parks, returns `unavailable` | n/a | no |

### The count that matters

Across the whole run: **9 approvals, 13 gateway requests for the demo tool, exactly 1 execution.**

```
demo tool calls by status : {"denied":8,"succeeded":1,"awaiting_approval":3,"unavailable":1}
actions actually executed : 1
succeeded demo calls      : 1
approvals ledger          : {"consumed":3,"expired":1,"cancelled":4,"rejected":1}
```

Three approvals show `consumed` and only one produced an action. That is deliberate and worth
stating plainly, because it looks wrong at a glance. `ApprovalExecutor` **spends the approval
whatever the tool did**: the permission case and the freeze case were claimed, then denied by the
gateway at execution time, and each produced a `denied` tool call. Verified individually:

```
approval 1 (M3 production acceptance) -> tool_call 11 status=succeeded
approval 7 (permission case)          -> tool_call 18 status=denied
approval 8 (freeze case)              -> tool_call 20 status=denied
```

Burning an approval on a denied attempt is the conservative reading of "the authority is
re-evaluated at execution time". If the freeze is cleared a minute later, a human decides again
rather than a stale yes reactivating itself. It fails closed.

All test approvals were closed out afterwards. The permission-test user was created and deleted
within the run. The freeze flag was set and cleared within the run, and verified off afterwards.

---

## I. Security acceptance

| check | result |
| --- | --- |
| container logs scanned, 152,221 bytes over 24h | `APP_KEY`, `DB_PASSWORD`, `ALERT_TELEGRAM_BOT_TOKEN`, `ALERT_TELEGRAM_CHAT_ID`: **0 literal occurrences each** |
| log patterns `api.telegram.org`, `bot<digits>:`, `Bearer `, `api_key`, `password=`, `secret` | **0** |
| `approvals.payload`, 9 rows | 0 credential-shaped values |
| `approvals.evidence` and `preview`, 9 rows | 0 |
| `tool_calls.input`, `output`, `error`, 22 rows | 0 |
| `audit_logs.before` and `after`, 182 rows | 0 |
| notification bodies, 10 rows | 0 |
| browser-supplied execution instructions | impossible: the request surface is `note`, `reason`, `user_id`, `edited_payload` only |
| approval bypass path | none exists; execution goes through `ToolGateway::execute()` |
| historical audit rows modified or deleted | **none** |

---

## J. The Phase 1 work still runs

All six live scheduled jobs were run through the gateway after the M3 deploy, not merely inspected:

| job | tool | run | tool call | policy | scope resolved |
| --- | --- | --- | --- | --- | --- |
| 1 Phase 0 acceptance | `noop` | succeeded | 26 succeeded | allow | group-wide |
| 2 LaLaDia reconciliation | `commerce.reconcile_facts` | succeeded | 27 succeeded | allow | business 2, store 1 |
| 3 Order fact integrity | `commerce.check_fact_integrity` | succeeded | 23 succeeded | allow | group-wide |
| 4 ERP overview | `erp.poll_overview` | succeeded | 24 succeeded | allow | business 1 |
| 5 ERP low stock | `erp.poll_low_stock` | succeeded | 25 succeeded | allow | business 1 |
| 6 Bionic reconciliation | `commerce.reconcile_facts` | succeeded | 28 succeeded | allow | business 3, store 2 |

Every call recorded `caller_kind = scheduler` and `policy_result = allow`, with M2's `scopeFrom`
resolution filling business and store correctly from the payload. Failed queue jobs: **0**. All
seven containers running.

One false alarm along the way, recorded because it is a trap worth remembering: `RunScheduledJob`
takes a **job run id**, not a scheduled job id. Dispatching it with the job id silently matched an
unrelated, already-terminal run and returned immediately, which is the at-least-once guard working
exactly as designed and looks identical to a job that ran and did nothing. The verification above
was redone through `ScheduledJobService::runNow()`.

---

## K. Frontend

`resources/js/pages/approvals/index.tsx` and `show.tsx` are in the built Vite manifest and served
from production: `assets/index-CDbSs-HV.js`, 4,999 bytes, HTTP 200, and `assets/show-CYEUvH0n.js`,
5,070 bytes, HTTP 200. `/approvals` correctly redirects an unauthenticated request to `/login`.
`tsc --noEmit` is clean.

The one thing not verified here is the rendered screen under a logged-in session, because that would
have meant authenticating as Joy with his saved credentials. It needs one look from him.

---

## L. Rollback

`16919f6`. The migration is reversible and drops only what it added: the `approvals` table, the two
Postgres constraints, and the foreign key on `tool_calls.approval_id`. The column itself predates
this milestone and stays. Backup taken before deploy: `hq-20260908-070636.dump`, 306,336 bytes, 42
tables, verified with `pg_restore --list`.

---

## M. Known limitations

1. **A tampered approval is not marked `cancelled`.** ADR-018 as proposed said it would be. The
   implementation records the integrity failure, refuses execution at both guard stages permanently,
   and leaves the row's status where it was. Mutating a row already known to have been changed
   underneath the application destroys the forensic state at the moment of detection. The ADR text
   is amended to match. The practical effect is that a poisoned approval sits visible as open until
   the expiry sweep closes it, which is preferred over hiding it.
2. **A policy denial at execution time consumes the approval**, as described in H. It fails closed,
   and a fresh human decision is required. Worth revisiting only if it proves annoying in real use.
3. **No agent has ever created an approval.** Every approval so far was raised by an operator
   request, because no model provider is connected. The agent path is designed and tested but not
   exercised by a real proposer until M4.
4. **`escalateOverdue()` escalates by role, not to a named person.** Sufficient while HQ has one
   owner; it will need a rota when it does not.
5. **Approve-with-edit has no production exercise.** It is covered by tests only. The production run
   deliberately did not edit a payload, since the successor mechanism is best proven against a real
   proposal rather than a synthetic one.

---

## N. The M4 boundary, explicitly

Not started, not begun, not partially present: the Model Router, AI cost accounting, the Executive
and Analytics agents, Ask HQ, natural-language scheduling, the daily briefing, and any autonomous
operation. No model provider is connected. No R4 or R5 tool is enabled. Nothing in this milestone
gives an agent a capability it did not have at the end of M2, and `agent_executable` remains
unliftable by any approval.

---

## O. ADR status changes

| ADR | change |
| --- | --- |
| ADR-018 | proposed to **accepted and realised** (Phase 2 M3), with two amendments recorded in M above: a tampered approval is refused and recorded rather than cancelled, and the successor link is stored as `supersedes_id` on the successor rather than `superseded_by` on the original |
