# 48 - Phase 2: Command Center architecture (8 September 2026)

**Status: design only. Nothing in this document is built. No code, migration, deployment or shop
change follows from it until Joy approves it.**

Phase 1 closed on 7 September with the ERP, LaLaDia and Bionic all integrated and proven on
production data (`docs/40`, `docs/41`, `docs/44`, `docs/45`, `docs/46`, `docs/47`). HQ can now see
the group. Phase 2 is about letting it *act* on what it sees, and the entire design problem is that
acting is the dangerous half.

The governing sentence, unchanged from ADR-007 and repeated here because every later section is a
consequence of it:

> **HQ is the control and intelligence layer. The ERP and the commerce systems remain the systems of
> record. HQ never becomes the truth about an order, a price, a balance or a stock level; it holds
> read models, and it acts on the systems of record through their own interfaces.**

The second governing sentence, which is new to Phase 2:

> **A language model in HQ is a proposer, never an executor. Everything it can do to the world is a
> typed tool call that a deterministic layer approves or refuses after the model has finished
> speaking. Enforcement lives downstream of the model, which is why a clever prompt cannot escalate
> anything.**

---

## A. Verified current state, read from the code and the running system on 8 September 2026

This section exists because the memory store had already drifted from the repository once. Every
line below was read from the tree at `f23d4ae` or from the running production stack, not recalled.

### A.1 What is actually deployed

| system | production | verified how |
| --- | --- | --- |
| HQ | `184d133` (repo `main` is `f23d4ae`, one docs-only commit ahead) | `git log` on `/opt/bordeguna-hq` |
| ERP | `1c3ca24`, answering only on `erp.bordeguna.com` | `hq:erp:probe` -> `ok:true`, business 4, 128 ms |
| laladia.com | `main` `53cb7015` | `hq:commerce:probe` -> public/assistant/admin all 200 |
| bionic.garden | `relaunch` `4b346adf` | `hq:commerce:probe` -> public/assistant/admin all 200 |

Seven containers up and healthy (`app`, `web`, `horizon`, `scheduler`, `db`, `redis`, `backup`).
`hq:facts:check --hours=200` reports **16 checked, 16 ok, 11 without line detail, 0 discrepancies**.
Event table: `order.created` 5 processed, `order.status_changed` 10 processed plus 6 `dead` and
inert by design, `order.updated` 1 processed, `inventory.low` 1 processed.

### A.2 What Phase 0 and Phase 1 already built, which Phase 2 reuses rather than reinvents

This is the important part. Phase 0 deliberately laid the control-plane foundation and left the
seams open. Phase 2 closes them.

| Already exists | Where | Phase 2 relationship |
| --- | --- | --- |
| `Tool` interface with `key()`, `inputSchema()`, `execute()`, `reconcile()` | `app/Scheduling/Tools/Tool.php` | **Extended, not replaced.** Its own docblock says "and, from Phase 2, an agent". |
| `ToolRegistry` populated from `config('hq.tools')` | `app/Scheduling/Tools/ToolRegistry.php` | Becomes registry plus metadata; the config list stays the outermost kill switch. |
| `ToolContext`, `ToolResult` (with an `uncertain` status) | same namespace | `ToolContext` gains an actor and an agent; `ToolResult` is unchanged. |
| Six working tools | `Tools/{Noop,Failing}`, `Tools/Erp/{PollErpOverview,PollErpLowStock}`, `Tools/Commerce/{ReconcileShopFacts,CheckFactIntegrity}` | The first tools registered in the gateway, and the migration's own test fixture. |
| `scheduled_jobs` + `job_runs` carrying `tool_key`, `payload`, `timezone`, `approval_state`, `missed_policy`, `nl_source_text`, `created_from` | `2026_09_05_100300` migration | **The natural-language scheduler's target schema already exists.** Nothing to add for NL entry but the front door. |
| `ScheduledJobService` (validated, audited state changes; one unique logical slot) | `app/Scheduling/` | Unchanged. NL becomes a caller passing `createdFrom: 'nl'`. |
| `ai_runs`, `tool_calls`, `connector_calls`, `decision_records`, `owner_preferences` | `2026_09_05_100200` migration | Tables exist with the right shape and the right columns. |
| `audit_logs`, append-only enforced by a **Postgres trigger** that refuses UPDATE and DELETE | `2026_09_05_100100` migration | The audit-immutability requirement is already satisfied at the database level. |
| `Auditor` with human / system / agent actor types and key redaction | `app/Support/Audit/Auditor.php` | `recordAgent()` already exists and has never once been called. |
| `feature_flags` with per-environment and per-business rollout | `Features.php` | The Phase 2 kill switches and dark launches. |
| `integration_credentials`, encrypted payload, status, expiry, plus `OAuthManager` | `app/Support/Credentials/` | The credential identity layer, unchanged. |
| Correlation ids assigned to every request | `Http/Middleware/AssignCorrelationIds.php` | The spine of the Phase 2 trace. |
| spatie RBAC, 15 permissions, 5 roles, an additive seeder | `RolesAndPermissionsSeeder` | Extended with tool-group permissions, still additive. |
| Mandatory TOTP 2FA on every account | `2fa.enabled`, `2fa.verified` middleware | Unchanged. |
| `ConnectorCallLogger` writing `connector_calls` | `app/Commerce/Support/` | Generalised so the ERP connector logs the same way. |

### A.3 The four gaps, stated precisely

1. **`RunScheduledJob` calls `$tool->execute()` directly.** There is no gateway, no policy check and
   no permission check between a scheduled job and a tool. Today that is safe, because all six
   registered tools are read-only or write only HQ's own read models, and because only an
   authenticated human holding `hq.jobs.manage` can create a job at all. It stops being safe the
   moment either a write tool or an agent exists.
2. **`tool_calls`, `ai_runs`, `decision_records` and `owner_preferences` have never been written to
   by any code path.** Grepped across `app/`: the only writer of any control-plane table is
   `ConnectorCallLogger`, which writes `connector_calls` for commerce. The other four tables are
   correctly shaped and completely empty.
3. **`tool_calls.approval_id` is an `unsignedBigInteger` with no foreign key**, because `approvals`
   does not exist. Phase 2 creates the table and adds the constraint.
4. **There is no agent, no model call, no policy class and no approval anywhere in the codebase.**
   `config/hq.php` says as much in a comment: "real tools arrive with the tool gateway (Phase 2)".

### A.4 Where the older design documents are now wrong

`docs/08`, `docs/11`, `docs/12`, `docs/13`, `docs/21`, `docs/24`, `docs/25` and `docs/26` are good
design sketches written before Phase 0 shipped, and they remain the intent. Three things in them no
longer match reality, and this document overrides them:

- **`docs/11` lists v1 tools spanning ads, content, publishing, messaging and order creation.**
  Those belong to Phases 3 to 6. Phase 2 registers a much smaller set, and registers the dangerous
  ones **disabled**. Section D.6 gives the real Phase 2 list.
- **`docs/11` and `docs/13` use a three-value action class (AUTO / APPROVAL_REQUIRED / HUMAN_ONLY)
  as the primary risk axis.** That conflates "how much damage could this do" with "who may run it",
  which are different questions with different owners and different rates of change. Section P
  replaces it with an orthogonal pair: an intrinsic risk tier on the tool, and a derived action
  class produced by policy. The three old names survive as the derived outcome, so nothing already
  written becomes meaningless.
- **`docs/12` says the default for an unmatched call is `require_approval`.** For Phase 2 the
  default is **deny**, and `require_approval` is reserved for calls that matched a rule saying so.
  A call nobody has thought about must not be able to reach a human as a plausible-looking approval
  card, because a human approving an unclassified action is exactly the failure the approval system
  exists to prevent. It should fail loudly as a configuration error instead. Fail closed means
  closed.

---

## B. Scope, in one page

Phase 2 delivers eleven things:

1. **Tool Gateway** - the single mandatory door between any caller (agent, scheduler, UI, API) and
   any effect.
2. **Policy Engine** - deterministic, versioned, testable, model-free.
3. **Permission model** - four distinct identity kinds and the rule for how they intersect.
4. **Approval Center** - value-gated human control, with cryptographic binding of what was approved.
5. **Two agents** - `executive` and `analytics`, both read-only in their first release.
6. **Model Router** - provider-neutral tier selection with fallback.
7. **AI cost accounting** - per run, per tool, per agent, per business, and attributable further.
8. **Ask HQ** - an operator conversation surface that holds no execution authority of its own.
9. **Natural-language scheduling** - a new front door onto the scheduler that already exists.
10. **Daily executive briefing** - with fact, metric, inference and recommendation kept visibly apart.
11. **Observability** - one correlation id from an operator's question to an external HTTP status.

They are delivered in order of dependency, not order of visibility. Section U gives the milestone
sequence.

---

## C. The shape of the whole thing

The Command Center is one pipeline with four callers at the top and one exit at the bottom. Every
arrow below is a real function call in the design; none of them is optional or bypassable.

```
 CALLERS
   Ask HQ turn        Scheduler tick        Inbound event        Operator UI button
        |                    |                    |                     |
        +--------------------+--------------------+---------------------+
                                     |
                              [ AGENT RUNTIME ]        (only for the AI callers)
                              context assembly -> ModelRouter -> model call
                              structured output: {answer, proposed_tool_calls[]}
                                     |
                                     |  a tool key + typed args, nothing else crosses this line
                                     v
 ================================ TOOL GATEWAY ==================================
   1. registry lookup + enabled?          -> ToolNotRegistered / ToolDisabled
   2. schema validation of args           -> InvalidArguments   (before anything else runs)
   3. resolve the acting identities       -> agent, enabling human, business scope
   4. PERMISSION check (intersection)     -> Denied
   5. POLICY evaluate (deterministic)     -> allow | require_approval | deny
   6. APPROVAL (only when required)       -> pending, run parked; resumed on decision
   7. budget + rate limits                -> Throttled / BudgetExceeded
   8. idempotency: seen this key?         -> return the recorded result, do not re-run
   9. CREDENTIAL resolution               -> the connector receives a secret; nothing above does
  10. EXECUTE tool -> connector -> external system
  11. record: tool_calls + connector_calls + audit_logs + ai_runs cost
 ================================================================================
                                     |
                                     v
                          ERP  |  LaLaDia  |  Bionic  |  provider APIs
```

Four properties of that picture matter more than the boxes:

**The model is upstream of enforcement.** By the time policy runs, the model has finished. Policy
reads a tool key and a typed argument array. It never reads prose, never reads the model's
reasoning, and cannot be talked to. This is the entire prompt-injection defense and it is
structural, not textual.

