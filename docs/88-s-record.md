# 88-S - Stream S record: the Phase 5 ads read side, built inert, and ADR-050 accepted (22 September 2026)

**Instruction:** `docs/88` A points 1, 4, 8, 20 and 21 (ChatGPT, 22 September). Built 22 September, finished and verified 26 September after the session that started it was cut. **Branch:** `p5/ads-read-side`,
worktree `hq-wt/h`, test database `hq_test_h`. Built on `main` at `37a2be6`. Local verification only; GitHub
Actions validation is deferred until the 1 October reset.

An analogy first. Until now HQ could see the shops and the ERP but not the advertising. This stream gives HQ
eyes on the ad accounts, and deliberately no hands. It can read what an ad account says (the currency, the
owner's spending limit, the campaigns, the daily spend) and copy it into its own records with the date and the
source on every figure. It cannot pause, change, start or pay for anything, and today it cannot even read,
because four separate locks are still closed: no tool is registered, no agent holds one, no credential exists,
and the Meta connection itself is not plugged in. Opening them is gate P5-G1, with the owner's own token.

## 1. ADR-050: accepted

`docs/adr/050-paid-media-moves-money-only-by-a-persons-decision.md` now reads **ACCEPTED (22 Sep 2026)**, with a
new section "Clarifications on acceptance" carrying all eleven clarifications of `docs/88` A point 4. Where
each is held in what this stream built:

| Clarification | Where it is held |
| --- | --- |
| A person decides any change to spend or live delivery | No write tool exists at all (`AdsInertPostureTest`: only four ads tool classes, none writes at the platform) |
| No tool for payment methods, billing, legal identity, account deletion, people, ownership, the hard ceiling | `App\Ads\AdsHumanOnly` names them; `AdsInertPostureTest` reads every tool class in the application and fails if any key is one of them |
| Platform limits are observed, never rewritten | The account spending limit is read into `facts_ad_accounts.spend_cap_minor`; the adapter can only GET |
| Reads are R2 | `ads.read_account`, `ads.read_campaigns`, `ads.read_insights` are R2; the sync is R3 (it writes HQ's own facts only) |
| Recoverable changes approval-gated with precondition, proposal, read-back, reconciliation | Not built (gate P5-G2); `AdBudgetGuard::fits()` is the precondition such a tool will check |
| Irreversible actions HUMAN_ONLY | Same list as above; nothing built can do them |
| Raise budget and activate R5 | Not built |
| Customer-list upload R5 with consent, not first release | Not built |
| The owner's ceiling never infers authority | `fits()` says "no" whenever anything is unknown, and a "yes" is a precondition, never an approval |
| Credentials separate from HQ authorisation | The token is opened only by the gateway's vault for a declared call, and the gateway still decides |
| MCP only behind the gateway | Nothing here speaks MCP; the provider contract is the only way in |

## 2. What was built

- **A provider-neutral read contract.** `App\Platform\Contracts\AdsReadPlatform` (a provider that can hand out
  a reader for one account) and `App\Ads\AdsReader` (account, campaigns, ad sets, ads, daily insights; reads
  only, every figure in the account's own currency). Nothing above them names Meta.
- **The Meta read adapter.** `App\Ads\Meta\MetaAdsPlatform` (platform `meta_ad_account`, the name the seeded
  account row already carries; its ceiling is the two read operations only) and `App\Ads\Meta\MetaAdsReader`
  (GET only, the token as a bearer header and never in a URL, pagination by cursor, Meta's errors classified,
  Meta's usage headers read and the next call refused at 80 per cent usage, every call recorded in
  `connector_calls` as `meta_ads:<business>`).
- **Exact money.** `App\Ads\Dto\AdMoney`: basic units to decimals by the currency's offset (BDT and USD, 100),
  exact sums, no float anywhere, an unknown currency refused, more decimals than a currency has refused.
- **The read models.** Migration `2026_09_22_305000_create_ads_read_models` (additive, two new tables):
  `facts_ad_accounts` (currency, time zone, status, the owner's limit and amount spent as last read) and
  `facts_ad_spend` (one row per object per day per level, with business, brand, a nullable store, provenance
  `third_party`, source, API version, fetch time and the correlation id of the read).
- **The sync, defined and not scheduled.** `App\Ads\AdSpendSync` and its tool `ads.sync_ad_spend` (R3):
  reads everything first, writes in one transaction, idempotent, refuses a row in a currency other than the
  account's.
- **Three read tools and the sync tool**, in `app/Tools/Ads`, all behind `App\Tools\Ads\AdsTool`:
  business-scoped, `business_slug` required, the credential `meta_ads_token` per business, the account chosen
  by the capability router (two candidates refused, an account with no recorded grants refused), provenance on
  every answer. Permission `hq.tools.ads.read`, added to the permission list as reserved.
- **The budget guard.** `App\Ads\AdBudgetGuard`, reads only: per account, the owner's limit and what remains
  under it; for the business, HQ's own monthly budget (policy key `ads.monthly_budget`, rules `{amount,
  currency}`, set by the owner) against month-to-date spend; `fits()` answers yes only when every ceiling is
  known, fresh (six hours), in the same currency and has room.
- **The contribution report.** `App\Ads\Contribution\ContributionReport`: delivered net goods revenue less the
  cost of those goods less ad spend, per campaign and in total. Delivered orders come from a
  `DeliveredSalesSource` (today `facts_orders`, the shops' own delivered state), cost prices from a
  `CostPriceSource`. A missing cost makes the contribution blank with the SKU named; Meta's purchase count is
  never revenue; currencies are never mixed; spend with no attributed order is shown as a loss of that spend.
- **Configuration:** `config/hq.php` `ads.meta` (base URL, API version v26.0, timeout, back-off 80 per cent),
  plain values, no new environment keys.

## 3. Why it is inert, lock by lock

| Lock | State | Held by |
| --- | --- | --- |
| No ads tool registered | None of the four classes is in `config('hq.tools')`; the gateway answers "Unknown tool" and sends nothing | `AdsInertPostureTest` |
| No agent holds one | `agents.allowed_tools` untouched, no `ads.*` anywhere | `AdsInertPostureTest` |
| No credential | No `meta_ads_token` row | `AdsInertPostureTest` |
| No provider registered | `PlatformRegistry` stays empty; even a registered, credentialed call is refused at the router ("HQ has no provider implementation for [meta_ad_account]") and sends nothing | `AdsInertPostureTest` |
| Nothing scheduled | No `Schedule::` entry mentions ads; no `scheduled_jobs` row; filing one for the sync is refused because the tool is not registered | `AdsInertPostureTest` |
| No write at the platform | Ceiling is the two reads; the adapter's source contains no verb but GET; every faked request in every test is a GET | `AdsInertPostureTest`, `MetaAdsReaderTest` |

**Behaviour change for Gate 13 (point 8): none.** No agent's tool list, prompt, role policy, runtime path or
argument validation changed; the new tools are held by nobody and registered nowhere.

## 4. Meta facts, and what was not verified

Read from Meta's own pages on 22 September 2026: the Ad Account reference (`account_status` codes; `spend_cap`
and `amount_spent` "in basic unit of the currency, for example 'cents' for USD"); the Currencies page (BDT and
USD offset 100); the Campaign reference (budgets and `spend_cap` in the currency's subunit; status and effective
status values); the Ad Account Insights reference (`level` ad, adset, campaign, account; `time_range`;
`time_increment`; the fields read); the Graph API changelog (v26.0 released 29 July 2026, sunset not announced;
v24.0 ends 6 October 2026); rate limiting (the three usage headers and their fields; throttling codes 4, 17,
613, 80000 to 80014); error handling (the error body; 190 token expired; 10 and 200 to 299 permission; 1 and 2
temporary); pagination (`paging.next` absent means the last page).

Not verified on those pages, and built so that being wrong fails loudly rather than quietly: that insights
`spend` is in the major unit (Meta's examples show it; `AdMoney::major` refuses more decimals than the currency
has); that an account with no spending limit answers `"0"` (read as "no limit set", which the guard treats as
unknown, so the reading can only make HQ more cautious); that the Graph API accepts the token as an
`Authorization: Bearer` header (the OAuth form; if not, the first P5-G1 call fails with code 190 and says so).

## 5. Verification

- New tests, all on `hq_test_h`: `MetaAdsReaderTest` (20), `AdsInertPostureTest` (8), `AdsReadToolsTest` (7),
  `AdBudgetGuardTest` (7), `ContributionReportTest` (5): 47 tests. The eighth posture test (added 26 September,
  when the stream was resumed after the 22 September session was cut) pins the read contract's exact public
  surface: `AdsReader` has the six read methods and `AdsReadPlatform` the four of a provider, and no public
  method on the reader, the read platform or the Meta implementations opens with a verb that changes anything
  at the platform. A write method added to the contract is a red test, never a quiet widening.
- Mutations, each applied to the built code, the relevant file run, the code restored; all 12 caught:
  the provider registered in the application; a read tool registered in `config/hq.php`; the adapter sending a
  POST; basic units read as major units; an ad-account throttle read as a refusal; an unknown cost costed at
  zero; "no owner limit" read as room; the guard writing a row (the first version of this mutation, a
  `touch()` on a row created in the same second, wrote nothing and so survived; replaced by a real write, caught);
  the sync scheduled; currencies mixed in the contribution; the provider ceiling including a write; stale
  account facts trusted. A thirteenth on 26 September: a `pause()` method added to `AdsReader` and its Meta
  implementation (caught by the contract test; the original seven posture tests all still passed with it, which
  is why the eighth exists).
- **Verified on the branch, 26 September, on the whole tree** (local; GitHub Actions deferred to 1 October):
  full Pest suite on `hq_test_h` **1322 passed, 13,086 assertions, 0 failed** (468 s; `main` at the branch's base
  `37a2be6` had 1275, plus the 47 here; `main` has since gained stream U's 37, which are not in this tree),
  `vendor/bin/pint --test` passed, `npx tsc --noEmit` clean, `npx vite build` built. No file under
  `resources/js` changed. One additive migration (two new tables), nothing dropped or altered.

## 6. Status (point 21)

| | implemented | merged | registered | credentialed | live-called | agent-held | agent-granted | activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Stream S, ads read side | yes | no (branch, for review) | no | no | no | no | no | no |

## 7. What Joy provides later, and why

1. **Meta Business Verification** (`docs/82` S1): Meta requires it for advertising and developer features.
2. **The Meta app with the Marketing API product** (O-2): the only door into the ad accounts.
3. **A system user in the official business portfolio**, assigned to the ad accounts with ads read permission,
   and its token stored on `/credentials` as provider `meta_ads_token`, account reference the HQ business slug
   (O-3): a connection that belongs to the business, not to a person's profile.
4. **Which ad account belongs to which brand, and its currency** (O-5): the records disagree (BDT in `docs/16`,
   dollars on the LaLaDia account); the router refuses two candidates and HQ never guesses.
5. **A spending limit on every ad account HQ will read** (O-6): the outer ceiling; with none set, `fits()`
   says no to everything.
6. **A monthly advertising budget per business** (a decision): stored as policy `ads.monthly_budget`; without
   it, `fits()` says no.
7. **ERP cost prices** (O-8): without them every contribution is blank, by design.

## 8. Open items

1. **ERP cost prices are not readable by HQ.** The HQ read contract (`hq-order-reads.v1`) carries no cost, so
   the report's default cost source is `UnavailableCostPrices` and every contribution is blank with the reason.
   A cost-price read is a contract change on both sides (ERP endpoint plus an HQ `CostPriceSource`); it belongs
   with the ERP work, and the report needs no change when it lands.
2. **Delivered orders come from `facts_orders`** (the shops' own delivered state). Orders sold through the ERP
   itself are not in it; an ERP delivered-orders source is another `DeliveredSalesSource` when needed.
3. **Store scope is null** on every spend row until an experiment names the store it promotes (`docs/84` C.1).
4. **Registering the reads (gate P5-G1)** is: the Meta provider added to `PlatformRegistry`, the three read
   tools added to `config/hq.php`, the account row's grants recorded by a person, the token stored, the sync
   scheduled hourly, and three days of totals reconciled with Ads Manager. Each is a reviewed release.
