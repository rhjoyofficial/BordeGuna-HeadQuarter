# 45 - ERP connector fidelity, and a guard against the next silent defect (7 September 2026)

The LaLaDia incident (`docs/44`) was never really about LaLaDia. HQ read a key the shop did not
send, defaulted quietly, and stored a confident wrong answer that no test and no health check could
see. The ERP connector reads its payload the same way, so the same question had to be asked of it.

This records the answer, the one real defect it found, the live outage it found by accident, and
the guard now standing behind both.

**Scope:** the ERP connector and HQ's own data. No Bionic, no Econic, no Meta, no n8n, no
Phase 2. **No ERP source data was modified**, and the ERP was not deployed.

---

## A. State before

| | |
| --- | --- |
| HQ repo `main` / production | `32e0c32` / `3140042` |
| ERP production | `e871b82` (was `17d595c` when docs/40 was written) |
| ERP credential | `integration_credentials` id 1, `erp_api_token`, `bor-de-guna`, business 1, active |
| `facts_erp_snapshots` | 2 rows, both polled 2026-09-06 07:39 UTC |
| connector calls | 5, all succeeded, all on 6 September |
| scheduled jobs | 2 (Phase 0 no-op; LaLaDia reconciliation) |
| CI | run 34084919843 green, 94 tests |

---

## B. The outage the audit walked into

The first probe of the day failed:

```
[bor-de-guna] {"ok":false,"business_id":4,"error":"Refused: ERP businesses: HTTP 404","ms":42}
```

That call had worked the day before. The cause was not in HQ's code and not in the ERP's:

- the ERP had moved to its own domain (`815f3fb erp.bordeguna.com: the ERP answers on its own
  domain`) and then **removed the provider hostname from its routing** (`518262f The provider
  hostname is gone`);
- HQ's `config/hq.php` defaulted `base_url` to `https://erp.srv1808022.hstgr.cloud`;
- **nothing set `HQ_ERP_BASE_URL` in HQ's production `.env`**, so that default was the operative
  value.

Confirmed both ways: the old host answers `404` on `/api/hq/v1/businesses`, the new one answers
`401` - the route exists there and wants a token.

So **HQ's entire ERP integration was dead**, and nothing anywhere said so. The snapshots were not
stale-looking; they were simply the last ones ever written. Nothing polls the ERP on a schedule, so
no job went red, and `facts_erp_snapshots` has no freshness expectation to violate.

**Fixed** by pointing the config default at `https://erp.bordeguna.com`. A default that names a
hostname the business no longer uses is not a fallback, it is a trap. The ERP's HQ API code and
routes are **unchanged** between `17d595c` and `e871b82` - only the address moved.

⚠ **Left for Joy:** HQ's `deploy/.env` still has no `HQ_ERP_BASE_URL` line, so the code default is
what production uses. Setting it explicitly would pin the address independently of a release. I did
not add it: editing the production secrets file was refused, and the code default was the better fix
anyway.

---

## C. The real payload

Captured from production through the same authenticated connector production uses, with a new
read-only command, `hq:erp:inspect`. Not from the DTO, and not from the endpoint's source.

**overview** - `GET /api/hq/v1/4/overview`

```
$                                    object{data, meta}
$.data                               object{sales, inventory, purchasing, approvals, tickets}
$.data.sales.invoiced_this_month     int = 0
$.data.sales.invoices_past_due_date  int = 0
$.data.sales.customers               int = 1
$.data.inventory.stock_value         int = 0
$.data.inventory.low_stock_count     int = 0
$.data.inventory.active_products     int = 118
$.data.purchasing                    null = null
$.data.approvals.pending             int = 1
$.data.tickets.open                  int = 1
$.data.tickets.overdue               int = 0
$.meta.as_of                         string = "2026-09-07T11:41:09+06:00"
```

**low stock** - `data` an empty list; `meta` carries `business_id`, `count: 0` and `as_of`.
**businesses** - one row, `{id: 4, name, is_active: true}`.
**reports** - one report, `sales_by_brand`, with a nested `parameters` list whose entries carry
`help` and `default` as explicit nulls.

Sanitised copies are committed as `tests/fixtures/erp/*.json`, key for key.

