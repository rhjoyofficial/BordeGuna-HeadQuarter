# 81 - Preparing the exact shadow build: the P0 defects, the execution context on the run page, deploy verify-only, the autonomy evidence policy and producer, and Phase 4's read side (21 September 2026)

**Status:** the plan for the milestone ChatGPT ordered on 21 September in answer to the `docs/80`
end-of-milestone report, written before the code, then the record as each stream completes. Nothing
here activates anything.

**Read first:** `docs/80` (the previous milestone, its findings and section D), ADR-047 (Effective
Execution Context), ADR-023 and ADR-030 (autonomy and evaluation dimensions), ADR-045 (the shadow
period), `docs/79` and ADR-046 (Phase 4 design), `docs/58` H.1 (the backup-first invariant).

## A. The decision that changes the sequence, and the instruction verbatim

The `docs/80` report put a sequencing question to ChatGPT: gate 11 measures release `3314c54`, but the
release after it carries the Effective Execution Context, which changes how the runner takes
authority. ChatGPT chose the strengthened form of option (c): **the next safety measurement is
performed on the same build that becomes the Executive's A0 shadow build.** Main is not promoted as it
stands, because the last milestone found defects in existing code, three of them in the control plane
(a disabled user can still sign in; a 60-second business-scope cache; a rollback script that disagrees
with reality). So: fix those, verify locally, release the exact candidate inert, verify production,
then one fresh Gate 11 attempt 2 on that build. It stays Gate 11. This supersedes the "use release
`3314c54`" line of the 16 and 20 September instructions.

Its other answers, in short: the autonomy evidence policy is built now as versioned, governed
configuration with the missing producer, and **no numeric threshold is invented** (values stay
proposed and inactive until real shadow evidence and an owner decision); the briefing pipeline is
approved exactly as implemented (deterministic composition, narration as a separate optional step over
a stored bundle); `--by <a real person>` becomes mandatory for opening a production shadow, and
`as_enabled` may never open one. The instruction itself:

