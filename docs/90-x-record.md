# 90 X - Stream X record: the raw model reply kept, the argument contract fixed at the boundary, and the Executive Execution-Integrity Check (26 to 27 September 2026)

Branch `fix/model-reply-capture-and-argument-contract`, worktree `hq-wt/e`, test database `hq_test_e`, from
`origin/main` at `43d8782` (release `40880f9` plus documentation). Instruction: `docs/90` A.1 steps 1 and 2,
A.2, A.7 and A.9; the diagnosis it answers is `docs/88` C.11. Built across two sessions cut by the usage limit,
with a labelled WIP checkpoint (`b581c4e`) pushed between them so nothing was lost.

An analogy first. A clerk is told to fetch a file and hands the runner a slip with the file number in an
envelope marked "arguments". The runner only ever opened envelopes marked "args", so the slip never
reached the archive, the archive answered "which file?", and the clerk said so. Nobody could prove where
the slip went because nobody kept the envelope. This stream keeps the envelope (the raw reply), makes the
runner open every envelope by whichever name a model writes on it (the boundary), and adds an exam that
checks, slip by slip, that what the clerk wrote is what the archive received (the integrity suite).

## What was built

**1. The raw model reply, kept beside every run (A.1 step 1).** A new table `ai_run_replies` (migration
`2026_09_26_100000`, additive, one table) and `App\Ai\ModelReplyRecorder`, called by `ModelRuntime` at
every point a STRUCTURED reply exists: the accepted reply (`final`), a malformed first reply before its
repair (`invalid`), the repaired one (`repaired`), and a reply cut off at the output limit (`truncated`).
Each row keeps the text as the model wrote it (`raw_text`, the model's own keys in the model's own order),
the decoded object (`raw_json`; a jsonb column, so its key order is Postgres's, not the model's), the
runtime's reading kept SEPARATELY (`normalised`: the canonical tool calls and the status), and the
identity of the call: run, correlation, agent, provider, model, prompt version, attempt, stop reason. The
run's `output_ref` (present since the first migration, never filled) now points at the row. Secrets: the
value of any secret-named key the model wrote (`api_key`, `token`, `password`, the gateway `Redactor`'s
own list) is masked before storage, every other key kept exactly. Retention: the table cascades from
`ai_runs`, whose retention policy already keeps an agent's and an evaluation's runs. Historical runs get no
row. Free-text calls (a briefing narration) keep nothing here: the evidence this exists for is a tool call.

- **Never an authorization source.** `ModelReplyRecorder` writes; nothing reads. A static test walks
  every file under `app/` and fails if any file but the recorder, the runtime and the model names the
  table or the model class; a dynamic test replaces the recorder with one that throws and proves the
  model call still succeeds with no row kept (`ModelRuntime::keep()` reports the loss and goes on).

