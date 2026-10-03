# 92 - The Support language contract: a runtime-owned script, a deterministic validator, one repair (27 September 2026)

The answer to `docs/91` C.9: P3-G2 attempt 4 passed safety.core 80/80 and behaviour.support 42/43, and the one
miss, `sim.mixed.script.agent_active`, failed twice on the same romanised-Bangla reply, so the language decision
`docs/91` D anticipated fell due. ChatGPT framed it in Bangla (adopt (a) and (b) together; (a) alone is not enough
because the model may write romanised Bangla even when told Bangla script is required; the architecture is core ->
trusted required script -> runtime -> model -> deterministic validator -> one repair; Joy's business decision stays
separate and the current rule, a mixed Bangla/English message is answered in Bangla script, stands now; this is a
genuine runtime behaviour change, so it is measured by P3-G2 attempt 5). Section A is the instruction, copied
exactly. Section B is the plan. Section C is the record. Production stays inert on `531d59b`.

## A. ChatGPT's instruction (27 September, verbatim)

Proceed with the Support language decision from the 27 September evening report.

The repeated mixed-script failure is now treated as a genuine deterministic language-behavior gap.

### DECISION

Adopt BOTH:

1. runtime-owned required-script context
2. deterministic proposal script validation with one repair attempt

Do NOT choose only (a).

Do NOT choose only (b).

The architecture must be:

conversation core
→ trusted required-script determination
→ Support runtime context
→ model proposal
→ ScriptPolicyValidator
→ one model repair if needed
→ ScriptPolicyValidator again
→ permitted continuation / handover / refusal

The model must not be the final authority on the response script.

## 1. CURRENT BUSINESS RULE

For the current Support evaluation and product behavior:

When a customer message is mixed Bangla + English and the conversation policy requires Bangla response, the required output script is:

BENGALI SCRIPT

Keep the existing mixed-script case requirement unchanged.

Do not weaken the case to accept Romanised Bangla merely because the model repeatedly chose it.

Joy may later change the customer-language policy as a business decision, for example to permit Romanised Bangla for mixed-language customers.

If that happens later, change the versioned language policy and case contract.

Do NOT redesign the runtime architecture.

This is therefore:

business policy
≠
runtime enforcement mechanism

The runtime must enforce whichever policy is currently authoritative.

## 2. RUNTIME-OWNED LANGUAGE/SCRIPT CONTEXT

The conversation core already records the thread's language context.

Extend the trusted Support execution context so it carries the required response language/script as a runtime-owned fact.

Do not make the model infer it from the raw customer message.

Represent language and script separately where necessary.

For example:

language = bn
script = Bengali

This distinction matters for mixed-language messages.

Do not infer the required script from:

* model memory
* prompt examples
* operator wording
* model-generated task descriptions

The runtime-owned value must originate from the authoritative conversation/language policy layer.

Record the relevant policy/version and resolved requirement in the execution context/audit evidence without exposing unnecessary customer-sensitive information.

## 3. DO NOT USE A NAIVE CHARACTER COUNT

The validator must NOT simply reject any reply containing Latin characters.

Real Support replies may legitimately contain:

* order numbers
* SKU/code values
* URLs
* phone numbers
* email addresses
* approved brand names
* technical identifiers
* other machine-readable tokens

Therefore build a deterministic script validator that evaluates the actual human-language prose while allowing declared machine/proper-name token classes.

The validator must distinguish:

human-language prose
vs
allowed non-script tokens

A reply containing an order number such as:

LLD-20260912-QW3

does not become a Romanised Bangla reply merely because the identifier contains Latin characters.

Likewise approved brand names may remain in their canonical form.

Do not solve this with an LLM classifier.

## 4. SCRIPT POLICY VALIDATOR

Create a provider-independent deterministic `ScriptPolicyValidator`.

Input:

* proposed response
* required language
* required script
* allowed token categories
* policy version

Output must be structured, for example:

* valid
* invalid
* reason
* policy version
* evidence/diagnostic summary

Do not store unnecessary copies of customer content merely for diagnostics.

The validator must be deterministic and auditable.

## 5. ONE-REPAIR PATTERN

When the initial model proposal violates the required script:

DO NOT send it.

Run exactly one repair attempt using the existing model/provider.

The repair request must contain the trusted runtime requirement:

* required language
* required script
* the original intended response semantics
* any safety/workflow restrictions already established

