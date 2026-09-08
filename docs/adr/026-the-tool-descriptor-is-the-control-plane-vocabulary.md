# ADR-026: The tool descriptor is the control plane's vocabulary

**Status:** accepted (8 Sep 2026, approved by Joy against docs/53). **Partly implemented**: ADR-025's
`permanentlyHumanOnly` landed in M4. The remaining fields are scheduled for Phase 2.5, each arriving
with the subsystem that reads it.

## Context
`ToolDescriptor` already declares key, version, risk tier, permission, audit action, scope, read or
write, agent executability, supported businesses and stores, policy class, scope source, credentials,
idempotency, timeout, retry, cost hint, redaction and output schema. That vocabulary was enough for
six scheduled jobs and one demo approval.

It is not enough for an AI workforce that talks to customers, and the failure mode is specific: a
rule that cannot be expressed in the descriptor gets written inside a tool's body instead, where the
control plane cannot see it, cannot audit it, and cannot notice when the next tool forgets it.

## Decision
The descriptor is where a tool states its properties, and the control plane reads them there. The
following become expressible, each added with the subsystem that consumes it rather than all at once:

- **`channel`**, because sending on WhatsApp and sending on SMS have different consent, capability
  and cost rules, and today they would be either one tool or two unrelated ones.
- **`monetaryImpact`**, a value band. `CostHint` says what a call costs HQ, not what it moves in the
  business, and autonomy by monetary value cannot be expressed without the second number.
- **`consentRequired`**, naming the consent class the gateway must check (ADR-021).
- **`customerFacing`**, because a customer-visible action is reputationally irreversible even when it
  is technically reversible, which is a different question from read or write.
- **`reversibility`**, and the compensating action where one exists. R4 is defined as "correctable
  without loss" and nothing currently names what the correction is.
- **`dataClass`**, so calls carrying personal data can be routed and retained differently.
  `RoutingContext::$sensitivity` already exists as a null placeholder described as reserved for
  Phase 3, and this is what it was reserved for.

All are additive fields on a readonly class with defaults. None weakens an existing control.

## Alternatives
- **Put these in configuration.** Rejected for the reason ADR-017 gave: a risk property editable in a
  config file is one editable to make an expensive action look cheap. These are statements about what
  the code does, and they belong with the code.
- **Let each subsystem keep its own table of tool properties.** Rejected: the descriptor stops being
  the single place a tool describes itself, and the tables drift apart silently.

## Consequences
Phase 2.5's consent, autonomy and capability work each add the field it needs together with the check
that reads it. The descriptor grows, which is the cost. The alternative is rules scattered through
tool bodies where nothing can audit them, which is the reason the descriptor exists at all.