**The gateway is the only path, including for humans.** A button in the Approval Center that
executes an approved action calls the same `ToolGateway::execute`. There is no "admin shortcut"
path, because a second path is a second set of bugs and an audit trail with holes in it.

**Credentials are resolved at step 9, below every decision.** Nothing above the connector ever holds
a secret. An agent cannot ask for a credential, because there is no tool that returns one, and the
gateway's own context object has no field to put one in.

**The scheduler becomes a caller, not a peer.** Today `RunScheduledJob` executes tools directly.
In Phase 2 it calls the gateway like everyone else. That migration is the first real proof the
gateway works, because six live production jobs must keep producing byte-identical output through
it (see milestone M2).

---

## D. Tool Gateway

### D.1 What a tool is

A tool is a PHP class. It is the only unit of capability in HQ. If HQ can do something to the
world, there is a class for it, with a schema, a risk tier and tests. If there is no class, the
capability does not exist, and no amount of prompting creates one.

The existing `Tool` interface stays and gains metadata. The additions are marked:

```php
interface Tool
{
    public function key(): string;                    // exists: stable identifier, e.g. "erp.overview"
    public function inputSchema(): array;             // exists: JSON-schema-like, used for validation AND
                                                      //         for the provider function definition
    public function execute(array $payload, ToolContext $context): ToolResult;   // exists
    public function reconcile(ToolContext $context): ?ToolResult;                // exists (ADR-011)

    // --- added in Phase 2 ---
    public function outputSchema(): array;            // what callers and the model may rely on
    public function descriptor(): ToolDescriptor;     // all metadata below, as one typed object
}
```

`ToolDescriptor` is a readonly value object, and it is the tool's whole contract with the control
plane:

| Field | Meaning | Why it is on the tool and not in config |
| --- | --- | --- |
| `key` | `commerce.reconcile_facts`, stable forever | the identity everything else references |
| `version` | semantic; a breaking schema change bumps major | a stored approval names a version, so an upgraded tool cannot execute an old approval |
| `title`, `purpose` | one line and one paragraph, human readable | the approval card and the model's function description come from here |
| `risk_tier` | R0..R5 (section P) | intrinsic to what the code does; a config file must never be able to make a refund look like a read |
| `agent_executable` | bool | `false` means no agent may ever run it, even with an approval |
| `read_write` | `read` \| `write` | coarse and blunt on purpose; used by the environment guard |
| `scope` | `hq` \| `business` \| `store` \| `brand` | what the args must name |
| `supported_businesses` / `supported_stores` | list or `*` | a tool that only works on a Laravel shop says so |
| `permission` | one permission string | what the enabling human must hold |
| `policy_class` | FQCN or null | null means the tier's default policy applies |
| `credentials` | list of `{provider, account_ref_from}` | declares what the gateway must resolve; also what the credentials screen shows as "used by" |
| `idempotent` | bool | false forbids automatic retry; the run goes `uncertain` and waits for a human |
| `idempotency_key_from` | list of arg paths | how a stable key is derived from the args |
| `timeout_seconds` | int | passed to the connector, enforced by the gateway too |
| `retry` | `{max, backoff, retry_on[]}` | which failures are worth repeating |
| `cost_hint` | `{external_calls, est_usd}` | fed into budget checks before execution |
| `redact` | list of arg/output paths | schema-driven redaction before anything is stored |
| `audit_action` | e.g. `tool.commerce.reconcile_facts` | the action string written to `audit_logs` |

### D.2 Registration and discovery

Registration stays exactly where it is: `config('hq.tools')` is the list of classes, and a tool
absent from that list cannot be constructed, scheduled or called. That is already the outermost kill
switch and it requires a deploy to change, which is the correct weight for "does this capability
exist at all".

Phase 2 adds a second, lighter switch: a `tools` table holding one row per registered key with
`enabled`, `enabled_businesses`, `disabled_reason`, `disabled_by`, `disabled_at`. Disabling is an
audited runtime action with no deploy. The two switches compose by AND, and the config list always
wins. Startup reconciles the table against the config list and reports keys in one but not the
other, so a tool cannot quietly exist in the database after its class is deleted.

Discovery has two audiences and they get different views of the same source:

- **Humans** get a Tool Registry screen: key, version, purpose, tier, permission, businesses,
  enabled state, last 20 calls, and the schema rendered readably.
- **Models** get function definitions generated from `inputSchema()` at context-assembly time,
  filtered by the seven filters of `docs/08` (role, scope, permission, business, brand, policy,
  environment). **A model is never told that a tool it may not call exists.** This is the ERP
  assistant's rule ("the model is never told a table exists") applied to capabilities, and it means
  a model cannot even name a forbidden tool, let alone be argued into calling one.

### D.3 The execution contract, step by step

The order is not arbitrary. Each step is placed so that the cheapest and most certain refusals
happen before anything expensive or irreversible.

1. **Registry lookup.** Unknown or disabled key -> `ToolNotAvailable`. Nothing is recorded beyond an
   audit line, because an unknown key is a bug or an attack, not a business event.
2. **Schema validation.** Args are validated against `inputSchema()` **before** policy. A malformed
   call never reaches policy, a connector or a credential. This is already the stated guarantee in
   `docs/11` and it survives unchanged.
3. **Identity resolution.** The gateway builds an `ActingIdentity` (section E): the agent if any,
   the enabling human, the service token if any, and the business scope. A call with no resolvable
   human authority is denied. There is no anonymous execution.
4. **Permission.** The intersection rule of section E.3.
5. **Policy.** Section F. Returns exactly one of allow / require_approval / deny.
6. **Approval.** If required, the gateway creates the `approvals` row, writes a `tool_calls` row with
   `status = awaiting_approval`, returns a `ParkedResult` to the caller, and **stops**. The agent
   run is checkpointed, not blocked; nothing sits holding a worker.
7. **Budgets and rate limits.** Redis counters per agent per day, per tool per hour, per business per
   day, plus the AI spend budget of section J. Exceeding a budget is `require_approval` for a human
   caller and `deny` for an agent caller, because a human can see why and an agent will simply keep
   trying.
8. **Idempotency.** The key is derived from `idempotency_key_from` over the validated args plus the
   caller's run identity. If a `tool_calls` row exists for that key in a terminal state, its recorded
   output is returned without executing. If one exists in `uncertain`, the gateway calls
   `reconcile()` first (ADR-011) and only executes when the effect is provably absent.
9. **Credential resolution.** `CredentialResolver` reads `integration_credentials`, decrypts, and
   hands the secret **to the connector only**. The decrypted value never enters `ToolContext`, a log
   line, an audit row, a model prompt or an exception message.
10. **Execute.** Inside a timeout. Exceptions are caught and classified: `Refused`, `Unavailable`,
    `Uncertain`, `ValidationFailed`, `Unsupported` (these five already exist in
    `app/Commerce/Exceptions/`, and Phase 2 promotes them to the shared gateway vocabulary).
11. **Record.** One `tool_calls` row always, whatever happened. `connector_calls` from the connector
    layer. `audit_logs` for anything at R3 or above. `ai_runs` cost attribution when the caller was
    an agent.

### D.4 What a tool may never do

These are enforced by architecture, not by convention:

- **No raw SQL and no query builder against business data.** A tool reads through a typed repository
  or a connector. The ERP assistant rule stands: the application compiles the query, the model never
  writes one, and no tool accepts a query as an argument.
- **No free-text URL to fetch.** A connector's base URL comes from config or a `stores` row. No tool
  argument is ever a URL that gets requested.
- **No shell, no `eval`, no dynamic class resolution from arguments.** A tool argument never names a
  class, a method, a command or a file path.
- **No browser automation.** Explicit non-goal (section T).
- **No credential access.** There is no tool that returns a secret, and `ToolContext` has no field
  that could carry one.
- **No writing to another tool's read model.** Facts are written by the fact pipeline; a tool that
  wanted to "correct" a fact would be laundering a guess into a read model, which is precisely the
  `docs/44` failure mode.

### D.5 Sharing the gateway with the scheduler

`RunScheduledJob` currently does registry lookup, reconcile-on-retry and execute itself. In Phase 2
it becomes:

```php
$outcome = $gateway->execute(new ToolRequest(
    toolKey:   $job->tool_key,
    args:      $job->payload ?? [],
    caller:    Caller::scheduler($run),
    identity:  ActingIdentity::forJob($job),   // owner_user_id is the enabling human
));
```

The run-record handling, attempt log, backoff and audit line all stay in `RunScheduledJob`, because
that is scheduler concern and not gateway concern. The reconcile-on-retry logic moves *into* the
gateway, where every caller gets it rather than only this one.

A job whose `owner_user_id` is null (possible today, the column is nullable) resolves to the
`system` identity, which holds only R0 to R2 read tools and nothing else. That is deliberately
narrow: the five Phase 1 poll and reconcile jobs all fit inside it, and anything wider must name an
owner.

### D.6 The Phase 2 tool set

Small on purpose. `docs/11`'s longer list stands as the eventual target, not as Phase 2 scope.

**Registered and enabled, read-only (R1 to R2):**

| Key | Backed by | Tier | Notes |
| --- | --- | --- | --- |
| `erp.overview` | existing `PollErpOverviewTool` | R2 | already live as a job |
| `erp.low_stock` | existing `PollErpLowStockTool` | R2 | already live as a job |
| `erp.run_report` | `ErpConnector::runReport` | R2 | report key whitelisted, never free-form |
| `commerce.list_orders` | `CommercePlatform` admin | R2 | per store, date-bounded |
| `commerce.search_products` / `get_product` / `get_variant` | adapters | R2 | live prices and status, never memory |
| `commerce.get_availability` | adapter | R2 | follows product status, not quantity (Q9). ⚠ Per ADR-015 the answer is **not a boolean**: it is a per-channel verdict with `backorder` and `preorder` as first-class values, because a made-to-order product is misreported by "in stock: false". |
| `facts.query_orders` | `facts_orders` read model | R1 | typed filters only |
| `facts.check_integrity` | existing `CheckFactIntegrityTool` | R1 | reports, never repairs |
| `facts.snapshot_freshness` | new, section N.4 | R1 | closes a `docs/45` gap |
| `hq.list_approvals` / `hq.list_jobs` / `hq.recent_events` | HQ tables | R1 | how an agent answers "what needs attention" |

**Registered and enabled, HQ-internal write (R3):**

