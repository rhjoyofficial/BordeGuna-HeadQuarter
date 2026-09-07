# 44 - HQ fact integrity and reconciliation (7 September 2026)

A production correction, not a feature. HQ was receiving LaLaDia's orders correctly and storing
them wrongly, in a way that threw no error and failed no test. This records what was wrong, how it
was found, what was changed, what was repaired, and what could not be.

**Scope discipline:** LaLaDia only. No Bionic, no Econic, no Meta, no n8n, no payments, no Phase 2.
No LaLaDia release was cut, and no LaLaDia source order was modified.

---

## A. Production versions

| | before | after |
| --- | --- | --- |
| HQ repo `main` | `739c79c` | see section J |
| `hq.bordeguna.com` | `6bb9f06` (5 Sep, 15:27 UTC) | `739c79c` (7 Sep, 04:39 UTC), then section J |
| Rollback target | - | `6bb9f06` |

`6bb9f06` had been live for 37 hours. Everything between it and `739c79c` is listed in section C.

---

## B. The defect

On 6 September at **14:49:14 UTC** a real customer placed LaLaDia order
**`LLD-20260906-JRQXXJLN`**, grand total 1,089.00. It was not a test: it arrived roughly two hours
after the shop was restored, with nobody watching. HQ received `order.created` at 14:50:05 and had
written the fact by 14:51:02 - the restored webhook path worked end to end and unattended.

The fact it wrote was wrong in three places, all silently:

| field | what HQ stored | what the order actually was |
| --- | --- | --- |
| line `unit_price` | `0.00` | 999.00 |
| line `total` | `0.00` | 999.00 |
| `source` | NULL | the channel the order came through |
| `district` | NULL | the customer's district |

Nothing failed, because nothing was invalid. **A zero is a perfectly good price.** Every monetary
check HQ could make still passed: the order's own `subtotal`, `shipping_cost` and `grand_total`
were correct, so any total-level assertion agreed with the shop. Only the line detail was hollow.

### B.1 Root cause

The shop's `DispatchOrderCreatedWebhook` listener sends each line as:

```php
'items' => [['sku' => ..., 'name' => ..., 'quantity' => ..., 'price' => ...]]
```

HQ's `FactsOrderUpserter` read `$i['unit_price'] ?? 0` and `$i['total'] ?? 0`. Neither key is in
that payload, so both fell to the default. The same listener sends a flat `address` string and no
`source`, while HQ reads `$o['shipping_address']['district']` and `$o['source']` - hence the two
nulls.

### B.2 Why the tests did not catch it

`orderPayload()` in `ShopWebhookAndIdentityTest` was written from the **design** of the webhook
contract rather than from the shop's listener. It supplied `source`, `shipping_address`,
`unit_price` and `total` - precisely the four things the real payload does not carry. The fixture
agreed with the mapper, the mapper agreed with the fixture, and the pair of them disagreed with
production. 73 tests passed the whole time.

This is the failure mode already recorded as "a green suite lies", in a new shape: Phase 1 **was**
exercised against a real system, but only for **arrival**. Nobody compared what arrived with what
was stored. Delivery was proven; fidelity was assumed.

### B.3 A second defect, found on the way

`SchedulerAcceptanceTest` created its one-off job at a hardcoded `2026-09-06 17:00:00` Asia/Dhaka.
`NextRunCalculator::next()` returns null for a `once` job whose `run_at` is not strictly in the
future, so from 7 September `next_run_at` was null and the first assertion called `equalTo()` on
null. The entire suite went red on a commit that touched nothing near the scheduler. A date literal
in a fixture that must lie in the future is a bomb with a one-day fuse.

---

## C. Release gate

Commits between production `6bb9f06` and the candidate `739c79c`:

```
739c79c The scheduler acceptance test was a bomb set for the next day
e2f18c5 HQ keeps the line price the live webhook actually sends
3cc2141 docs/43 (in progress)
16e140a docs/41: LaLaDia Phase 1 integration complete
b70c0f7 docs/40: ERP Phase 1 integration complete
3ef5b8c docs/39: Step 0 - the verified production state
4339241 docs/38: Phase 1 production readiness
61470ba docs/36: HQ Phase 1 released as 6bb9f06
```

