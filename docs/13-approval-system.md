# 13 - Approval System

## Classes of action

| Class | Meaning | Examples (defaults, all configurable in doc 12) |
|---|---|---|
| AUTO | agent executes, audited | product/stock/price lookup, standard reply in an active conversation, reports, creating a pending scheduled job, pausing a campaign that breached stop-loss |
| APPROVAL_REQUIRED | agent proposes, a human with the right permission decides | budget change beyond guardrail, order above threshold, discount, refund, outbound campaign message, sensitive publication, purchase recommendation above a value, new skill activation |
| HUMAN_ONLY | the agent may draft or recommend but never execute; a human performs the action in HQ or the source system | legal, employee decisions, money transfers, deleting campaigns/records, changing policies, resuming AI after a handoff |

## The approval record

`approvals(id, subject_type, subject_id, action_class, requested_by, agent_id, tool_key, payload (exact args), reason (agent's justification), evidence (facts used), status pending|approved|rejected|expired, decided_by, decided_at, decision_note, sla_due_at, escalation_to)`.

The stored `payload` is what executes on approval. The agent is never asked to regenerate it, so what was approved is what runs.

## Flow

```
tool call -> policy says require_approval
  -> approvals row (pending) + audit entry
  -> notification to approvers (in-app inbox, Telegram; email optional) with a one-screen summary:
       what, why, evidence, cost/impact, what happens if ignored
  -> approver: Approve / Approve with edit (edits are recorded as a decision_record) / Reject with reason
  -> on approve: ToolGateway.execute(stored payload) as the approver's authority + agent's identity
  -> on reject: agent receives the reason as feedback; workflow step marked refused
  -> on SLA breach: escalate to the next approver; after the final SLA, expire (never auto-approve)
```

## Approvers and SLAs (defaults)

| Domain | Approver role | SLA |
|---|---|---|
| Orders, discounts, refunds | Operations lead (Joy) then Owner | 2 h |
| Ads and budgets | Marketing lead then Owner | 4 h, 30 min for stop-loss reversals |
| Content publication | Brand manager | 12 h |
| Purchase recommendations | Procurement (ERP workflow takes over) | 24 h |
| Skills, policies, agents | Operator (Joy) + Owner | 48 h |

## Reuse of the ERP pattern

The ERP already has a config-driven, multi-stage approval workflow with a shared inbox. HQ copies the *shape* (workflow, stages, role/permission per stage, decisions table, inbox badge) rather than sharing tables, and adds the AI-specific fields (agent, tool, payload, evidence). Where an action belongs to the ERP (a purchase order, a journal), HQ creates the *proposal* and the ERP's own workflow approves it; HQ never bypasses an ERP gate.

## UI

Approval Center: filter by business, class, agent, age; batch approve only for identical low-risk classes; every card shows the evidence the agent used and links to the live record. Mobile-first, because approvals happen on a phone.