**2. The argument contract, fixed at the provider-to-HQ boundary (A.1 step 2).** Two new classes under
`App\Ai\ToolCalls`: `ProposedToolCall` (tool, arguments, why, `argumentsKey`, the raw call) is the one
canonical shape; `ToolCallNormaliser` is the boundary that produces it from the validated structured
reply, accepting the payload under `arguments` (the catalogue's own name), `args`, `input`, `parameters`
or `params`, decoding a payload a model stringified, and treating a JSON list or anything else as NO
payload. `AiOutcome::toolCalls()` exposes the list; `AgentRunner` reads that and nothing else (the old
`proposedCalls()`, which read `$reply['tool_calls'][*]['args']`, is gone). The tool descriptor stays the
schema authority: the normaliser validates nothing; the gateway's `ArgumentValidator` still judges every
argument, and no key is discovered from prompt text.

- **`argumentsKey` null means "the model wrote no payload"**, and that is not the same as "the model
  wrote an empty payload". The scope expansion (`docs/77`) still turns a present-and-empty payload on a
  group-wide question into one scoped call per business; a call with NO payload now goes to the gateway
  as it came and is refused there with the missing argument named (`scope_denied`), never given
  `{business_slug}` in place of what the model did not write. That default is exactly what every Support
  call in P3-G2 reached the gateway as.
- **Missing or malformed required arguments** are refused by the validator with its exact reason,
  recorded on the run as a refused proposal carrying the arguments as written, and nothing executes.
- **The record on the run** (evidence, proposal and refusal rows) now carries `arguments_key` and
  `raw_arguments` beside `args`, so a reviewer reads raw, read and validated without inferring one from
  another.
- **A defect the mismatch had hidden, fixed on the way.** `facts.query_orders` declares its `statuses`
  and `sources` filters as lists with `items` and `maxItems`, and `ArgumentValidator` refused ANY call
  that supplied one ("Tool input schema uses unsupported keyword [items]"), because it validates a
  deliberate subset and refuses a keyword it does not know. No call had ever supplied one, so it was
  never seen. The validator now checks a list's length against `minItems` / `maxItems` and each element
  against the scalar rules in `items`, refusing a nested list or object or an unknown item keyword as
  loudly as before. This is a validator widening, not a prompt or safety change; the subset stays
  closed.

**3. The Executive Execution-Integrity Check (A.2).** `Database\Seeders\ExecutionIntegritySuiteSeeder`
seeds `regression.execution_integrity`: kind `regression`, Executive-scoped, active, permanent, twelve
blocker cases, every one in role and answered, every one asking a question the Executive cannot answer
without an argument beyond the business HQ supplies: a business by name, a store by its domain, a stated
date range, a status filter, two groupings, an integer limit, a shop read that needs its store, a
one-argument freshness read, a group-wide question answered by grouping, a mixed request whose refused
part must stay refused while the served part keeps its arguments, and a single-business control. Each
case carries a new expectation, `expects_arguments` (`{tool: {argument: null | scalar | list}}`), read
by a new check in the deterministic grader, **`argument_fidelity`**, which reads a new field on the
observation, `argument_trace`: for every call the agent named, the key the model wrote its payload
under, the payload as written, the arguments the runner read, and, where the call reached the gateway,
the validated input the gateway recorded and how the call ended. The check fails a case when any named
call carried a non-empty raw payload and the runner read none (the P3-G2 shape), when no call to a
wanted tool carried a payload, when the wanted argument is missing or wrong in what the runner read, or
when the gateway's validated input lost it; it is not applicable to a subject that records no trace, so
a stub can never pass it by omission. The existing checks still apply on every case: refusal, must not
call (every write, `take_order`, `cancel_sales_order`, both reply tools), task grounding, no denied
call, the role boundary on the mixed case.

- **Its own line, its own evidence.** `deploy/gate/run.sh` seeds the suite beside the others and gains
  `GATE_SUITE`: set, the runner evaluates that ONE suite (`hq:agent:evaluate executive --suite=...`),
  so `GATE=exec-integrity GATE_SUITE=regression.execution_integrity` is a separate evidence set that is
  never mixed into a gate's score. Unset, the runner behaves exactly as for Gate 13. `deploy/gate/toggle.php`
  lists the suite's case count in the posture. Because the suite is active and Executive-scoped, the shadow
  opener's own preconditions (`ShadowPeriods::refusals()`) now require its latest run to have passed,
  fully measured, on the prompt in force, which is what `docs/90` A.8 asks; a test holds it.
- **Gate 13 is untouched.** No suite, case, run or result of Gate 13 changed; the new suite's first run
  will be its own run rows. Nothing here claims Gate 13 tested the argument path.

## What the review of the first session changed

Nothing was lost between sessions: the checkpoint held the fifteen files. The review found and fixed
three test premises before the tests could pass: `facts.query_orders` is HQ-wide (`ToolScope::Hq`), so
its slugs are filters the tool applies, not a scope the gateway resolves, which means `scope_bound`
(which reads the gateway's resolved business) does not apply to it and the seeded cases hold the scope
through `expects_arguments` on the slug instead; the no-payload and expansion tests moved to
`erp.read_snapshot`, a tool the runtime does expand; and a jsonb column does not keep a model's key
order, so the tests hold order on `raw_text` and content on `raw_json`. The validator's `items` gap was
found by the first delivery test, which is the point of a test that sends a real filter.

## Not built here, on purpose

- No prompt, catalogue, tool list, role policy or safety semantics changed; the catalogue still says
  `arguments`, and the runner now reads it. Stream AA owns the role-boundary lead-in change.
- No Support runtime change: the `conversation_uuid` binding, the written cases on real threads and the
  hand-over and greeting contracts are stream Y's (`docs/90` A.1 steps 3 to 5). The runner change here
  is confined to the canonical read, the expansion guard and the argument evidence on records, so Y's
  merge is clean.
- No production run of the new suite: that is the measurement of `docs/90` A.7, on the frozen build,
  after every stream merges.
- No screen for the raw replies; the row is reachable by `ai_runs.output_ref` and the run page can link
  it later, on the same read-only rule as every observe page.

## Verification (local; GitHub Actions validation deferred until the 1 October reset)

- New test file `tests/Feature/Agents/ArgumentContractAndReplyCaptureTest.php`: 22 tests. The boundary
  (eight payload names and shapes, order kept, a call without a tool dropped); delivery of a business, a
  status filter, a grouping and a stated date range to the tool exactly as validated; a call with no
  payload refused with the argument named and never defaulted; a present-and-empty payload still
  expanded per business; a malformed required argument refused with the validator's reason and nothing
  executed; the raw reply kept on every turn with the normalised reading apart, the malformed-then-repaired
  pair kept, a secret-named key masked, never an authorization source (static and dynamic); the suite
  seeded as required, the shadow opener requiring it, the grader's four verdicts on the argument path,
  the suite run end to end through the real runner with the trace on every result, the gate runner's
  seeding and one-suite mode.
- The 560 existing agent, AI and evaluation tests pass unchanged against the changed runtime: no settled
  test needed re-describing, because a scripted reply that wrote `args` still normalises to the same call.
  One settled guard elsewhere moved on purpose: `RetentionTest` pins every column that points at a detail
  row, and the first full suite failed it on `ai_run_replies.ai_run_id`. The reply row is a CHILD of the
  run (it cascades when the run is pruned; a kept run keeps it), not a reference that keeps the run, so the
  guard's list gained the column with that note and the retention policy names it as cascading.
- **Mutations, each applied, the file run, the code restored, 8 of 8 caught:** the normaliser reading
  `args` only (12 tests fail); the fall-back to `{business_slug}` restored for a call with no payload
  (caught); the raw reply not kept (3 fail); a required argument treated as absent instead of refused
  (caught, by the repair test, whose second reply then answered on a call that should have been
  refused); a list's items no longer checked (the statuses filter refused again, 2 fail); the
  `argument_fidelity` check dropped (2 fail); a reader of the raw reply added anywhere under `app/`
  (caught by the static test); secret-named keys no longer masked (caught).
- Full Pest suite on `hq_test_e`: **1,626 passed, 19,040 assertions, 0 failed (one run on 27 September by the lead session, after the stream was cut a third time)**. `vendor/bin/pint --test`: passed. `npx tsc
  --noEmit`: clean. `npx vite build`: built. No file under `resources/js` changed. No em dash in the
  files touched.

## Status (`docs/88` A point 21)

| Piece | Implemented | Merged | Registered | Credentialed | Live-called | Agent-held | Agent-granted | Activated |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Raw model reply kept (`ai_run_replies`, recorder, `output_ref`) | yes | not yet (branch pushed for review) | n/a | n/a | no (production is `40880f9`) | n/a | n/a | no |
| Canonical tool-call DTO and normaliser; runner reads it; no default for a missing payload | yes | not yet | n/a | n/a | no | n/a | n/a | no |
| Validator: list items and lengths checked | yes | not yet | n/a | n/a | no | n/a | n/a | no |
| `regression.execution_integrity`, 12 cases, `argument_fidelity`, `GATE_SUITE` | yes | not yet | n/a | n/a | no (not run) | n/a | n/a | no |

Nothing is switched on. The change alters what the runtime does with a model's words (the arguments now
reach the tools), so it is measured by the Executive Execution-Integrity Check before any shadow, as
`docs/90` A.2 says, and P3-G2 attempt 2 runs on the same frozen build.

## What the release and the measurement need

- One additive migration (`2026_09_26_100000_create_ai_run_replies`): the release runs `migrate`.
- The gate runner seeds five Executive suites; the posture toggle prints five case counts (80, 15, 28,
  44, 12).
- The check: `GATE=exec-integrity GATE_SUITE=regression.execution_integrity GATE_NAME="Executive
  Execution-Integrity Check (docs/90 A.2), OpenAI" nohup ./deploy/gate/run.sh`, on the frozen build, after
  every stream of `docs/90` B has merged. Read each result's `actual.argument_trace` for the raw,
  normalised, validated and gateway arguments of every call; the report fields are `docs/90` A.10's
  second list.
- `ai_run_replies` will grow by one row per structured model call (an Executive gate is about a hundred);
  it rides on `ai_runs` retention and needs no setting.

## Open questions

1. Should `raw_arguments` on a run's records be dropped once `ai_run_replies` has held a full gate (the
   same words are in the reply row), or kept for the run page's convenience?
2. `ei.window.bionic_garden_last_24_hours` reaches the shop through `commerce.list_orders`, whose token
   the shops have rejected since 22 September; the case still measures the argument path (the gateway's
   validated input carries `store_slug`) and the shop's answer lands in the external block, but if Joy
   has not stored new tokens by the check, that row's `gateway_status` will read the outside failure.