---

## D. Field-by-field assessment

Categories are the ones the audit brief defines: (1) correctly mapped, (2) incorrectly mapped,
(3) intentionally absent, (4) intentionally nullable, (5) HQ-derived, (6) silently defaulted,
(7) unused upstream field, (8) unverified.

| ERP field | HQ mapper | snapshot field | observed | meaning | status |
| --- | --- | --- | --- | --- | --- |
| `data.sales.*` | `$data['sales'] ?? null` | `payload.sales` | `{0, 0, 1}` | stored verbatim as a section | **1** |
| `data.inventory.stock_value` | inside the section | `payload.inventory.stock_value` | `0` | the ERP's own computed value | **1** (see E.2) |
| `data.inventory.low_stock_count` | inside the section | same | `0` | agrees with the low-stock endpoint | **1** |
| `data.inventory.active_products` | inside the section | same | `118` | agrees with the known catalogue size | **1** |
| `data.purchasing` | `$data['purchasing'] ?? null` | `payload.purchasing` | `null` | permission boundary | **4** (see E.1) |
| `data.approvals.pending` | inside the section | same | `1` | | **1** |
| `data.tickets.{open,overdue}` | inside the section | same | `1, 0` | agrees with TK-2026-27-0001 | **1** |
| `meta.as_of` (overview) | `$r['meta']['as_of'] ?? null` | `as_of` column | `+06:00` string | when the ERP says this was true | **2 - defect, see E.3** |
| `meta.as_of` (low stock) | **was ignored** | `as_of` column | HQ's `now()` | was HQ's clock, not the ERP's | **2 - fixed** |
| `meta.count` (low stock) | **was ignored** | - | `0` | the disambiguator for an empty list | **7 - now used** |
| `meta.business_id` | ignored | - | `4` | HQ already knows it | **7 - intentionally unused** |
| `data` (envelope) | `$r['data'] ?? []` | - | present | required; `?? []` hid its absence | **6 - now required** |
| low-stock row fields | `$row['product_id']` etc., no `??` | `payload[]` | no rows today | absence would throw, correctly | **1** |
| `businesses[].{id,name,is_active}` | `(int) $b['id']` | - | one row | | **1** |
| `reports[].*` | passed through | - | one report | `sales_by_brand` only, as designed | **1** |
| `reports[].parameters[].{help,default}` | passed through | - | `null` | explicit nulls, not omissions | **4** |
| `erp_business_id` | HQ adds it | `payload.erp_business_id` | `4` | HQ-derived | **5** |

---

## E. The three questions the brief asked by name

### E.1 `purchasing: null` - legitimate absence, correctly represented

The ERP builds the section conditionally:

```php
'purchasing' => $can('purchasing.reports.view') ? [...] : null,
```

The key is **always sent**. Its value is null when the token lacks `purchasing.reports.view` - and
HQ's token was deliberately not granted it (docs/40: Joy's decision, alongside `accounting.view`,
`sales.view` and the HRM abilities). Confirmed in the live payload: `purchasing` is **present** with
value `null`, not absent.

**Conclusion: real data, deliberately withheld by a permission boundary, faithfully recorded.** Not
a defect. The DTO's own comment already stated the rule - "a null section means the token lacked the
ability for it, which is different from a zero" - and the payload proves the rule is being honoured.

To make `purchasing` carry numbers, the ERP token would need `purchasing.reports.view`. That is
Joy's call, not a bug fix.

### E.2 `stock_value: 0` - the source says zero

The ERP computes it:

```php
'stock_value' => (float) (StockBatch::where('status', Active)
    ->selectRaw('SUM(current_quantity * unit_cost) as value')->value('value') ?? 0),
```

and the live payload carries `stock_value` as a **present key with integer 0**. Had HQ invented the
zero, the key would be missing from the payload; it is not.

Corroborated three ways: `low_stock_count` is also 0, the low-stock endpoint returns an empty list
with `meta.count: 0`, and the ERP genuinely holds no stock documents yet - opening stock is the
outstanding cutover item. Three independent readings of "there is no stock" agree.

