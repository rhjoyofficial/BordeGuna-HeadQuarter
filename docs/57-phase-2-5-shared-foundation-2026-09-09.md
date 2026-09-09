# 57 - Phase 2.5: the Shared Foundation (9 September 2026)

**Status: built, CI green, DEPLOYED 9 September 10:57 UTC as `db3430a` (batch 9), corpus seeded; see `docs/58`. No agent exists, no autonomy is granted, no customer-facing
capability is enabled, no external channel is reachable, and `ai.runtime` is still off.**

Phase 2.5 builds the six subsystems `docs/53` identified as missing and shared, before Phase 3 needs
any of them. The argument for doing it now rather than inside whichever phase reached each one first
is in `docs/53` and is not restated here: a subsystem built inside a phase takes that phase's shape,
and consent built inside the Messenger work would be Messenger's consent.

| | |
| --- | --- |
| Milestone | Phase 2.5, Shared Foundation |
| Migrations | 6, all additive and reversible (this row first said 5; the list in section N was always six) |
| Suite | **367 passed / 1764 assertions** (was 296 / 1451) |
| New tests | **71 across 7 files** |
| Deployed | **yes, `db3430a`, 9 September 10:57:36 UTC** (`docs/58`) |
| ADRs implemented | 020, 021, 022, 023, 024, 026 (025 was already done in M4) |

---

## A. Platform Capability Registry

`App\Platform\Capabilities` is the commerce flag-map pattern promoted out of `App\Commerce\Dto`,
which stays where it is and keeps working. What is new is `CapabilityResolver`, which answers a
different question: not what the PLATFORM supports, but what this CONNECTED ACCOUNT can do today.

The rule is the provider's static ceiling narrowed by the account row, and every narrowing step
fails closed. An inactive account resolves to nothing. A platform with no registered provider
resolves to nothing, because "HQ has no code for this" and "this account may do anything" must never
be the same answer. An account whose `capabilities` column has never been populated resolves to
nothing, because nobody recording the grants is not the same as the grants being unlimited.

**A capability that is not usable right now is absent from the effective set**, so an agent cannot
offer it. The reason it is absent is kept beside it, so the agent can say "the Page's messaging
permission is still in App Review" rather than merely failing. That distinction is what stops a
customer being promised a reply on a channel the account cannot use.

The five `platform_accounts` columns that have been carried since Phase 0 with nothing reading them
are now load-bearing. A sixth question arrived with them: how old is this list? A capability set
nobody has re-checked since App Review changed is worse than none, because it looks current, so
`capabilities_verified_at` and `capabilities_verified_by` were added.

Eight provider-neutral interfaces are declared and **none is implemented**: `MessagingChannel`,
`CommentChannel`, `TelephonyProvider`, `AdsPlatform`, `PublishingPlatform`, `MediaGenerator`,
`ResearchSource`, `StorageDriver`. The registry ships empty, and a test asserts that it does.

## B. Consent and Compliance

`consent_events` is append-only and immutable at the database level, with the same triggers that
protect `audit_logs`, plus a model guard that turns the PostgreSQL exception into a sentence naming
the rule. `consent_state` is a projection that exists only for speed; `rebuild()` throws it away and
reconstructs it from the ledger, and a test corrupts the projection deliberately to prove the ledger
wins.

**The question this exists to answer is historical.** `permittedAt()` replays the ledger to a past
instant and answers with the event id that decided it. A test records a grant in June, a withdrawal
in August, and asks about a message sent in July: the answer is "permitted, by event #N", which the
mutable column it replaces could never have given.

The resolution rule, stated once and tested: events may be recorded at four scopes (this channel and
purpose, this purpose on every channel, every purpose on this channel, everything for this business);
the latest event at each scope is taken; **any denial wins**. A person-level do-not-contact is not
overridden by a channel-level grant recorded afterwards, because the broad refusal is a statement
about the person and the narrow grant is a statement about a channel.

