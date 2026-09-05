# ADR-011: At-least-once execution with idempotent tools and reconciliation; no exactly-once claim

**Status:** accepted

## Context
Early drafts said scheduled jobs were "exactly-once per slot". That is true only of the local run record; an external side effect (a Facebook post, an order at a store, a campaign change) cannot be made mathematically exactly-once by HQ.

## Decision
Reliability model for every external effect:
1. one logical job slot, one unique run record (no duplicate logical scheduling);
2. **at-least-once** execution by the queue;
3. idempotent tool execution keyed by an idempotency key;
4. provider idempotency where the provider supports it (order `Idempotency-Key`, Meta client request ids, Woo idempotency where available);
5. **reconciliation when provider state is uncertain**: every effect with a provider identity keeps a `logical_key` and a `state` (`pending|submitted|confirmed|uncertain|failed`); on retry the tool first calls `reconcile(logical_key)` to look the effect up at the provider and only executes again if it is provably absent.

Worked example: a scheduled Facebook publish has `publications.logical_key`. Worker crashes after Facebook accepts the post but before HQ stores the post id. On retry HQ marks the row `uncertain`, asks the Meta adapter for recent Page posts carrying HQ's embedded marker or request id, adopts the found post id, and does not publish twice. If lookup is impossible, the row stays `uncertain`, a `side_effect.uncertain` event alerts a human, and nothing is re-sent automatically.

## Consequences
Every write tool implements `reconcile()` or declares that it cannot (then a failed-uncertain state requires a human). Provider adapters must expose a lookup path. Documentation never says "exactly-once" for side effects.