> I have reviewed the complete milestone.
> 
> Continue at full speed, but do NOT promote the current main to production unchanged. We have several known control-plane defects that must be corrected before the next production candidate.
> 
> ## 1. Gate-11 sequencing decision
> 
> I choose the strengthened form of option (c).
> 
> The next safety measurement must be performed on the same build that will become the Executive A0 shadow build.
> 
> Therefore:
> 
> **Do not run Gate 11 on old release `3314c54` if the shadow would later run a different main build.**
> 
> First correct the priority control-plane defects below.
> 
> Then:
> 
> 1. local exhaustive verification
> 2. promote the exact resulting main candidate
> 3. verify production
> 4. run one fresh Gate-11 attempt
> 5. if the complete Gate-11 measurement passes, proceed to the explicitly authorized A0 shadow opening
> 6. if it fails, remain inert
> 
> This remains **Gate 11**, not Gate 12.
> 
> The earlier provider-failure runs remain historical and untouched.
> 
> Do not change prompts or safety semantics merely to improve the gate result.
> 
> ---
> 
> # 2. Fix these existing defects before the next production candidate
> 
> ### P0-1: Disabled users must not authenticate
> 
> `users.is_active = false` must be honored by authentication.
> 
> Implement and test:
> 
> * disabled user cannot login
> * existing authenticated session is invalidated or rejected appropriately when the account becomes inactive
> * enabled user continues to authenticate
> * audit records remain correct
> 
> Do not create a bypass path.
> 
> ### P0-2: Business-scope cache must not produce stale authorization
> 
> The current 60-second `BusinessScope` cache is unacceptable for security-sensitive scope resolution.
> 
> Change it so that:
> 
> * scope mutation invalidates relevant cache immediately, OR
> * security-sensitive resolution bypasses stale cache
> * a newly narrowed scope takes effect immediately
> * a newly granted scope cannot unexpectedly appear before authorization state says it is valid
> * gateway scope checks use authoritative current state
> 
> Add regression tests for immediate narrowing/widening.
> 
> ### P0-3: Fix rollback contract
> 
> `rollback.sh`, Docker/compose configuration, README and actual behavior must agree.
> 
> A rollback must not unexpectedly run migrations because of an inherited `RUN_MIGRATIONS=1`.
> 
> Establish one explicit rollback invariant, test it, and document it.
> 
> ### P0-4: Fix approval requester identity
> 
> An approval created by an agent must record the accountable requester/asker correctly.
> 
> Do not record the enabling user as the requester merely because the enabler authorizes execution.
> 
> Preserve separate concepts:
> 
> * requester
> * enabler
> * acting identity
> * approver
> 
> Add regression coverage and ensure existing historical records remain unchanged.
> 
> ### P1-1: Deterministic query ordering
> 
> Fix `facts.query_orders` tie handling.
> 
> Grouping/order must be stable when metric values tie.
> 
> Add a deterministic secondary key and regression coverage.
> 
> ---
> 
> # 3. Build the generic Effective Execution Context into the UI/audit surface
> 
> Add the resolved `eec.v1` context to the relevant agent run detail.
> 
> Show safe, non-secret information such as:
> 
> * requester
> * enabling identity
> * acting identity/context
> * business scope
> * brand scope
> * applicable agent
> * resolved tool reach
> * context digest
> * resolution timestamp
> 
> Do not expose credentials or hidden secrets.
> 
> This is read-only observability.
> 
> ---
> 
> # 4. Build deploy verify-only mode
> 
> Add a verify-only mode to the deployment tooling that performs validation without changing:
> 
> * checkout
> * containers
> * database
> * release records
> * production state
> 
> It should validate as much of the real deployment preflight as possible and fail with a specific reason.
> 
> This is an operational safety tool, not a replacement for actual deployment.
> 
> ---
> 
> # 5. Build the autonomy evidence threshold mechanism now
> 
> APPROVED.
> 
> Create versioned, governed autonomy-evidence policy configuration independent of individual grants.
> 
> The policy model should support at minimum:
> 
> * autonomy level
> * evaluation dimension
> * minimum agreement rate
> * minimum evidence/sample count
> * recency constraint
> * required measured state
> * hard safety requirements
> 
> An unmeasured required dimension must not satisfy eligibility.
> 
> A single overall score must never substitute for missing dimension-specific evidence.
> 
> However:
> 
> **Do not invent final numeric thresholds automatically.**
> 
> The schema, producer, resolver integration and UI may be built now, but initial numeric policy values must be explicitly governed before they become effective.
> 
> Build the missing evidence producer so AgentRunner can receive the applicable dimension/rate evidence when evaluating an autonomy grant.
> 
> Keep production autonomy grants at zero.
> 
> ---
> 
> # 6. Keep the M7 briefing bundle as the canonical Executive briefing contract
> 
> APPROVED exactly as implemented.
> 
> The standard briefing architecture is:
> 
> trusted briefing task
> → deterministic typed EvidenceBundle
> → completeness/provenance/freshness validation
> → persisted briefing artifact
> → optional LLM narration
> → final human-readable briefing
> 
> The narrator must not become the source of facts.
> 
> Keep:
> 
> * lineage
> * source references
> * as-of timestamps
> * freshness
> * deterministic PHP calculations
> * no partial group totals
> * no fabricated zeroes
> * unsourced-number blocking
> 
> Keep narration behind its flags and OFF.
> 
> Do not create the scheduled standard briefing job yet.
> 
> Do not implement TaskScope heuristics.
> 
> ---
> 
> # 7. Continue M6 inertly
> 
> Keep:
> 
> * Ask HQ
> * strict JobSpec
> * deterministic validation
> * NL scheduler
> * preview/one-time token
> * approval workflow
> * effective execution context intersection
> 
> Do not enable `ask_hq`.
> 
> Do not enable `scheduler.nl`.
> 
> Do not allow any direct model → tool path.
> 
> ---
> 
> # 8. Continue Phase 3 inertly
> 
> Continue the safe Phase 3 work.
> 
> Maintain:
> 
> * channel-neutral contracts
> * customer memory labelled as memory
> * untrusted inbound provenance
> * conversation/handoff state machine
> * Instagram/Facebook normalizers
> * simulation environment
> * P3-G3 pairing infrastructure
> * Support evaluation fixtures
> * runbooks
> 
> Do not enable:
> 
> * Meta inbound
> * real outbound messaging
> * customer production traffic
> * Support production activation
> 
> ### P3-G1
> 
> Run/close the Conversation Core gate where the current release permits.
> 
> Do not confuse P3-G1 with Support-agent safety.
> 
> ### P3-G2
> 
> Prepare the Support-agent gate using the simulation world.
> 
> Use Joy as the explicitly approved enabling user only when the actual Support evaluation is run.
> 
> Keep Support paused.
> 
> ---
> 
> # 9. Phase 4: build read-side ERP prerequisites
> 
> Continue Phase 4 at design/read-only implementation level.
> 
> The canonical future capability remains:
> 
> `take_order`
> 
> behind the ERP Sales Order capability.
> 
> Now implement only read-side prerequisites:
> 
> * product lookup
> * variant/SKU lookup
> * current price lookup
> * stock/availability lookup
> * relevant customer/order eligibility facts
> 
> All through the existing governed read interfaces.
> 
> No order creation.
> 
> No order modification.
> 
> No autonomous commerce write.
> 
> The future write remains behind the ERP Sales Order capability and its own gates.
> 
> ---
> 
> # 10. Credential/readiness model
> 
> Continue the readiness state design.
> 
> Keep distinct:
> 
> * credential_missing
> * credential_unverified
> * credential_invalid
> * provider_blocked_by_credit
> * provider_unauthorized
> * provider_unavailable
> * connector_error
> * verified
> 
> A provider-credit problem must never condemn the credential itself.
> 
> Never expose credential values.
> 
> ---
> 
> # 11. Shadow Probes
> 
> Keep the twice-daily production Shadow Probes.
> 
> Each is an explicit trusted task, not open-ended permission.
> 
> Required production shadow behavior:
> 
> * exact task recorded
> * normal AgentRunner path
> * normal EffectiveExecutionContext resolution
> * normal Gateway
> * complete evidence required
> * missing probe evidence prevents shadow completion
> 
> Keep `--no-schedule` for test/local use only.
> 
> For production shadow opening, scheduled probes are mandatory.
> 
> ---
> 
> # 12. Production A0 activation
> 
> Joy has already given the conditional authorization.
> 
> When and only when the fresh Gate-11 run on the exact candidate build fully passes:
> 
> * require explicit human `--by`
> * enable `ai.runtime`
> * activate Executive at A0
> * open seven-day shadow
> * keep Analytics paused
> * keep Support paused
> * grants = 0
> * Meta inbound = OFF
> * outbound = OFF
> * business writes = OFF
> 
> `--by` is mandatory for production.
> 
> Do not permit `as_enabled` as an implicit production shadow opener.
> 
> The shadow-opening action must be audited under the named human.
> 
> ---
> 
> # 13. Do not automatically promote after the shadow week
> 
> The seven-day shadow produces evidence.
> 
> It does not automatically:
> 
> * create an autonomy grant
> * widen autonomy
> * enable writes
> * enable customer messaging
> * activate Support
> 
> The next autonomy decision is a separate governed step.
> 
> ---
> 
> # 14. CI constraints
> 
> Until 1 October:
> 
> * local exhaustive verification is the temporary merge gate
> * report it as local verification, not CI green
> * run Pest
> * Pint
> * strict TypeScript
> * Vite
> * relevant production/build tests
> * deploy-safety tests
> 
> On 1 October:
> 
> * run full CI against main
> * reconcile every failure
> * do not suppress CI-only failures
> 
> Do not claim Linux deploy-safety parity until the actual Linux runner has passed.
> 
> ---
> 
> # 15. Historical evidence
> 
> Preserve all prior Gate 1–11 records and traces.
> 
> Do not rewrite:
> 
> * Gate 7
> * Gate 8
> * Gate 9
> * Gate 10
> * Gate 11 attempt 1
> 
> The provider-failure Gate-11 run remains historical evidence.
> 
> The next complete measurement is Gate 11 attempt 2.
> 
> ---
> 
> # 16. Priority ordering
> 
> Proceed in this order while the Anthropic provider remains blocked:
> 
> ### First:
> 
> P0 control-plane defects:
> 
> * disabled authentication
> * stale business scope cache
> * rollback invariant
> * approval requester identity
> 
> ### Then:
> 
> * deterministic query ordering
> * EffectiveExecutionContext UI
> * deploy verify-only
> * autonomy evidence threshold/producer
> 
> ### In parallel:
> 
> * M7 briefing bundle/lineage
> * M6 inert work
> * Phase 3 inert work
> * P3-G1/P3-G2 preparation
> * Phase 4 ERP read-side foundation
> 
> Do not block safe engineering on Anthropic credit, Meta App Review, or GitHub Actions availability.
> 
> ---
> 
> # 17. Gate 11 attempt 2
> 
> When the exact production candidate is ready AND Anthropic capacity is restored:
> 
> * verify provider HTTP 200
> * verify sufficient credit for the complete suite
> * deploy the exact candidate using backup-first controls
> * run one locked Gate-11 attempt 2
> * require zero unmeasured cases
> * do not tune after seeing the first result
> * report all suites/families/dimensions
> * report measured vs unmeasured
> * report runtime/provider failures separately
> * stop for the activation decision
> 
> If it passes, use the conditional A0 process above.
> 
> If it fails, remain inert.
> 
> ---
> 
> # 18. Next report
> 
> Provide one cumulative report covering:
> 
> 1. P0 defect fixes
> 2. production candidate commit/release
> 3. Effective Execution Context
> 4. deploy verify-only
> 5. autonomy evidence policy/producer
> 6. M7 briefing bundle
> 7. M6 status
> 8. P3-G1/P3-G2 status
> 9. Phase 4 read foundation
> 10. Anthropic capacity
> 11. Gate-11 readiness
> 12. local verification status
> 13. main vs production state
> 14. A0 activation/shadow status
> 15. exact remaining blockers
> 
> Continue without waiting for another daily instruction.
> 
> Do not activate any production AI capability unless the explicit Gate-11 pass and `--by` conditions above are satisfied.