Eight commits, **four non-documentation files**, **no migrations**:

| file | change |
| --- | --- |
| `app/Facts/FactsOrderUpserter.php` | the fix: a named `line()` mapper |
| `tests/.../ShopWebhookAndIdentityTest.php` | two regression tests on the real payload |
| `tests/.../SchedulerAcceptanceTest.php` | the date-literal fix, one line plus a comment |
| `README.md` | documentation index entries |

Nothing unrelated. Six of the eight commits are documentation of work already released.

CI run **34080261220** on `739c79c`: **75 passed, 425 assertions** - Pest, Pint, migrations
(fresh + rollback + fresh + seed on PostgreSQL 17), TypeScript strict, Vite build, route listing,
and both production image builds.

---

## D. Backup

Taken before deploying, through the stack's own backup service:

```
/backups/daily/hq-20260907-043741.dump   257,408 bytes   38 tables
```

Verified rather than assumed: `pg_restore --list` reads it (346 entries), `/backups/LAST_STATUS` is
`0`, and `/backups/LAST_OK` points at this file. Nightly dumps were already running (01:02 UTC).
The deploy script then took its own pre-deploy dump as well.

**Rollback:** `cd /opt/bordeguna-hq && ./deploy/rollback.sh 6bb9f06`. No migration ran in this
release, so a rollback needs no database restore; the dump above exists for the case where one is
wanted anyway.

---

## E. Deployment

`./deploy/deploy.sh` at **04:39:13 UTC**, fast-forwarding `/opt/bordeguna-hq` from `6bb9f06` to
`739c79c` on branch `main`. Recorded in `deploy/RELEASES.md`.

Verified **on the server**, not from CI:

- `git rev-parse HEAD` in `/opt/bordeguna-hq` = `739c79c9317b...`, working tree clean.
- The fix is in the **running container**: `docker compose exec app grep price
  app/Facts/FactsOrderUpserter.php` shows `$i['unit_price'] ?? $i['price'] ?? 0`.
- All seven containers up; `app`, `web`, `horizon`, `db`, `redis` healthy.
- `https://hq.bordeguna.com/api/health` → `{"status":"ok"}` with database, redis, queue
  (1 supervisor) and scheduler (last tick 110 s) all ok. `/up` → 200.
- `php artisan horizon:status` → running. `queue:failed` → none.
- `migrate:status`: every migration `Ran`, highest batch still 2 - this release had none.
- Laravel schedule intact: tick and event drain every minute, Horizon snapshot every five,
  two daily prunes.
- No log file and no error, exception or 5xx in the container logs since the deploy.

---

## F. What was actually damaged

Every LaLaDia fact, read before anything was changed:

| id | order_ref | source | district | lines | zero-priced lines | stored `order.created` event |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | LLD-20260906-B9C17FQL | landing | Chuadanga | 1 | 1 | yes |
| 2 | LLD-20260906-QFGKLCD9GC | checkout | Dhaka | 0 | 0 | **no** |
| 3 | LLD-20260905-XVRB7QDLCX | checkout | Khulna | 0 | 0 | **no** |
| 4 | LLD-20260906-GZPTTHF9 | admin | NULL | 1 | 1 | yes |
| 5 | LLD-20260906-41XPMXWF | landing | Dhaka | 1 | 1 | yes |
| 6 | LLD-20260906-JRQXXJLN | **NULL** | **NULL** | 1 | 1 | yes |

So the damage is wider than the order that exposed it: **four of six facts** carry a line priced at
0.00, not one. Facts 2 and 3 have no lines at all - they were inserted by reconciliation before any
webhook subscriber existed, and reconciliation never sees line detail (section G).

Only fact 6 has a null `source`: the others were enriched by reconciliation runs on 6 September,
and fact 6 arrived after the last of them.

---

## G. Why reconciliation alone cannot fix this

