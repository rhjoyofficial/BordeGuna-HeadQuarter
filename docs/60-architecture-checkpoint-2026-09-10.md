# 60 - Architecture checkpoint: the boundaries HQ is built to keep (10 September 2026)

**Status: the governing decision package. Phase 3, Customer AI, outbound messaging, autonomy
expansion and every additional product capability are blocked on this document existing, and it now
does.**

HQ is a business operating system with a ten-to-twenty-year horizon, not a collection of AI features.
One principle governs every decision below:

> **AI models, AI vendors, tool protocols, external providers and infrastructure implementations must
> remain replaceable. HQ's business and control contracts must remain durable.**

This document is the map. The decisions themselves live in ADRs, and the ADR is what a future change
has to argue with.

---

## 1. The twenty-year invariants

Nine statements that must survive Claude changing or disappearing, OpenAI and Gemini changing, new
model architectures, MCP evolving or being replaced, every vendor changing its API, new communication
and media platforms, infrastructure moves, more businesses and brands, many more agents, and far more
autonomous decisions.

| # | Invariant | Held by |
| --- | --- | --- |
| **I1** | One door. Every caller reaches every effect through the Tool Gateway. There is no second path and no break-glass. | ADR-016 |
| **I2** | Capability is HQ's vocabulary. Vendors, protocols and accounts are implementations behind it. | ADR-029 |
| **I3** | A model proposes; it never executes. Structured output in, gateway decision out. | ADR-004, ADR-019 |
| **I4** | An agent borrows a human's authority. The effective set is the intersection, never the union. | docs/48 E.3 |
| **I5** | Provenance is structural. Untrusted content can inform an answer and can never raise autonomy. | ADR-024 |
| **I6** | Truth lives in systems of record. HQ holds copies, and a copy carries its age. | ADR-007, ADR-033 |
| **I7** | Autonomy is earned from measured evidence, and permanent boundaries are never earned past. | ADR-023, ADR-030, ADR-034 |
| **I8** | Process state is durable and deterministic. It never lives in a model. | ADR-032 |
| **I9** | An effect is not real because a request succeeded. It is real when its postcondition is read back. | ADR-031, ADR-011 |

Every one is a **refusal**, and that is deliberate: an invariant expressed as an aspiration is a
preference, and preferences lose arguments to deadlines.

---

## 2. The boundaries

### 2.1 Model Router

**Owns:** which provider and model serve a task; fallback; degradation; token, cost and latency
accounting; the budget ceiling.
**Never owns:** what may happen. It returns text.
⛔ No model id appears in `app/` outside the driver layer, enforced by a grep test. Prices and models
are rows, so a new model is a row edit and a retired one is not a deploy. **Replacing Anthropic is a
driver plus rows.**

### 2.2 Agent Runtime

**Owns:** the loop, its hard bounds (3 model turns, 8 tool calls, stop on the second denial of a
tool), context assembly, the shadow record.
**Never owns:** permission, policy, consent, autonomy, credentials, execution.
⛔ It holds no connector, no vault, no query builder; exactly one `->execute(` and it is the
gateway's. Reading is not acting (ADR-027), so evidence gathering is not gated by autonomy while
anything with an effect is.

### 2.3 Capability Registry

**Owns:** the eight durable capability families - messaging, comments, telephony, advertising,
publishing, media generation, research, storage - and which connected account satisfies one now.
**Never owns:** authorization.
⭐ `publish_content` must never become "call Meta endpoint X". Two equally-valid accounts are
**refused, not chosen**, mirroring `AmbiguousCredential`: acting as the wrong identity reaches a real
person under the wrong name. (ADR-022, ADR-029)

### 2.4 Tool Gateway

**Owns:** everything between a proposal and an effect - registry and runtime switch, schema
validation, scope and business isolation, permission intersection, policy, consent, approval,
idempotency, credential pre-flight, execution, audit.
**Never owns:** prose. There is nowhere in a `ToolRequest` to put a sentence.
This is I1, and it is the single most valuable thing already built: a Phase 3 support agent and a
Phase 5 campaign agent inherit all of it without any of it being rebuilt.

### 2.5 Protocol adapters, including MCP

**Owns:** speaking a foreign protocol and translating it into HQ's canonical tool and capability
contract.
**Never owns:** identity, permission, consent, autonomy, approval, audit, provenance, idempotency.
⛔ **An agent may not bypass the gateway because a provider exposes an MCP server**, and a tool
discovered over a protocol is not a tool HQ has until somebody registers a descriptor with a risk
tier, a permission and a consent requirement. Anything a protocol returns is content and carries
provenance. (ADR-029)

### 2.6 Identity, permission, consent, autonomy

Four different questions, four components, combined in a fixed order and never merged:

```
who is acting        ActingIdentity      an agent borrows a human; intersection, never union
may they do this     PolicyEngine        first match wins; one governing rule
may we contact them  ConsentGate         at the gateway, not inside a tool
how much without us  AutonomyResolver    minimum of every ceiling; absence is A0
```