Covered: channel-specific consent, service versus marketing purpose, withdrawal, expiry,
do-not-contact, recording consent, synthetic identity consent, business and brand scope, evidence
and basis, and retention with a data class. `ConsentPurpose::permittedWithoutRecord()` returns false
for every purpose including service, and says in its own docblock why service is not the exception it
feels like.

**Enforcement is at the gateway**, as step 5b, after policy and before execution. A test greps
`ToolGateway` to prove the check is there and that it sits between the policy decision and the tool
call, because a check written into each tool's body is one that will eventually be forgotten in the
newest channel.

## C. Autonomy Control Plane

`AutonomyResolver` is a separate component from `PolicyEngine`, and the reason is mechanical rather
than organisational: the policy engine is first-match-wins, which is right for "which rule governs
this call", and autonomy combines by MINIMUM, because every applicable ceiling is simultaneously
true. Two combinators cannot share one component without one corrupting the other.

Twelve dimensions are expressible as nullable columns on `autonomy_grants`, where null means "this
grant does not care about that dimension": agent, business, brand, channel, tool, action class,
customer segment, environment, data class, monetary band, time window, and required agreement rate.

The rules, each with a test:

- **Absence is A0.** No matching grant means no autonomy. This is the opposite default to
  `tool_registrations`, where an absent row means enabled, and the asymmetry is deliberate.
- **Minimum wins**, across grants and ceilings alike. A ceiling above the grant changes nothing.
- **HUMAN_ONLY is checked first and cannot be lifted** by any grant, ceiling or approval, and the
  refusal still distinguishes "never" from "not yet".
- **Untrusted context caps autonomy at A1**, structurally, by reading provenance labels rather than
  text.
- **A monetary band the call exceeds does not match**, and a call that does not say what it moves is
  not a call that moves nothing.
- **A grant requiring measured evidence does not apply until the evidence exists.** No measurement
  is not a passing measurement.
- **A5 is defined and unreachable**, capped at A4 with the cap named in the trace.

The decision carries the constraint that bound it, because the interesting question is never "what
level" but "what bound it", and an answer that cannot name the binding constraint cannot be argued
with or widened deliberately. A structural test asserts the resolver has an empty constructor and no
reference to any executor: it returns a LEVEL, and a higher level means fewer human interruptions,
never a different code path.

## D. Evaluation

`docs/48` listed an evaluations table in M1's dependency detail and it was never built. Phases 9 and
10 plan to widen autonomy using evaluation data from Phases 2 to 6, and nothing was recording any.

Four tables: suites, cases, runs and results. Cases carry expected tool calls, expected refusals,
expected citations, expected outcomes and a `must_not_call` list. Runs are stamped with the model,
tier, prompt version, skill version, policy version and autonomy level in force, because without
those a regression can be noticed but never attributed, and "something got worse last week" is not a
finding anybody can act on.

**Agreement rate is the principal evidence metric**, and it is not merely reported:
`autonomy_grants.min_agreement_rate` reads it, which is the mechanical link between "we measured"
and "it may act".

**A blocker failure fails the whole run regardless of the rate.** A safety corpus where 39 of 40
cases pass is not 97.5% safe; it is one working injection away from a customer.

Regressions are reported as NAMED cases that used to pass, not as a lower number.

The **permanent safety corpus** ships with 12 cases across the five required categories: prompt
injection (including one delivered through memory weeks earlier), credential requests (direct and
disguised as debugging), cross-business access (direct and framed as helpfulness), SQL and shell
execution, and policy bypass (claimed prior approval, urgency, and asking the agent to raise its own
autonomy). Every case is deterministic and every case is a blocker. `EvaluationSuite` refuses to
delete a permanent suite, because that corpus is the one that would be dropped on the day it becomes
inconvenient. A test runs an obedient subject that does what the injected text says, and watches the
corpus catch it.

`RubricGrader` exists and **refuses while `ai.runtime` is off**, which in this milestone means
always. An ungraded case is recorded as unmet rather than passed: a grader that quietly returned a
passing score without grading anything would be worse than no grader.

## E. Media Asset service

