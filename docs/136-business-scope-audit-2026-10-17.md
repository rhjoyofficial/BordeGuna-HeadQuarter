# 136. Business scope audit of every screen, 17 October 2026

Branch `fix/business-scope-approvals-and-jobs` from main `c0db11e`, worktree `hq-wt/j`, test database `hq_test_j`. Nothing here touches production; Joy alone releases.

## Why

- Found during W-UI2 (`docs/135` (i), branch `ui/w-ui2-console`): the approvals list and the scheduler list were not narrowed by business for a person whose access is limited to some businesses, while the dashboard counts already were.
- Rulings `docs/120` A4 and `docs/131` §1: every query enforces business scope independently. A corrected reader elsewhere is not enough.

## The rule applied (found, not invented)

- `BusinessScope` (`app/Support/Authorization/BusinessScope.php`): no `user_business_scopes` rows means group-wide; rows mean "only these businesses". `restrict()` is the dashboard's mechanism.
- Rows with no business are hidden from a restricted person, fail closed. Sources: ADR-035 §4 ("a run with no business is cross-business by nature and is hidden from a restricted user; fail closed"), `docs/111` and `docs/125` item 34 (a record resolving to no business is counted for a group-wide viewer only), `BriefingRequest::restrict()` ("rows with no business drop out for a restricted caller"), and `restrict()` itself (`whereIn` drops NULL).
- An id from another business answers 404, as `AgentController`, `InboxController`, `CustomerController` and `FollowUpController` already do (learning that a row exists is a disclosure).

## Plan

1. Audit every controller under `app/Http/Controllers` and the readers under `app/Observe` that list or show business-owned rows; record the verdict per endpoint, before and after.
2. Fix every gap with `BusinessScope::restrict()` (through a `visibleTo` model scope where a model needs one, as `FollowUp` does) and a 404 on an out-of-scope id for show and every action.
3. Screens whose rows carry no business at all (audit log, alerts) are group-wide by nature: a restricted person is refused the screen (403 with the reason) and gets no count or row of them on the dashboard or the sidebar.
4. Tests per fixed endpoint: restricted person sees only their businesses; another business's id (and a group-level one) answers 404; an unrestricted owner still sees everything. A structural test listing every audited controller and its verdict, so a new controller fails until it is audited.
5. Record the verdicts below; note that production `b96a396` carries the same gaps.

## Verdicts

(filled in after the build)