| Key | Effect | Notes |
| --- | --- | --- |
| `commerce.reconcile_facts` | existing tool | writes HQ read models only |
| `schedule.create_job` | `ScheduledJobService::create` | inherits the tier of the tool it will run (section M.5) |
| `schedule.change_job` | pause / resume / cancel / run-now | audited |
| `notify.operator` | in-app, and Telegram when configured | to Joy and named operators only, never to a customer |

Every one of these is written against the `CommercePlatform` interface, never against a shop's
shapes. That matters beyond tidiness: **ADR-015 makes the LaLaDia and Bionic adapters transitional**,
retiring as each store cuts over to the ERP's own website module. Phase 2's tools survive that
cutover unchanged, because they only ever knew the interface. `erp.*` tools speak to the ERP's HQ
API, which is a separate thing from that future `ErpCommerceAdapter` and is not affected by it.

**Registered and DISABLED in Phase 2 (present so the shape is right, off so nothing can run them):**

`commerce.create_order`, `commerce.update_order`, `commerce.cancel_order`, `erp.create_ticket`,
`message.send_customer`, and everything ads, content or publishing. Each ships with
`agent_executable: false` as well as `enabled: false`, so enabling the row alone does not hand it to
an agent. Turning one on is a Phase 3 or Phase 4 decision with its own approval.

---

## E. Permission and identity model

### E.1 Four kinds of identity, and why keeping them apart matters

Most authorization accidents in systems like this come from letting two of these blur together.

| Identity | What it is | Where it lives | What it grants |
| --- | --- | --- | --- |
| **Human** | a person who logs in with a password and TOTP | `users` + spatie roles/permissions | the ceiling on everything done in their name |
| **Agent** | a configured AI role: prompt, tools, model tier, memory scope | new `agents` table | never more than its enabling human; usually far less |
| **Service** | a machine caller: the scheduler, a webhook processor, a future API consumer | `system` pseudo-identity, and Sanctum tokens for external callers | a fixed narrow set, scoped per token |
| **Credential** | who HQ *is* at an external provider | `integration_credentials` | **nothing inside HQ** |

That last row is the one worth saying out loud. **A credential is an identity at a provider, never
an authorization in HQ.** Holding the LaLaDia admin token does not mean a caller may cancel an
order; it means that *if* the caller is permitted to cancel an order, this is the token the
connector will use. Systems that forget this end up read-only by design and read-write in practice,
because the token could always do more than the design intended.

### E.2 The `agents` table

```
agents(id, key, name, purpose, status active|paused|retired,
       enabling_user_id -> users.id,        -- the human whose authority the agent borrows
       role_key,                            -- executive | analytics
       allowed_tools jsonb,                 -- the MAXIMUM, not the effective set
       business_scope jsonb,                -- ["*"] or [business ids]
       brand_scope jsonb,
       model_tier, prompt_version,
       memory_scope jsonb,
       max_autonomy_level,                  -- section Q
       daily_cost_cap_usd, daily_call_cap,
       created_by, timestamps)
```

Agents are configuration, not code (`docs/08`). One runtime executes all of them. Adding the
`support` agent in Phase 3 is a row plus a prompt file, not a new subsystem.

### E.3 The intersection rule

The effective permission set for any gateway call is:

```
effective =  tool.permission
             AND enabling_human.permissions
             AND agent.allowed_tools
             AND business_scope(agent) INTERSECT business_scope(enabling_human)
             AND tool.supported_businesses
             AND policy(current)
             AND environment(current)
```

It is an intersection at every step, never a union, and never a fallback. If any term is empty the
answer is deny. There is deliberately no "agent override", no service account with wildcard
permissions and no break-glass path, because a break-glass path is the thing an attacker aims at.

If Joy needs something an agent cannot do, he does it as himself in the UI, and that is a feature.

### E.4 Business scoping

spatie's permissions are global to a user, and HQ needs "may view LaLaDia but not Bionic". Rather
than reconfigure the whole permission stack onto spatie's teams feature (which would touch every
existing check), Phase 2 adds a narrow pivot:

```
user_business_scopes(user_id, business_id, access read|manage, timestamps, primary key(user_id, business_id))
```

A user with **no rows** has group-wide scope, which keeps Joy and the `owner`/`operator` roles
working exactly as they do today with no migration of existing accounts. A user with rows is limited
to those businesses. The check lives in one `BusinessScope` service, called by the gateway and by
every controller that lists cross-business data.

This is a deliberate trade: an explicit narrow mechanism now, rather than a large refactor of a
working permission system for a requirement that today has one user.

### E.5 New permissions

Additive, following the existing seeder's discipline (`givePermissionTo`, never `syncPermissions`,
so re-running on production cannot strip a customised role):

```
hq.tools.view                  see the registry
hq.tools.manage                enable / disable a tool at runtime
hq.tools.erp.read              call R1-R2 ERP tools
hq.tools.commerce.read         call R1-R2 commerce tools
hq.tools.commerce.write        call R3+ commerce tools           (nothing enabled uses this in Phase 2)
hq.tools.facts.read
hq.tools.schedule              create and change scheduled jobs through a tool
hq.tools.notify                send an operator notification
hq.agents.view / hq.agents.manage
hq.approvals.view / hq.approvals.decide / hq.approvals.delegate
hq.policies.view / hq.policies.manage
hq.ask.use                     use the Ask HQ surface
hq.ai.cost.view
```

Permissions are grouped, not per tool. Sixty permission strings for sixty tools would be a list
nobody reads, and a list nobody reads is not a control.

---

## F. Policy Engine

### F.1 What it is

A deterministic, versioned, unit-tested PHP layer that answers one question: *given this actor, this
tool and these exact arguments, right now, what happens?* It returns exactly one of:

| Outcome | Meaning |
| --- | --- |
| `allow` | execute now |
| `require_approval(reason, approver_role, sla)` | park it, ask a human |
| `deny(reason)` | refuse, tell the caller why, record it |
| `dry_run_only(reason)` | execute the tool's preview path, never its effect |
| `require_confirmation(reason)` | for a **human** caller only: a second click in the same session. Meaningless for an agent, and denied if an agent triggers it. |

`docs/12` listed `require_additional_permission` as a sixth outcome. It is removed: "you lack a
permission" is a deny with a reason, and giving it a distinct outcome invites a code path that tries
to acquire the permission mid-call.

### F.2 Evaluation order

Order matters, because the first match wins and the cheap certain refusals must come first.

1. **Kill switches.** Global AI freeze flag, agent paused, tool disabled, business inactive,
   environment mismatch. -> `deny`.
2. **`agent_executable = false` and the caller is an agent.** -> `deny`. No approval can lift this;
   a human must perform the action themselves.
3. **Environment guard.** Non-production may not call a tool whose `read_write` is `write` against a
   production credential. -> `deny`.
4. **Permission.** Section E.3 intersection. -> `deny`.
5. **Risk tier default.** The tier's baseline outcome (section P table).
6. **Tool policy class.** If the tool declares one, it may make the tier's baseline *stricter*, never
   looser. A policy class that tried to return `allow` where the tier says `require_approval` is a
   programming error and throws in tests. Loosening happens only through an explicit guardrail rule
   (step 7) that a human configured and that is audited.
7. **Guardrail rules** from `policies.rules` for the business (the `docs/12` table: value
   thresholds, spend caps, discount limits, quiet hours, consent).
8. **Budgets.** Daily agent cost, tool call counts, business spend.
9. **Default: `deny`.** Not `require_approval`. See A.4.

### F.3 Representation

Policies are typed PHP classes reading data-driven thresholds, as ADR-004 decided. No rule DSL, no
OPA sidecar, no YAML interpreter. The thresholds live in:

```
policies(id, key, business_id nullable, tool_key nullable, rules jsonb,
         version, active_from, active_to, created_by, note, timestamps)
```

Versioned and append-only in effect: changing a policy writes a new row and closes the old one, so
`tool_calls` can point at the exact policy version that decided it. A decision that cannot be
reproduced later is not an audit trail, it is a rumour.

**Joy's Q5 answer ("as much as needs, no barrier for now") is honoured as written**: the value and
spend guardrails ship with no numeric caps. This is safe in Phase 2 only because no tool that can
spend money is enabled. Before the first R4 tool is turned on, section V.1 asks him for real numbers,
and the design refuses to invent them for him.

### F.4 Testing

Every rule gets table-driven tests at its boundary values, and every policy change ships with a test
that was **watched failing against the previous version**. That discipline is not new advice; it is
the standing practice across the ERP and both shops, and it is what caught three of the seven shop
tests in `docs/47`.

There is one additional test class specific to policy: a **completeness test** asserting that every
registered tool key resolves to a policy decision under a default context. A tool that falls through
to the `deny` default is a tool somebody forgot to classify, and CI should say so rather than
production discovering it.

---

## G. Approval Center

### G.1 The record

```
approvals(id, uuid,
          tool_key, tool_version,
          payload jsonb,               -- the EXACT validated args
          payload_hash char(64),       -- sha256 of canonical JSON of payload
          risk_tier, action_class,
          business_id, brand_id, store_id,
          requested_by_user_id, agent_key, ai_run_id, correlation_id,
          reason,                      -- the agent's justification, in its own words
          evidence jsonb,              -- the facts it used, each with source + as_of
          preview jsonb,               -- what the tool says will happen (dry-run output)
          estimated_cost jsonb,        -- money, quota, tokens
          status pending|approved|rejected|expired|cancelled|consumed,
          approver_role, decided_by_user_id, decided_at, decision_note,
          delegated_to_user_id, delegated_by_user_id, delegated_at,
          sla_due_at, escalate_to_role, expires_at,
          consumed_at, resulting_tool_call_id,
          timestamps)
```

### G.2 Binding: what was approved is what runs

This is the security-critical property, and it is worth being explicit about the attack it prevents:
an agent proposes a harmless-looking action, a human approves the card they read, and the agent then
executes something else under that approval.

The design makes that impossible in four steps:

1. The gateway stores the **validated** args, after schema validation and coercion. Not the model's
   raw output, and not a re-serialisation.
2. `payload_hash` is the SHA-256 of a canonical JSON encoding (sorted keys, no insignificant
   whitespace, fixed number formatting) of that payload.
3. On execution, the gateway recomputes the hash from the stored payload and compares. A mismatch
   is impossible through the application and therefore means database tampering: the execution is
   refused, an alert fires, and the approval is marked `cancelled`.
4. **The agent is never asked to regenerate the arguments.** The approval executes the stored
   payload directly. There is no round trip back through the model between approval and execution,
   which means there is no window in which anything could change.

Additionally: `tool_version` is stored, and an approval whose tool has since had a major version
bump is refused rather than executed against a changed schema. Approvals are **single use**
(`consumed_at`), so a stolen or replayed approval id executes nothing twice.

