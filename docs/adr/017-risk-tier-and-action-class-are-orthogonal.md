# ADR-017: Risk tier is intrinsic to a tool; action class is derived by policy per call

**Status:** proposed (Phase 2, docs/48)

## Context
`docs/11` and `docs/13` classify every tool as AUTO, APPROVAL_REQUIRED or HUMAN_ONLY. That single
axis answers "who may run it", and it is used as though it also answered "how much damage could it
do". Those are different questions. Damage is a property of what the code does and changes only when
the code changes. Who may run it depends on the actor, the business, the amount, the hour, the
current guardrails and the agent's earned autonomy, and changes often.

Collapsing them means either the static thing drifts (a tool's class edited for a situational
reason) or the dynamic thing cannot be expressed (the same tool needing approval at one value and
not at another).

## Decision
Two orthogonal concepts.

**Risk tier** is declared in the tool's `ToolDescriptor`, changed only by a code change and review:

| R0 | pure computation, no I/O |
| R1 | internal read of HQ's own read models |
| R2 | external read of a system of record or provider |
| R3 | internal write: HQ state only, reversible, invisible outside HQ |
| R4 | external side effect, recoverable without loss |
| R5 | material or irreversible: money, a customer-visible message, a publication, a price, a cancellation, a deletion, a policy change |

**Action class** (`allow`, `require_approval`, `deny`, `dry_run_only`, `require_confirmation`) is the
runtime output of the policy engine for one specific call. The tier supplies the baseline; a tool's
policy class may make it stricter and never looser; only an explicit, audited guardrail rule
configured by a human can relax it, and never below R5's floor.

Orthogonal to both, `agent_executable: false` reproduces HUMAN_ONLY exactly. It is checked before
everything else and no approval can lift it.

## Alternatives
- **Keep the three classes only.** Rejected: cannot express value-dependent gating without editing a
  static classification for a situational reason.
- **Numeric tiers only, no action class.** Rejected: `docs/11`, `docs/12` and `docs/13` are written
  in the three names and remain readable if they survive as the derived outcome.

## Consequences
Every tool must declare a tier, and CI fails if one does not. Policy gains a completeness test: every
registered tool must resolve to a decision, so a tool nobody classified is caught in CI rather than
falling through to the default deny in production. Phase 2 enables only R0 to R3 tools; R4 and R5
ship registered, disabled, and not agent-executable, which means turning one on takes both a code
change and an audited runtime action.