## B. The plan (written before the code)

Five streams in parallel worktrees (`hq-wt/e..i`, test databases `hq_test_e..i`), one branch each,
each planned in section C before its code, each reviewed and merged `--no-ff` only on full local
verification, then the combined tree verified as a whole (the last milestone's one defect appeared
only in the combined tree).

| Stream | Branch | Instruction points |
| --- | --- | --- |
| E. Disabled users cannot authenticate; business scope is never stale; a production shadow needs `--by` | `fix/auth-scope-and-shadow-opener` | P0-1, P0-2, 11, 12 |
| F. The approval's requester is the asker; stable ordering in `facts.query_orders`; the execution context on the run page | `fix/approval-requester-ordering-and-run-context` | P0-4, P1-1, 3 |
| G. One rollback invariant; deploy verify-only | `deploy/rollback-invariant-and-verify-only` | P0-3, 4 |
| H. Versioned autonomy evidence policy, the evidence producer, resolver integration, the view | `autonomy/evidence-policy-and-producer` | 5 |
| I. Phase 4 read side: product, variant, price, stock and eligibility lookups through governed reads | `p4/erp-read-foundation` (HQ) and a branch in the ERP repository | 9 |

Rules every stream keeps: a correction is watched failing first; no prompt file changes; nothing is
switched on; no grant, flag row or scheduled job is created; historical rows are never rewritten;
no invented business rule or number; migrations additive and rehearsed apply, roll back, re-apply on
Postgres.