⛔ **A credential is never an authorization.** Holding a working token proves the far side will accept
the call, not that HQ should make it. `IdentityKind::Credential` returns false for every permission,
by construction.

⚠ Policy is **first match wins**; autonomy is **minimum of all**. Two combinators cannot share one
component without one corrupting the other, which is why they are two.

### 2.7 Provenance and trust

Five labels - system, business, customer, third-party, generated - attached to the content, not
described near it. `Generated` is **not trusted**: a model's own prior output can carry an injection
it read earlier, and believing a summary of untrusted text is how a laundering path opens.

⛔ A bundle containing untrusted content caps autonomy at A1, read from the label rather than the
text, so no wording inside the untrusted block can lift it. The `<untrusted>` markers in a prompt are
belt, not braces. (ADR-024)

### 2.8 Evaluation → autonomy evidence

```
suite → cases (each declaring a dimension) → run against a real subject
  → results, per dimension → rates, per dimension
  → autonomy_grant.min_agreement_rate [+ min_agreement_dimension]
  → AutonomyResolver: a grant whose evidence is unmeasured DOES NOT MATCH
```

Six dimensions, because one number averages six things that fail for different reasons and are fixed
by different people. A blocker failure fails the run whatever the rate. A proposed call stays
distinguishable from a permitted one. (ADR-023, ADR-028, ADR-030)

### 2.9 Workflow and orchestration

**Agents decide what should happen. Durable infrastructure decides how it progresses** through
retries, timing, deduplication, state transitions, deadlines and compensation.
The test to apply to any workflow design: **is it still valid if the model provider is swapped
mid-process?** With durable state in the scheduler, yes. In the model, the process is gone.
(ADR-032)

### 2.10 Verification and reconciliation

```
precondition → proposal → policy → authorization → execution
   → postcondition verification → reconciliation → compensation / retry / escalation
```

⛔ **A 200 is not a postcondition.** An unverified effect is `uncertain`, never `succeeded`. Where an
effect is reversible, the compensating action is modelled; where it is not safely reversible, the
human-only boundary stays. (ADR-031)

### 2.11 The permanent HUMAN_ONLY boundary

Approval-required is a **stage** that measured evidence can end. HUMAN_ONLY is a **decision** that it
cannot, and the two must remain distinguishable in the refusal itself, not merely in a flag.

Permanent: moving money; changing credentials, permissions, roles or scopes; changing autonomy
itself; overriding or deleting consent; granting synthetic-identity consent; altering audit logs,
decision records or the consent ledger; legal, regulatory and tax statements; final pricing policy;
employment and payroll decisions; mass outbound above a human-set threshold; accepting liability on
the group's behalf; any write to a system of record where HQ is not authoritative.

⛔ An agent may never request its own widening. `bypass.autonomy_self_grant` is a blocker case in the
safety corpus. (ADR-025, ADR-034)

---

## 3. What changed in this checkpoint

| | Change | Where |
| --- | --- | --- |
| Built | `CapabilityRouter` - capability → account, fail-closed, refuses ambiguity | ADR-029 |
| Built | Evaluation dimensions; dimension-scoped autonomy evidence; proposal vs permitted call kept apart | ADR-030 |
| Built | Backup-first deploy ordering, truthful predecessor from the running image label, backup-boundary repair | `docs/58` H.1 closed |
| Built | A missing HQ backup root now **alerts** instead of removing the check | - |
| Specified | MCP and protocol adapter contract, deliberately not implemented | ADR-029 |
| Specified | Verification-first execution | ADR-031 |
| Stated | Deterministic orchestration; memory is never truth; approval is a stage | ADR-032, 033, 034 |

---

## 4. What this checkpoint deliberately did NOT do

- **No MCP implementation.** The contract is written; no adapter is built, because no provider is
  being integrated. Building one now would fix its shape around an imagined case.
- **No provider implementations.** `PlatformRegistry` is still empty, and the router's tests assert
  that an empty registry resolves to "HQ has no provider implementation", never to a permissive
  default.
- **No verification machinery.** There is no agent-executable write tool, so there is nothing to
  verify. ADR-031 is a precondition for consequential autonomy, not a description of it.
- **No new agent capability, no customer-facing surface, no outbound channel.**
- **No autonomy grant.** The shipped state remains: absence of configuration, which resolves to A0.

---

## 5. The honest position

HQ can **observe** across four systems with evidence, citations and an audit trail, and it can refuse
correctly. It cannot yet **act** autonomously in any consequential way, and the evidence base for
doing so does not exist: `execution` and `outcome` have no measurements, because nothing an agent may
run has an effect.

That is the correct order. The architecture for acting is now written down and enforced where it can
be enforced, so that the first capability that acts meets a system rather than an absence.

**Next milestone after activation: M6 (Ask HQ, natural-language scheduling) and M7 (daily briefing,
dashboards, retention), then Phase 3 - each governed by the boundaries above.**
