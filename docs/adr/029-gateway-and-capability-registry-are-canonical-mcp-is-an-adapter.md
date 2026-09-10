# ADR-029: The Tool Gateway and Capability Registry are canonical. MCP is an adapter.

**Status:** accepted (10 Sep 2026). The capability router is **implemented**; the protocol adapter
contract is **specified and deliberately not implemented** until a provider needs it.

## Context

HQ is being built to run a group of businesses for a decade or more. Over that period Claude may
change or disappear, OpenAI and Gemini will change, model architectures nobody has seen will arrive,
MCP will evolve or be replaced, every vendor will change its API at least once, new communication
platforms will appear, and the number of agents and autonomous decisions will grow by an order of
magnitude.

The failure mode this ADR exists to prevent is not any one of those events. It is **adopting a
protocol as an architecture**. MCP is a good interoperability protocol and it is spreading fast,
which is exactly what makes it tempting to let it become the place where tools are described,
discovered, permitted and invoked. If that happens, HQ's governance is defined in somebody else's
schema: every control it has - identity, policy, consent, autonomy, approval, budget, idempotency,
audit, provenance - becomes a thing that has to be re-expressed each time the protocol moves, and
the parts that do not fit the protocol quietly stop being enforced.

The same argument applies to any vendor. An agent that knows it is calling a Meta endpoint is an
agent that has to be rewritten when the group moves to another platform, adds a second Page, or
starts publishing somewhere that did not exist when the agent was written.

## Decision

**HQ's internal Tool Gateway and Capability Registry are canonical. Every other protocol, vendor and
transport is an adapter that translates into them.**

```
Agent
  └─ structured model output (a proposal; never an invocation)
       └─ HQ Tool Gateway  ← the single door (ADR-016)
            ├─ registry + runtime switch      ├─ consent           ├─ idempotency
            ├─ schema validation              ├─ approval          ├─ audit
            ├─ scope + business isolation     ├─ autonomy          └─ provenance
            ├─ permission intersection        ├─ budget
            └─ policy
                 └─ resolved capability implementation
                      ├─ direct API adapter
                      ├─ MCP adapter (client or server path)
                      ├─ internal service
                      └─ a protocol that does not exist yet
```

Four rules follow, and each is a refusal:

1. **An agent may never bypass the Tool Gateway because a provider exposes an MCP server.** An MCP
   server is a way of reaching an implementation. It is not an authorization to reach it, and the
   convenience of a ready-made transport is not a reason to skip the eight checks above.
2. **HQ's internal architecture must not depend on MCP-specific semantics.** Tool descriptors, risk
   tiers, capability contracts and the policy vocabulary are HQ's own. If MCP is replaced, the
   adapter is replaced; nothing above it moves.
3. **An agent reasons about a business capability, not a vendor.** `publish_content` must never
   become "call Meta endpoint X". Which provider, account and protocol satisfies a capability is
   resolved by HQ under the permissions and approvals in force at that moment.
4. **Credential identity is never authorization.** Holding a working token proves the far side will
   accept the call. It says nothing about whether HQ should make it. These are answered by different
   components and the vault has never been one of them.

### The capability registry is the durable abstraction

Eight families are long-lived business capability contracts rather than vendor integrations:
**messaging, comments, telephony, advertising, publishing, media generation, research, storage.**
Their interfaces already exist (Phase 2.5, ADR-022). What this ADR adds is the missing direction.

`CapabilityResolver` answers *"what can this account do"*, which is the right question once you hold
an account. The question a capability-shaped architecture actually asks is the inverse:
**"who can publish for this brand, right now?"** Without it, something upstream picks the account -
and whatever picks the account has made the vendor decision, whether it meant to or not.

`CapabilityRouter` answers it, and fails closed at every step: no registered provider means that
account can do nothing; capabilities never recorded means nothing; **two equally-valid candidates are
refused rather than chosen.** That last rule mirrors `AmbiguousCredential` deliberately - an
unordered `->first()` over two active credentials once kept silently using the key a replacement was
meant to replace, and choosing which Page to post as has the same shape with a louder consequence: it
reaches a real person under the wrong identity. There is no rule (newest wins, lowest id wins) that
is right often enough to be safe, because the operator's intent is not in the rows.

⚠ **Routing is not authorization.** The router says which implementation *could* serve a capability.
Whether this caller, at this identity, under this policy, consent, autonomy and approval, may invoke
it is the Tool Gateway's decision, and there is exactly one of those. A test asserts the router's
source names none of those components.

### Protocol adapters, specified now and built when needed

An adapter translates a foreign protocol into HQ's canonical contract. These concerns stay separate,
and an adapter that merges any two of them is wrong:

| Concern | Owner |
| --- | --- |
| protocol discovery and invocation | the adapter |
| provider authentication | `CredentialVault`, opened for the length of one call |
| HQ identity | `ActingIdentity` |
| business and brand scope | `ScopeResolver` + `BusinessScope` |
| consent | `ConsentGate` |
| policy | `PolicyEngine` |
| autonomy | `AutonomyResolver` |
| approval | `ApprovalService` |
| audit | `Auditor` |
| provenance and trust | `Provenance` on every context block |
| idempotency | `IdempotencyKeyFactory` |
| execution verification | ADR-031 |

Two rules bind every adapter, MCP included:

- **A tool discovered over a protocol is not a tool HQ has.** It becomes available only by being
  registered as a `ToolDescriptor` in deployed code, with a risk tier, a permission and a consent
  requirement that a person wrote down. Dynamic discovery may inform that registration; it may never
  substitute for it. A capability that appears because a remote server advertised it is a capability
  nobody reviewed.
- **Anything a protocol returns is content, and carries provenance.** An MCP resource is
  `ThirdParty` unless something specific makes it `Business`. It can inform an answer; it can never
  raise autonomy, and ADR-024 already makes that structural rather than advisory.

MCP is supported as a **first-class adapter where it provides genuine interoperability value** -
reaching a tool ecosystem HQ would otherwise reimplement. It is not the governance layer, and no
implementation is built until a provider is actually being integrated.

## Alternatives

- **Adopt MCP as the internal tool layer.** Rejected. It would put HQ's governance vocabulary inside
  a protocol HQ does not control, and every control that does not map cleanly would erode by
  omission. The transport is the cheapest part of an integration; the governance is the expensive
  part, and it is the part that must survive.
- **Let agents call vendor APIs directly for "simple" reads.** Rejected. The line between a simple
  read and a consequential one is drawn by the risk tier, not by how the call feels while writing it,
  and a second path is a second place for that judgement to be made differently.
- **Have the router return the first matching account.** Rejected: see the ambiguity rule above.
- **Wait for a real MCP integration before writing this down.** Rejected. The decision is cheap now
  and expensive after the first integration, because by then there is a working path to argue from.

## Consequences

Adding a provider, an account or a protocol is a row and an adapter. **No agent business logic
changes**, because no agent ever knew the vendor.

The cost is a layer of indirection that looks unnecessary while there is exactly one provider per
capability - which is today, where there are none. That is the correct moment to pay it: the
abstraction is free before the first provider and expensive after the third.

⚠ The router is implemented and **routes nothing today**, because `PlatformRegistry` is empty. That
is the honest state and the tests assert it: an empty registry resolves to "HQ has no provider
implementation for this platform", never to a permissive default.
