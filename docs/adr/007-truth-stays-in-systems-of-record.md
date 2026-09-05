# ADR-007: HQ holds read models only

**Status:** proposed

## Decision
HQ never becomes the master for employees, ledgers, inventory, products, prices, orders or payments. It keeps rebuildable read models (`facts_*`, customer profile) and its own masters only for what it invents: agents, skills, tools, policies, approvals, schedules, workflows, conversations, identities, memory, assets, marketing experiments, content items, audit.

## Consequences
A `rebuild` command per read model from day one; every fact shown to a customer or used in an action is re-read live; the ERP/shop stock disagreement is surfaced, not resolved, by HQ.