**Conclusion: the source reports zero. HQ is right to store zero.** Not a defect. It will stop being
zero when Joy enters opening stock, and the value should be re-checked then rather than assumed.

### E.3 The defect: a timestamp six hours in its own future

The ERP stamps every response in its own timezone: `2026-09-07T11:41:09+06:00`. Handing that string
to a `datetime` cast stored the **wall-clock reading and dropped the offset**.

Evidence from the 6 September snapshot, before any change:

| | value |
| --- | --- |
| `payload.as_of` (what the ERP said) | `2026-09-06T13:39:16+06:00` = 07:39:16 UTC |
| `as_of` column (what HQ stored) | `2026-09-06 13:39:16+00` |
| `polled_at` (the real instant) | `2026-09-06 07:39:16+00` |

The snapshot claimed to describe a moment **six hours after it was written**. Nothing complained,
because a timestamp is valid whatever it says - the same shape of silence as a 0.00 price.

**Fixed**: parsed to an instant and stored UTC, with an unparseable value recorded as `null` rather
than as a wrong instant. Verified live after deploying - payload `2026-09-07T11:56:32+06:00`, column
`2026-09-07 05:56:32+00`, equal to `polled_at` to the second.

### E.4 A fourth finding: `as_of` meant two different things

`PollErpLowStockTool` never read the ERP's `meta.as_of`; it stamped the rows with HQ's own
`now()`. So one column carried "when the ERP said this was true" on one row and "when HQ asked" on
the other. It now uses the ERP's timestamp, like the overview.

---

## F. Tests: what was wrong with them, and what replaced them

The existing ERP fixtures carried the same false confidence as the shop's. The overview fixture in
`ErpConnectorTest` sent `sales` **without `invoices_past_due_date`**, a field the endpoint has
always returned, and another sent an overview containing only `inventory` - a shape the real ERP
cannot produce. Both passed, because the mapper stores sections verbatim and never looks.

`ErpContractFidelityTest` is new and built entirely on the captured payloads:

- the captured overview carries exactly the five sections HQ knows about - **and no others**, so a
  new section cannot slip past unnoticed;
- **`purchasing` is asserted present and null**, deliberately, as the legitimate-absence case;
- **`stock_value` is asserted present and 0**, as the source-says-zero case;
- an empty low-stock list is accepted when `meta.count` agrees and **refused when it does not**;
- a response with no `data` key is refused rather than persisted as a blank;
- an overview missing a section is refused, because a vanished section is indistinguishable from a
  permission boundary once it has been stored;
- `as_of` is asserted to land as `05:41:09` UTC from `11:41:09+06:00`, and as `null` when unparseable.

`ErpConnectorTest` keeps its behaviour tests (auth, path scoping, failure classification, the one
write) with a comment saying plainly that its shapes are built to exercise branches, and that the
fidelity question is answered next door.

⚠ One honest limit: the live low-stock list is **empty**, so no captured row exists. The row-mapping
test still uses a constructed row, whose keys come from the ERP endpoint's own source rather than
from a captured response. That is source evidence, not a guess, but it is weaker than the rest and
should be re-captured the first time a product actually goes below threshold.

---

## G. Validation introduced

`App\Erp\ErpContract` - four functions, not a framework:

| assertion | why it is required |
| --- | --- |
| `requireData` | every read endpoint returns `{data, meta}`; without `data` there is nothing to store, and storing the `?? []` default would record a confident blank as fact |
| `requireDataArray` | `data` must be an array before it is mapped |
| `requireSections` | the overview's five sections are nullable, but their **presence** is not: a section that stops being sent is indistinguishable from one the token cannot see |
| `requireRowCount` | when the ERP says how many rows it sent, a disagreement is data loss - an empty list the ERP says should have had rows in it is exactly that shape |

A violation raises `Unsupported`, not `Unavailable`, because retrying will not help: the payload is
the wrong shape and will still be the wrong shape in sixty seconds. `PollErpTool` turns that into a
failed run rather than a retry, which is the outcome that gets a person to look.