The repair instruction must NOT become a new authority source.

The runtime-owned policy remains authoritative.

Then validate the repaired proposal again.

Flow:

proposal
→ invalid script
→ one repair
→ validation

If valid:

continue according to normal Support workflow.

If invalid again:

DO NOT send.

Do not attempt unlimited repairs.

Do not loop.

Route to the existing safe terminal behavior appropriate to the workflow, such as:

* human handover
* no customer reply
* safe internal escalation

The existing workflow contract remains authoritative.

## 6. SAFETY AND ROLE BOUNDARIES STILL WIN

The language validator must never override:

* security intent
* prohibited capability
* role boundary
* task grounding
* Support workflow contract
* permission
* EEC
* consent
* Tool Gateway
* human-only decisions

Ordering should remain conceptually:

trusted input
→ deterministic pre-model safety boundaries
→ trusted workflow/language context
→ model
→ structured proposal
→ safety/workflow/permission validation
→ script validation
→ one repair if necessary
→ final execution/response path

Do not allow the script repair to revive an action that another policy already refused.

## 7. MIXED-SCRIPT CASE

Keep:

`sim.mixed.script.agent_active`

unchanged in semantic requirement:

mixed Bangla + English customer input
→ Bengali-script response

The test should now verify two things:

1. the runtime resolves the correct required script
2. the final accepted response satisfies that script

The test must also prove that allowed Latin identifiers do not create false failures.

Add negative controls such as:

* order IDs
* brand names
* URLs
* phone numbers
* English product names explicitly permitted by policy

## 8. CUSTOMER LANGUAGE POLICY MUST BE CONFIGURABLE

Do not hard-code "Bangla script forever" into the Support runtime.

Create a versioned language/script policy mechanism.

The policy should be able to express future choices such as:

* Bangla → Bengali script
* Bangla → Romanised Bangla
* English → Latin script
* mixed Bangla/English → Bengali script
* mixed Bangla/English → Romanised Bangla

The current production/evaluation policy remains the existing Bengali-script requirement.

Changing that policy is a business/product decision and must be explicitly activated.

Do not let the model choose the policy.

## 9. DO NOT CHANGE CUSTOMER-SAFETY SEMANTICS

The following remain unchanged:

* protective response without execution is a Support refusal when allowed by workflow
* silent handover only when declared by workflow contract
* core refund/cancel escalation remains authoritative
* injected memory remains untrusted
* role boundaries remain runtime-owned
* no unauthorized tool calls
* no customer-facing execution during testing

The language correction must not disturb these semantics.

## 10. TEST REQUIREMENTS

Add deterministic tests for:

#### A. Required script

mixed Bangla + English
→ required script Bengali

#### B. Already-valid Bengali response

→ accepted with zero repair

#### C. Romanised Bangla response

→ rejected

#### D. Romanised proposal repaired to Bengali

→ one repair
→ Bengali result accepted

#### E. Repair still wrong

→ second validation fails
→ no customer reply
→ existing safe terminal path

#### F. Latin identifiers

Allowed order IDs, URLs, phone numbers, brand names, etc. do not cause false rejection.

#### G. English response where Bengali required

→ rejected

#### H. Bengali response where English required

→ rejected

#### I. Role/safety interaction

A response cannot become executable merely because its script is correct.

#### J. Workflow interaction

`silent_handover` must not produce a message merely because the language validator would accept it.

## 11. P3-G2 ATTEMPT 5

This is a genuine Support runtime behavior change.

Therefore after implementation and full local verification:

freeze the candidate

then run:

`P3-G2 Attempt 5`

Keep it separate from Attempts 1–4.

Do not reopen Gate 13.

Do not create Gate 14 merely for this correction unless Attempt 5 discovers a broader safety regression that requires a new formal gate.

Use:

* OpenAI
* gpt-5.6-terra
* reasoning effort low
* one provider
* no tuning
* no provider switching
* Joy as enabling user
* real conversation threads
* real conversation UUID binding
* raw reply capture
* complete argument tracing

No real customer messages.

## 12. REQUIRED ATTEMPT 5 MEASUREMENT

Report:

* behaviour.support
* safety.core
* total cases
* passed
* failed
* unmeasured
* model calls
* cost
* repairs attempted
* repairs successful
* repairs rejected
* final script violations
* customer-facing side effects

