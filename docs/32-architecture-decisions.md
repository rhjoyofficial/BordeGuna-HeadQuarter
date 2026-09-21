# 32 - Architecture Decisions

Index of ADRs in `docs/adr/`. Each ADR: context, decision, alternatives, consequences, status.

| ADR | Decision | Status |
|---|---|---|
| [ADR-001](adr/001-separate-hq-application.md) | HQ is a separate Laravel application, not an ERP module | proposed |
| [ADR-002](adr/002-laravel-core-node-edge.md) | PHP/Laravel core; Node only for the realtime voice gateway | proposed |
| [ADR-003](adr/003-postgres-pgvector-redis.md) | Postgres 17 + pgvector + Redis for HQ; ERP stays on MySQL | proposed |
| [ADR-004](adr/004-tool-gateway-policy-first.md) | Every AI action passes a typed tool gateway and a deterministic policy engine | **accepted and realised** (gateway M1, policy engine M2, docs/50); refined by ADR-016 and ADR-017 |
| [ADR-005](adr/005-durable-scheduler-over-cron.md) | Business scheduling lives in HQ's `scheduled_jobs` + Horizon; cron is only the tick; scheduling is a core product feature | accepted |
| [ADR-006](adr/006-n8n-transitional.md) | n8n is retained as a transitional executor and replaced workflow by workflow after shadow mode | proposed |
| [ADR-007](adr/007-truth-stays-in-systems-of-record.md) | HQ holds read models only; systems of record are never duplicated | proposed |
| [ADR-008](adr/008-commerce-tier-separate-vps.md) | Shops migrate to a second VPS (commerce tier) | **deferred**: kept as a scaling option, superseded for now by ADR-012 |
| [ADR-009](adr/009-econic-commerce-platform-abstraction.md) | Commerce Platform Interface; WooCommerce is a legacy adapter, Econic will be a custom platform | accepted (Joy, 5 Sep) |
| [ADR-010](adr/010-business-brand-store-entity-model.md) | Business, Brand, Store, Product owner, Seller, Channel, Platform account as separate entities | accepted |
| [ADR-011](adr/011-external-side-effect-idempotency.md) | At-least-once execution with idempotent tools and reconciliation; no exactly-once claim | accepted |
| [ADR-012](adr/012-current-vps-first-capacity-strategy.md) | Deploy on the current VPS with swap, limits and monitoring; scale on evidence | accepted (Joy, 5 Sep) |
| [ADR-013](adr/013-behavior-alignment-without-fine-tuning.md) | Behavior Alignment Layer instead of training | accepted (Joy, 5 Sep) |
| [ADR-014](adr/014-local-backup-no-offsite-provider.md) | Local on-VPS PostgreSQL backup; Backblaze B2 removed; no offsite provider in the current deployment | accepted (Joy, 5 Sep) |
| [ADR-015](adr/015-erp-owns-commerce-hq-adapts-to-it.md) | The ERP owns commerce; HQ reaches it through the platform interface. Amends ADR-009, narrows ADR-010 | accepted (Joy, 6 Sep) |
| [ADR-016](adr/016-one-gateway-for-every-caller.md) | One tool gateway for every caller; the scheduler stops executing tools directly | **accepted and realised** (Phase 2 M2, docs/50; scheduler runs in gateway mode on production) |
| [ADR-017](adr/017-risk-tier-and-action-class-are-orthogonal.md) | Risk tier is intrinsic to a tool; action class is derived by policy per call | **accepted** (Phase 2 M1, docs/49) |
| [ADR-018](adr/018-approval-binds-a-payload-hash.md) | An approval binds a canonical payload hash and a tool version, and is single use | **accepted and realised** (Phase 2 M3, docs/51; proven on production, two amendments recorded in the ADR) |
| [ADR-019](adr/019-provider-neutral-model-router.md) | The model router is provider-neutral; no model id appears in business code | proposed (Phase 2) |
| [ADR-020](adr/020-autonomy-control-plane-is-a-separate-resolver.md) | The Autonomy Control Plane is a separate resolver from the Policy Engine | **accepted and implemented** (8 Sep, approved by Joy against docs/53; built 9 Sep, Phase 2.5 C, docs/57) |
| [ADR-021](adr/021-consent-is-an-append-only-ledger.md) | Consent is an append-only ledger, enforced at the gateway | **accepted and implemented** (8 Sep, approved by Joy; built 9 Sep, Phase 2.5 B, docs/57) |
| [ADR-022](adr/022-platform-capabilities-resolve-per-connected-account.md) | Platform capabilities are resolved per connected account, not per platform | **accepted and implemented** (8 Sep, approved by Joy; built 9 Sep, Phase 2.5 A, docs/57) |
| [ADR-023](adr/023-evaluation-evidence-precedes-autonomy-widening.md) | Evaluation evidence is the precondition for widening autonomy | **accepted and implemented** (8 Sep, approved by Joy; built 9 Sep, Phase 2.5 D, docs/57) |
| [ADR-024](adr/024-untrusted-content-never-increases-autonomy.md) | Untrusted content is labelled, and can never increase autonomy | **accepted and implemented** (8 Sep, approved by Joy; built 9 Sep, Phase 2.5 G, docs/57) |
| [ADR-025](adr/025-human-only-is-permanent-and-distinct-from-not-yet.md) | HUMAN_ONLY is a permanent declaration, distinct from "not yet agent-executable" | **accepted and implemented** (8 Sep, Phase 2 M4) |
| [ADR-026](adr/026-the-tool-descriptor-is-the-control-plane-vocabulary.md) | The tool descriptor is the control plane's vocabulary | **accepted and implemented** (8 Sep, approved by Joy; built 9 Sep, Phase 2.5 H, docs/57) |
| [ADR-027](adr/027-reading-is-not-acting.md) | Autonomy governs proposed actions, not evidence gathering | **accepted and implemented** (10 Sep, Phase 2 M5) |
| [ADR-028](adr/028-a-named-tool-counts-as-a-call.md) | In an evaluation, a tool the agent NAMED counts as a call | **accepted and implemented** (10 Sep, Phase 2 M5) |
| [ADR-029](adr/029-gateway-and-capability-registry-are-canonical-mcp-is-an-adapter.md) | The Tool Gateway and Capability Registry are canonical; MCP is an adapter | accepted (10 Sep); the capability router implemented, the protocol adapter specified and deliberately not implemented |
| [ADR-030](adr/030-evaluation-measures-six-dimensions.md) | Evaluation measures six dimensions, and autonomy consumes them | **accepted and implemented** (10 Sep) |
| [ADR-031](adr/031-verification-first-execution.md) | Consequential execution is verification-first | accepted as the governing pattern (10 Sep); specified, not implemented (no agent-executable write tool exists) |
| [ADR-032](adr/032-orchestration-is-deterministic.md) | Agents decide what should happen; deterministic infrastructure decides how it progresses | accepted (10 Sep); already true of the built system |
| [ADR-033](adr/033-memory-is-never-truth.md) | Memory is never truth | accepted (10 Sep); enforced by the existing separation |
| [ADR-034](adr/034-approval-is-a-stage-human-only-is-a-decision.md) | Approval is a stage that evidence can end; HUMAN_ONLY is a decision that it cannot | accepted (10 Sep); the counterpart to ADR-025 |
| [ADR-035](adr/035-agent-role-is-a-boundary.md) | An agent's role is an authorization and behaviour boundary, not metadata | **accepted and implemented** (10 Sep) |
| [ADR-036](adr/036-two-layers-of-evaluation.md) | Global safety invariants and role behaviour are two separate evaluation layers | **accepted and implemented** (10 Sep) |
| [ADR-037](adr/037-authority-never-originates-in-the-loop-and-a-run-ends-with-a-verdict.md) | An agent's authority never originates inside its own loop, and a run ends with a verdict | **accepted and implemented** (12 Sep, after gate 2, docs/62) |
| [ADR-038](adr/038-the-boundary-comes-before-the-evidence-and-a-cut-off-reply-is-a-budget.md) | The boundary is recognised before evidence is gathered, and a cut-off reply is a budget, not a malformed object | **accepted and implemented** (12 Sep, after gate 3, docs/65) |
| [ADR-039](adr/039-no-implicit-task-invention-and-the-business-reference-contract.md) | An agent invents no task, a business reference is a contract, and a repeated denial ends in a verdict | **accepted and implemented** (13 Sep, after gate 4, docs/66) |
| [ADR-040](adr/040-a-task-is-named-before-evidence-and-a-question-back-is-a-verdict.md) | A task is named before evidence, and a question back is a verdict | **accepted and implemented** (13 Sep, after gate 5, docs/67) |
| [ADR-041](adr/041-only-trusted-input-establishes-a-task-and-a-group-reference-is-expanded-by-hq.md) | Only trusted input establishes a task, and a group reference is expanded by HQ | **accepted and implemented** (13 Sep, after gate 6, docs/69) |
| [ADR-042](adr/042-security-intent-is-a-runtime-owned-signal-and-a-refused-request-has-no-substitute.md) | Security intent is a runtime-owned signal, and a refused request has no substitute | **accepted and implemented** (14 Sep, after gate 7, docs/71) |
| [ADR-043](adr/043-a-connector-failure-is-not-a-refusal-and-a-model-omission-is-not-a-group-wide-task.md) | A connector failure is not a refusal, and a model omission is not a group-wide task | **accepted and implemented** (15 Sep, after gate 8, docs/73) |
| [ADR-044](adr/044-a-prohibited-capability-is-its-own-boundary-and-a-scope-question-is-answered.md) | A prohibited capability is its own runtime-owned boundary, and a scope question is answered from the directory | **accepted and implemented** (15 Sep, after gate 9, docs/74) |
| [ADR-045](adr/045-the-a0-shadow-period-is-a-record-with-hard-stops.md) | The A0 shadow period is a record, opened on database evidence, with hard stops | **accepted and implemented** (16 Sep, docs/77); not yet used, no period has been opened |
| [ADR-046](adr/046-the-erp-sales-order-is-the-canonical-order-write.md) | The ERP Sales Order is the canonical order write; `take_order` is one business capability, and shops are projections | proposed, **not implemented** (16 Sep, docs/78 and docs/79) |
| [ADR-047](adr/047-effective-execution-context-authority-is-an-intersection-resolved-once.md) | Effective Execution Context: an agent run's authority is the intersection of requester, enabling identity, agent, business and brand scope and tool permission, resolved once by one resolver for every AI entry point, recorded and audited; the gateway checks each call against the same object | **accepted and implemented** (20 Sep 2026, docs/80 C.2); nothing switched on |
| [ADR-048](adr/048-autonomy-evidence-is-a-governed-versioned-policy-and-a-ceiling.md) | Autonomy evidence is a governed, versioned policy per level and dimension (no number invented: NULL is undecided and can never be activated), produced from evaluation results and applied as a ceiling combined by minimum, never a grant | **accepted and implemented** (21 Sep 2026, docs/81 C.4); no policy exists, grants stay zero |

Decisions inherited from the existing estate and treated as settled unless Joy reopens them: LaLaDia is the product and Bionic its fork (fixes flow upstream then down); brands are a product dimension in the ERP, not tenants; per-account identifiers are settings rows, never code literals; every schema change ships with its migration in the same commit; deploy only after CI is green.