**Approve with edit** does not mutate the approval. It creates a new approval row with the edited
payload, a new hash, `superseded_by` linkage, and a `decision_records` row capturing proposal, final
and delta. That delta is not bookkeeping: it is the highest-value training signal the Behavior
Alignment Layer has, because it is Joy saying "close, but this is how I would have done it".

### G.3 Lifecycle

```
policy -> require_approval
  -> approvals row (pending) + tool_calls row (awaiting_approval) + audit entry
  -> notification to the approver role: one screen saying what, why, on which business,
     the evidence with timestamps, the estimated cost, and what happens if it is ignored
  -> decision:
       Approve       -> gateway executes the stored payload, under the approver's authority
                        AND the agent's identity (both recorded; neither is lost)
       Approve+edit  -> new approval row, executes that one, decision_record written
       Reject        -> reason returned to the agent run as feedback; run resumes and adapts
       Delegate      -> another permitted approver becomes responsible; both recorded
       Cancel        -> the requester withdraws it
  -> SLA breach      -> escalate to escalate_to_role, notify again
  -> expiry          -> status=expired. NEVER auto-approve. An ignored approval is a refusal.
```

That last line is a design commitment, not a default. Auto-approval on timeout would make every
approval gate decorative under load, which is exactly when it matters.

### G.4 Approvers

Joy holds every approval right (Q22), implemented as the `operator` role carrying all permissions,
so no queue can be blocked by an absent approver. Domain approver roles exist in the schema from day
one so Phase 5 and 6 do not need a migration, but in Phase 2 only Joy and the `owner` role are
populated.

Where an action belongs to the ERP (a purchase order, a journal), HQ creates a *proposal* and the
ERP's own multi-stage workflow approves it. **HQ never bypasses an ERP gate**, and HQ's approval of
a proposal is not the ERP's approval of the document.

### G.5 UI

Mobile-first, because approvals happen on a phone. One card per approval showing: the action in
plain language, the business and store, the agent and why it proposed this, the evidence with each
item's `as_of`, the estimated cost, the risk tier, the SLA clock, and a link to the live record in
the source system. Batch approve exists only for identical low-risk classes, and never across
businesses.

---

## H. Agent architecture

### H.1 Both Phase 2 agents are read-only in their first release

Neither `executive` nor `analytics` gets a single write tool in the Phase 2 release. They get R1 and
R2 read tools, `notify.operator` (R3, and its only possible target is an HQ operator, never a
customer), and the ability to *propose* a scheduled job, which itself lands as an approval when the
job's target tool warrants one.

This is a deliberate choice about the shape of the first release rather than a limitation. The value
in Phase 2 is "HQ can tell me what is happening across four systems, with evidence, every morning".
That value needs no write capability at all, and shipping it without one means the first weeks of
agent operation generate an evaluation record before anything can go wrong.

### H.2 Executive agent

**Purpose.** The daily briefing, the cross-business overview, anomalies, what needs Joy's attention
today, and answering an operator's question that spans systems.

**Tools.** All enabled R1 and R2 read tools across every business, plus `notify.operator` and
`schedule.create_job`. No commerce writes, no ERP writes, no messaging.

**Model tier.** HIGH for the briefing and for multi-step questions. It is the one place where
reasoning quality is worth the cost, because the output is a decision aid for the person who runs
the group.

**Delegation.** It does not chat with the analytics agent. When it needs analysis, it creates a
run for the analytics agent, which executes under its own identity and its own policy set. Every
action stays attributable to exactly one agent and one policy evaluation. Agents talking to each
other in free text would launder one agent's permissions into another's, which is the multi-agent
version of the credential confusion in E.1.

**Conservatism, concretely.** Its system prompt requires that every claim carry a source and an
`as_of`; that it says "I do not know" rather than estimating; that it distinguishes a fact it read
from an inference it drew (section N); and that it surfaces a decision for Joy rather than taking
one. Those are prompt-level instructions and therefore not enforcement. The enforcement is that it
holds no write tools.

### H.3 Analytics agent

**Purpose.** KPI analysis, sales and customer trends, anomaly detection, comparisons, explanations,
and the numbers behind the executive's narrative.

**Tools.** `facts.query_orders`, `facts.check_integrity`, `facts.snapshot_freshness`,
`erp.run_report` (whitelisted report keys), `commerce.list_orders`, and the product read tools.

**No SQL. At all.** `facts.query_orders` takes a typed filter object: business, store, date range,
status set, grouping dimension, metric. The application compiles that to a query. The model never
sees a table name, never emits a query, and cannot request a column that the schema does not expose.
This is the ERP assistant's proven pattern and it is not negotiable.

**Model tier.** MEDIUM for routine analysis, HIGH when the operator asks "why".

**The honesty requirement.** Its most important behaviour is refusing to answer. HQ holds 16 order
facts, 11 of them without line detail. An analytics agent that produces a confident trend from
sixteen rows would be worse than useless. Every response carries the row count and the coverage
window it is based on, and the agent is required to state when the data is too thin to support the
question. A deterministic guard backs this up: `facts.query_orders` returns `coverage` metadata
(row count, date range, share of orders lacking line detail) alongside every result, and the
briefing renderer refuses to print a trend claim whose supporting coverage is below a configured
floor.

### H.4 The agent loop

```
 trigger (operator turn | schedule | event)
   -> agent selected by role + business + channel
   -> context assembled (section I)
   -> ModelRouter picks provider + model for the tier and the task
   -> model call with JSON-schema-enforced output: {answer, evidence[], proposed_tool_calls[]}
   -> for each proposed call:  ToolGateway::execute(...)     <- everything in section D happens here
        -> allow            -> result folded back into context, loop may continue
        -> require_approval -> run checkpointed, approval raised, run resumes on decision
        -> deny             -> refusal reason returned to the model as feedback, once
   -> loop bounded: max 8 tool calls and max 3 model turns per run, hard-stopped by the runtime
   -> ai_runs row: tokens, cached tokens, cost, latency, outcome
   -> answer delivered to the caller
```

The loop bound is not a performance measure. It is the containment for the failure mode where a
model retries a denied action in slightly different words until something gets through. After the
second denial of the same tool key in one run, the runtime stops the run and reports it, and
repeated denials across runs raise an alert, because that pattern is either a broken prompt or an
injection attempt and both need a human.

---

## I. Context assembly and the Behavior Alignment Layer

ADR-013 stands unchanged: **no fine-tuning, no model training, in this phase or any planned one.**
"The AI learns how Joy works" is implemented as data the system already produces.

```
Model + Memory + Knowledge + Skills + Tools + Policies + Feedback + Evaluation
```

### I.1 What goes into a prompt, and what each part may do

Every context block is labelled with its kind, its source and its `as_of`, and the kinds have
different authority. This table is the whole security model of context assembly:

| Block | Source | May it influence the answer? | May it influence what is *permitted*? |
| --- | --- | --- | --- |
| System prompt + agent role | versioned file in `resources/prompts/` | yes | no |
| Policy summary | policy engine, generated | yes (so the model stops proposing refusals) | **no** |
| Owner preferences | `owner_preferences` | yes: tone, length, thresholds he likes to see | **no** |
| Structured memory | `owner_preferences`, brand guidelines | yes | **no** |
| Episodic memory | `decision_records`, past run summaries | yes, as examples | **no** |
| Semantic knowledge | approved `knowledge_documents` + pgvector | yes, cited | **no** |
| Live tool results | this run, via the gateway | yes, and they win every conflict | **no** |
| Operator's turn | the authenticated human | yes, it is the instruction | **no** |
| **External / customer-authored content** | order notes, customer names, addresses, ERP free text | as **data only** | **no** |

The right-hand column has no "yes" in it, and that is the point. **Nothing retrieved, remembered or
received can change what is permitted**, because permission is decided after the model, by code that
never reads any of these blocks.

### I.2 Precedence when blocks disagree

Live tool result beats retrieved memory beats stored preference beats prompt example. The model is
instructed to say when it is overriding remembered information with a live fact, and the briefing
renders that as a visible note. This is the `docs/09` rule and the `docs/45` lesson combined: prices,
stock, order status and balances are **never** memory. They are looked up every time.

### I.3 Feedback capture

Every approval decision, every edit of an AI draft, every rejection with a reason and every explicit
rating writes a `decision_records` row (proposal, final, delta, reason, outcome). The table exists
and is empty; Phase 2 is the first thing to write to it. Retrieval then surfaces the closest past
decisions as examples on future runs, which is how "he always wants the Bionic numbers separated
out" becomes behaviour without anybody writing a rule.

### I.4 Evaluation

A weekly scheduled job replays stored `decision_records` against the current prompt and model, and
reports agreement rate and edit distance per agent. Falling agreement is the signal that a prompt
change or a model change made things worse, and it is the evidence base for any future widening of
autonomy (section Q). Results land in an `evaluations` table per prompt version.

Phase 2 ships the job and the table. It will report almost nothing for the first weeks because there
is no history yet, and that is expected and fine: the point is that the record starts accumulating
from the first day an agent runs, not from the day somebody wishes it had.

---

## J. Model Router and cost accounting

### J.1 Provider neutrality, and where model ids live

`ModelRouter::for(TaskType $task, RoutingContext $ctx): ModelChoice`.

**No model id appears in business code.** Ids live in a `model_tiers` table (tier, provider, model
id, context window, input and output price per million tokens, capabilities, priority, active), so a
price change or a new model is a row edit. Provider access is an `integration_credentials` row, per
Q4, changeable from the UI without a deploy.

Routing inputs: task type, estimated complexity, latency requirement, remaining cost budget,
required context size, modality, required capabilities (function calling, JSON schema output,
vision), data sensitivity, and current provider health.

| Tier | Used for | Selection |
| --- | --- | --- |
| LOW | classification, intent, extraction, NL-to-JobSpec parsing, summarisation, schema repair | cheapest model meeting the capability requirement |
| MEDIUM | analytics narration, standard operator questions, briefing sections | mid-tier |
| HIGH | the daily briefing narrative, multi-step reasoning, "why did X happen" | strongest reasoning model |
| SPECIALISED | embeddings | embedding model, dimension recorded with each vector |

Phase 2 needs LOW, MEDIUM, HIGH and embeddings. Vision, image, audio and realtime are Phase 6 and 7.

### J.2 Fallback and degradation