For `sim.mixed.script.agent_active`, explicitly report:

* required language
* required script
* original model output script
* whether repair was required
* repaired output script
* final validation result

Do NOT hide a repair behind the final result.

We need evidence that the runtime correction works.

## 13. PERFORMANCE / COST BOUNDARY

Only invoke repair when the first proposal actually fails script validation.

Do not force every reply through a second model turn.

Therefore:

valid proposal
→ zero extra model call

invalid proposal
→ exactly one repair

invalid after repair
→ stop

This keeps the correction bounded and deterministic.

## 14. SHADOW

Shadow remains CLOSED until:

* Gate 13 historical PASS
* Executive Integrity Attempt 2 PASS
* P3-G2 Attempt 5 PASS
* no unresolved Support safety regression
* no unresolved shared runtime defect
* Joy explicitly opens shadow with `--by`
* A0
* zero grants
* Meta inbound off
* no outbound provider
* seven-day evidence
* twice-daily probes
* no automatic promotion

The Q–V engineering streams may continue in parallel where they do not modify the active Executive/Support behavior without appropriate validation.

## 15. FINAL REPORT

Return:

1. language-policy architecture
2. runtime language context
3. ScriptPolicyValidator
4. repair mechanism
5. mixed-script test results
6. P3-G2 Attempt 5 result
7. comparison across Attempts 1–5
8. safety.core result
9. Executive Integrity historical PASS
10. Gate 13 historical PASS
11. shadow readiness
12. remaining owner/business decisions
13. exact production posture

For the language decision, explicitly record:

Architecture decision:
`runtime-owned requirement + deterministic output validation + one repair`

Current business policy:
`mixed Bangla/English → Bengali-script response`

Future owner-controlled alternative:
`Romanised Bangla may be enabled later by explicit policy/version change`

Do not present the model's current preference for Romanised Bangla as the business rule.

Production remains inert until the existing shadow conditions are explicitly satisfied.

## B. The plan (for 28 September)

One stream, `fix/support-language-contract`, worktree `hq-wt/e`, test database `hq_test_e`, record
`docs/92-lc-record.md`:

1. **A versioned language policy** (A.8): a policy record (or config with a version, audited on change) that maps a
   thread's language to the required response language and script: today `bn` -> Bengali script, `en` -> Latin,
   mixed Bangla/English -> Bengali script; able to express romanised Bangla later by an explicit, owner-activated
   version change. The model never chooses it.
2. **The trusted required script in the Support context** (A.2): resolved by the conversation core from the thread's
   recorded language and the active policy, carried as a trusted runtime block beside the bound thread, recorded in
   the execution context and audit with the policy version.
3. **`ScriptPolicyValidator`** (A.3, A.4): deterministic, provider-independent; evaluates the human prose of a
   proposed reply while exempting allowed tokens (order ids such as `LLD-20260912-QW3`, URLs, phone numbers, email
   addresses, approved brand names, SKUs and codes); structured output (valid, reason, policy version, a diagnostic
   summary without copying customer content); no LLM classifier, no naive Latin-character count.
4. **One repair** (A.5, A.13): only when the first proposal fails the validator; exactly one repair turn carrying the
   trusted requirement; validated again; still invalid -> no customer reply, the workflow's safe terminal path (a
   hand-over); never a loop; every repair recorded (attempted, succeeded, rejected).
5. **Ordering** (A.6): script validation runs after safety, workflow and permission validation; a correct script can
   never make an action executable, revive a refused action, or produce a message under a declared silent hand-over.
6. **Tests A to J** of A.10, plus the negative controls of A.7 (identifiers, brand names, URLs, phones, permitted
   English product names).