After the merges: full local verification of the combined tree, the release candidate named, and
the by-hand release steps handed to Joy (deploy and gate launch are his to type). Gate 11 attempt 2
still needs Anthropic capacity, which is his.

## C. Record

(filled in per stream as it completes)

### C.5 Stream I: Phase 4 read side (product, variant, price, stock, eligibility), reads only

**Status: plan written before the code (this section), then the as-built record below it. Branches:
HQ `p4/erp-read-foundation` (worktree `hq-wt/i`), ERP `feature/hq-api-order-reads` (worktree
`erp-wt/hq-reads`, from `origin/master`). No order is created, changed or cancelled by anything in
this stream, on either side; `take_order` is registered nowhere; no write ability is added to any
token; nothing is switched on.**

#### C.5.1 What both codebases hold today (read, not assumed)

ERP (`erp-wt/hq-reads`):

- The HQ API is `routes/api.php:18-33`: `auth:sanctum` + `throttle:120,1`, then `{business}` under
  `HqApiContext` (`app/Http/Middleware/HqApiContext.php:23-53`: the token must hold
  `hq:business:{id}`, the business must be active, `TenantScope::enter` from the TOKEN, the
  `X-Correlation-Id` header echoed when it matches `^[A-Za-z0-9._:-]{8,128}$`, else a fresh uuid).
  One controller, `app/Http/Controllers/Api/Hq/HqReadController.php`, with inline field arrays (no
  Resource classes), `requireAbility()` (403 `{"error":{"code":"forbidden","message":"Token lacks
  ability X."}}`, lines 253-258), `ok()` (`data` + `meta.as_of`, line 270), `paginated()` (line
  275: `meta.page/per_page/total/as_of`, `links.next/prev`) and `error()` (line 284). Thirteen
  routes; the one write is `POST tickets`.