Metadata in Postgres, bytes behind `StorageDriver`, and no filesystem path anywhere in the domain
model: the row names a driver and an opaque key. A test asserts the row has no `path` or `disk`
column and that the key contains no base path.

Provenance, checksum, generator metadata, rights, retention and a consent link are all recorded.
`permitsUse()` fails closed on rights nobody recorded, so the cost of forgetting is an asset that
cannot be published rather than a publication nobody had the right to make. Identical bytes resolve
to the existing asset rather than a second row, because two rows for one file can later disagree
about its rights. `verify()` re-checksums what is on disk against what was recorded.

`LocalStorageDriver` is the only driver, and it refuses a key containing a traversal sequence even
though every key today is generated rather than supplied.

## F. Conversation and Memory core

A conversation is keyed by its own id, links to a customer through the `customer_identities` model
that already exists, and carries the channel and the platform's thread reference as attributes.
`customer_id` is nullable, because the first message from a stranger arrives before anybody knows
who they are, and a schema that demands the answer up front forces the caller to invent one. A test
walks one person across Messenger, WhatsApp and phone: three threads, one customer, no phone number
used as a key anywhere.

Every inbound message is labelled untrusted **by construction**, and a caller that tries to mark one
trusted is overridden. An agent's own outbound words are labelled `generated`, which is not trusted
either: a model's prior output can carry an injection it read earlier, and believing it because a
model wrote it is a laundering path.

Memory is three kinds that differ in what would make them wrong. Structured memory is a current fact
with a key, corrected by superseding, one live value per key enforced by a partial unique index.
Episodic memory happened at a time and stays true. Semantic memory is inferred, carries a confidence,
and `isAssertable()` returns false so it is never quoted to a customer as fact. `recall()` returns
labelled context blocks, and a test proves a customer sentence remembered today is still untrusted
when it comes back out.

## G, H, I. Provenance, the descriptor, and the injection threat

`Provenance` labels content as system, business, customer, third-party or generated. Only system and
business are trusted; **generated is not**. `ContextBlock` renders untrusted content inside a fence,
and the docblock says plainly that the fence is belt rather than braces: enforcement is
`AutonomyResolver` reading the label, because the marker sits inside the channel the attacker
controls.

`ToolDescriptor` gained six fields, all additive with safe defaults, and three construction guards
that fail the build rather than the runtime:

- a customer-facing outbound tool that names no consent class is refused;
- `Reversibility::Compensable` without a named compensating tool is refused, because a compensable
  effect that does not say what compensates it is an irreversible one with better manners;
- `DataClass::Secret` is refused outright, because tools do not carry credentials.

A test constructs a descriptor exactly as one written before this milestone would have been, and
asserts every new field defaults to the safest value.

## J. What this milestone deliberately did not enable

No agent, no autonomy grant, no conversation, no consent event and no evaluation run exists in a
fresh database. No registered tool is customer-facing, acts on an outbound channel, declares a
consent requirement, or is agent-executable. The platform registry is empty. `ai.runtime` is off.
A test asserts every one of those, and a second test greps the six new shared-foundation classes for
`DB::statement`, `DB::raw`, `exec(`, `shell_exec`, `proc_open` and `ToolGateway`, so none of them can
become a route from a model to an executor.

## K. Issues discovered during implementation

**1. A referential action is a write, and an append-only table refuses writes.** `cascadeOnDelete`
issues a DELETE and `nullOnDelete` issues an UPDATE; the immutability triggers on `consent_events`
refuse both. A foreign key that can only ever fire into a refusal is a deletion that fails at 2am for
a reason nobody expects, so the table uses `restrictOnDelete` throughout and records `tool_call_id`
as a plain column with no constraint.

**2. PostgreSQL treats NULLs as distinct in a unique index - again.** The same defect that needed a
`coalesce` in the credential index from `docs/55` needed one in three more places: the consent
projection's identity, the conversation thread key, and the structured-memory key. Worth recording
as a pattern rather than three coincidences: **any uniqueness rule over a nullable column in this
schema needs `coalesce`**, and forgetting it produces an index that looks right and enforces nothing.

