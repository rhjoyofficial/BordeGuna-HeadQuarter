# ADR-019: The model router is provider-neutral and no model id appears in business code

**Status:** proposed (Phase 2, docs/48)

## Context
HQ will call several model providers over the life of this system, and model ids change faster than
anything else in the stack. A model id written into a service class is a deploy every time a price
changes, a provider has an outage, or a better model ships. Joy's Q4 answer also requires that
provider accounts be changeable from the UI.

There is a second, quieter reason. If model selection is scattered, cost accounting is approximate,
because nothing central knows which model actually ran.

## Decision
One entry point: `ModelRouter::for(TaskType, RoutingContext): ModelChoice`.

Model ids, prices, context windows and capabilities live in a `model_tiers` table, so a price change
or a new model is a row edit. Provider credentials are `integration_credentials` rows, editable from
the UI. Business code names a **tier and a task**, never a model.

Routing inputs: task type, estimated complexity, latency requirement, remaining budget, required
context size, modality, required capabilities (function calling, JSON-schema output, vision), data
sensitivity, and current provider health.

Fallback, in order: retry once on the same model with a repair instruction; the tier's fallback model
at a **different provider**; the tier below if the task is degradable, with the degradation recorded
on the run; then failure with an operator notification. A circuit breaker per provider stops HQ
hammering one that is down. Output that ran degraded says so on its face.

Exact ids are read from the `claude-api` reference and the provider's current model list at
implementation time, never hard-coded and never recalled from memory.

## Alternatives
- **One provider, ids in config.** Rejected: single point of failure for a system Joy will depend on
  every morning, and it makes a provider outage an HQ outage.
- **A third-party routing gateway (OpenRouter and similar).** Rejected for now: another dependency,
  another credential, another party seeing every prompt, and it hides the per-call cost data that
  `ai_runs` exists to capture.

## Consequences
A grep test asserts no model id appears in `app/` outside the driver layer. `ai_runs` gains
`actual_cost_usd`, `cost_source` and `degraded_from_tier`, so an estimate is never silently mixed
with provider-reported billing and a downgrade is visible in the data rather than only in a log line.
Adding a provider is a driver class plus rows, not a refactor.
