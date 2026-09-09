# ADR-022: Platform capabilities are resolved per connected account, not per platform

**Status:** accepted (8 Sep 2026, approved by Joy against docs/53) and **IMPLEMENTED** (9 Sep 2026,
Phase 2.5 A, docs/57). `App\Platform\Capabilities` and `CapabilityResolver` resolve per connected
account, the five previously unread `platform_accounts` columns are load-bearing, and eight
provider-neutral interfaces are declared. No provider is implemented and the registry ships empty.

## Context
The pattern already exists and works. `App\Commerce\Dto\Capabilities` carries a flag map with
`supports(string $operation): bool`; `CommercePlatform::capabilities()` obliges every adapter to
declare its own; `FactsReconciler` gates real behaviour on `supports('list_orders')`; and the
LaLaDia adapter's flags carry a verification date with pending shop features explicitly false.

Two things stop it serving the target. It is hard-coded in PHP, so a capability that varies by
connected account cannot be expressed. And `platform_accounts` has carried `capabilities`, `scopes`,
`approval_status`, `quotas` and `restrictions` since Phase 0 while nothing in the application reads
or writes any of them.

That matters because platform access is not a property of a platform. Two Facebook Pages in the same
group can hold different scopes, be at different stages of App Review, and have different quotas.
WhatsApp adds a rule no other channel has, where outside a 24-hour service window only pre-approved
templates may be sent. For months at a time some capabilities will be pending approval.

## Decision
A shared `App\Platform\Capabilities` service, promoted out of `App\Commerce\Dto`, resolving
capabilities **per connected account**: the adapter's static declaration is the ceiling, and the
`platform_accounts` row narrows it to what that account actually holds today.

**An agent is offered only the capabilities the connected account holds right now.** Where approval
is pending, the capability is absent, and the agent must be able to say so rather than promise
something the platform will refuse.

## Alternatives
- **Keep capability tables in documentation.** Rejected: `docs/18` already holds a per-platform
  matrix, and a matrix in Markdown cannot stop an agent promising a customer a reply on a channel
  the account cannot use.
- **One capability set per platform.** Rejected: it cannot express two Pages at different review
  stages, which is the actual situation.

## Consequences
Every provider interface defined from Phase 2.5 onward carries capability negotiation. The five
unused `platform_accounts` columns become load-bearing. Adapters declare a ceiling and stop being
the source of truth for what an account can do.
