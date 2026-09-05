# ADR-004: Typed tool gateway with a deterministic policy engine in front of every action

**Status:** proposed

## Context
The brief requires that AI never bypasses policy, permission, approval and audit. The ERP assistant already proves the pattern for reads ("the model never writes SQL; the app compiles a structured request under the user's permissions").

## Decision
Every action an agent can take is a `Tool` class with a JSON schema, a permission, a policy class and an action class (AUTO / APPROVAL_REQUIRED / HUMAN_ONLY). `ToolGateway::execute` validates, evaluates policy, checks permission, requests approval when required (persisting the exact args), calls the connector with an idempotency key, and writes `tool_calls` + `audit_log`. Policies are code with data-driven thresholds; no free-form rule DSL in v1.

## Alternatives
- Let agents call connectors directly with prompt-level rules. Rejected: prompts are not enforcement.
- A generic rule engine / OPA sidecar. Rejected for v1: more moving parts than a handful of typed policy classes need.

## Consequences
Adding a capability means adding a tool with tests; the model's function definitions are generated from the same schema; a kill switch per tool exists from day one.