Provider outage, rate limit or a malformed structured output triggers, in order: retry once on the
same model with a repair instruction; then the tier's fallback model at a **different provider**;
then, if the task is degradable, the tier below with the degradation recorded on the run; then
failure with an operator notification. A briefing that ran on a lower tier says so on its face
rather than silently being worse.

A circuit breaker per provider (consecutive failures within a window) stops HQ hammering a provider
that is down, and its state is visible on the platform dashboard.

### J.3 Cost accounting

`ai_runs` already carries provider, model, tier, prompt version, input tokens, output tokens, cached
tokens, latency, estimated cost and status. Phase 2 adds three columns:

- `actual_cost_usd` and `cost_source` (`estimated` | `provider_reported`), because estimates from
  token counts and provider billing disagree, and a cost report that quietly mixes them is a report
  that cannot be reconciled later.
- `degraded_from_tier`, so a downgrade is visible in the data and not only in a log line.

Attribution works by rollup along the correlation id and the foreign keys that already exist:

```
ai_runs (agent, model, tokens, cost)
   -> correlation_id ties to -> tool_calls -> connector_calls
   -> business_id / brand_id on the run
   -> input_ref names the conversation turn, job run or approval that caused it
```

That gives cost per run, per agent, per business, per brand, per conversation, per scheduled job and
per briefing on day one. Cost per order, per customer, per campaign and per content item arrive in
Phases 4 to 6 by adding a reference, not by changing the model, which is why `input_ref` is a
generic string ref rather than a typed foreign key.

**Budget behaviour.** Per-agent daily cap and per-business monthly cap. On breach the router
downgrades to the tier below and notifies; on a hard cap it refuses and notifies. Refusing silently
is forbidden: a briefing that did not run must announce that it did not run. Per Q5, Phase 2 ships
with a soft advisory cap and no hard cap, and section V.1 asks Joy whether to set one.

---

## K. Ask HQ

### K.1 What it is, and what it is emphatically not

Ask HQ is a conversation surface for authenticated HQ operators. It is a **client of the runtime**,
not a privileged environment. The browser posts a turn; the runtime assembles context, calls a
model, and routes every proposed action through the gateway under the operator's own identity
intersected with the agent's. The UI renders results. **The UI holds no authority the operator does
not already hold, and no code path lets the chat perform something the same person could not do
through a normal screen.**