`hq:facts:reconcile` reads the shop's admin **list** endpoint. The shop's `OrderListQuery` does
`Order::withCount('items')->with(['zone','user','shipments','shippingAddress'])` - items are
**counted, never loaded** - so the list response carries `items_count` and no `items` array. The
upserter therefore keeps whatever lines it already had. That is the structural reason facts 1 and 5
still had zero-priced lines *after* being reconciled, and why facts 2 and 3 have no lines at all.

The shop's admin **show** endpoint does return full line detail. It is not used, on purpose:
`show()` calls `$order->markAdminViewed()`, which stamps `admin_viewed_at` on the shop's order. HQ's
own repair would then have marked real orders as "seen" in the shop's workspace, changing what the
order screen calls New. A read that writes to the source system is not a read.

**So the repair does not ask the shop at all.** HQ already stores every webhook payload verbatim in
`events`, including the correctly-priced `items`. Re-delivering those payloads to the corrected
handler rebuilds the lines from the authoritative record HQ was given. Confirmed before running
anything:

| order | subtotal | shipping | grand total | sku | qty | price | qty x price |
| --- | --- | --- | --- | --- | --- | --- | --- |
| LLD-20260906-41XPMXWF | 999 | 60 | 1059 | PIC-HIL-V1 | 1 | 999 | 999 |
| LLD-20260906-B9C17FQL | 2610 | 0 | 2610 | GHE-ROY-V1 | 3 | 870 | 2610 |
| LLD-20260906-GZPTTHF9 | 999 | 90 | 1089 | PIC-HIL-V1 | 1 | 999 | 999 |
| LLD-20260906-JRQXXJLN | 999 | 90 | 1089 | PIC-HIL-V1 | 1 | 999 | 999 |

In all four, `qty x price` equals the order's own subtotal and `subtotal + shipping` equals its
grand total. Those two identities are what makes the repair verifiable without ever calling the
endpoint that would have mutated the shop.

---

## H. The code changes

**`e2f18c5` - the mapping itself.** One order line is now built by a named `line()` helper that
takes `unit_price` **or** `price`, `total` **or** `line_total`, and derives `qty x unit_price` when
the shop sends no total. Both live payload shapes now map correctly through one function.

**`6e45679` - repairing what was already stored, and ending the reliance on memory.**

- `FactsReconciler` - the reconciliation lifted out of the console command into a service, because
  the nightly job runs the same thing and two copies would drift. The symptom of that drift would
  be a nightly run quietly reconciling a different set of orders than the command a human just
  watched succeed.
- `hq:events:replay` - re-delivers events HQ has already accepted to their current handlers. A
  mapping bug is not a delivery failure: the correct data was in the payload all along, so the
  repair is to run the corrected handler over the payload HQ already holds. It refuses to run
  without a filter, prints what it would do, writes nothing without `--apply`, and records one
  audit row per replayed event. `attempts` is left alone deliberately - it counts delivery
  attempts, and a replay is not evidence the original delivery was flaky.
- `ReconcileShopFactsTool` (`commerce.reconcile_facts`) - the reconciliation as a registered tool,
  so the durable scheduler can run it. An unknown store fails rather than retrying (a configuration
  mistake), while an unreachable shop throws so the scheduler retries with backoff.
- The contract fixture moved into `tests/Pest.php` and is now **pinned**:
  `assertLiveWebhookContractUnchanged()` asserts the payload carries `price` and carries neither
  `unit_price` nor `total` nor `source` nor `shipping_address`. Without that, the obvious way to
  "fix" a future failure is to add the missing key to the fixture - which is exactly how the
  original defect stayed invisible for two days.

**`687d462` - `hq:scheduler:ensure`.** A scheduled job is configuration, and configuration that can
only be created by a UI click or a tinker session drifts and gets duplicated. The name is the
identity: create if absent, update in place if present, refuse outright if two live jobs already
share it rather than guessing which is real. `--run-now` uses the scheduler's own run-now path, so
proving the mechanism does not mean waiting until 02:00. Bionic will need the same job with a
different `store_slug`, which is the other reason this is a command and not a one-off.

