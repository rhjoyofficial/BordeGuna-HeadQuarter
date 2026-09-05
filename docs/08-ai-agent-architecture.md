# 08 - AI Agent Architecture

## The agent loop, every time

```
 input (message, command, event, schedule)
   -> agent selected by router (role + business + channel)
   -> context assembled: system prompt, brand persona, policy summary, retrieved memory, live facts via read tools
   -> model call (tier chosen by ModelRouter)
   -> structured output: {reply?, actions[]}  (JSON schema enforced)
   -> for each action: ToolGateway.execute(agent, tool, args)
        -> PolicyEngine.evaluate   -> deny | allow | require_approval
        -> PermissionEngine.check  (agent role AND the enabling human)
        -> ApprovalEngine.request  (if required; the run pauses and is resumable)
        -> Connector call with idempotency key
        -> audit_log + tool_calls
   -> result composed -> delivered to channel
   -> ai_runs row (tokens, cost, latency, outcome)
   -> feedback hooks (human edit, rating, downstream business outcome)
```

Agents are **configuration, not code**. One runtime; agents differ by row in `agents`: role, system prompt, allowed tools, memory scope, model tier, KPIs, policies.

## Registry (v1)

| Agent key | Role | Tools (initial) | Tier | KPIs |
|---|---|---|---|---|
| `executive` | AI CEO: briefings, prioritisation, cross-business questions | all read tools, `create_notification`, `generate_report` | high | owner rating of briefings, decisions surfaced and acted on |
| `operations` | orders, shipments, exceptions | shop order tools, courier tools, `request_human` | medium | orders stuck past SLA, exception resolution time |
| `support` | customer conversations | commerce read tools, `create_order` (value-gated), `send_message`, `request_human`, `resume_ai` | medium (low for FAQ) | resolution without human, escalation precision |
| `sales` | lead follow-up, upsell, dealer quotes | ERP CRM tools, shop tools, `send_message` | medium | conversion, response time |
| `marketing` | strategy, plans, audience/offer hypotheses | analytics read, `create_experiment` | high | experiments with a stated hypothesis and a recorded result |
| `advertising` | campaign ops within guardrails | `create_campaign`, `update_campaign`, `pause_campaign` | medium | CPA/ROAS vs target, guardrail breaches (target 0) |
| `content` | copy, scripts, hooks per brand persona | asset tools, `schedule_content`, `publish_content` (approval) | medium | first-pass approval rate, engagement |
| `creative` | image/video briefs and QA | media generation tools | specialised | asset acceptance rate |
| `finance` | receivables, cash, cost alerts, anomaly flags | ERP finance read tools | medium | anomalies caught, false alarms |
| `inventory` | stock watch, reorder recommendations | shop + ERP stock tools, `create_purchase_order` (proposal) | low/medium | stockouts avoided |
| `procurement` | vendor comparison, PO drafts | ERP purchasing tools | medium | PO cycle time |
| `analytics` | attribution, reports on demand | facts tables, `generate_report` | medium/high | spot-checked correctness |
| `hr` | attendance/leave summaries only, **no decisions** | ERP HR read tools | low | none autonomous |
| `voice` | phone conversations (Phase 7) | support tool set via the voice gateway | realtime | call completion, handoff rate |

An agent's tool list is the *maximum*. The effective set at runtime is computed per call from seven filters: **role** (the agent), **scope** (the task or conversation), **permission** (the enabling human's), **business**, **brand**, **policy** (current guardrails and kill switches) and **environment** (staging exposes no production write tools). No agent ever holds unrestricted tools.

## Prompts and personas

System prompts are versioned files in `resources/prompts/<agent>/<version>.md`; brand persona blocks (doc 19) are injected per brand. Prompt changes are code changes, reviewed and run against the evaluation set (doc 29). Nothing is edited live.

## Context assembly rules

1. Live facts come from read tools in the same run; each fact carries its timestamp.
2. Retrieval (memory, SOPs, brand knowledge) has a token budget per tier; chunks carry source pointers.
3. The policy summary for the agent's tools is included so the model does not propose what will be refused.
4. Never include credentials, rows outside the permission map, or another customer's data.

## Behavior Alignment Layer (ADR-013)

How the AI learns the owner's way of working. **No fine-tuning and no model training** in Phase 0 through Phase 9; the layer is designed so fine-tuning could be added later as one more input if it ever proves worthwhile.

- **Preferences**: `owner_preferences` rows (tone, report length, thresholds, working hours, escalation rules).
- **Decisions**: every approval, edit of an AI draft and override is stored as proposal plus final version; retrieval surfaces the closest past decisions as examples.
- **Evaluation**: a weekly job scores agents against stored decisions (agreement rate, edit distance) and reports drift.
- **SOPs and policies**: approved procedures and policy prose as embedded documents; agents cite them.
- **Examples**: approved outputs per brand and task type, retrieved as few-shot examples.
- **Human handoff feedback**: edited AI replies, human resolutions and marked outcomes from conversations (doc 14) flow into `decision_records`.

## Coordination

Agents do not chat with each other. The `executive` agent **delegates** by creating a `workflow_run` for another agent, which then runs under its own policies. Every action stays attributable to one agent and one policy set.

## Reuse from the ERP

`AssistantDriver`, `AnthropicDriver`, `OpenAiDriver`, `AiCredentialResolver`, the permission-derived table map, and the rule "the app compiles the query, the model never writes SQL" are lifted directly.