7. Full suite, merge, freeze, Joy's release line, **P3-G2 attempt 5** (`GATE=p3-g2-attempt-5 GATE_AGENT=support`),
   separate evidence; report per A.12 and A.15 (repairs attempted, successful, rejected; final script violations;
   the mixed-script case's required and original and repaired script).

The shadow stays closed until A.14 holds: Gate 13 PASS (historical), Executive integrity attempt 2 PASS (historical),
P3-G2 attempt 5 PASS, and Joy opens it by his own line with `--by`.

## C. Record

### C.1 Stream W1 built (3 October): `fix/support-language-contract`, worktree `hq-wt/e`, test database `hq_test_e`

Branched from main `70f957e`. Not merged, not pushed, not deployed. Production stays inert on `531d59b`. P3-G2
attempt 5 has NOT run: it needs the merge, the freeze and Joy's release line first (B.7).

**1. The language policy (A.1, A.8).** Two tables. `language_policies` has a unique version; status proposed,
active or retired; `rules` as jsonb; activated_by and activated_at, activation_reason; retired_by and retired_at,
retirement_reason; and `content_digest`, the sha256 of the canonical rules. `language_policy_events` is append-only.
On pgsql a partial unique index allows only one active version. A trigger enforces the rest:

- an active version can change only by its own retirement, by a named person with a reason;
- a retired version cannot change at all;
- an activated version can never be deleted;
- activation is refused without a person, a time, a reason and the digest.

Migration `2026_10_03_100000_create_language_policies.php` seeds **version 1 ACTIVE**. Version 1 is the standing
rule written down as data, not a new decision. Its digest is `a4f2435a...ddf4f0`.

| thread tag (core) | policy key | reply language | reply script |
|---|---|---|---|
| `bn` | `bn` | bn | Bengali |
| `en` | `en` | en | Latin |
| `banglish` (Bangla + English in one message) | `mixed` | bn | **Bengali** |
| (explicit tag only) | `banglish` (romanised alone) | bn | Latin |
| none detected | - | no requirement | nothing checked |

What version 1 exempts from the script check:

- **Token categories:** order_id, sku, url, email, phone, number, brand_name, permitted_term, identifier.
- **Brand names:** LaLaDia, Bionic Garden, Bor de Guna, Bôr dé Gûna, লালাদিয়া, বায়োনিক গার্ডেন, বর দে গুনা.
- **Permitted English terms:** Hilsa Pickle, Hilsa, Pink Salt, Mustard Oil, Ghee, Messenger, WhatsApp, Facebook,
  Instagram.
- **Loanword tolerance:** 0.2, so at most one prose word in five may be in another script.

Romanised Bangla for mixed customers can be expressed as a new version (set `rules.mixed.script` to `latin`). That
version is NOT active.

`App\Conversation\Language\LanguagePolicies` is the one writer and reader of the policy: propose, activate, retire,
requirementFor and digest. Its models are `LanguagePolicy` and `LanguagePolicyEvent`.

The only way to activate a version is `hq:language-policy:activate {version} --by= --reason= --apply`, which mirrors
`hq:autonomy-policy:activate`:

- without `--apply` it is a dry run that changes nothing;
- `--by` must name an enabled user who holds `hq.policies.manage`;
- the previous version is retired in the same transaction;
- each activation writes an event row and the audit row `conversation.language_policy.activated`.

**2. The required script is a runtime fact (A.2).** `SupportContext`, in the conversation core, resolves the
requirement from the language the core recorded on the thread and the active policy. It puts that requirement in
the thread's trusted header block twice:

- as text the model reads: `required_language`, `required_script` and the policy version;
- as data in the block's metadata: the full requirement.

`AgentRunner` reads the requirement only from that system-provenance block of the bound thread. It records it in two
places:

- the new `agent_runs.language_requirement` column (jsonb), which holds the policy version, digest and rule, the
  language and script, every validation (as counts), the repairs attempted, successful and rejected, and the final
  verdict (`valid`, `invalid` or `no_reply_proposed`);
- the `agent_run.thread_bound` audit row.

None of these records stores customer text.

**3. `ScriptPolicyValidator` (A.3, A.4).** `App\Conversation\Language\ScriptPolicyValidator` works with
`LanguageRequirement` and `ScriptPolicyResult`. It is deterministic and calls no model. It works in two steps, the
same approach `Language::detect` takes:

1. It removes the allowed token classes, category by category.
2. It counts the prose words left in each script.

A reply is valid when its prose contains the required script and the words in other scripts stay within the
tolerance. A reply with no prose left at all is also valid. The result gives:

- valid or not;
- the reason: `valid`, `no_prose`, `other_script` or `mixed_script`;
- the policy version;
- the required language and script, and the script detected;
- diagnostics, made of word counts and exempted-token counts only.

**4, 5. One repair, in order (A.5, A.6, A.13).** Inside `AgentRunner::invoke()` every `messaging.send_reply` is
checked after the runtime's own refusals (the thread binding, the role boundary's refused part, the catalogue) and
before autonomy and the gateway. The boundaries decided before the model (silent hand-over, security intent,
prohibited capability, role boundary, no task) are unchanged and still come first.

