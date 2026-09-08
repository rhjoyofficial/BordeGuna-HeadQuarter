# 52 - Phase 2 M4: the Model Router and AI cost accounting (8 September 2026)

**Status: built, merged and DEPLOYED to production on 8 September 2026. The live safety gate has not
yet been run, because it needs a provider API key Joy has still to supply (Q4).**

**This document is the BUILD record: what M4 is, and the defects found while building it. Its
findings below are preserved as written and are not revised by later events.** The production
deployment evidence is a separate document, `docs/54`, so that what was built and what was released
stay distinguishable.

M4 of the Phase 2 Command Center (`docs/48`), following M1 (`docs/49`), M2 (`docs/50`) and M3
(`docs/51`). M1 gave HQ one door for tools, M2 gave that door a real policy engine, M3 gave it a
human. M4 gives HQ the ability to **call a model** - deliberately, once, at a chosen tier, with the
cost written down. It gives it no ability to act on the answer.

| | |
| --- | --- |
| Starting release | **`ff34d7d`** (M3 + the M3 UI findings, PR #5) |
| Ending release | **`ae41291`** (PR #6, merged 8 Sep 11:55 UTC) |
| Production | **`ae41291`, released 8 Sep 12:08 UTC** (previous `ff34d7d`). See `docs/54` |
| CI | **286 passed / 1385 assertions**, from 237 / 1217 at M3. Inherited red at 20 failed / 256 passed |
| Migrations | 1, additive, reversible, **never run anywhere yet** |
| Shop or ERP code changed | **none** |
| Model provider connected | **none.** No model has been called |
| Tools enabled at R4 or above | **none** |
| Tools that are agent-executable | **none** |

---

## A. The one sentence M4 had to make true

*HQ can call a model, choose which one deliberately, and account for what it cost - and the model
still cannot do anything but return text.*

Both halves matter. The first is the capability. The second is the constraint, and it is asserted by
a test rather than promised in prose: `ModelRuntime` is grepped for `ToolRegistry`, `ToolGateway`,
any ERP or commerce connector, `DB::`, `Artisan::`, `shell_exec` and `proc_open`. Its dependencies
**are** its capabilities. The day one of those appears, the model has stopped being a proposer and
the build fails.

Nothing an agent might later propose bypasses M1 through M3. A model produces text; if that text is
ever turned into an action, the action goes through the Tool Gateway under a human's identity,
subject to policy, and above R3 subject to an approval. M4 changes none of that.

---

## B. Why the router refuses rather than substitutes

`ModelRouter::for(TaskType, RoutingContext)` is the only place a model is chosen. Business code names
a **task** - `briefing_narrative`, `classification`, `nl_to_job_spec` - and the router turns that into
a tier, then a row in `model_tiers`. A caller cannot name a model, a provider, or a price.

That monopoly is not tidiness. Cost accounting is only ever as accurate as the router's exclusive
knowledge of what actually ran; a second path that picked its own model would make `ai_runs` a
sample rather than a ledger. ADR-019, and a grep test fails the build if a model id appears anywhere
in `app/` outside `app/Ai/Providers/`.

The order of consideration: the task's tier unless the budget says to degrade; then candidates at
that tier by priority that meet the required capabilities and context size, whose provider is
configured and whose breaker is closed; then the tier below, **but only if the task is degradable**;
then refusal.

⭐ **The refusal is the part worth defending.** Quietly running on whatever happens to be available is
how a briefing silently gets worse with nobody knowing, which is the exact outcome a router exists to
prevent. A refusal is visible. A substitution is not. When a call does degrade, the run records
`degraded_from_tier` and `AiOutcome::provenance()` says so on the output's face.

Schema repair and embedding are **not** degradable. Repair exists to fix output a model already got
wrong, and a weaker model is the last thing that should be asked to do it; an embedding from a
different model is not comparable with the vectors already stored, so `TaskType::Embedding` refuses
loudly instead. No embedding model is registered - Anthropic does not offer one - so the specialised
tier is deliberately empty rather than quietly filled with something incomparable.

---

## C. What the model catalogue holds

`model_tiers` carries the id, the context window, the capabilities and **the prices**, each row
stamped with `pricing_verified_on` so a later reader can judge staleness without trusting the row.
Everything below was read from the provider's own reference on 8 September 2026, not recalled.

Haiku 4.5 at LOW for classification, extraction and repair. Sonnet 5 at MEDIUM, the working tier.
Opus 5 at HIGH for the briefing narrative and multi-step reasoning.

⚠ Opus 5 is **also** registered at MEDIUM with a worse priority, so if Sonnet is unavailable the
medium tier degrades *upward* in capability at a higher price rather than downward in quality.
Paying more to keep working is the right trade for the tier the morning briefing depends on.

The seeder is idempotent and non-destructive: it upserts by `(provider, model_id, tier)` and sets
`is_active` only on create, so re-running it cannot switch a model back on that an operator
deliberately disabled.

---

## D. The defects that mattered

M4 was inherited with **20 failing tests and a red PR**. Four of the six findings below were behind
that; two were not visible in CI at all and would have surfaced only against a live provider.

### D.1 The budget refused every call that reached it

`AppServiceProvider` calls `Date::use(CarbonImmutable::class)`, so `now()` returns a
`CarbonImmutable` - which is **not** a subclass of `Illuminate\Support\Carbon`.
`AiBudget::periodStart()` was typed as the latter, so it threw a `TypeError` on the first budget read
of every model call. That was 18 of the 20 failures, and they presented as unrelated router and
runtime bugs, which is why the count looked so much worse than the cause.

### D.2 ⭐⭐ The structured-output schema was sent in a shape the API no longer reads

The driver sent the JSON schema as a top-level `output_format`. That parameter is **deprecated
API-wide**; the canonical one is `output_config.format`.

This is the finding that would have hurt most, because of *how* it fails. An unrecognised top-level
key is not rejected. The call would have **succeeded** with the constraint silently dropped - the
reply merely likely-shaped rather than guaranteed - and the only symptom would be an occasional
repair, or a `StructuredOutputFailed` that reads like the model being bad at JSON. It would have been
diagnosed as a prompt problem for as long as anyone cared to look.

Two tests now pin the outgoing request body: `output_config.format` present, `output_format` absent,
and the system block marked cacheable. The assertion is written against the provider's documented
shape, not against our own idea of it. That is the lesson the live-webhook fixture taught in Phase 1
and `docs/45` recorded: a fixture written from the same design as the code will agree with it, and
both can be wrong together.

### D.3 ⭐⭐ A cache write was billed as ordinary input

`cache_write_price_per_mtok` was seeded on every model row and **read by nothing**. The driver folded
`cache_creation_input_tokens` into `inputTokens`, and the calculator charged the whole non-read
remainder at the base input rate - so every call that wrote the prompt cache under-reported its own
cost by the 25 per cent write premium.

This is the same error `CostCalculator` already guarded against on the *read* side, pointing the
other way, and it is the more dangerous direction: an over-report gets questioned, an under-report
gets believed. Caching is on by default and the system prompt is the cached block, so the first call
with any new prompt pays a premium nothing recorded.

`ModelResponse` now carries `cacheWriteTokens` as a subset of `inputTokens` that never overlaps
`cachedTokens`; the calculator prices the three portions separately and clamps them so they can never
sum past the input total; `ai_runs.cache_write_tokens` stores it so a row can re-derive its own cost;
and `hq:ai:cost` shows reads and writes as separate columns, so the premium is visible rather than
merely recorded.

### D.4 The retryable-versus-refusal test proved nothing

It called `Http::fake()` three times for the same URL. ⚠ **`Factory::fake()` MERGES its stubs and the
first match answers**, so all three assertions were served the first 529 and the `ProviderRefused`
case tested nothing at all. One `fakeSequence` instead. This is a known Laravel trap, found again in
a new place.

### D.5 The safety gate passed on an empty reply

`hq:ai:probe` reported SUCCESS whenever the call did not throw. On a tier served by a model that
reasons before answering, a small `max_output_tokens` can be spent entirely before the first visible
token; the gate would then certify a path that never delivered a word. It now fails, and says where
the tokens went.

### D.6 Two owners for one cap

`BudgetLedger::aiSpendThisMonth()` was written in M2 in anticipation of M4. M4 arrived with
`AiBudget` as the real owner, and the M2 method had no callers and summed `estimated_cost_usd` alone
where `AiBudget` coalesces the reported cost over the estimate. The two would have disagreed the
moment a reconciliation wrote an actual cost, and whichever a future caller reached for would have
been a coin toss. Removed.

### D.8 HUMAN_ONLY could not be told apart from "not yet cleared"

Pulled forward from `docs/53` on Joy's approval, because it is cheap before the tool count grows and
archaeology afterwards. A tool declaring `agentExecutable: false` reads as `ActionClass::HumanOnly`
and cannot be lifted by any approval, which is correct. What it could not say is **why**: all seven
registered tools are false because none has been cleared yet, not because none may ever be.

`ToolDescriptor` gains `permanentlyHumanOnly`, defaulting false, so nothing existing changes and no
behaviour changes. The refusal now names which case applies, splitting the reason code into
`action_class.human_only` and `action_class.human_only_permanent`, so the difference reaches the
audit trail rather than staying in a boolean nobody prints. The contradiction of declaring both
permanent and agent-executable throws at construction. ADR-025.

### D.7 Two test defects of the ordinary kind

`ai_runs.business_id` is a real foreign key and two spend tests inserted rows against ids 1 and 2
without creating either; nothing had costed a run against a business before, so the constraint had
never been exercised. And a repair-prompt assertion called `end()` on a readonly property - `end()`
takes its array by reference, and PHP will not hand out a reference to one.

---

## E. How a call is recorded

`ModelRuntime` is the only place in HQ that calls a provider, and every attempt writes its own
`ai_runs` row **including the failed ones**, because a fallback that leaves no trace is
indistinguishable from a first-choice success. A row carries the correlation id, the agent key, the
business and brand, the task, the provider and model, the tier and what it degraded from, the routing
reason, the token counts in all four categories, latency, and the estimated cost.

⚠ `actual_cost_usd` stays NULL. It is filled only by a future reconciliation against the provider's
own billing; writing our own estimate into it would make the two columns agree by construction and
destroy the only check they exist to provide.

Cost arithmetic is done in string decimals through `bcmath`, never floats. Per-million-token prices
multiplied by token counts and summed across thousands of calls is exactly the shape where float
error becomes visible money.

Structured output gets **one** repair attempt, and both calls are billed to the run - recording only
the successful one would make repairs look free and remove the incentive to fix the prompt. HQ never
patches a malformed object into shape: filling in a missing field produces an object that is partly
invented and then flows onward as though a model had said it.

---

## F. The budget is soft, exactly as decided

Decision **V.8** asked for a soft advisory cap. It is soft: at 80 per cent of a cap it notifies, at
100 per cent it degrades the tier, and it does not refuse - because a cap that silently stops the
morning briefing is a worse failure than the overspend it prevents. The warning says what will
*happen*, not merely that a number was passed.

A `hard` enforcement value exists for when Joy asks for one, and nothing sets it. Even then the
refusal is announced rather than silent. The default global cap is USD 50 a month; a business with no
row of its own is bounded only by the group cap, because inventing a per-business number would be a
policy decision made by a default value.

The per-agent cap is deliberately **not** here - it already lives on the `agents` row and is enforced
by the policy engine before a tool ever runs.

---

## G. The circuit breaker, and the one thing it must not get wrong

Four consecutive failures inside a five-minute window open a provider for two minutes, after which
one call is let through as a probe. State lives in the cache, not the database: it is operational, it
is read on every call, and it should evaporate on its own.

⚠ **Only `ProviderUnavailable` trips it.** A `ProviderRefused` means HQ sent something broken, and
counting that as an outage would take a perfectly healthy provider out of rotation because of a bug
on this side. That distinction is the thing a naive breaker gets wrong. It is why the driver
separates a 429 or 5xx from a 400 or 404 in the first place, and why the runtime does not try a
second provider after a refusal: it would only earn a second refusal while burning another call.

---

## H. The credential boundary held

The M2 rule is unchanged and now covered by its own test: `CredentialVault` is the only decryption
point, and a model provider is not an exception to that because it is new. `AnthropicProvider` asks
the vault for the key inside a scope the runtime opened, and the test asserts the driver's source
contains `$this->vault->secret(` and does **not** contain `IntegrationCredential`, `->payload`, or
`env('`.

Provider keys are `integration_credentials` rows added from the UI, never env values.

⚠ A client exception can embed the request, and the request carries the key - so a non-connection
throwable is reported by **class only**, never by message.

---

## I. The safety gate has NOT been run

`docs/48` sets the M4 gate as: one real call, end to end, recording a complete `ai_runs` row with
correct token counts and cost.

**It cannot be run yet**, for two independent reasons. M4 is not deployed, so the production HQ has
no `hq:ai:probe` command to run at all. And the gate needs an `anthropic_api_key` credential row: no
model provider has ever been connected (`docs/51`), and until one is, `AnthropicProvider::available()`
is false and the router refuses. That was the documented dependency on Joy (Q4) from the start, not a
slip.

> **STATUS UPDATE, 8 September 2026, 12:08 UTC.** The first reason is gone: M4 is deployed and
> `hq:ai:probe` ran dry on production, correctly reporting `credential: NOT CONFIGURED` and refusing
> to route. The second stands. The gate is still pending the API key. Evidence in `docs/54`.

What is ready for it: `hq:ai:probe` defaults to `--dry` and needs `--live` typed deliberately, so
running the command can never spend money by accident. Dry mode reports the providers, their
credential and breaker state, the registered models with prices, the budget position, and the routing
decision with its reason - all without sending anything.

⚠ And the runtime ships **OFF**. `ai.runtime` is a feature flag, not config, so it can be turned off
during an incident without a deploy, and `Features::enabled()` returns false when no row exists. HQ
ships able to call a model and not doing so.

**To complete the gate, in order:** add the Anthropic API key as an `integration_credentials` row
(`provider: anthropic_api_key`, `account_ref: hq`) · run `ModelTierSeeder` · enable the `ai.runtime`
flag · `hq:ai:probe` dry first, then `--live` · check the `ai_runs` row · `hq:ai:cost`.

---

## J. Known limitations, none of them bugs

- **`actual_cost_usd` is never written.** There is no billing reconciliation yet, so every figure in
  `hq:ai:cost` is an estimate from token counts. The column and `cost_source` exist so the two can be
  compared when a reconciliation is built; they are deliberately not equal by construction.
- **`BudgetLedger::checkAgentBudget()` sums `estimated_cost_usd` alone**, not the coalesced cost.
  Harmless today because `actual_cost_usd` is always NULL, but it should move to the same COALESCE
  the moment a reconciliation starts writing one, or the per-agent cap will quietly under-count.
- **`stop_reason` is not stored on the run.** `ModelResponse::truncated()` exists and the probe now
  refuses an empty reply, but a truncated-yet-non-empty answer is not distinguished in the data. It
  wants a column, which is an M5 concern once something actually consumes long output.
- **The probe makes one call, not one per configured provider.** With a single driver registered the
  distinction is currently theoretical.
- **`hq:ai:probe` and `hq:ai:cost` had no tests at all** when this milestone was reviewed. They have
  three now: a dry run makes no call and writes no row, a `--live` probe refuses while the runtime
  flag is off, and the cost report renders before any model has been called. What is still untested
  is the `--live` path itself, which cannot be exercised without a real key.
- **Approve-with-edit is still never exercised on production** (carried over from M3), and
  `/approvals` still wants Joy's eyes on the rendered screen.

---

## K. Rollback

M4 is behind a flag and nothing depends on it. Turning `ai.runtime` off stops every model call
without a deploy. The migration is additive and reversible; it has never run anywhere, so the
rollback target is simply `ff34d7d`, which is also what production is running today.

> **STATUS UPDATE, 8 September 2026.** The migration has now run on production, once, as batch 7.
> It remains additive and reversible and the rollback target is unchanged at `ff34d7d`
> (`./deploy/rollback.sh ff34d7d`). The faster remedy is still the flag: `ai.runtime` off stops
> every model call without a deploy, and it is off.

---

## L. The M5 boundary, explicitly

M5 is the Executive and Analytics agents, read-only, shadow first. Nothing in M4 anticipates them
beyond leaving the seam clean: no agent exists, no tool is agent-executable, no prompt files are
under version control yet, and no context assembly has been written.

The tests that must keep passing into M5 and beyond: no model id in `app/` outside the driver layer ·
no provider endpoint outside the driver layer · no credential read outside the vault · every tool
non-agent-executable · nothing at R4 or above except the disabled `demo.request_approval` · and the
model runtime unable to reach a tool, a shop, the ERP or the database.