- `HqApiToken::READ_ABILITIES` (`app/Console/Commands/HqApiToken.php:23-27`) is the whole
  allow-list: `inventory.view`, `inventory.reports.view`, `sales.view`, `sales.reports.view`,
  `sales.customers.view`, `purchasing.view`, `purchasing.reports.view`, `accounting.view`,
  `hrm.employees.view`, `hrm.attendance.view`, `crm.tickets.view`, `crm.tickets.manage`,
  `crm.contacts.view`. ⚠ Finding: `inventory.view`, `sales.view` and `sales.customers.view` are
  NOT Spatie permissions seeded anywhere in the ERP; they are the ERP's AI `ReadableData` names
  (`app/Modules/Foundation/Support/Ai/ReadableData.php:55-87`). The screens themselves gate the
  product list on `inventory.reports.view` (`app/Modules/Inventory/routes/web.php:42`) and the
  price list and customer pages on `sales.reports.view` (`app/Modules/Sales/routes/web.php:26-33`).
  `docs/40`'s "abilities are the ERP's own permission names" is therefore true for the report and
  ticket abilities and loose for the three `*.view` names. Reported, not changed.
- Tests: `tests/Feature/HqApi/HqApiTest.php` (7 tests; `Sanctum::actingAs` with an ability list
  plus `hq:business:{id}`; models created `withoutGlobalScopes()` with an explicit `business_id`).
- Product: `app/Modules/Inventory/Models/Product.php` (`sku`, `barcode`, `name`, `type`
  (`ProductType`, `Service` is not stockable), `is_active`, `availability_override`
  (`AvailabilityPolicy`, narrows only), `lead_time_days`, `low_stock_threshold`; relations
  `brand`, `category`, `unitOfMeasure`; `currentStockQuantity()` sums active batches, normalised
  decimal string). ⚠ There is NO `cost_price` column on products and NO `mrp` column anywhere in
  the schema: cost lives on `stock_batches.unit_cost` (`averageUnitCost()`), and `mrp` and
  `cost_price` are spreadsheet columns the price importer knowingly does not import
  (`app/Modules/Sales/Support/PriceListFile.php:71`, `NOT_A_SELLING_PRICE`).