**What was deliberately left alone.** Genuinely optional fields keep their `?? null`:
`reports[].help` and `.default` are nulls by design; `product`, `customer` and `pendingApprovals`
return `['data'] ?? []` and are not yet used by anything that persists; `ErpTicketResult`'s defaults
guard a write whose response HQ does not control. The aim is an integration that is **observable
when it breaks**, not one that is brittle when it works.

---

## H. Historical snapshot assessment

`facts_erp_snapshots` is a **current-state read model, not a history**: `PollErpTool` does
`updateOrCreate` on `(business_id, kind)`, so there is exactly one row per business per kind and a
poll replaces it. There were two rows, both from 6 September.

| row | field | classification | action |
| --- | --- | --- | --- |
| overview | `payload.*` | **verified correct** - values agree with the ERP read again on 7 Sep, and `purchasing`/`stock_value` are explained in E.1/E.2 | none |
| overview | `as_of` column | **likely mapping defect, and provable** - the payload retained the true offset | superseded by the fresh poll |
| low_stock | `payload` (`[]`) | **source says empty** - corroborated by `meta.count: 0` and `low_stock_count: 0` | none |
| low_stock | `as_of` column | **verified correct by coincidence** - it was HQ's clock, which happened to agree with the ERP's instant to the second | superseded |

**Nothing was rewritten.** Both rows were replaced in the ordinary way by a fresh poll through the
corrected mapping, which is what this table is for. No historical value was fabricated, and no
uncertainty was papered over.

---

## I. The integrity guard

`FactsIntegrityChecker` asks only what money must answer:

| check | code |
| --- | --- |
| line totals sum to subtotal | `line_total_sum_ne_subtotal` |
| each line's `qty x unit_price` is its own total | `line_qty_x_unit_price_ne_line_total` |
| no free line on an order that charged | `zero_priced_line_on_paid_order` |
| `subtotal + shipping - discount = gross` | `subtotal_plus_shipping_minus_discount_ne_gross` |

The third is the shape of the defect that started all this. The first would have caught it too: the
lines said 0 while the order they arrived in said 999, and both numbers came from the same payload.

Design decisions worth stating:

- **It reports and never repairs.** Knowing a number is wrong is not knowing what it should be, and
  a checker that quietly closes the gap destroys the only evidence of the bug. A repair workflow can
  be approved separately.
- **An order with no line detail is counted apart, not called broken** - reconciliation legitimately
  produces those (docs/44 M.1), and flagging them nightly would train everyone to ignore the report.
- **A zero price is only suspicious on an order that charged**, so a genuine free line on a free
  order passes.
- **A discrepancy is a successful run.** The tool looked and found something; a failed run means the
  check could not be performed, which deserves a different colour in the job history.
- Half-paisa tolerance, so rounding noise is not reported as a defect.
- Bounded by window, store and row limit; read-only apart from one audit row per run.

Reachable two ways: `hq:facts:check` (exits non-zero on a discrepancy, so it works as a gate after a
release or a repair) and `commerce.check_fact_integrity` on the durable scheduler.

---

## J. Scheduler

| id | name | tool | cron | tz | next run (UTC) |
| --- | --- | --- | --- | --- | --- |
| 2 | LaLaDia order reconciliation | `commerce.reconcile_facts` | `0 2 * * *` | Asia/Dhaka | 2026-09-07 20:00 |
| 3 | **Order fact integrity check** | `commerce.check_fact_integrity` | `30 3 * * *` | Asia/Dhaka | 2026-09-07 21:30 |
| 4 | ERP overview snapshot | `erp.poll_overview` | `0 4 * * *` | Asia/Dhaka | 2026-09-07 22:00 |
| 5 | ERP low stock snapshot | `erp.poll_low_stock` | `5 4 * * *` | Asia/Dhaka | 2026-09-07 22:05 |

03:30 puts the integrity check ninety minutes after the reconciliation, so it reads facts that have
already been repaired rather than racing it. Its window is 168 hours - a week, long enough to catch
a defect that only shows up on an unusual order.

Jobs 4 and 5 are an addition beyond the brief, and are flagged as such: the ERP poll tools existed
since Phase 1 and **nothing ever ran them**, which is the same disease as the unscheduled
reconciliation, and §11 required a fresh poll that only the scheduler can perform. They are
read-only and can be paused or cancelled without touching code if Joy would rather HQ did not poll
the ERP nightly.