`9138b20` and `3140042` are test corrections only: Postgres returns a whole number out of jsonb as
an int (so compare values, not PHP types), Laravel pairs each `expectsOutputToContain` with one
output call in sequence, and jsonb does not preserve key order.

### H.1 Regression tests

`tests/Feature/Facts/ReconcileAndReplayTest.php`, eight tests:

- reconciliation inserts an order no webhook delivered, and a second run inserts nothing;
- reconciliation fills the channel and district a webhook cannot carry **and leaves the lines it
  never sees** - the property that makes replay necessary;
- a stored `order.created` replays into correct line prices with `Http::assertNothingSent()`, so
  the test fails if anyone ever makes the repair path call the shop;
- replaying twice changes nothing and does not pull a cancelled order back to pending;
- replay refuses to run with no filter;
- the tool reconciles under its registered key, repeats safely, and fails on an unknown store;
- `hq:scheduler:ensure` declares the job twice without ever making a second one, and stores
  02:00 Asia/Dhaka as 20:00 UTC;
- it refuses an unregistered tool and a payload that is not an object.

---

## I. CI evidence

Run **34084919843** on `3140042`: **83 passed, 489 assertions**. Pest, Pint, migrations
(fresh + rollback + fresh + seed, PostgreSQL 17), TypeScript strict, Vite build, route listing,
and both production image builds. The count went 73 (before this milestone) to 83.

---

## J. Second deployment

`./deploy/deploy.sh` at **05:03:12 UTC**, `739c79c` to **`3140042`**. Pre-deploy dump
`/backups/daily/hq-20260907-050242.dump` (257,408 bytes, 38 tables). No migrations. Health ok on
all five checks; all containers healthy; the three new commands confirmed present in the running
container via `php artisan list hq`.

Commits in this release: `a2c79af` (ADR-009/010/015, documentation written by another session in
the same working tree - documentation only, no application file), `6e45679`, `9138b20`, `687d462`,
`3140042`.

---

## K. The repair, in the order it was run

**1. `hq:facts:reconcile --store=laladia-com --hours=48`** gave `checked 6 orders, inserted 0 missing`.

Fact 6 was repaired through the authoritative path: `source` NULL to **`landing`**, `district`
NULL to **`Narayanganj`**. Nothing was inserted, so no duplicate fact and no duplicate customer.

**2. `hq:events:replay --type=order.created --store=laladia-com --hours=48`** (dry run) selected
exactly the four affected events and changed nothing. The same command with `--apply` and a reason
gave **`replayed 4, failed 0`**.

**3. `hq:facts:reconcile --store=laladia-com --hours=48`** again gave `checked 6, inserted 0`.

The shop was read (list endpoint only, twice) and never written to. No LaLaDia order was modified.

---

## L. Verification of `LLD-20260906-JRQXXJLN`

| field | before | after |
| --- | --- | --- |
| order number | LLD-20260906-JRQXXJLN | unchanged |
| store / business | store 1 (laladia-com) / business 1 | unchanged |
| source / channel | **NULL** | **landing** |
| district | **NULL** | **Narayanganj** |
| thana | NULL | NULL (the source order carries no thana) |
| status | pending | pending |
| subtotal | 999.00 | 999.00 |
| shipping | 90.00 | 90.00 |
| discount | 0.00 | 0.00 |
| grand total | 1,089.00 | 1,089.00 |
| line count | 1 | 1 |
| SKU | PIC-HIL-V1 | PIC-HIL-V1 |
| quantity | 1 | 1 |
| **unit price** | **0.00** | **999.00** |
| **line total** | **0.00** | **999.00** |
| placed at | 2026-09-06 14:49:14 UTC | unchanged |

**How this is known to agree with the shop without calling the mutating endpoint:** the order-level
figures came from the shop's admin list endpoint during reconciliation, and the line figures came
from the shop's own webhook payload. Those two independent sources now agree arithmetically -
`1 x 999 = 999 = subtotal`, and `999 + 90 - 0 = 1089 = grand total`. A wrong line price could not
satisfy both.

---

## M. Every affected fact after repair

