# 10 - AI Skills

A skill is a reusable, tested, approved procedure an agent can run: a named sequence of tool calls with a fixed input/output contract. Skills are how repeated work becomes reliable and cheap (a skill can run on a low tier or with no model at all).

## Skill record

| Field | Meaning |
|---|---|
| `key`, `name`, `version` | stable identifier, semantic version |
| `purpose` | one paragraph, human readable |
| `inputs_schema`, `outputs_schema` | JSON schema, validated at the boundary |
| `required_tools[]` | the only tools the skill may call |
| `permissions[]` | what the enabling human must hold |
| `policy_keys[]` | policies evaluated per step |
| `owner` | the human accountable |
| `status` | `proposed` -> `draft` -> `testing` -> `approved` -> `active` -> `retired` |
| `tests_ref` | fixture set + expected outputs |
| `metrics` | success rate, cost per run, human corrections per run |
| `implementation` | either a declarative step list (tool, arg mapping, condition) or a PHP class implementing `Skill` |

## Initial skills (Phase 2-4)

| Skill | Agent | Steps |
|---|---|---|
| `daily_briefing` | executive | ERP overview + shop orders + ad spend + open handoffs -> summarise -> `create_notification` |
| `answer_product_question` | support | `search_products` -> `get_product` -> compose reply within brand persona |
| `check_stock` | support/inventory | `get_inventory` -> reply or `inventory.low` |
| `take_order` | support | resolve customer -> `search_products` -> build draft -> confirm with customer -> `create_order` (policy: value threshold) |
| `escalate_to_human` | support | `request_human` with reason + summary |
| `schedule_post` | content | validate asset + caption -> `schedule_content` |
| `weekly_content_plan` | content/marketing | brand calendar + past performance -> plan -> approval |
| `campaign_health_check` | advertising | pull metrics -> compare to guardrails -> recommendation or `pause_campaign` within policy |
| `low_stock_sweep` | inventory | shops + ERP stock -> threshold -> notify + purchase recommendation |
| `order_followup` | operations | delivered orders -> review request / reorder nudge (consent-checked) |

## Skill proposal loop (controlled self-improvement)

```
observe (ai_runs, tool_calls)  -> detect repeated tool sequences with stable shape (>= N runs, high success)
  -> generate skill specification (inputs, outputs, steps, tests from the observed runs)
  -> status=proposed, notify owner
  -> owner reviews -> draft
  -> run tests against recorded fixtures in a sandbox (no real connector writes)
  -> evaluation report (cost, accuracy vs the original runs)
  -> approval by the skill owner (HUMAN)
  -> active
```

Rules: a proposed skill can never self-activate; it inherits the strictest policy of its tools; activation is an audited action; a skill whose success rate drops below its floor is auto-suspended (a safe direction) and the owner is told.

## Reuse

Skills are stored as data plus optional class, so a skill can be exported to another business or brand with its tests. The existing Claude Code skills in Joy's environment (n8n deploy, VPS, Meta audit) are operator skills, not runtime skills, and stay where they are.