Concretely: there is no "execute this" endpoint that takes a tool key from the browser. The browser
can send a message, approve an approval (through the Approval Center's own permissioned endpoint),
and cancel a run. That is all.

### K.2 Data model

```
ask_conversations(id, uuid, user_id, agent_key, business_scope jsonb, title,
                  status open|archived, last_activity_at, timestamps)
ask_messages(id, conversation_id, role user|assistant|tool|system,
             content, structured jsonb, ai_run_id, tool_call_id,
             evidence jsonb, tokens, cost_usd, created_at)
```

**These are separate tables from the customer conversations Phase 3 will build, on purpose.** An
operator turn is trusted input from an authenticated, 2FA-verified human. A customer message is
untrusted input from the public internet. Putting both in one table with a `kind` column means the
whole system's safety depends on every future query remembering a `where` clause, and one forgotten
clause turns a customer's message into an operator instruction. Two tables cost a little duplication
and remove that failure mode entirely.

### K.3 Behaviour

- **Streaming** over Server-Sent Events; the run continues server-side if the browser disconnects,
  and reconnecting replays from the persisted turn.
- **Tool calls are visible.** Each one renders as a row: tool, arguments, policy outcome, duration,
  result summary. An operator can always see what HQ actually did, which is the difference between a
  control surface and a magic box.
- **Evidence and citations.** Every factual claim links to its source: a fact row, an ERP report, a
  shop order, each stamped with `as_of`.
- **Approval prompts inline.** When policy parks an action, the card appears in the conversation and
  in the Approval Center. Deciding in either place resolves both.
- **Action previews.** Any R3+ proposal shows its dry-run output before the decision.
- **Errors are shown as errors.** A denied tool call says which policy denied it and why. A model
  failure says the model failed. Nothing is smoothed into a plausible-sounding sentence.
- **Agent routing.** The operator may pick an agent; by default the executive answers and delegates
  analysis. The chosen agent is shown, because "which agent answered" changes what the answer means.

---

## L. Natural-language scheduling

The scheduler already exists, is durable, is running six production jobs, and its table already has
`created_from` and `nl_source_text` columns waiting. Phase 2 adds the front door, and **does not
create a second scheduler**. ADR-005 stands.

### L.1 The flow

```
 "Every morning at 9 send me the LaLaDia and Bionic sales summary"
   -> LOW-tier model, strict JSON schema, temperature 0  ->  JobSpec draft
   -> JobSpecValidator (deterministic, no model):
        kind in {once,cron,interval,event}; cron parseable; interval >= 60s;
        timezone real; tool_key REGISTERED; payload validates against that tool's inputSchema;
        business/store/brand ids RESOLVED to real rows, never invented
   -> unresolved or ambiguous  ->  ONE clarifying question, never a guess
   -> preview card, in words and in the job's own timezone:
        "Every day at 09:00 Asia/Dhaka, run 'Sales summary' for LaLaDia and Bionic,
         deliver to Telegram. First run: Tue 9 Sep 2026 09:00 (+6). Approve?"
   -> approval state = the tier of the tool the job will run (section L.5)
   -> ScheduledJobService::create(..., createdFrom: 'nl', nlSourceText: <the original words>)
   -> tick -> job_runs -> ToolGateway -> execution -> history -> notification
```

The parse is a proposal like any other. The validator, not the model, decides whether a job exists.

### L.2 What the schema already supports and what is added

Supported today, unchanged: one-time (`kind=once` + `run_at`), recurring cron, interval,
event-triggered (`event_type`), timezone per job defaulting to `Asia/Dhaka`, retry with backoff,
missed-job policy (`skip` | `catch_up` | `run_latest_only`), pause, resume, cancel, run-now, retry a
run, and full per-attempt execution history in `job_runs.attempts`.

Added in Phase 2: **delayed** jobs (expressed as `kind=once` with a computed `run_at`, so no schema
change), an `edit` path through `ScheduledJobService` that recomputes `next_run_at` and audits the
change, and the NL parser plus preview.

### L.3 Ambiguity handling

Bangla and English phrasing, relative dates ("tomorrow", "next Sunday", "আগামীকাল"), and bare times
all resolve against `Asia/Dhaka` and the operator's current time. Three things always produce a
question rather than a guess: an unresolvable entity (which store?), an ambiguous recurrence ("every
month" on the 31st), and a time that has already passed today. `docs/21` already states the rule and
it is worth restating because it is the one place a scheduler quietly does the wrong thing forever:
**the parser produces a persisted row or a question, never a silent guess.**

### L.4 Timezone correctness

This is the failure mode `docs/45` already caught once, when a Laravel `datetime` cast dropped the
ERP's `+06:00` offset and stored wall clock, putting a snapshot six hours in its own future. The
scheduler must not repeat it:

- Every instant is stored `timestamptz` and computed in UTC. The job's `timezone` column governs
  only the interpretation of a cron expression and the rendering of a time to a human.
- `NextRunCalculator` computes in the job's zone and converts, never the reverse.
- Tests cover a DST-shifting zone even though Asia/Dhaka has no DST, because a job may one day carry
  another zone and the bug would be invisible until it wasn't.

### L.5 Approval of a scheduled job

A scheduled job is a *deferred tool call*, so it inherits the risk tier of the tool it will run. A
job running an R1 or R2 read tool is created active. A job running an R3+ tool is created with
`approval_state = pending` and does not run until approved. Changing a job's payload or tool
re-triggers approval. Otherwise scheduling would be a way to launder a gated action through a delay,
which is exactly the kind of gap that only shows up after somebody exploits it.

### L.6 Business scheduling stays separate from infrastructure cron

Verified in the code today: `routes/console.php` contains only the tick, the event drain, the Horizon
snapshot and two prune commands. No business logic. That separation holds in Phase 2 and the tick
watchdog (section N.5) enforces it by alerting when the tick itself is late.

---

## M. Daily executive briefing

### M.1 Structure over prose

The briefing is not "a paragraph the model wrote". It is a typed document, assembled deterministically
and narrated by a model, where **every element carries its kind**:

| Kind | Definition | Rendering |
| --- | --- | --- |
| `fact` | read from a system of record or an HQ read model this morning, with `source` and `as_of` | plain, with the source |
| `metric` | computed by HQ from facts, with the formula named | shown with its inputs available |
| `inference` | the model's reading of the facts | visibly marked as an inference |
| `recommendation` | a suggested action, always with the decision left to Joy | marked, and linked to the tool that would do it |

Never present an inference as a fact. Enforcement is deterministic, not a prompt instruction: the
composer collects an **evidence bundle** of typed values first, and a post-check asserts that every
number appearing in the narrative exists in that bundle. A narrative containing an unsourced figure
marks the briefing `degraded` and shows the failure, rather than shipping a plausible number nobody
can trace. This is the direct lesson of `docs/44`, where every order line read 0.00 and nothing
complained, and of `docs/45`, where a timestamp six hours in the future looked perfectly valid.

### M.2 Content

What happened yesterday (orders, value, status changes per business and store), what is scheduled
today, anomalies (order volume or value outside the recent band, integrity discrepancies, failed
jobs, dead events, stale snapshots), inventory signals from the ERP low-stock read, pending approvals
and their SLA clocks, and the decisions that need Joy. Marketing and financial sections exist as
placeholders that render "not connected yet" until Phase 5, because an empty section that says why
it is empty is honest and a missing section is a silent gap.

### M.3 Freshness is part of the output

Each source carries a freshness budget. If the ERP snapshot is older than its budget, or a shop's
reconcile has not run, the briefing says so **at the top**, before the numbers. HQ has already been
bitten by a stale snapshot that looked exactly like a fresh one; the fix is that freshness is a
first-class field of the briefing, not an operational detail.

### M.4 Generation and delivery

A scheduled job (`kind=cron`) running a `briefing.compose` tool. Collection is deterministic tool
calls through the gateway; narration is one HIGH-tier model call over the assembled bundle; delivery
is in-app plus whichever channel Joy chooses (section V.4). The rendered briefing is stored, so it
can be re-read, and so `decision_records` can later link "he acted on this recommendation" back to
the exact briefing that made it.

Full audit: which tools ran, what they returned, which model narrated, what it cost, how long it
took, and whether it was degraded.

---

## N. Observability

### N.1 One trace, end to end

`correlation_id` already exists on `ai_runs`, `job_runs`, `audit_logs` and the request middleware.
Phase 2 makes it unbroken:

```
operator turn (ask_messages.id, correlation_id)
  -> ai_runs        (agent, model, tier, tokens, cost, latency, prompt version)
  -> tool_calls     (tool, redacted args, policy result, policy version, approval id, duration, status)
  -> approvals      (who decided, when, on what hash)
  -> connector_calls(connector, operation, idempotency key, HTTP status, provider ref, duration)
  -> the external system's own response
  -> audit_logs     (append-only, trigger-enforced)
```

Given any one of those ids, an operator can retrieve the whole chain. That is the acceptance test
for this section, and it is a Playwright test, not a claim.

### N.2 Redaction

Three layers, because one is never enough:

1. **Schema-driven.** Each tool's `redact` list names arg and output paths replaced with
   `[redacted]` before anything is written to `tool_calls`.
2. **Key-based.** `Auditor::redact()` already blanks known secret-bearing keys.
   ⚠ **A real trap to fix in M1:** that list currently includes the literal key `payload`, so any
   audit record whose `after` contains a `payload` key is blanked wholesale. Reused naively for tool
   arguments, it would erase exactly the evidence the audit exists to hold. The fix is to make the
   key list apply to leaf values and let the schema-driven layer handle structured payloads.
3. **Log processor.** `RedactSecretsProcessor` already scrubs Monolog output.

**No credential value is ever stored anywhere.** `tool_calls` records which credential *id* was
resolved, never its content. The credentials screen shows present or absent and an expiry, never a
value, and that is enforced by the model cast rather than by the template.

### N.3 Dashboards

| Dashboard | Panels |
| --- | --- |
| AI health | runs and success rate by agent, failures by error class, p50/p95 latency by tier, denials by policy, approvals pending and overdue, loop-bound hits, repeated-denial alerts |
| Cost | spend by provider, model, agent and business per day; cost per run, per conversation, per briefing; budget burn; degradations |
| Control plane | tool calls by tier, policy outcome distribution, approval decision times, tools disabled, kill-switch state |
| Platform | Horizon queue depth, scheduler tick freshness, event inbox lag and dead count, connector circuit state, credential expiries, fact-integrity discrepancies, snapshot freshness |

### N.4 Two gaps from Phase 1 that this closes

`docs/45` recorded that nothing watches ERP snapshot **freshness**, and that a stale snapshot is
indistinguishable from a fresh one. Phase 2 adds `facts.snapshot_freshness` as a tool, a panel and
an alert, so the condition that hid a dead ERP hostname for a day cannot hide again.

### N.5 Alerting

Telegram where configured, in-app otherwise: scheduler tick missed beyond its 180-second staleness
threshold, Horizon down, provider circuit open, budget at 80 and 100 percent, approval SLA breach,
repeated policy denials for one agent, fact-integrity discrepancy, event dead-letter, credential
expiring within 14 days, snapshot stale.

---

## P. Risk taxonomy

### P.1 Two axes, not one

The existing three action classes (AUTO / APPROVAL_REQUIRED / HUMAN_ONLY) answer "who may run it".
The requested R0-R5 scale answers "how much damage could it do". These are different questions:
damage is intrinsic to the code and essentially never changes, while who may run it depends on the
actor, the business, the amount, the time and the current guardrails, and changes often.

So Phase 2 keeps both, orthogonally:

- **Risk tier** is a static property of the tool, declared in its descriptor, changed only by a code
  change and a review.
- **Action class** is the *derived outcome* of policy for a specific call, computed at runtime.

The three familiar names survive as that outcome, so `docs/11` and `docs/13` remain readable.

### P.2 The tiers

| Tier | Definition | Examples | Default outcome | Approval |
| --- | --- | --- | --- | --- |
| **R0** | Pure computation. No I/O beyond the process. | unit conversion, formatting, date maths | allow | none |
| **R1** | Internal read. HQ's own read models and control tables. | `facts.query_orders`, `hq.list_approvals`, `facts.check_integrity` | allow, permission-scoped | none |
| **R2** | External read. Calls a system of record or a provider. Consumes quota, may expose data, may cost money. | `erp.overview`, `commerce.list_orders`, `commerce.get_product` | allow, permission-scoped, rate- and budget-limited | none |
| **R3** | Internal write. Changes HQ state only. Reversible in HQ. Invisible outside HQ. | `commerce.reconcile_facts`, `schedule.create_job`, `notify.operator` | allow for the owning agent, audited | none by default |
| **R4** | External side effect, recoverable. Something changes outside HQ but can be corrected without loss. | create a draft order, open an ERP ticket, schedule a post, create a paused campaign | **require_approval** | yes, may be relaxed by an explicit guardrail |
| **R5** | Material or irreversible. Money, a message a customer will read, a publication, a price, a cancellation, a deletion, a policy or permission change. | refund, send customer message, publish content, change price, cancel order, delete a record | **require_approval**, strictest approver | yes, never relaxable to AUTO by configuration alone |

Orthogonal to the tier, every tool carries `agent_executable`. `false` reproduces the old HUMAN_ONLY
class exactly, and it is checked at step 2 of policy evaluation, before anything else, because it
can never be overridden by an approval. A human may run such a tool; an agent may propose it and
must then hand it to a person.

### P.3 What Phase 2 actually enables

Every tool **enabled** in the Phase 2 release is R0 to R3. R4 and R5 tools are registered so the
shape is right and the tests exist, and they ship `enabled: false` **and** `agent_executable: false`.
Turning one on requires a code change for the second flag and an audited runtime action for the
first: two different kinds of decision by two different mechanisms, which is the correct amount of
friction for handing an AI the ability to spend money or talk to a customer.

---

## Q. Autonomy boundaries

### Q.1 Levels

| Level | Meaning | Phase 2 |
| --- | --- | --- |
| **A0 Shadow** | The agent runs on real inputs and writes what it *would* do to a shadow log. Nothing executes. | how both agents start |
| **A1 Propose** | The agent produces answers and proposals; a human executes. | where both agents sit after shadow |
| **A2 Execute with approval** | The agent executes, each action individually approved. | available for R4 when Joy enables one |
| **A3 Execute within guardrails** | The agent executes without per-action approval while inside configured limits; breaching a limit downgrades to A2. | **not used in Phase 2** |
| **A4 Execute and report** | Execution with after-the-fact review only. | **not in this roadmap at all** |

`agents.max_autonomy_level` caps each agent, and policy caps each call. The lower of the two wins.

### Q.2 The commitment

**Phase 2 introduces no autonomous write behaviour, silently or otherwise.** Both agents ship at A0
for a shadow period, then A1. The only things they can change without a human are HQ's own read
models and an operator notification, both R3, both reversible, both audited, none visible outside
HQ.

Widening is possible later and is deliberately made to require evidence rather than time: raising an
agent's level requires a decision-replay agreement rate above a stated threshold over a stated
number of decisions, on that agent, for that tool class. Autonomy is earned against a measurement,
not granted on a schedule. That is ADR-013's evaluation loop being used for the purpose it was built
for, and it is why `decision_records` had to exist from Phase 0.

---

## R. Security boundaries

### R.1 Credential isolation

Credentials live encrypted in `integration_credentials` (AES-256-GCM under `APP_KEY`), are decrypted
only in the connector layer, and are resolved at the last step of the gateway. No tool argument, no
`ToolContext` field, no audit row, no log line, no model prompt and no exception message can carry
one. The credentials screen shows presence and expiry only.

⛔ The Phase 1 rule carries forward and applies to HQ too: **never insert a secret by SQL.** The
shops' `webhooks.secret` is an encrypted cast, and a raw INSERT stores plaintext that then fails
every HMAC silently. HQ's credential payload behaves the same way. Credentials are created through
the application, always.

### R.2 Tool isolation

A tool receives validated arguments and a context object. It cannot see another tool's arguments,
cannot call another tool directly (composition happens in a skill, which is itself gated), and
cannot reach the database outside its declared repository. The `Unsupported` exception exists so a
tool asked to do something outside its contract refuses loudly rather than improvising.

### R.3 Business and tenant isolation

Proven live twice in Phase 1: a Bionic key against laladia.com returns 401 and vice versa, because
`CommerceRegistry::secret()` matches provider plus `account_ref` plus active status with **no
fallback**. Phase 2 extends the same no-fallback discipline to the gateway: a call naming a business
outside the acting identity's scope is denied before credential resolution, so the wrong token is
never even fetched, let alone sent.

### R.4 Prompt injection and untrusted content

The threat is real in Phase 2 even though both agents are read-only, because customer-authored text
(names, addresses, order notes, ERP free-text fields) flows into their context. The defenses, in
order of how much they actually matter:

1. **Enforcement is downstream of the model.** This is the one that counts. An injected instruction
   can at most cause the model to *propose* a tool call. That proposal then faces schema validation,
   permission intersection, policy and approval, none of which read prose. The worst achievable
   outcome of a successful injection is a denied call and an alert.
2. **The model is never told a forbidden tool exists.** It cannot name what it has not been shown.
3. **Untrusted content is delimited and labelled** with its provenance, and the system prompt states
   that only the operator turn carries instructions.
4. **Tool outputs are typed structures**, not free text that could read as an instruction.
5. **No tool accepts SQL, a URL, a file path, a class name or a shell string.** There is nothing for
   an injected instruction to aim at.
6. **Repeated denials raise an alert.** An injection attempt looks like a loop of refused calls, and
   that pattern is monitored.

### R.5 Approval integrity

Section G.2: canonical-JSON hash, tool version pinning, single use, no regeneration between approval
and execution, mismatch treated as tampering.

### R.6 Audit immutability

Already enforced by a Postgres trigger that raises on UPDATE and DELETE against `audit_logs`, plus
a model-level guard. Nothing in Phase 2 weakens it. The application user does not need and should
not be granted the right to drop that trigger; verifying that is an M1 task.

### R.7 Replay protection and idempotency

Inbound: `events.dedupe_key` is unique, webhook signatures verified, timestamps checked. Outbound:
idempotency keys derived from validated arguments, provider idempotency where supported,
`reconcile()` before any retry whose previous state is uncertain (ADR-011).

### R.8 Service-to-service authentication

The scheduler and event processor run in-process as the `system` identity, which is a narrow
permission set and not a wildcard. Any future external caller uses a Sanctum token with abilities
scoped to a business and a tool group, rotated on a schedule. Nothing authenticates by network
position alone.

---

## S. Failure and recovery

Design assumption, unchanged from ADR-011: **execution is at-least-once, side effects are idempotent
or reconciled, and HQ never claims exactly-once for anything outside itself.**

| Failure | Behaviour |
| --- | --- |
| Model timeout | retry once same model; then fallback provider; then tier below if degradable; else fail with notification. Run marked `failed`, never silently empty. |
| Provider outage | circuit breaker opens; router avoids that provider; alert; queued non-urgent work waits, live turns fail fast with an honest message. |
| Malformed model output | one repair attempt with the schema and the validation error; then fallback model; then fail. The malformed output is stored for evaluation. |
| Policy denial | terminal for that call. Reason returned to the run once as feedback. Two denials of the same tool in one run stop the run. |
| Missing approval | the run parks. No worker is held. It resumes on decision. |
| Expired approval | never executes. The requester is told. A new proposal must be made. |
| Connector timeout | tool returns `uncertain` if the effect may have landed, `failed` if provably not. `uncertain` blocks automatic retry until `reconcile()` answers. |
| External API failure | classified: `Refused` (business rule, do not retry), `Unavailable` (retry with backoff), `Uncertain` (reconcile first). These five exception types already exist in the commerce layer. |
| Duplicate tool invocation | idempotency key hit returns the recorded result without executing. |
| Partial workflow | each tool call is its own transaction boundary; the run is checkpointed after each; resume continues from the last completed call, never from the start. |
| Scheduler failure | tick watchdog alerts beyond 180 seconds; missed slots handled per `missed_policy`; `job_runs` unique slot means recovery cannot double-schedule. |
| Stale data | freshness budget per source; a stale source is announced, and beyond a hard limit the dependent output is refused rather than published. |
| Reconciliation mismatch | `hq:facts:check` reports and never repairs; a discrepancy raises an alert and a human decides. A successful run that finds a discrepancy is a **successful** run. |
| Repeated denial pattern | run stopped, alert raised, treated as a possible injection until reviewed. |

---

## T. Explicit non-goals for Phase 2

Deferred, by name, so nothing creeps in:

customer-facing AI and the conversation inbox (Phase 3) · AI order taking (Phase 4) · Meta Ads
automation (Phase 5) · Google, TikTok and other ad platforms (Phase 5+) · the Content Factory and
publishing (Phase 6) · voice (Phase 7) · avatar and voice cloning (Phase 8) · autonomous business
operation (Phases 9-10) · **unrestricted browser agent, ever** · **unrestricted database or SQL
access for any model, ever** · **model fine-tuning or training, ever** (ADR-013) · retiring n8n or
Hermes (needs explicit approval, `docs/31` Q23 and Q24) · any change to the ERP, LaLaDia or Bionic
repositories.

Two of those are not "later", they are never: unrestricted SQL and fine-tuning. They are listed with
the deferrals only so the list is in one place.

---

## U. Implementation milestones

Seven milestones. Each is a coherent, shippable, independently valuable unit that ends with the
feature exercised against a real system, per the standing rule that a green suite is not evidence.

### M1 - Control-plane foundation

**Purpose.** The tables, types and taxonomy everything else references. No behaviour change.
**Delivers.** `agents`, `approvals`, `policies`, `tools`, `evaluations`, `user_business_scopes`
migrations; the `tool_calls.approval_id` foreign key; `ToolDescriptor`, `RiskTier`, `ActionClass`,
`AutonomyLevel` enums; the extended `Tool` interface with descriptors on all six existing tools;
new permissions in the additive seeder; the `Auditor::redact` `payload` fix (N.2).
**Dependencies.** None.
**Safety gate.** Additive migrations only, no data change, no behaviour change. Verified on a scratch
Postgres before production, per standing practice. The six live scheduled jobs must be unaffected.
**Tests.** Migration up and down on Postgres 17; a descriptor completeness test asserting every
registered tool declares a tier, a permission and an audit action; an audit-immutability test proving
UPDATE and DELETE still raise; a redaction test proving a tool payload survives while a secret does
not.
**Outcome.** The vocabulary exists. Nothing behaves differently.
**Rollback.** `migrate:rollback`; no production data depends on the new tables yet.

### M2 - Tool Gateway, Policy Engine, Permission model

**Purpose.** The single door, and the proof that it works: move the running scheduler through it.
**Delivers.** `ToolGateway::execute`, `PolicyEngine`, `PermissionResolver`, `BusinessScope`,
`CredentialResolver`, `IdempotencyStore`, first `tool_calls` writes, tier-default policies, the tool
registry screen, and the runtime enable/disable kill switch.
**Dependencies.** M1.
**Safety gate.** ⭐ **The six production scheduled jobs run through the gateway and produce
byte-identical output to their current direct execution.** Captured before, compared after. This is
the milestone's entire credibility: if the gateway changes what a working job does, it is wrong.
**Tests.** Policy table-driven at boundaries, each watched failing first; a completeness test that
every registered tool resolves to a decision; permission intersection tests including the empty
cases; idempotency replay; `reconcile()` before retry on `uncertain`; a denied call writes a
`tool_calls` row and touches no connector.
**Outcome.** Every tool execution in HQ, including the scheduler's, passes policy and is recorded.
**Rollback.** Feature flag `gateway.scheduler` returns `RunScheduledJob` to direct execution; the
gateway stays deployed and unused.

### M3 - Approval Center

**Purpose.** Value-gated human control, with binding.
**Dependencies.** M2.
**Delivers.** Approval lifecycle, payload hashing and verification, single-use consumption,
approve / reject / edit / delegate / cancel / expire, SLA and escalation, `decision_records` written
on every decision, the mobile-first Approval Center UI, inline approval in Ask HQ later.
**Safety gate.** Exercised against a real parked action: a scheduled job targeting an R4 tool is
created, parked, approved, and executes the stored payload. A deliberately tampered payload is
proven to be refused.
**Tests.** Hash mismatch refuses; expiry never auto-approves; a consumed approval cannot execute
twice; approve-with-edit creates a new row and a decision record; an approval on a since-bumped tool
version refuses; Playwright for the decision flow on a phone viewport.
**Outcome.** A gated action can safely exist.
**Rollback.** No R4 tool is enabled yet, so disabling the UI leaves nothing stranded.

### M4 - Model Router, AI runtime, cost accounting

**Purpose.** HQ can call a model, choose one deliberately, and account for it. Still no agent.
**Dependencies.** M1 (M2 not strictly required, but sequenced after it).
**Delivers.** `model_tiers` table and seeder, `ModelRouter` with fallback and circuit breaker,
provider drivers behind one interface, structured-output enforcement with one repair attempt,
`ai_runs` writes, the three new cost columns, prompt-cache-friendly prompt assembly, budget checks,
and `hq:ai:probe` to exercise a real provider end to end.
**Dependency on Joy.** Provider API keys must exist as `integration_credentials` rows (Q4). Until
they do, this milestone is testable only against recorded fixtures.
**Safety gate.** `hq:ai:probe` performs one real call per configured provider and records a complete
`ai_runs` row with correct token counts and cost. Model ids read from the `claude-api` reference at
implementation time, never hard-coded.
**Tests.** Routing table-driven; fallback on simulated outage; malformed output repaired then failed;
cost arithmetic against fixtures; budget breach downgrades then refuses; **no model id appears in
`app/` outside the driver layer** (a grep test).
**Outcome.** A model call is a first-class, costed, traced operation.
**Rollback.** Flag off; nothing depends on it yet.

### M5 - Executive and Analytics agents

**Purpose.** The first two agents, read-only, shadow first.
**Dependencies.** M2, M3, M4.
**Delivers.** Agent runtime and loop with hard bounds, context assembly with the labelled-block
model, prompt files under version control, the Phase 2 read tool set, `facts.query_orders` with
coverage metadata, shadow-mode logging, decision-replay evaluation job, agent admin screen.
**Safety gate.** ⭐ **Both agents run at A0 shadow against real production data for an agreed period
before A1.** Zero write tools in the release. The shadow log is reviewed with Joy before either
agent is allowed to answer him for real.
**Tests.** Golden question set with expected tool calls; a safety corpus (injection attempts,
credential requests, other-business data requests, SQL requests) with expected refusals; loop-bound
enforcement; a test that no agent's effective tool set contains a write tool; coverage-floor refusal.
**Outcome.** HQ can answer a cross-business question with cited evidence.
**Rollback.** Set both agents `paused`; nothing else is affected.

### M6 - Ask HQ and natural-language scheduling

**Purpose.** The operator surface, and the scheduler's new front door.
**Dependencies.** M5.
**Delivers.** `ask_conversations` and `ask_messages`, SSE streaming with reconnect, tool-call and
evidence rendering, inline approval cards, NL-to-JobSpec parser with strict schema, deterministic
`JobSpecValidator`, entity resolver, preview card, `created_from: 'nl'` job creation, job edit and
delayed jobs.
**Safety gate.** ⭐ A real job created from a real sentence in Bangla and in English, previewed,
approved, and observed running at the correct Asia/Dhaka time, with `nl_source_text` preserved. And
a deliberately ambiguous sentence proven to ask a question rather than create a job.
**Tests.** Parser against a fixture corpus in both languages; validator rejects unregistered tools,
unresolvable entities and impossible schedules; timezone tests including a DST zone; a test that the
chat endpoint cannot execute a tool key supplied by the browser; Playwright against the built app,
never the dev server (the `public/hot` trap).
**Outcome.** Joy can ask HQ a question and schedule work in his own words.
**Rollback.** Flags `ask_hq` and `scheduler.nl` off; the scheduler UI keeps working as today.

### M7 - Daily briefing, dashboards, hardening

**Purpose.** The daily habit, and proving the trace is unbroken.
**Dependencies.** M5, M6.
**Delivers.** `briefing.compose`, the evidence bundle and the unsourced-number post-check, freshness
reporting, `facts.snapshot_freshness` tool and alert, the four dashboards, the alert set, retention
and pruning per Joy's answer, and the end-to-end trace test.
**Safety gate.** ⭐ Seven consecutive real briefings, reviewed with Joy, before the schedule is
considered accepted. Every number in each one traceable to its source. **The end-to-end trace test
is the acceptance criterion for the whole phase**: from an `ask_messages` row to an external HTTP
status, with no broken link.
**Tests.** Composer fixtures including a stale source and a thin-data case; the unsourced-number
check watched failing against a fabricated narrative; dashboard queries against seeded data; the
trace test as Playwright plus a query assertion.
**Outcome.** Phase 2 is done and Joy gets a briefing every morning that he can trust because he can
check it.
**Rollback.** Pause the briefing job; dashboards are read-only.

### Dependency graph

```
 M1 Control-plane foundation
   |
   +--> M2 Gateway + Policy + Permission  (scheduler migrated onto it)
   |        |
   |        +--> M3 Approval Center
   |        |
   +--> M4 Model Router + cost  ----------+
                                          |
                    M3 + M4 --------------+--> M5 Agents (shadow -> propose)
                                                  |
                                                  +--> M6 Ask HQ + NL scheduling
                                                          |
                                                          +--> M7 Briefing + dashboards
```

M4 can proceed in parallel with M2 and M3 once M1 lands, if provider keys exist. Nothing else
parallelises safely.

### Dependency detail for M1, the first milestone

```
 config/hq.php tool list  ---------+
 existing Tool interface  ---------+--> ToolDescriptor + RiskTier/ActionClass/AutonomyLevel enums
 existing 6 tool classes  ---------+          |
                                              +--> descriptors written for all 6 tools
                                                        |
 businesses / brands / stores (exist) ------------------+--> agents migration
 users + spatie (exist)              ------------------+--> user_business_scopes migration
 tool_calls.approval_id (exists, no FK) ---------------+--> approvals migration -> add FK
 audit_logs trigger (exists) --------------------------+--> immutability test + grant check
 Auditor::redact (exists, has the `payload` bug) ------+--> redaction fix + test
                                                        |
                                                        +--> policies, tools, evaluations migrations
                                                        +--> RolesAndPermissionsSeeder additions
```

Nothing in M1 depends on a model provider, an external credential, or a decision from section V.
It can start the moment this document is approved.

⚠⚠ **CORRECTION, 8 September 2026.** The dependency diagram above promises a `policies`, `tools`
and **`evaluations`** migration. M1 delivered the first two. **There is no `evaluations` table
anywhere in the schema and there never was.** `docs/53` found this by checking the architecture
against the live database rather than against this document, and it matters because `docs/30`
planned Phases 9 and 10 to widen autonomy using evaluation data that nothing was recording. The
evaluation subsystem is now a Phase 2.5 deliverable under ADR-023. Do not read the diagram above as
a record of what shipped; `docs/49` is that record.

---

## V. Decisions that need Joy

Separated as requested. Implementation details are mine; these are not.

### Already decided, applied, and not reopened here

GitHub `rhjoyofficial` · `hq.bordeguna.com` · same VPS (ADR-012) · AI keys under the Bor de Guna org,
changeable from the UI (Q4) · no budget barrier for now (Q5) · Econic is HQ-only, not an ERP business
(Q7) · availability follows product status, never quantity (Q9) · Joy holds every approval right
(Q22) · Hermes and the n8n bot are rebuilt, not migrated, and retired only on explicit approval
(Q23, Q24) · local on-VPS backup only (ADR-014) · **the ERP owns commerce and HQ stays the
intelligence layer; the shop adapters are transitional** (ADR-015, decision D1 of 6 Sep) ·
**no fine-tuning, ever** (ADR-013).

### Open, and needed before the milestone shown

| # | Decision | Options and consequences | Needed by |
| --- | --- | --- | --- |
| **V.1** | **Approval thresholds for R4 and R5.** Q5 said no barrier, but that answer predates any tool that can spend. | **(a) Recommended: every R4 and R5 action requires approval regardless of value in Phase 2.** Nothing is enabled at those tiers anyway, so this costs nothing now and sets the safe default before it matters. **(b)** Set BDT thresholds now (order value, discount percent, daily spend). Faster later, but he is choosing numbers before seeing a single AI proposal. **(c)** Leave unlimited AUTO. Rejected: it makes the approval system decorative. | M3 |
| **V.2** | **Autonomy level the two agents ship at.** | **(a) Recommended: A0 shadow for a defined period, then A1 propose.** Builds an evaluation record before anything acts. **(b)** Straight to A1: usable a week or two sooner, no shadow baseline to judge later widening against. **(c)** A2: rejected for Phase 2, there is nothing safe for them to execute. | M5 |
| **V.3** | **Shadow period length.** | Recommended: 7 days of real briefings and questions. Shorter gives thin evidence; longer delays the value with diminishing return. | M5 |
| **V.4** | **Notification and briefing delivery channel.** ~~`ALERT_TELEGRAM_BOT_TOKEN` and `ALERT_TELEGRAM_CHAT_ID` exist in config and are **not set** in production.~~ **CORRECTED 8 Sep 2026 (docs/51 F): both ARE set in production and were read off the host. This line was written from the config file's defaults, not the deployed environment.** | **(a) Recommended: Telegram**, already in daily use, instant, good for approvals on a phone. Needs him to create a bot and hand over the token and chat id. **(b)** Email: no setup, but approvals sit unread. **(c)** In-app only: nothing to configure, and he only sees an approval when he opens HQ. | M3 |
| **V.5** | **Briefing time, recipients and content.** | Recommended 08:00 Asia/Dhaka to Joy only. Needs his confirmation of the hour, whether anyone else receives it, and whether all four businesses appear or only the two trading ones. | M7 |
| **V.6** | **Agent data visibility: does customer PII enter model prompts?** | **(a) Recommended: aggregates and order references by default; a customer's name, phone or address only when the operator asks about that specific customer, and never in the briefing.** Keeps routine AI traffic free of personal data. **(b)** Full customer records available to the agents: slightly better answers about individuals, much larger exposure surface, and every prompt becomes a place personal data has been. | M5 |
| **V.7** | **Retention.** | Recommended: `audit_logs` and `decision_records` **forever** (they are the alignment corpus and the legal record); `tool_calls`, `ai_runs`, `connector_calls` detail 180 days then rolled up to daily aggregates; `events` payloads 365 days. Longer costs disk on a box already watched for capacity; shorter loses the evaluation history that autonomy decisions depend on. | M7 |
| **V.8** | **A soft AI spend cap.** Q5 said no barrier; a cap that *downgrades* rather than refuses is not a barrier. | **(a) Recommended: a soft advisory cap (say USD 50/month) that notifies at 80 percent and downgrades the tier at 100, never refusing a briefing.** Protects against a runaway loop without blocking work. **(b)** No cap at all: a bug in a loop is discovered on the invoice. | M4 |
| **V.9** | **Grant the ERP `hq` token `purchasing.reports.view`?** Today `purchasing` is correctly null because the token deliberately lacks it (`docs/45`). | Granting it lets the analytics agent see purchasing. Not granting it means the briefing says "not available: permission not granted" rather than showing nothing. Either is fine; the design must not guess. | M5 |
| **V.10** | **Do the ERP overview and low-stock poll jobs (scheduler jobs 4 and 5) stay?** They were added beyond the original brief in `docs/45`. | Keep: the briefing depends on a fresh snapshot. Remove: the briefing must poll the ERP live each morning, which is slower and puts the ERP on the briefing's critical path. Recommended: keep. | M7 |
| **V.11** | **Who else, if anyone, gets an HQ login and what may they see?** `user_business_scopes` exists precisely for this. | Today HQ has one real user. If a brand manager is coming, scoping should be designed with a real case rather than imagined. | after M5 |

**No unresolved decision above is implemented in any form. Where one is open, the milestone that
needs it stops at its boundary rather than shipping a guess.**

---

## W. Backlog carried from Phase 1

Recorded, not scheduled. None of these blocks Phase 2, and none should be allowed to derail it
unless it becomes an actual blocker.

| # | Item | Owner | Note |
| --- | --- | --- | --- |
| 1 | ⛔ Delete `~/hq-bionic-credentials.txt` (mode 600) on the Bionic account | **Joy** | All three values are active in HQ; the file has served its purpose. Destroying his secrets file is not a tidy-up I make. |
| 2 | ERP snapshot freshness monitoring | HQ | **Absorbed into M7** as `facts.snapshot_freshness` plus an alert. The one backlog item Phase 2 actually closes. |
| 3 | `stores` id 4 `erp-counter` still holds the dead `erp.srv1808022.hstgr.cloud` base_url | HQ | Inert: the `erp_pos` adapter is unimplemented. **Fix in code, never by SQL**, before anything is built on that adapter. Not Phase 2. |
| 4 | Shop event evolution beyond `order.updated` | shops | `order.created` now carries `source` and the shipping address. Further fields wait for a real need. |
| 5 | `AdminOrderCreationService` drops the `district` and `thana` the admin form posts | shops | A real defect: a phone order reaches the courier with no routing pair. It changes a screen, so it wants its own small slice in the shop repos. **Not Phase 2, and not a Phase 2 excuse to touch a shop.** |
| 6 | Bionic duplicate-delivery and stale-timestamp guards demonstrated directly | HQ + shop | Currently inferred from CI plus live LaLaDia proof against the same store-agnostic controller. Worth doing when a validly-signed request can be built where the secret lives. |
| 7 | The six inert `dead` LaLaDia events | none | Deliberately left. They were replayed before the resolution fix existed; marking them by hand would be typing a status rather than earning one. |

---

## X. New ADRs

Phase 2 moves four boundaries, so four ADRs accompany this document:

- **ADR-016** - One gateway for every caller: the scheduler stops executing tools directly.
- **ADR-017** - Risk tier and action class are orthogonal; risk is intrinsic to the tool, the action
  class is derived by policy per call.
- **ADR-018** - An approval binds a canonical payload hash and a tool version, is single use, and is
  never regenerated by the model.
- **ADR-019** - The model router is provider-neutral and no model id appears in business code.

ADR-004 (typed tool gateway, policy first) moves from **proposed** to **accepted**, refined by 016
and 017. `docs/32` is updated with all four.

---

## Y. Exit criteria for Phase 2

Phase 2 is done when all of these are true, and not when the suite is green:

1. Every tool execution in HQ, including all six existing scheduled jobs, passes through the gateway
   and writes a `tool_calls` row.
2. A policy denial and an approval requirement are both demonstrated on production, with the audit
   trail retrieved afterwards from a correlation id.
3. An approval executes the exact stored payload, and a tampered payload is proven refused.
4. Both agents have run in shadow against real data, and their shadow log has been reviewed with Joy.
5. A real question asked in Ask HQ returns an answer whose every claim links to a source with a
   timestamp.
6. A real job created from a sentence in Joy's own words ran at the right Asia/Dhaka time.
7. Seven consecutive daily briefings have been produced and reviewed, with fact, metric, inference
   and recommendation visibly distinguished, and freshness stated.
8. The full trace from an operator's turn to an external HTTP status is retrievable from one
   correlation id.
9. No write tool at R4 or above is enabled, and no agent holds one.
10. CI green on Postgres 17, `tsc --noEmit` clean, backups verified, runbook updated, dashboards
    showing the new activity.

Item 10 is last on purpose. Nine things have to be true about the running system before the green
suite means anything at all.