All run-now verified, all `succeeded`, no duplicates (five jobs, five distinct purposes):

```
job_runs 3  commerce.check_fact_integrity  {"checked":6,"ok":6,"without_lines":2,"discrepancies":0}
job_runs 4  erp.poll_overview              {"kind":"overview","as_of":"2026-09-07T11:56:32+06:00"}
job_runs 5  erp.poll_low_stock             {"kind":"low_stock","items":0}
```

---

## K. Releases and evidence

| release | contents | CI | deployed |
| --- | --- | --- | --- |
| `e5839d0` | `hq:erp:inspect` + `rawRead` | 83 passed | 05:30 UTC |
| `6e39d73` | ERP base URL default + integrity checker, tool and command | 94 passed | 05:40 UTC |
| `c8485a3` | `ErpContract`, `as_of` fix, `ErpLowStock`, real-payload fixtures | **105 passed, 560 assertions** | **05:55 UTC** |

Backups before each: `hq-20260907-053009.dump`, `-054026.dump`, `-055520.dump` (258 KB, 38 tables).
No migrations in any of the three.

**Production verified on `c8485a3`:** `/api/health` ok on all five checks; seven containers healthy;
Horizon running; no failed jobs; zero errors, exceptions or 5xx in the container logs; ERP probe
`{"ok":true,"business_id":4,"visible_businesses":1}`; fresh snapshots written with correct UTC
timestamps; `hq:facts:check` reports **6 checked, 6 ok, 2 without line detail, 0 discrepancies**.

The two `failed` rows in `connector_calls` at 05:30 are the 404s from the dead hostname - the
evidence of section B, kept rather than cleaned up.

---

## L. Rollback

- **Code:** `./deploy/rollback.sh 3140042` returns to the pre-milestone release. Note that this also
  restores the dead ERP hostname, so the ERP integration would break again; `6e39d73` is the
  intermediate point that keeps the hostname fix.
- **Database:** no migration ran in any of the three releases, so no restore is needed. The three
  dumps above exist if one is wanted.
- **Snapshots:** a rollback does not un-write them; the next poll replaces them either way.
- **Scheduled jobs 3, 4 and 5** can be paused or cancelled through the scheduler without code.

---

## M. Unresolved, and stated plainly

1. **`HQ_ERP_BASE_URL` is still unset in production `.env`.** The code default is correct now, but
   the address lives in a release rather than in configuration. Worth pinning explicitly.
2. **No captured low-stock row.** The list is empty today; the row-mapping test uses a shape read
   from the ERP's source. Re-capture when a product first goes below threshold.
3. **`purchasing` stays null** until Joy decides the HQ token should hold `purchasing.reports.view`.
4. **`stock_value` will stop being zero** when opening stock is entered, and should be re-verified
   against the ERP's own inventory screen at that point rather than assumed to still be faithful.
5. **Nothing watches snapshot freshness.** The ERP outage was invisible partly because a stale
   snapshot looks exactly like a fresh one. Now that the polls are scheduled, a check on
   `polled_at` age would close that gap - not built here, because it is a new mechanism rather than
   a correction.
6. **The ERP's other read endpoints** (`stock`, `products`, `customers`, `tickets`,
   `pendingApprovals`, `runReport`) are unaudited: nothing persists their output yet, so a silent
   default there cannot currently damage stored data. They need the same treatment before anything
   starts storing them.

---

## N. The lesson, restated for a second integration

The shop taught it and the ERP confirmed it: **the fixture is the trap, not the mapper.** A fixture
written from a design document will always agree with code written from the same design document,
and the pair will fail together, silently, against reality.

The ERP audit adds a second edge. Two of the three things that looked wrong were right -
`purchasing: null` and `stock_value: 0` were both the source telling the truth - and the thing
nobody had thought to question, a timestamp, was the one that was wrong. Auditing means reading what
arrived, not confirming what you suspected.

And the outage sharpens it further. HQ's most serious problem this morning was not a mapping at all:
it was an integration that had been dead for an hour with every dashboard still green, because
nothing on either side was obliged to notice.
