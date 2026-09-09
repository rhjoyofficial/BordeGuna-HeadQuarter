# 56 - M4 closed: the live safety gate passed (9 September 2026)

**Status: M4 is COMPLETE. A model has been called from production, once, deliberately, and
`ai.runtime` is off again. Nothing else about the safety posture moved.**

This is the closing record for the item `docs/54` left open. What M4 is, is in `docs/52`; how it was
deployed, in `docs/54`; the credential hardening that preceded this, in `docs/55`. None of that is
restated here.

| | |
| --- | --- |
| Production | **`2784663`**, unchanged by this exercise |
| First successful model call | **`ai_run` #4**, 9 Sep 2026 **07:30:02 to 07:30:03 UTC** |
| `ai.runtime` window | **07:29:54 to 07:30:18 UTC, 24 seconds**, one call inside it |
| `ai.runtime` now | **OFF**, row kept |
| Credential used | `anthropic_api_key` / `hq`, row **14** |
| Cost | **$0.000085** |
| Code changed | **none.** No commit, no migration, no configuration edit |

---

## A. The credential arrived, and this time it was verified before anything was tested

The rule written after 8 September is that a claimed replacement is checked in the audit trail
before a single request is sent, because twice the key HQ held was not the key Joy thought he had
stored. So the trail was read first:

Row **14**, active, payload present, created **07:15:13 UTC**. Row 13 revoked at **07:15:09** with
its payload nulled. Two audit records, both by `joy@bordeguna.com`: `credential.revoked` on 13, then
`credential.stored` on 14. Exactly one active `anthropic_api_key/hq`. Eight active credentials in
total, one per identity, zero duplicates.

⭐ Worth noticing about the hardening from `docs/55`: Joy revoked 13 by hand and then stored 14, four
seconds apart, so what ran was the plain create path into an empty identity. Row 14 carries no
`meta.replaces` and the revocation carries no "superseded by" reason, which is exactly what a manual
revoke-then-store looks like. The automatic replacement path has still not been exercised on
production by a real key change. The end state is correct either way, and the index held.

## B. The bypass test, which is what actually settled it

Run first, from inside the production `app` container, with `AnthropicProvider` out of the picture
entirely: open a vault scope, take the secret, and POST `api.anthropic.com/v1/messages` by hand with
`x-api-key`, `anthropic-version: 2023-06-01`, model `claude-haiku-4-5-20251001`, `max_tokens` 16.

The credential as the vault handed it over: **108 characters, `sk-ant-api03-` prefix, no
whitespace**.

**HTTP 200.** `msg_011CesUJoPjgPz8j7B4TfrY2`, reply `ok`, 14 input tokens and 4 output tokens.

That is the first request from this container that Anthropic has ever accepted. Four keys failed
before it, and the bypass test 401'd on both `x-api-key` and `Bearer` with the driver removed from
the path, which is what proved HQ was not at fault. The same test with a new key returning 200,
before HQ's own code was asked to do anything, is what makes the rest of this record meaningful:
whatever happened next could no longer be confused with a bad key.

## C. The gate, in the order it was run

**The dry probe first, with the runtime still off.** Provider `anthropic`, credential
**configured**, breaker **closed**. Four registered models across three tiers. Budget $0.0000 of
$50.00. Routing: task `diagnostic` to Claude Haiku 4.5 at the low tier, reason "task diagnostic
routes to the low tier". Nothing sent.

**`ai.runtime` confirmed off** by reading both the row (`enabled = false`) and
`Features::enabled('ai.runtime')`, which returned false.

**Enabled deliberately, with an audit record.** Audit log `290`, 07:29:54 UTC,
`feature_flag.toggled`, actor `system`, reason: "M4 live safety gate: ai.runtime enabled
deliberately for exactly one hq:ai:probe --live, on Joy explicit authorisation, to be turned OFF
immediately after."

**`hq:ai:probe --live`.** Reply: **"HQ model router is working."** Claude Haiku 4.5 (anthropic),
**35 in / 10 out, $0.0001, 1039 ms**, `ai_run #4`, status succeeded.

**Turned off immediately**, audit log `291` at 07:30:18 UTC with the reason recording that the row is
kept rather than deleted. The runtime was enabled for **24 seconds**.

## D. The `ai_runs` row, field by field

`task` diagnostic · `provider` anthropic · `model` claude-haiku-4-5-20251001 · `model_tier` low ·
`prompt_version` probe/1 · `input_tokens` 35 · `output_tokens` 10 · `cached_tokens` 0 ·
`cache_write_tokens` 0 · `latency_ms` 1039 · `estimated_cost_usd` **0.000085** · `status` succeeded ·
`error` null · `attempt` 1 · `degraded_from_tier` null · `routing_reason` "task diagnostic routes to
the low tier" · `input_ref` hq:ai:probe · started 07:30:02, finished 07:30:03.

The cost is not merely present, it is right: 35 input tokens at $1.00 per million is $0.000035, 10
output tokens at $5.00 per million is $0.000050, and the row says $0.000085. Cache read and write
are both zero, which is correct for a first call with no cached prefix. `actual_cost_usd` and
`cost_source` are null, as designed: the row records what HQ computed, and a reconciled figure would
have to come from a billing source HQ does not have.

**`hq:ai:cost`** agrees across all four of its breakdowns: by task, diagnostic 4 runs and $0.000085;
by agent, `(none)`; by business, `(none)`; by status, **succeeded 1 with 35/10 and failed 3 with
zeroes**. Cap: **$0.0001 of $50.00, 0%**. The `ai_budgets` table is empty, so the $50 ceiling is
`AiBudget::DEFAULT_GLOBAL_MONTHLY_USD` applying because no row overrides it, not a value read from
the environment.

⭐ The three earlier failures still sit there with zeroed counts and no cost. A failed call that
still writes its row, with its routing reason and its zeroes, is what made this afternoon's
diagnosis possible at all.

## E. No leakage

`sk-ant` appears **zero times** in the last four hours of logs for all seven containers, and there
is no Laravel log file inside the app container to hold it. Zero rows in `ai_runs`, `tool_calls`,
`audit_logs`, `job_runs` and `connector_calls` contain the string in any column that could carry it.
The probe's own output prints the reply, the token counts and the cost, and never the key.

## F. The posture, after the gate

`ai.runtime` **off**, row present. `scheduler.gateway` still the only other flag, true.
**Agents: 0.** Seven tools, none agent-executable, the only R4 tool disabled, no R5 registered.
Approvals unchanged at 12. Active credentials still 8, one per identity. Failed queue jobs 0. Seven
containers healthy. The dry probe run again afterwards routes and refuses to send, exactly as
before.

The kill switch itself was not re-tested by another live attempt, deliberately: the CI suite already
covers that the runtime ships off and that a test wanting a model call has to turn it on, and
spending an unauthorised second live call to re-prove it would be the wrong trade.

## G. M4 is closed

Everything M4 promised is now built, deployed and demonstrated on production: the model router, the
tier table, cost accounting, the fail-closed runtime, and a real provider call that produced a real
answer, a real token count and a real cost.

**The next milestone is not started.** Phase 2.5 and M5 remain closed, no customer-facing AI, no
autonomous execution, no R4 or R5 capability enabled, and no agent exists to run any of it.