- Variants: the ERP has no variant concept (confirmed again: `app/Modules/Inventory` and
  `app/Modules/Commerce` hold no variant model). A SKU identifies exactly one product per business
  (`docs/79` C.1, C.2).
- Price: one engine, `app/Modules/Sales/Actions/ResolveSellingPrice.php` (customer rule beats
  price-group rule beats default; highest quantity break reached; RETAIL when no group, lines
  212-233; with no customer and a `priceGroupId`, the store's own group, lines 159-176). The rule
  travels with the number: `ResolvedPrice` (`app/Modules/Sales/Support/ResolvedPrice.php`) carries
  `tier()` (`customer`, `price_group`, `default`) and `explain()`. Rows are `product_prices`
  (`ProductPrice`: `customer_id`, `price_group_id`, `min_quantity`, `unit_price`, `effective_from`,
  `effective_to`, `is_discount`, `discount_label`, `note`). ⚠ Finding: the "10/15/20 percent off
  the selling price" tiers are NOT an ERP rule: the ERP stores absolute `unit_price` rows per price
  group (`RETAIL`, `DEALER`, `DISTRIBUTOR`, `CORPORATE`, `PriceListFile::PRICE_COLUMNS`), and the
  percentages live in the spreadsheet that fills them. The price the ERP would apply to an HQ order
  is the engine's answer at the store's group (`SalesOrderOrigin::website`, `docs/79` C.4).
  Promotions (`app/Modules/Sales/Support/ApplyPromotions.php`, Buy X Get Y) are applied by
  `RecordCounterSale` only; `CreateSalesOrder` applies none. So a sales order carries no promotion,
  and the price endpoint says so rather than pretending one applies.
- Availability: `app/Modules/Commerce/Support/Availability.php` decides per STORE (`policyFor`:
  the store's `availability_policy` narrowed by the product override; `decide`: inactive is "out
  of season", zero stock refuses only under `Strict`, else `madeToOrder`). Without a store there
  is no verdict in the ERP, only the facts it would decide from. `CartRules::refusalFor`
  (`app/Modules/Commerce/Support/CartRules.php:27-47`) adds "published on this store" and "a price
  in force" before the availability verdict. Stock per warehouse is what `HqReadController::stock`
  already aggregates (`stock_batches` active, grouped by product and warehouse, line 79-92).
- Customer: `app/Modules/Sales/Models/Customer.php` (`is_active`, `is_system` for the seeded
  walk-in row, `phone` kept verbatim, `phone_normalized` derived by `PhoneIdentity::normalize`
  on save, `payment_terms`, `credit_limit`, `price_group_id`). ⚠ Findings the eligibility endpoint
  must state and not improve on: the ERP has NO blocked flag, NO COD flag and NO credit-hold flag;
  `CreateSalesOrder` refuses only an inactive customer (line 66); `PlaceWebsiteOrder::identify`
  (`app/Modules/Commerce/Actions/PlaceWebsiteOrder.php:329-363`) matches the FIRST active,
  non-system customer of the business by normalised phone (a household may share a number) and
  otherwise creates a new Retail customer; an INACTIVE match is skipped, not refused; the credit
  limit is "shown, never enforced" (`CustomerExposure` docblock, C-SO-3). No sales order carries an
  idempotency key column, so `docs/79`'s `?idempotency_key=` reconcile read needs a migration and
  belongs to the write gate (P4-G0), not here.

HQ (`hq-wt/i/apps/hq`):

- `app/Erp/ErpConnector.php`: one connector per (business, token); `get()` through
  `ConnectorCallLogger::call` (`app/Commerce/Support/ConnectorCallLogger.php`: correlation id from
  `Context('correlation_id')`, HTTP status observed); `throwForStatus()` (lines 246-260) maps
  401/403 to `Unsupported`, 404 to `Refused`, 422 to `ValidationFailed`, 429 and 5xx to
  `Unavailable`, a connection error to `Unavailable` (`send()`), never to a policy refusal. ⚠
  Finding: `client()` (line 217-224) sends a FRESH uuid as `X-Correlation-Id` on every request
  while the `connector_calls` row records the gateway's `Context` correlation id, so the id the
  ERP echoes and logs is never the one HQ recorded. Stream I sends the Context id when it is one
  the ERP accepts, and the uuid only when there is none.
- `app/Erp/ErpRegistry.php` opens the connector from the `erp_api_token` credential. Tools:
  `app/Tools/Erp/PollErpTool.php` (R3, Write, `agentExecutable: false`: it replaces a snapshot
  row), `ReadErpSnapshotTool` (R1, Read, true), `RunErpReportTool` (R2, Read, true, calls the ERP
  live, `scopeFrom: 'business:args.business_slug'`, `CredentialRequirement(ErpRegistry::PROVIDER,
  'args.business_slug|business.slug')`, `idempotencyKeyFrom`, `replayWithinSeconds: 60`,
  `Unsupported` returned as `ToolResult::failedBy`, `Unavailable` rethrown). Registration:
  `config/hq.php` `tools`. Pinned lists: `tests/Feature/Gateway/ToolNamespaceTest.php` (the key
  list) and `tests/Feature/Ai/AiSafetyPostureTest.php:113-131` (every agent-executable tool is
  Read, below R3, not customer-facing; the R4/R5 list is exactly `demo.request_approval`).
- Fixtures: `tests/fixtures/erp/*.json` consumed by `tests/Feature/Erp/ErpContractFidelityTest.php`
  (captured from production with `hq:erp:inspect`). Stream I adds fixtures the ERP's own tests
  WRITE, so both sides read one file.

#### C.5.2 The endpoints (ERP side), the ability each needs, and what each returns

All under `/api/hq/v1/{business}/order-reads/`, the existing middleware, envelope, pagination and
error shape, a new controller `HqOrderReadController` whose every response body is built from an
explicit field allow-list (a constant per shape) and nothing else. No ability is added to
`READ_ABILITIES`: every endpoint needs one that already exists there.

| Endpoint | Ability | Returns |
| --- | --- | --- |
| `GET order-reads/products` (`filter[q]` name or SKU contains, `filter[sku]`, `filter[id]`, `filter[barcode]`, `filter[brand]` code, `filter[status]` active (default), inactive, all) | `inventory.view` | paginated product identity rows: `id, sku, barcode, name, type, status, is_active, orderable` (type is not Service), `unit {name, abbreviation}`, `brand {code, name}`, `category {name}`, `availability_override`, `lead_time_days` |
| `GET order-reads/skus/{sku}` | `inventory.view` | the one product a SKU names, the same row, plus `variant_model: "none"` and the sentence that the ERP has no variants; 404 when none |
| `GET order-reads/prices/{sku}` (`quantity` default 1, `store` key or `customer_id`, `on` date optional) | `sales.view` | `unit_price` (string), `basis: "selling_price"`, `mrp: null` with `mrp_note`, `quantity`, `as_of`, `priced_as` (`store`, `customer`, `nobody`), `price_source {rule_id, tier, price_group {code,name}, min_quantity, effective_from, effective_to, is_discount, discount_label, explanation}`, `promotions_applied_to_sales_orders: []` with the sentence that Buy X Get Y is a counter-sale rule; 404 `no_price_in_force` when the engine has no rule |
| `GET order-reads/availability/{sku}` (`store` key optional) | `inventory.view`; per-warehouse rows only with `inventory.reports.view`, else `warehouses: null` and `warehouses_withheld: true` | two separate facts: `stock {on_hand, unit, warehouses[{code,name,on_hand}]}` and `availability {rule: "status_based per store", product_is_active, availability_override, store: null or {key, policy, published, has_price, sellable, made_to_order, message}}`; with no store the verdict fields are null and `verdict_needs_store: true` |
| `POST order-reads/customer-eligibility` (body `phone`) | `sales.customers.view` | `phone_valid`, `phone_normalized`, `customer_exists`, `match {id, display_name, is_active}` (the row `identify` would pick) or null, `inactive_matches`, `would_create_new_customer`, `refusals` (only `inactive` exists), `fields_the_erp_does_not_have: [blocked, cod_eligible, credit_hold]`, `credit_limit_enforced: false`. No phone, email, address, credit limit or price group in the body. POST so the phone is not in an access log. |

Absent by construction from every body: `unit_cost`, `avg_unit_cost`, `averageUnitCost`,
supplier, margin, `credit_limit`, `payment_terms`, `bin`, `tin`, `email`, `address`, `note`,
journals, payroll. The ERP tests assert the absence of each by name on every endpoint, and that a
token for business A gets 403 on business B, that a missing ability is 403 and no token is 401.
`HqApiToken` tests gain a case proving every sensitive ability (`sales.orders.record`,
`sales.orders.cancel`, `sales.prices.manage`, `hrm.payroll.manage`, `accounting.journals.post`,
`inventory.stock.adjust`) is still refused.

Fixtures: the ERP tests write `tests/fixtures/hq-api/order-reads/*.json` (the exact response
bodies) and the HQ repo carries a copy under `tests/fixtures/erp/order-reads/`; each side's
fixture test states the origin (ERP test name and commit).

#### C.5.3 The tools (HQ side)

Connector methods `lookupProducts`, `lookupSku`, `readPrice`, `readAvailability`,
`checkCustomerEligibility`, each through `ConnectorCallLogger`, each failure through the existing
classes. Tools, all `ToolScope::Business`, `scopeFrom: 'business:args.business_slug'`,
`business_slug` REQUIRED in the schema, `additionalProperties: false`, `permission:
hq.tools.erp.read`, `ReadWrite::Read`, `idempotent: true` with `idempotencyKeyFrom` and
`replayWithinSeconds: 60`, outputs carrying `business`, `source: "erp"`, `as_of` (the ERP's) and
`read_live_at`:

| Key | Tier | agentExecutable | Why |
| --- | --- | --- | --- |
| `erp.lookup_product` | R2 | true | the same posture as `erp.run_report`: a live read of the ERP, nothing written, no person reached (ADR-027, reading is not acting) |
| `erp.lookup_sku` | R2 | true | same |
| `erp.read_price` | R2 | true | same; the price source is named in the output so a model cannot present a price without its rule |
| `erp.read_availability` | R2 | true | same; `sellable` and `on_hand` are two fields, as the ERP keeps them |
| `erp.check_order_eligibility` | R2 | **false** | it takes a phone number and returns whether a person exists and their display name (`DataClass::Personal`); an agent that can call it can enumerate customers by phone. It is a stage: P4-G3 decides it, after the suite in `docs/79` G measures it |

`take_order`, `quote_order`, `get_order_status`, `cancel_sales_order`: registered nowhere.

#### C.5.4 Verification planned

ERP: `tests/Feature/HqApi` (7 existing + the new file), then `tests/Feature/Sales`,
`tests/Feature/Commerce`, `tests/Feature/Inventory`, then the full suite `--parallel --processes=6`;
Pint on changed files. HQ: the new tool tests, `tests/Feature/Erp`, `tests/Feature/Gateway`,
`tests/Feature/Ai`, then the full Pest suite on `hq_test_i`, `pint --test`, `tsc --noEmit`,
`npm run build`. Numbers in C.5.5.

#### C.5.5 As built

(filled in as the stream completes)