**3. Null and empty are different questions, and only sometimes.** `CapabilityResolver` must treat a
null `capabilities` column as "nobody recorded what this account holds" and refuse everything, while
treating a null `restrictions` column as "there are none". A test caught the first version applying
the same rule to both.

**4. No embedding column, deliberately.** The Phase 0 migration creates `pgvector` only when the
server has it, so a `vector` column here would make the schema differ between two machines that both
report a clean migration. Semantic retrieval arrives with the phase that needs it, on a schema that
is the same everywhere.

**5. ADR-014's backup reasoning no longer covers everything.** Local-only backup was chosen because
HQ's data was derived and reconstructable from the ERP and the shops. Generated media is not:
nothing else in the group holds it. `media_assets` is the first thing in HQ that a lost disk actually
loses. Recorded in the migration and here; **the backup posture needs revisiting before Phase 6
generates anything at volume**, and that is a decision for Joy rather than a change to make quietly.

**6. `customers.consent` is still there and is now legacy.** Nothing was migrated out of it, and
nothing reads it as authoritative any more. Migrating the existing values into the ledger is a data
task with a real question attached - what `occurred_at` to claim for a value whose date nobody
recorded - and inventing that date silently would be the exact failure the ledger exists to prevent.

**7. A test helper using `??` proved nothing.** The descriptor guard test passed an explicit null to
a helper that used `?? $default`, so the case under test never ran. Caught because the guard did not
throw. Noted because it is the same class of error as a fixture written from a design rather than
captured from reality: the test agreed with itself.

## L. The Phase 2.5 dependency map

```
                         ToolDescriptor (H)
                    the control plane's vocabulary
                                 │
        ┌────────────────┬───────┴────────┬─────────────────┐
        ▼                ▼                ▼                 ▼
   Provenance (G)   Capabilities (A)  Consent (B)     Evaluation (D)
   context labels   per-account        append-only     agreement rate
        │           ceilings           ledger          + safety corpus
        │                │                │                 │
        │                │                │                 │
        └────────┬───────┴────────┬───────┘                 │
                 ▼                ▼                         │
          Conversation +      ConsentGate                    │
           Memory (F)      (in ToolGateway)                  │
                 │                                           │
                 └──────────────┬────────────────────────────┘
                                ▼
                     Autonomy Control Plane (C)
                  effective level = MIN(every ceiling)
                                │
                                ▼
                 an INPUT to the existing gateway sequence
        (gateway → policy → permission → consent → approval →
         credential → idempotency → audit), never a way around it
```

Read the arrows as "is needed by". The descriptor is at the top because every other subsystem reads a
field from it. Autonomy is at the bottom because it consumes provenance, evaluation evidence,
consent state and capability availability, and produces only a number that the gateway already knows
what to do with. Media (E) sits beside conversation, feeding attachments and carrying the same
provenance and consent links.

**What Phase 3 may now assume**: a consent ledger to ask, a capability set that reflects what the
connected account actually holds, a conversation model that survives a customer changing channel, a
memory core that knows what kind of thing it is holding, a provenance boundary that already labels
what it will read, and an evaluation subsystem that will record whether any of it worked.

## M. Not started, and not touched

Phase 3 customer AI, M5 agents, Messenger and WhatsApp communication, Meta and TikTok and Google ads
automation, and the Research Engine, which stays in Phase 6 as approved. The Anthropic driver, its
headers, authentication mode and endpoint, the `CredentialVault` decryption path, the `ModelRouter`
and every M4 safety-gate semantic are unchanged; `ai_runs` still holds the four rows M4 left, of
which one succeeded.

## N. Merged, and the deployment plan (9 September, not yet executed)

PR #8 was approved and merged as **`acf9e1e`** (`acf9e1eb9558ba3a6da9638e0c7f0d2e0378d871`), carrying
`8025ef8` and `813e602`. CI on `main` at `acf9e1e`: ****green on both jobs** (run 34329868956, the suite at 367 / 1764 and the production image build)**.