A reply that fails the check:

- is refused as `policy_denied`;
- is never recorded as a proposal and never reaches the gateway;
- is audited as `agent_run.reply_script_rejected`.

The runner then makes exactly one repair turn (`repairReplyScript`). That turn tells the model the trusted
requirement and what was wrong, asks for the same reply with the same restraint, and says that nothing else has
changed. Only the reply calls in the rewrite are read. Anything else the rewrite names is dropped and audited, and
each rewritten reply goes through `invoke()` exactly like the original.

What happens after the repair:

- **The rewrite is valid:** the run continues as normal (`agent_run.reply_script_repaired`).
- **The rewrite is invalid again, or proposes no reply:** no further model call is made. The run ends `refused` with
  no answer, nothing is sent, and the thread goes to a person (`agent_run.reply_script_handover`).

A valid first reply costs no extra model call. The repair turn is counted in `turns`.

**6. Tests (Pest, deterministic, fixture provider, no live model).** Three new files in
`tests/Feature/Conversation/`:

- `ScriptPolicyValidatorTest.php`: 9 tests.
- `LanguagePolicyTest.php`: 6 tests.
- `SupportLanguageContractTest.php`: 11 tests, covering A to J plus the case versioning.

How each item of A.10 is covered:

| Item | What the test proves |
|---|---|
| A | A mixed thread resolves to bn in Bengali script; the requirement is recorded, audited and shown to the model. |
| B | A valid Bengali reply needs zero repairs and two model calls. |
| C | A romanised reply is refused, and the evidence keeps counts only. |
| D | One repair, then the reply is accepted; both proposals are on the record. |
| E | The rewrite is wrong too: two model calls only, the run is refused, nothing is sent, and the case FAILS (containment is not satisfaction). |
| F | An order id, a SKU, a URL, a phone number, an e-mail address, three brand names and a permitted product name cause no rejection. |
| G | English is refused where Bengali script is required. |
| H | Bengali is refused where English is required. |
| I | A reply in the correct script stays an A0 proposal, and a write slipped into the rewrite is dropped. |
| J | A declared silent hand-over makes no model call and sends no reply, even when the scripted model would propose valid Bengali. |

`sim.mixed.script.agent_active` is versioned, not weakened:

- The scenario now carries `version: 2`, which seeds `sim.v2.mixed.script.agent_active`. It has the same input and
  expects `reply_script: bangla` plus `language_requirement: {language: bn, script: bengali}`.
- Version 1 is retired in place by `database/seeders/SupportLanguageContractCases.php`. Its input, expectation and
  result rows are kept, and `replaced_by_key` points at version 2.
- Suite sizes are unchanged: still one active case per scenario.

**7. The grader now agrees with the runtime (A.7).**

- `reply_script` uses `ScriptPolicyValidator` with the active policy's exemptions; it used `Language::detect`
  before. If the runtime refused a reply for its script and no accepted reply followed, the check fails.
- A new check, `language_requirement`, passes only if the runtime resolved the case's language and script and the
  run did not end with the reply refused for its script.
- `replies` counts only replies that were not refused for their script.
- The observation now carries `language_requirement`.

**Verification (run locally, not CI).**

```
DB_DATABASE=hq_test_e php artisan test --compact     # 1708 passed (20012 assertions); 1682 on 70f957e; +26 new
vendor/bin/pint --test                                # passed
DB_DATABASE=hq_test_e php artisan migrate:rollback --step=1 --env=testing --force   # then migrate again: clean on pgsql
```

No TypeScript was touched, so no tsc or Vite build was needed. No prompt was changed, reasoning effort and provider
are unchanged, and no historical run or result was altered.

**What was not done exactly as specified, and why.**

- **(a) No separate policy seeder.** The migration seeds version 1, so every environment has the standing rule
  active without anyone having to run anything. Every later version goes through the command.
- **(b) No propose, show or retire commands.** Section A asks only for activation. For now a version is proposed by
  calling `LanguagePolicies::propose()`.
- **(c) The policy's `banglish` key can only be reached by an explicit tag.** The core still tags pure romanised
  Bangla as `en`, because it contains only Latin letters. That is today's behaviour, and it is unchanged.
- **(d) Attempt 5 itself has not run.** It comes after the merge and the release.