| id | order_ref | source | district | status | subtotal | line total(s) | subtotal = sum of lines | gross = subtotal + shipping - discount |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | LLD-20260906-B9C17FQL | landing | Chuadanga | cancelled | 2610.00 | 3 x 870 = 2610 | MATCH | MATCH |
| 2 | LLD-20260906-QFGKLCD9GC | checkout | Dhaka | cancelled | 220.00 | _no lines_ | n/a | MATCH |
| 3 | LLD-20260905-XVRB7QDLCX | checkout | Khulna | confirmed | 2238.00 | _no lines_ | n/a | MATCH |
| 4 | LLD-20260906-GZPTTHF9 | admin | NULL | confirmed | 999.00 | 1 x 999 = 999 | MATCH | MATCH |
| 5 | LLD-20260906-41XPMXWF | landing | Dhaka | pending | 999.00 | 1 x 999 = 999 | MATCH | MATCH |
| 6 | LLD-20260906-JRQXXJLN | landing | Narayanganj | pending | 999.00 | 1 x 999 = 999 | MATCH | MATCH |

**Counts: 6 examined, 4 repaired, 0 already correct at line level, 2 not repairable automatically.**

Statuses did not regress. Fact 1 was cancelled and fact 4 confirmed before the replay; both stored
`order.created` payloads say `pending`, and both facts are still cancelled and confirmed.

### M.1 The two that could not be repaired, and why

Facts 2 and 3 have **no line detail at all**, and this is not repairable by any path currently
available:

- Neither has a stored `order.created` event. Both predate the webhook subscription and were
  inserted by reconciliation, which never sees items (section G). There is nothing in HQ to replay.
- The only source of their lines is the shop's admin **show** endpoint, and calling it stamps
  `admin_viewed_at` on the shop's order.

Their order-level money is correct and internally consistent, so nothing downstream is wrong about
what they cost - only what was in them is unknown to HQ. Repairing them means either accepting the
`admin_viewed_at` side effect on two orders, or adding an item-loading read to the shop in its next
ordinary release. Neither was done here, because neither is this milestone's decision to make.

### M.2 One further null with an honest explanation

Fact 4 (`LLD-20260906-GZPTTHF9`) still has a NULL district **after** reconciliation. That is the
source record, not a mapping fault: it is the admin-created order, and the shop's own canonical
record for it carries no address city. HQ is correctly recording an absence.

---

## N. Regression check

Rows where the source order has a non-zero price and HQ stores zero: **none.**

`select count(*) from facts_orders f, jsonb_array_elements(f.lines) l where (l->>'unit_price')::numeric = 0`
returns **0**, and it still returns 0 after the second reconciliation, which proves reconciliation
does not undo the replay.

The only remaining anomalies are the two line-less facts of M.1 and the one true null of M.2, each
with a stated reason. No value was invented anywhere: every repaired figure came from a payload the
shop itself produced.

---

## O. Idempotency proof

| | facts_orders | customers | customer_identities | customer_merges | events |
| --- | --- | --- | --- | --- | --- |
| before the second reconciliation | 6 | 5 | 21 | 0 | 8 |
| after | 6 | 5 | 21 | 0 | 8 |

`inserted 0 missing`. And no duplicate identities exist at all: for every channel the row count
equals the count of distinct `(channel, external_id, source_store_id)` - email 5/5, phone 5/5,
shop_order 6/6, shop_user 5/5. Zero merges were created; no identity relationship was altered.

(Identities went from 20 to 21 across the whole repair: fact 6 gained the `shop_order` identity it
was missing, taking that channel to one per order. That is the enrichment working, not a duplicate.)

---

## P. The nightly job

| | |
| --- | --- |
| name | `LaLaDia order reconciliation` |
| id / uuid | 2 / `01a07a41-edbf-7364-826d-d0e5bea14d5d` |
| kind | cron, `0 2 * * *` |
| timezone | **Asia/Dhaka** |
| next run | `2026-09-07 20:00:00 UTC` = **8 Sep 02:00 Dhaka** |
| tool | `commerce.reconcile_facts` |
| payload | `{"store_slug":"laladia-com","hours":48}` |
| state / approval | active / approved |
| retries | 3, 60 s backoff (scheduler defaults) |
| missed policy | skip - a missed night is covered by the next night's 48-hour window |