**Production remains `2784663`**, five commits behind `origin/main`. The diff between the two is
81 files, 6,256 insertions, 14 deletions: the whole of Phase 2.5 and nothing else.

### The pre-flight, run on production on 9 September

`deploy/.env` is mode 600, `root:root`, on `main` tracking `origin/main`. `ai.runtime` is `false`;
`scheduler.gateway` is the only other flag. Every Phase 2.5 table resolves to NULL through
`to_regclass`: `consent_events`, `consent_state`, `autonomy_grants`, `evaluation_suites`,
`media_assets`, `conversations`, `memories`. The two new `platform_accounts` columns are absent.
The last migration is batch 8, the credential index from `docs/55`. M4 posture: `ai_runs` 4 rows
with 1 succeeded, agents 0, approvals 12, 8 active credentials, and the section F duplicate check
from `docs/55` still returns zero rows. Seven containers up. Disk 74 GB free on the volume media
would land on.

⭐ `customers` holds 17 rows and **zero of them carry a `consent` blob**. There is nothing to
backfill, and nothing will be: the legacy column stays non-authoritative and no historical
timestamp is invented for it.

### What makes this release different from the last two

**Nothing in it can refuse.** The `docs/55` migration checked for duplicates and threw; these five
only create tables and add two nullable columns. The one way they fail is a name collision, and the
pre-flight above shows every name free. So the release either applies cleanly as **batch 9** or
fails at the build step before anything is touched.

**Nothing in it changes runtime behaviour on its own.** The consent gate runs only for a tool that
declares `consentRequired`, and none does. The resolver runs only when something asks it, and
nothing does. The six scheduled jobs pass through the same gateway sequence they did yesterday
with one extra step that returns `notRequired()` for every one of them. That step is still on the
hot path, which is why the six-job regression is run again afterwards rather than assumed.

### The release, when it is authorised

The normal backup-first path: from `/opt/bordeguna-hq`, confirm `deploy/.env` is still 600, re-run
the table-existence check because production can move, then `./deploy/deploy.sh`. It dumps first and
refuses to deploy if the dump fails, builds `app` and `backup`, brings the stack up, runs the five
migrations in the app entrypoint as batch 9, terminates Horizon gracefully, recreates `web`,
smoke-checks `/up` and `/api/health`, and appends to `RELEASES.md`.

⚠ **The safety corpus seeder is NOT run by the release.** `SafetyCorpusSeeder` is not in
`DatabaseSeeder`, so a deploy creates the evaluation tables empty. Seeding the corpus is a
deliberate, separate, authorised step - by full namespace, one at a time, as every seeder on this
VPS has been run - and it is the ONLY data this release should ever write. It creates twelve
evaluation cases and nothing else.

### What to verify afterwards

Batch 9 with five rows. Seven tables present and **all seven empty**, plus the two columns. The
three partial unique indexes present (`consent_state_identity_unique`,
`conversations_channel_thread_unique`, `memories_structured_key_unique`) and the two
`consent_events` triggers. Then the six-job regression through `ScheduledJobService::runNow()`:
all six succeed, zero denials, zero failed queue jobs, four connector calls succeeded. Then the
posture: `ai.runtime` false, `ai_runs` 4 with 1 succeeded, agents 0, autonomy grants 0,
conversations 0, consent events 0, approvals 12, 8 credentials one per identity, `hq:tools:sync
--list` showing agent "not yet" on every tool and `demo.request_approval` still DISABLED.

### Rollback

`./deploy/rollback.sh 2784663`, code-only. The seven tables survive a rollback and hold nothing,
which is harmless: the old code has no reference to any of them. If the corpus was seeded, its
twelve rows survive too and are equally inert.

### Recorded and carried forward, not fixed here

Local-only backup no longer covers generated media; a decision before Phase 6. `customers.consent`
stays non-authoritative and is never backfilled with invented timestamps. Any uniqueness rule over a
nullable column needs `coalesce`. Append-only tables take no cascading or nulling referential
action.
