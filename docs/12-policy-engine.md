# 12 - Policy Engine

Every tool call passes `PolicyEngine::evaluate(agent, tool, args, context)` and receives exactly one of `allow`, `require_approval(reason)`, `deny(reason)`. The engine is deterministic, versioned and testable; the model never participates in it.

## Evaluation order

1. **Kill switches**: tool disabled, agent disabled, business paused, global "AI freeze" flag -> deny.
2. **Action class** of the tool (doc 11): HUMAN_ONLY -> deny for agents (only a human may execute).
3. **Permission**: the enabling human holds the tool's permission for this business -> otherwise deny.
4. **Guardrail rules** for the tool (table below) -> allow / require_approval / deny.
5. **Budgets**: agent daily cost, tool daily count, business daily spend -> require_approval when exceeded.
6. **Consent and legal**: outbound messaging requires consent flag; no message outside 08:00-21:00 Asia/Dhaka unless replying to an open conversation.
7. Default for anything not matched: `require_approval`. Policies fail closed.

## Guardrail rules (configurable per business, stored in `policies.rules`)

| Rule key | Default | Effect |
|---|---|---|
| `ads.budget_increase_max_pct` | 20 | `update_campaign` raising daily budget by more than this -> approval |
| `ads.budget_decrease_max_pct` | 50 | decreases beyond this -> approval (a cut can also hurt) |
| `ads.max_daily_spend_bdt` | 3,000 | any change taking total daily spend above -> approval |
| `ads.pause_on_cpa_above_bdt` | 900 (LaLaDia AOV ~1,100) | `pause_campaign` allowed AUTO when exceeded over the window |
| `ads.pause_window_hours` | 48 | learning-phase protection: no changes in the first 48 h except stop-loss |
| `ads.min_roas` | 1.5 | below this over the window -> recommendation + approval to pause |
| `order.auto_create_max_bdt` | 3,000 | `create_order` above -> approval |
| `order.max_discount_pct` | 10 (staff cap 40 in the shop) | discounts above -> approval |
| `refund.auto_max_bdt` | 0 | all refunds -> approval |
| `stock.protect_below_units` | 5 | when stock below, ads for that product -> `pause_campaign` allowed AUTO |
| `content.sensitive_topics[]` | health claims, religion, politics, competitors | publication touching these -> approval |
| `content.auto_publish` | false | until a brand has 30 approved posts, every publish -> approval |
| `message.first_contact_requires_approval` | true | first outbound to a customer who has never messaged -> approval |
| `purchase.recommendation_max_bdt` | any | recommendations are proposals; the ERP approval workflow owns the PO |

Rules are versioned; a rule change is audited and takes effect for new evaluations only.

## Representation

```php
final class UpdateCampaignPolicy implements ToolPolicy {
    public function evaluate(ToolContext $ctx, array $args): PolicyResult { ... }
}
```
Each policy class reads its thresholds from `policies.rules` for the business, so behaviour is data-driven but the logic is code (typed, tested). No rule engine DSL in v1.

## Interaction with approvals

`require_approval` creates an `approvals` row with the full proposed action, pauses the run (the workflow step is checkpointed), notifies the approver, and on decision either executes the exact stored args (never re-generated) or records the refusal back to the agent as feedback.

## Testing

Every rule has table-driven tests at the boundary values. A policy change must ship with the tests that fail on the previous version (the "watched failing first" discipline already used across the codebases).