The 48-hour window is deliberate: it gives a full day of overlap so a webhook delayed past midnight
is still caught, without asking the shop for its whole history every night.

**Verified after registering it:**

- it exists in HQ's durable scheduler, and it is the **only** reconciliation job - the jobs table
  holds exactly two rows, the other being the Phase 0 acceptance no-op;
- running `hq:scheduler:ensure` a second time with the same name **updated job 2 rather than
  creating a job 3**;
- run-now through the scheduler's own path: `job_runs` id 2, trigger `run_now`, attempt 1,
  **succeeded**, output `{"store":"laladia-com","hours":48,"since":"2026-09-05T05:05:56+00:00",
  "checked":6,"inserted":0}` - idempotent on a repeat, exactly as the nightly run will be;
- execution history is recorded per run in `job_runs` with status, output and error;
- a failure would be visible in three places: the `job_runs` row (status + error), the job's
  `last_status`, and Horizon;
- pause and resume are the scheduler's own operations and remain available on this job like any
  other;
- the audit trail carries it all: `scheduled_job.created`, `scheduled_job.updated`,
  `scheduled_job.run_now`, and the four `event.replayed` rows.

### P.1 One observation, not fixed

`last_run_at` is written only by `SchedulerTick`, while `last_status` is written by the runner, so
after a run-now the job shows `last_status = succeeded` with `last_run_at` still null. The `job_runs`
history is the authoritative record and is complete, so nothing is lost. Changing scheduler
semantics did not belong inside a corrective milestone; it is written down here instead.

---

## Q. Rollback

- **Code:** `cd /opt/bordeguna-hq && ./deploy/rollback.sh 6bb9f06` returns to the pre-milestone
  release. `739c79c` is the intermediate point if only the second release is unwanted.
- **Database:** no migration ran in either release, so a code rollback needs no restore. The dumps
  `/backups/daily/hq-20260907-043741.dump` and `hq-20260907-050242.dump` exist if one is wanted.
- **The repair itself is not rolled back by either.** It only wrote correct values over incorrect
  ones in `facts_orders`, from payloads the shop produced. Rolling the code back would restore the
  defective mapping, and the next order would go back to storing 0.00.
- **The scheduled job** can be paused or cancelled through the scheduler without touching code.

---

## R. What remains, deliberately

**`source` and `district` on the webhook listener.** The shop's `order.created` payload still
carries neither, so a live order only gains its channel and district when reconciliation next runs
- within 24 hours now that the nightly job exists, instead of never. **No LaLaDia release was cut
for this.** It belongs in the next ordinary LaLaDia release: add `source` and the
`shipping_address` object (district, thana/area) to `DispatchOrderCreatedWebhook`, and Bionic
inherits it by merge. Until then the gap is analytical, not operational, and Phase 5 (marketing
attribution) is the first phase that genuinely needs `source` at the moment the order lands.

**Line detail for facts 2 and 3** - section M.1.

**Bionic** is untouched and remains its own milestone. The fact-integrity correction was proven on
LaLaDia because LaLaDia is the reference integration and the defect surfaced there; the same
`commerce.reconcile_facts` tool and `hq:scheduler:ensure` command will register Bionic's nightly job
with `store_slug: bionic-garden` when its credentials exist.

---

## S. The lesson worth keeping

The webhook worked. The event pipeline worked. The suite was green. The health check was ok. Every
one of those said the integration was fine, and the data was wrong anyway, because **a zero is a
valid number and an absent key has no error to raise.**

What actually caught it was comparing what arrived against what was stored, for one real order,
by hand. Nothing else would have. "Exercise the feature against a real system" has to mean
comparing the two sides field by field, not observing that something showed up.

And the fixture is the trap, not the mapper. A fixture written from a design document will always
agree with code written from the same design document. The only fixture worth trusting is one
copied from what the other system actually sends - which is why the real payload is now pinned in
`tests/Pest.php` with an assertion that it must **not** contain the keys HQ would prefer.
